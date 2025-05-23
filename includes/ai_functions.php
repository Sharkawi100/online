<?php
// /includes/ai_functions.php
// AI Quiz Generation Functions

/**
 * Get active AI provider settings
 */
function getActiveAIProvider()
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM api_settings WHERE is_active = 1 ORDER BY provider = ? DESC LIMIT 1");
    $stmt->execute([getSetting('ai_default_provider', 'openai')]);
    return $stmt->fetch();
}

/**
 * Make API call to OpenAI
 */
function callOpenAI($prompt, $model = 'gpt-4', $temperature = 0.7, $max_tokens = 2000)
{
    $provider = getActiveAIProvider();
    if (!$provider || $provider['provider'] !== 'openai') {
        throw new Exception('OpenAI provider not configured');
    }

    $api_key = decryptApiKey($provider['api_key']);
    $url = 'https://api.openai.com/v1/chat/completions';

    $data = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'أنت مساعد تعليمي متخصص في إنشاء أسئلة اختبارات تعليمية باللغة العربية. يجب أن تكون الأسئلة واضحة ومناسبة للمستوى الدراسي المحدد.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => $temperature,
        'max_tokens' => $max_tokens
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $error = json_decode($response, true);
        throw new Exception('OpenAI API Error: ' . ($error['error']['message'] ?? 'Unknown error'));
    }

    $result = json_decode($response, true);

    // Update usage count
    $stmt = $pdo->prepare("UPDATE api_settings SET usage_count = usage_count + 1, last_used = NOW() WHERE id = ?");
    $stmt->execute([$provider['id']]);

    return [
        'content' => $result['choices'][0]['message']['content'],
        'tokens_used' => $result['usage']['total_tokens'] ?? 0,
        'model' => $result['model']
    ];
}

/**
 * Make API call to Claude
 */
function callClaude($prompt, $model = 'claude-3-opus-20240229', $temperature = 0.7, $max_tokens = 2000)
{
    $provider = getActiveAIProvider();
    if (!$provider || $provider['provider'] !== 'claude') {
        throw new Exception('Claude provider not configured');
    }

    $api_key = decryptApiKey($provider['api_key']);
    $url = 'https://api.anthropic.com/v1/messages';

    $data = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'system' => 'أنت مساعد تعليمي متخصص في إنشاء أسئلة اختبارات تعليمية باللغة العربية. يجب أن تكون الأسئلة واضحة ومناسبة للمستوى الدراسي المحدد.',
        'temperature' => $temperature,
        'max_tokens' => $max_tokens
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01'
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $error = json_decode($response, true);
        throw new Exception('Claude API Error: ' . ($error['error']['message'] ?? 'Unknown error'));
    }

    $result = json_decode($response, true);

    // Update usage count
    $stmt = $pdo->prepare("UPDATE api_settings SET usage_count = usage_count + 1, last_used = NOW() WHERE id = ?");
    $stmt->execute([$provider['id']]);

    return [
        'content' => $result['content'][0]['text'],
        'tokens_used' => $result['usage']['input_tokens'] + $result['usage']['output_tokens'],
        'model' => $result['model']
    ];
}

/**
 * Generate quiz questions using AI
 */
function generateQuizQuestions($params)
{
    global $pdo;

    // Validate parameters
    $required = ['subject', 'grade', 'difficulty', 'count'];
    foreach ($required as $field) {
        if (empty($params[$field])) {
            throw new Exception("Missing required parameter: $field");
        }
    }

    // Check teacher's monthly limit
    if (!checkTeacherAILimit($params['teacher_id'])) {
        throw new Exception('لقد تجاوزت الحد الشهري لتوليد الأسئلة بالذكاء الاصطناعي');
    }

    // Get or build prompt
    if (isset($params['custom_prompt'])) {
        $prompt = $params['custom_prompt'];
    } else {
        $prompt = buildQuizPrompt($params);
    }

    // Call AI provider
    $provider = getActiveAIProvider();
    if (!$provider) {
        throw new Exception('No active AI provider configured');
    }

    try {
        if ($provider['provider'] === 'openai') {
            $result = callOpenAI($prompt, $provider['model']);
        } else {
            $result = callClaude($prompt, $provider['model']);
        }

        // Parse questions from response
        $questions = parseAIQuestions($result['content']);

        // Log the generation
        logAIGeneration([
            'teacher_id' => $params['teacher_id'],
            'provider' => $provider['provider'],
            'prompt_type' => $params['type'] ?? 'general',
            'subject_id' => $params['subject_id'] ?? null,
            'grade' => $params['grade'],
            'difficulty' => $params['difficulty'],
            'questions_generated' => count($questions),
            'tokens_used' => $result['tokens_used'],
            'cost_estimate' => estimateAICost($result['tokens_used'], $provider['provider']),
            'request_data' => json_encode($params),
            'response_data' => json_encode($questions),
            'success' => 1
        ]);

        return [
            'questions' => $questions,
            'tokens_used' => $result['tokens_used'],
            'provider' => $provider['provider'],
            'model' => $result['model']
        ];

    } catch (Exception $e) {
        // Log the error
        logAIGeneration([
            'teacher_id' => $params['teacher_id'],
            'provider' => $provider['provider'],
            'prompt_type' => $params['type'] ?? 'general',
            'subject_id' => $params['subject_id'] ?? null,
            'grade' => $params['grade'],
            'difficulty' => $params['difficulty'],
            'questions_generated' => 0,
            'tokens_used' => 0,
            'cost_estimate' => 0,
            'request_data' => json_encode($params),
            'success' => 0,
            'error_message' => $e->getMessage()
        ]);

        throw $e;
    }
}

/**
 * Build prompt for quiz generation
 */
function buildQuizPrompt($params)
{
    global $pdo;

    // Get subject name in Arabic
    $subject_name = 'عام';
    if (!empty($params['subject_id'])) {
        $stmt = $pdo->prepare("SELECT name_ar FROM subjects WHERE id = ?");
        $stmt->execute([$params['subject_id']]);
        $subject = $stmt->fetch();
        if ($subject) {
            $subject_name = $subject['name_ar'];
        }
    }

    // Get grade name
    $grade_name = getGradeName($params['grade']);

    // Difficulty mapping
    $difficulty_map = [
        'easy' => 'سهل',
        'medium' => 'متوسط',
        'hard' => 'صعب'
    ];
    $difficulty_ar = $difficulty_map[$params['difficulty']] ?? 'متوسط';

    // Build prompt based on type
    if ($params['type'] === 'text_based' && !empty($params['text'])) {
        $prompt = "بناءً على النص التالي:\n\n";
        $prompt .= $params['text'] . "\n\n";
        $prompt .= "أنشئ {$params['count']} سؤال فهم واستيعاب باللغة العربية مناسبة للصف {$grade_name}.\n";
        $prompt .= "يجب أن تكون الأسئلة بمستوى {$difficulty_ar}.\n";
        $prompt .= "يجب أن تختبر الأسئلة:\n";
        $prompt .= "- الفهم المباشر للنص\n";
        $prompt .= "- الأفكار الرئيسية\n";
        $prompt .= "- التفاصيل المهمة\n";
        $prompt .= "- الاستنتاجات والتحليل\n\n";
    } else {
        $prompt = "أنشئ {$params['count']} سؤال اختيار من متعدد باللغة العربية ";
        $prompt .= "للصف {$grade_name} في مادة {$subject_name}.\n";
        $prompt .= "يجب أن تكون الأسئلة بمستوى {$difficulty_ar}.\n\n";

        // Add topic if specified
        if (!empty($params['topic'])) {
            $prompt .= "الموضوع: {$params['topic']}\n\n";
        }
    }

    // Common instructions
    $prompt .= "التعليمات:\n";
    $prompt .= "1. كل سؤال يجب أن يحتوي على 4 خيارات (أ، ب، ج، د)\n";
    $prompt .= "2. يجب أن يكون هناك إجابة صحيحة واحدة فقط لكل سؤال\n";
    $prompt .= "3. الخيارات يجب أن تكون منطقية ومتقاربة في الطول\n";
    $prompt .= "4. تجنب الأسئلة السلبية (مثل: أي مما يلي ليس...)\n";
    $prompt .= "5. استخدم لغة عربية فصحى واضحة ومناسبة للعمر\n\n";

    $prompt .= "الصيغة المطلوبة لكل سؤال:\n";
    $prompt .= "س: [نص السؤال]\n";
    $prompt .= "أ) [الخيار الأول]\n";
    $prompt .= "ب) [الخيار الثاني]\n";
    $prompt .= "ج) [الخيار الثالث]\n";
    $prompt .= "د) [الخيار الرابع]\n";
    $prompt .= "الإجابة الصحيحة: [الحرف]\n\n";
    $prompt .= "ابدأ مباشرة بالسؤال الأول:";

    return $prompt;
}

/**
 * Parse AI response into structured questions
 */
function parseAIQuestions($response)
{
    $questions = [];

    // Split response into individual questions
    $pattern = '/س:\s*(.+?)\nأ\)\s*(.+?)\nب\)\s*(.+?)\nج\)\s*(.+?)\nد\)\s*(.+?)\nالإجابة الصحيحة:\s*([أبجد])/u';

    preg_match_all($pattern, $response, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $question_text = trim($match[1]);
        $options = [
            'أ' => trim($match[2]),
            'ب' => trim($match[3]),
            'ج' => trim($match[4]),
            'د' => trim($match[5])
        ];
        $correct_answer = trim($match[6]);

        // Convert Arabic letters to indices
        $letter_map = ['أ' => 0, 'ب' => 1, 'ج' => 2, 'د' => 3];
        $correct_index = $letter_map[$correct_answer] ?? 0;

        $questions[] = [
            'question_text' => $question_text,
            'options' => array_values($options),
            'correct_index' => $correct_index,
            'ai_generated' => true
        ];
    }

    // Fallback parsing if the format is different
    if (empty($questions)) {
        // Try alternative parsing methods
        $lines = explode("\n", $response);
        $current_question = null;
        $current_options = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            if (preg_match('/^س\d*[:.]?\s*(.+)/u', $line, $m)) {
                if ($current_question && count($current_options) >= 4) {
                    $questions[] = [
                        'question_text' => $current_question,
                        'options' => array_slice($current_options, 0, 4),
                        'correct_index' => 0, // Default to first option
                        'ai_generated' => true
                    ];
                }
                $current_question = trim($m[1]);
                $current_options = [];
            } elseif (preg_match('/^[أبجدabcd][\).\-]\s*(.+)/ui', $line, $m)) {
                $current_options[] = trim($m[1]);
            }
        }

        // Add last question
        if ($current_question && count($current_options) >= 4) {
            $questions[] = [
                'question_text' => $current_question,
                'options' => array_slice($current_options, 0, 4),
                'correct_index' => 0,
                'ai_generated' => true
            ];
        }
    }

    return $questions;
}

/**
 * Check if teacher has remaining AI generation quota
 */
function checkTeacherAILimit($teacher_id)
{
    global $pdo;

    $monthly_limit = getSetting('ai_monthly_limit', 1000);

    // Count this month's generations
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM ai_generation_logs 
        WHERE teacher_id = ? 
        AND MONTH(generated_at) = MONTH(CURRENT_DATE())
        AND YEAR(generated_at) = YEAR(CURRENT_DATE())
        AND success = 1
    ");
    $stmt->execute([$teacher_id]);
    $count = $stmt->fetchColumn();

    return $count < $monthly_limit;
}

/**
 * Get teacher's AI usage statistics
 */
function getTeacherAIUsage($teacher_id)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_generations,
            SUM(questions_generated) as total_questions,
            SUM(tokens_used) as total_tokens,
            SUM(cost_estimate) as total_cost,
            MAX(generated_at) as last_generation
        FROM ai_generation_logs 
        WHERE teacher_id = ? 
        AND MONTH(generated_at) = MONTH(CURRENT_DATE())
        AND YEAR(generated_at) = YEAR(CURRENT_DATE())
        AND success = 1
    ");
    $stmt->execute([$teacher_id]);
    $usage = $stmt->fetch();

    $usage['monthly_limit'] = getSetting('ai_monthly_limit', 1000);
    $usage['remaining'] = $usage['monthly_limit'] - $usage['total_generations'];
    $usage['percentage_used'] = ($usage['total_generations'] / $usage['monthly_limit']) * 100;

    return $usage;
}

/**
 * Log AI generation
 */
function logAIGeneration($data)
{
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO ai_generation_logs (
            teacher_id, provider, prompt_type, subject_id, grade, difficulty,
            questions_generated, tokens_used, cost_estimate,
            request_data, response_data, success, error_message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $data['teacher_id'],
        $data['provider'],
        $data['prompt_type'],
        $data['subject_id'],
        $data['grade'],
        $data['difficulty'],
        $data['questions_generated'],
        $data['tokens_used'],
        $data['cost_estimate'],
        $data['request_data'],
        $data['response_data'] ?? null,
        $data['success'],
        $data['error_message'] ?? null
    ]);
}

/**
 * Estimate AI generation cost
 */
function estimateAICost($tokens, $provider)
{
    // Rough estimates per 1000 tokens
    $rates = [
        'openai' => 0.03, // GPT-4
        'claude' => 0.025  // Claude 3
    ];

    $rate = $rates[$provider] ?? 0.03;
    return ($tokens / 1000) * $rate;
}

/**
 * Encrypt API key for storage
 */
function encryptApiKey($key)
{
    // Simple encryption - in production, use proper encryption
    $method = 'AES-256-CBC';
    $secret_key = hash('sha256', DB_PASS . 'ai_keys');
    $secret_iv = substr(hash('sha256', DB_NAME), 0, 16);

    return openssl_encrypt($key, $method, $secret_key, 0, $secret_iv);
}

/**
 * Decrypt API key
 */
function decryptApiKey($encrypted_key)
{
    $method = 'AES-256-CBC';
    $secret_key = hash('sha256', DB_PASS . 'ai_keys');
    $secret_iv = substr(hash('sha256', DB_NAME), 0, 16);

    return openssl_decrypt($encrypted_key, $method, $secret_key, 0, $secret_iv);
}

/**
 * Generate text using AI
 */
function generateReadingText($params)
{
    $prompt = "اكتب نصًا تعليميًا باللغة العربية مناسبًا للصف {$params['grade']} ";
    $prompt .= "في موضوع: {$params['topic']}\n";
    $prompt .= "يجب أن يكون النص:\n";
    $prompt .= "- بطول {$params['length']} كلمة تقريبًا\n";
    $prompt .= "- مناسب للفئة العمرية\n";
    $prompt .= "- تعليمي ومفيد\n";
    $prompt .= "- يحتوي على معلومات يمكن طرح أسئلة عنها\n";
    $prompt .= "- مكتوب بلغة عربية فصحى سليمة\n";

    $provider = getActiveAIProvider();
    if ($provider['provider'] === 'openai') {
        $result = callOpenAI($prompt, $provider['model']);
    } else {
        $result = callClaude($prompt, $provider['model']);
    }

    return [
        'text' => $result['content'],
        'tokens_used' => $result['tokens_used']
    ];
}

/**
 * Calculate reading time for Arabic text
 */
function calculateReadingTime($text)
{
    // Average reading speed for Arabic: 150-200 words per minute
    $words = str_word_count($text, 0, 'أابتثجحخدذرزسشصضطظعغفقكلمنهوي');
    $reading_speed = 175; // words per minute
    $seconds = ($words / $reading_speed) * 60;

    return max(30, round($seconds)); // Minimum 30 seconds
}
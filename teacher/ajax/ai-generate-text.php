<?php
// /teacher/ajax/ai-generate-text.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/ai_functions.php';

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Check if user is logged in and is a teacher
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check if AI is enabled
if (!getSetting('ai_enabled', true)) {
    echo json_encode(['success' => false, 'error' => 'AI service is disabled']);
    exit;
}

// Handle text generation request
if ($_POST['action'] === 'generate_text') {
    try {
        $params = [
            'topic' => sanitize($_POST['topic'] ?? ''),
            'length' => (int) ($_POST['length'] ?? 400),
            'grade' => (int) ($_POST['grade'] ?? 7)
        ];

        if (empty($params['topic'])) {
            throw new Exception('يرجى تحديد موضوع النص');
        }

        // Check teacher's AI limit
        if (!checkTeacherAILimit($_SESSION['user_id'])) {
            throw new Exception('لقد تجاوزت الحد الشهري لتوليد المحتوى');
        }

        $result = generateReadingText($params);

        // Log the generation
        logAIGeneration([
            'teacher_id' => $_SESSION['user_id'],
            'provider' => getActiveAIProvider()['provider'],
            'prompt_type' => 'custom',
            'subject_id' => null,
            'grade' => $params['grade'],
            'difficulty' => null,
            'questions_generated' => 0,
            'tokens_used' => $result['tokens_used'],
            'cost_estimate' => estimateAICost($result['tokens_used'], getActiveAIProvider()['provider']),
            'request_data' => json_encode($params),
            'response_data' => json_encode(['text_length' => strlen($result['text'])]),
            'success' => 1
        ]);

        echo json_encode([
            'success' => true,
            'text' => $result['text'],
            'reading_time' => calculateReadingTime($result['text'])
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Invalid request
echo json_encode(['success' => false, 'error' => 'Invalid request']);
exit;
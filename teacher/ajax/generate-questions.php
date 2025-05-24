<?php
// teacher/ajax/generate-questions.php
// AJAX endpoint for real-time AI question generation

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/ai_functions.php';

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Check if user is logged in and is a teacher
if (!isLoggedIn() || !hasRole('teacher')) {
    http_response_code(401);
    echo json_encode(['error' => 'غير مصرح']);
    exit;
}

// Check if AI is enabled
$ai_enabled = getSetting('ai_enabled', false);
if (!$ai_enabled) {
    echo json_encode(['error' => 'ميزة الذكاء الاصطناعي غير مفعلة']);
    exit;
}

// Verify CSRF token
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'خطأ في الحماية']);
    exit;
}

// Validate inputs
$subject_id = (int) ($_POST['subject_id'] ?? 0);
$grade = (int) ($_POST['grade'] ?? 0);
$difficulty = sanitize($_POST['difficulty'] ?? 'medium');
$question_count = (int) ($_POST['question_count'] ?? 5);
$generation_type = sanitize($_POST['generation_type'] ?? 'general');
$topic = sanitize($_POST['topic'] ?? '');
$text_content = $_POST['text_content'] ?? '';

// Validate required fields
if (empty($subject_id) || empty($grade)) {
    echo json_encode(['error' => 'يرجى ملء جميع الحقول المطلوبة']);
    exit;
}

if ($question_count < 1 || $question_count > 20) {
    echo json_encode(['error' => 'عدد الأسئلة يجب أن يكون بين 1 و 20']);
    exit;
}

if ($generation_type === 'text_based' && empty($text_content)) {
    echo json_encode(['error' => 'يرجى إدخال النص للقراءة']);
    exit;
}

try {
    // Check teacher's limit
    $usage = getTeacherAIUsage($_SESSION['user_id']);
    if ($usage['remaining'] <= 0) {
        echo json_encode([
            'error' => 'لقد تجاوزت الحد الشهري للتوليد',
            'usage' => $usage
        ]);
        exit;
    }

    // Prepare parameters
    $params = [
        'teacher_id' => $_SESSION['user_id'],
        'subject' => $subject_id,  // Important: use 'subject' not 'subject_id'
        'subject_id' => $subject_id,
        'grade' => $grade,
        'difficulty' => $difficulty,
        'count' => $question_count,
        'type' => $generation_type,
        'topic' => $topic
    ];

    if ($generation_type === 'text_based') {
        $params['text'] = $text_content;
    }

    // Generate questions
    $result = generateQuizQuestions($params);

    // Format questions for frontend
    $formatted_questions = array_map(function ($q) {
        return [
            'text' => $q['question_text'],
            'options' => $q['options'],
            'correct' => $q['correct_index'],
            'ai_generated' => true
        ];
    }, $result['questions']);

    // Get updated usage
    $updated_usage = getTeacherAIUsage($_SESSION['user_id']);

    // Return success response
    echo json_encode([
        'success' => true,
        'questions' => $formatted_questions,
        'tokens_used' => $result['tokens_used'],
        'provider' => $result['provider'],
        'model' => $result['model'],
        'usage' => $updated_usage
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'usage' => getTeacherAIUsage($_SESSION['user_id'])
    ]);
}
?>
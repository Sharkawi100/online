<?php
// teacher/ajax/generate-text.php
// AJAX endpoint for text generation

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/ai_functions.php';

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Check authentication
if (!isLoggedIn() || (!hasRole('admin') && !hasRole('teacher'))) {
    http_response_code(401);
    echo json_encode(['error' => 'غير مصرح']);
    exit;
}

// Verify CSRF
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'خطأ في الحماية']);
    exit;
}

// Get parameters
$topic = sanitize($_POST['topic'] ?? '');
$grade = (int) ($_POST['grade'] ?? 0);
$length = (int) ($_POST['length'] ?? 250);

// Validate
if (empty($topic) || empty($grade)) {
    echo json_encode(['error' => 'يرجى ملء جميع الحقول']);
    exit;
}

try {
    // Generate text using AI
    $result = generateReadingText([
        'topic' => $topic,
        'grade' => $grade,
        'length' => $length
    ]);

    echo json_encode([
        'success' => true,
        'text' => $result['text'],
        'tokens_used' => $result['tokens_used'] ?? 0
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
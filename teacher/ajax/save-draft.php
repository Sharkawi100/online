<?php
// teacher/ajax/save-draft.php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);

// Verify CSRF
if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// Save quiz draft to session
$_SESSION['quiz_draft'] = $data['quiz_data'];

echo json_encode(['success' => true]);
?>
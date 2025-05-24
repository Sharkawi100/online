<?php
// /config/constants.php
// Application constants and configuration

// Define BASE_URL dynamically
if (!defined('BASE_URL')) {
    // For production
    define('BASE_URL', '/online');

    // Alternative: Auto-detect (use for development)
    /*
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', $protocol . '://' . $host . '/online');
    */
}

// Application Version
define('APP_VERSION', '1.0.0');

// Session Configuration
define('SESSION_LIFETIME', 3600); // 1 hour
define('SESSION_NAME', 'iqdb_session');

// Upload Directories
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
define('AVATAR_DIR', UPLOAD_DIR . '/avatars');
define('QUESTION_IMG_DIR', UPLOAD_DIR . '/questions');

// Security Settings
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Quiz Settings
define('DEFAULT_QUIZ_TIME', 30); // minutes
define('DEFAULT_POINTS_PER_QUESTION', 10);
define('MAX_QUESTIONS_PER_QUIZ', 100);
define('PIN_LENGTH', 6);

// Achievement Types
define('ACHIEVEMENT_TYPES', [
    'score' => 'النقاط',
    'streak' => 'السلسلة',
    'speed' => 'السرعة',
    'perfect' => 'الكمال',
    'count' => 'العدد'
]);

// Grade Groups
define('GRADE_GROUPS', [
    'elementary' => ['name' => 'ابتدائي', 'grades' => [1, 2, 3, 4, 5, 6], 'color' => 'green'],
    'middle' => ['name' => 'متوسط', 'grades' => [7, 8, 9], 'color' => 'yellow'],
    'high' => ['name' => 'ثانوي', 'grades' => [10, 11, 12], 'color' => 'blue']
]);

// Default Settings (fallback if not in database)
define('DEFAULT_SETTINGS', [
    'site_name' => 'منصة الاختبارات التفاعلية',
    'site_description' => 'منصة تعليمية لإنشاء وإدارة الاختبارات الإلكترونية',
    'contact_email' => 'info@example.com',
    'enable_registration' => true,
    'enable_guest_quiz' => true,
    'maintenance_mode' => false
]);

// File Upload Settings
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_DOCUMENT_TYPES', ['pdf', 'doc', 'docx']);

// Pagination
define('ITEMS_PER_PAGE', 20);

// Cache Settings
define('CACHE_ENABLED', false);
define('CACHE_LIFETIME', 3600);

// Email Settings (if needed)
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 25);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('FROM_EMAIL', 'noreply@example.com');
define('FROM_NAME', 'منصة الاختبارات');

// API Rate Limiting
define('API_RATE_LIMIT', 100); // requests per hour
define('API_RATE_WINDOW', 3600); // 1 hour in seconds

// Timezone
date_default_timezone_set('Asia/Riyadh');

// Error Reporting (for development)
if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Helper function to get BASE_URL with trailing slash
function base_url($path = '')
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}
?>
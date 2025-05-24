<?php
// /config/database.php
// Simple fixed version - minimal changes from original

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'jqfujdmy_iqdb');
define('DB_USER', 'jqfujdmy_iqus');
define('DB_PASS', '^7MKW4lo275(');
define('DB_CHARSET', 'utf8mb4');

// Site constants - define only if not already defined
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'منصة الاختبارات التفاعلية');
}

if (!defined('BASE_URL')) {
    define('BASE_URL', 'https://www.iseraj.com/online');
}

// Set timezone
date_default_timezone_set('Asia/Riyadh');

// Create database connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // Don't expose database errors in production
    error_log("Database connection failed: " . $e->getMessage());
    die("حدث خطأ في الاتصال بقاعدة البيانات");
}

// Helper function to check if user is logged in
if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
}

// Helper function to check user role
if (!function_exists('hasRole')) {
    function hasRole($role)
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
}

// Helper function to redirect
if (!function_exists('redirect')) {
    function redirect($path)
    {
        header("Location: " . BASE_URL . $path);
        exit();
    }
}

// Helper function to escape output
if (!function_exists('e')) {
    function e($string)
    {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// Get settings from database
if (!function_exists('getSetting')) {
    function getSetting($key, $default = null)
    {
        global $pdo;

        try {
            $stmt = $pdo->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $setting = $stmt->fetch();

            if (!$setting) {
                return $default;
            }

            // Convert based on type
            switch ($setting['setting_type']) {
                case 'boolean':
                    return $setting['setting_value'] === 'true';
                case 'number':
                    return (int) $setting['setting_value'];
                case 'json':
                    return json_decode($setting['setting_value'], true);
                default:
                    return $setting['setting_value'];
            }
        } catch (Exception $e) {
            return $default;
        }
    }
}

// CSRF Protection functions
if (!function_exists('generateCSRF')) {
    function generateCSRF()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCSRF')) {
    function verifyCSRF($token)
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
?>
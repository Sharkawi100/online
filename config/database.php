<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'jqfujdmy_iqdb');
define('DB_USER', 'jqfujdmy_iqus');
define('DB_PASS', '^7MKW4lo275(');
define('DB_CHARSET', 'utf8mb4');

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
    die("Connection failed: " . $e->getMessage());
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone
date_default_timezone_set('Asia/Riyadh');

// Base URL
define('BASE_URL', 'https://www.iseraj.com/online');

// Helper function to check if user is logged in
function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

// Helper function to check user role
function hasRole($role)
{
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Helper function to redirect
function redirect($path)
{
    header("Location: " . BASE_URL . $path);
    exit();
}

// Helper function to escape output
function e($string)
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Get settings from database
function getSetting($key, $default = null)
{
    global $pdo;

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
}
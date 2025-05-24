<?php
/**
 * Simple Environment Variables Loader
 * /config/env.php
 * 
 * Usage: require_once 'config/env.php';
 * Access: $_ENV['DB_HOST'] or env('DB_HOST', 'default')
 */

/**
 * Load environment variables from .env file
 */
function loadEnv($path = null)
{
    if ($path === null) {
        $path = dirname(__DIR__) . '/.env';
    }

    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);

            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if (
                (substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")
            ) {
                $value = substr($value, 1, -1);
            }

            // Convert string booleans to actual booleans
            if (strtolower($value) === 'true') {
                $value = true;
            } elseif (strtolower($value) === 'false') {
                $value = false;
            }

            // Set environment variable
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }

    return true;
}

/**
 * Get environment variable with default fallback
 */
function env($key, $default = null)
{
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false) {
        return $default;
    }

    // Convert string booleans
    if (is_string($value)) {
        if (strtolower($value) === 'true')
            return true;
        if (strtolower($value) === 'false')
            return false;
        if (strtolower($value) === 'null')
            return null;
    }

    return $value;
}

// Auto-load .env file if it exists
loadEnv();

// Example usage in database.php:
/*
require_once __DIR__ . '/env.php';

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'jqfujdmy_iqdb'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
*/
?>
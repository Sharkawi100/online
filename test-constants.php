<?php
// test-constants.php - Upload to /online/ and run to test
// DELETE THIS FILE AFTER TESTING!

// Test if constants are defined
echo "<h2>Constants Test</h2>";
echo "<pre>";

// Check SITE_NAME
if (defined('SITE_NAME')) {
    echo "✓ SITE_NAME is defined: " . SITE_NAME . "\n";
} else {
    echo "✗ SITE_NAME is NOT defined\n";
    // Define it for testing
    define('SITE_NAME', 'منصة الاختبارات التفاعلية');
    echo "  → Defined SITE_NAME as: " . SITE_NAME . "\n";
}

// Check BASE_URL
if (defined('BASE_URL')) {
    echo "✓ BASE_URL is defined: " . BASE_URL . "\n";
} else {
    echo "✗ BASE_URL is NOT defined\n";
    define('BASE_URL', '/online');
    echo "  → Defined BASE_URL as: " . BASE_URL . "\n";
}

// Test database connection
try {
    require_once 'config/database.php';
    echo "\n✓ Database connection successful\n";

    // Test getSetting function
    if (function_exists('getSetting')) {
        echo "✓ getSetting function exists\n";
        $test_setting = getSetting('site_name', 'Default Name');
        echo "  → getSetting('site_name'): " . $test_setting . "\n";
    }

} catch (Exception $e) {
    echo "\n✗ Database error: " . $e->getMessage() . "\n";
}

// Show PHP info
echo "\nPHP Version: " . PHP_VERSION . "\n";
echo "Script Path: " . __FILE__ . "\n";

echo "</pre>";

// Add constants to database.php suggestion
echo "<h3>Suggested Addition to config/database.php:</h3>";
echo "<pre style='background: #f0f0f0; padding: 10px; border: 1px solid #ddd;'>";
echo htmlspecialchars("// Add this at the end of config/database.php
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'منصة الاختبارات التفاعلية');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}");
echo "</pre>";

echo "<p><strong style='color: red;'>⚠️ Remember to delete this test file after use!</strong></p>";
?>
<?php
// test-hash.php - DELETE THIS FILE AFTER TESTING
$password = '123456';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: $password<br>";
echo "Hash: $hash<br><br>";

// Test the hash
$stored_hash = '$2y$10$eImiTXuWVxfM37uY4JANjQ5RTU6M4mUGxHTw/3PKq4xfBoBypDgF2';
echo "Verify result: " . (password_verify($password, $stored_hash) ? 'SUCCESS' : 'FAILED');
?>
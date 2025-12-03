<?php
// Generate correct password hashes
$admin_password = 'admin123';
$user_password = 'user123';

echo "Admin Password Hash: " . password_hash($admin_password, PASSWORD_DEFAULT) . "\n<br>";
echo "User Password Hash: " . password_hash($user_password, PASSWORD_DEFAULT) . "\n<br>";

// Test verification
$hash = password_hash('admin123', PASSWORD_DEFAULT);
echo "\nTest: " . (password_verify('admin123', $hash) ? 'WORKING!' : 'NOT WORKING');
?>
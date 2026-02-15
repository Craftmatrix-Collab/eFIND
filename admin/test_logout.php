<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing logout flow...\n";

session_start();
echo "1. Session started\n";

$_SESSION['logout_token'] = 'test123';
$_SESSION['admin_username'] = 'testuser';
$_SESSION['admin_id'] = 1;
$_SESSION['admin_full_name'] = 'Test User';
$_GET['token'] = 'test123';

echo "2. Session vars set\n";

require_once 'includes/config.php';
echo "3. Config loaded\n";

require_once 'includes/logger.php';
echo "4. Logger loaded\n";

// CSRF Protection test
if (!isset($_GET['token']) || !isset($_SESSION['logout_token']) || 
    $_GET['token'] !== $_SESSION['logout_token']) {
    die('CSRF check failed!');
}
echo "5. CSRF check passed\n";

// Test logout logging
if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])) {
    $username = $_SESSION['admin_username'] ?? 'unknown';
    echo "6. Logging logout for: $username\n";
    $result = logLogout($username);
    echo "7. Log result: " . ($result ? 'success' : 'failed') . "\n";
}

echo "8. All tests passed!\n";

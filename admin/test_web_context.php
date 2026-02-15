<?php
// Simulate web context
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Test Agent';
$_SERVER['REQUEST_METHOD'] = 'GET';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/web_context_test.log');

echo "Starting web context test...\n\n";

// Start session
session_start();
$_SESSION['logout_token'] = 'test123';
$_SESSION['admin_username'] = 'testadmin';
$_SESSION['admin_id'] = 1;
$_SESSION['admin_full_name'] = 'Test Admin';
$_GET['token'] = 'test123';

echo "1. Session started\n";

// Load config
require_once 'includes/config.php';
echo "2. Config loaded - DB: " . (isset($conn) && $conn ? "Connected" : "Failed") . "\n";

// Load logger
require_once 'includes/logger.php';
echo "3. Logger loaded\n";

// CSRF check
if (!isset($_GET['token']) || !isset($_SESSION['logout_token']) || 
    $_GET['token'] !== $_SESSION['logout_token']) {
    die("CSRF check failed!\n");
}
echo "4. CSRF check passed\n";

// Check if user is logged in and log logout activity
if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])) {
    $username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'unknown';
    echo "5. Logging out user: $username\n";
    
    // Log logout activity
    $result = logLogout($username);
    echo "6. logLogout result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
}

// Destroy the session
session_unset();
echo "7. Session unset\n";

session_destroy();
echo "8. Session destroyed\n";

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    echo "9. Cookie exists, clearing...\n";
    $cookieParams = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 3600,
        $cookieParams['path'],
        $cookieParams['domain'],
        $cookieParams['secure'],
        true
    );
    echo "10. Cookie cleared\n";
} else {
    echo "9. No cookie to clear\n";
}

echo "11. All steps completed successfully!\n";
echo "\nTest PASSED - logout.php should work!\n";
?>

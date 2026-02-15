<?php
// Enable error logging to file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/logout_debug.log');
error_log("=== LOGOUT DEBUG START ===");

session_start();
error_log("Session started");

require_once 'includes/config.php';
error_log("Config loaded");

require_once 'includes/logger.php';
error_log("Logger loaded");

// Debug session and GET
error_log("GET token: " . ($_GET['token'] ?? 'NOT SET'));
error_log("SESSION token: " . ($_SESSION['logout_token'] ?? 'NOT SET'));
error_log("Admin ID: " . ($_SESSION['admin_id'] ?? 'NOT SET'));
error_log("User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));

// CSRF Protection: Validate logout token
if (!isset($_GET['token']) || !isset($_SESSION['logout_token']) || 
    $_GET['token'] !== $_SESSION['logout_token']) {
    error_log("CSRF check FAILED");
    die('Invalid logout request. Please use the logout button.');
}
error_log("CSRF check PASSED");

// Check if user is logged in and log logout activity
if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
    $is_admin = isset($_SESSION['admin_id']);
    
    // Get user details for logging
    $username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'unknown';
    $user_name = $_SESSION['admin_full_name'] ?? $_SESSION['full_name'] ?? 'Unknown User';
    $user_type = $is_admin ? 'admin' : ($_SESSION['role'] ?? 'user');
    
    error_log("Logging logout for: $username");
    
    // Log logout activity
    try {
        $result = logLogout($username);
        error_log("logLogout result: " . ($result ? 'SUCCESS' : 'FAILED'));
    } catch (Exception $e) {
        error_log("logLogout exception: " . $e->getMessage());
    }
}

error_log("Destroying session");
// Destroy the session
session_unset();
session_destroy();

error_log("Clearing cookie");
// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    try {
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        error_log("Cookie cleared");
    } catch (Exception $e) {
        error_log("Cookie clear exception: " . $e->getMessage());
    }
}

error_log("Redirecting to login");
// Redirect to login page
header("Location: login.php");
error_log("=== LOGOUT DEBUG END ===");
exit();
?>

<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/logger.php';

// CSRF Protection: Validate logout token
if (!isset($_GET['token']) || !isset($_SESSION['logout_token']) || 
    $_GET['token'] !== $_SESSION['logout_token']) {
    die('Invalid logout request. Please use the logout button.');
}

// Check if user is logged in and log logout activity
if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
    $is_admin = isset($_SESSION['admin_id']);
    
    // Get user details for logging
    $username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'unknown';
    $user_name = $_SESSION['admin_full_name'] ?? $_SESSION['full_name'] ?? 'Unknown User';
    $user_type = $is_admin ? 'admin' : ($_SESSION['role'] ?? 'user');
    
    // Log logout activity
    logLogout($username);
}

// Destroy the session
session_unset();
session_destroy();

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

// Redirect to login page
header("Location: login.php");
exit();
?>
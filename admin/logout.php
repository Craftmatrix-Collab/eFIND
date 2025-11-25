<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/logger.php'; // Use your existing logger

// Check if user is logged in and log logout activity
if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
    $is_admin = isset($_SESSION['admin_id']);
    
    // Get user details for logging
    $username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'unknown';
    $user_name = $_SESSION['admin_full_name'] ?? $_SESSION['full_name'] ?? 'Unknown User';
    $user_type = $is_admin ? 'admin' : ($_SESSION['role'] ?? 'user');
    
    // Update last_login field
    $table = $is_admin ? 'admin_users' : 'users';
    $query = "UPDATE $table SET last_login = NOW() WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    
    // Log logout activity using your logger function
    logLogout($username);
}

// Destroy the session
session_unset();
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user is logged in (admin or staff)
 */
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) || isset($_SESSION['staff_logged_in']);
}

/**
 * Check if the logged-in user is an admin
 */
function isAdmin() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Check if the logged-in user is a staff member
 */
function isStaff() {
    return isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;
}

/**
 * Get the current user's role
 */
function getUserRole() {
    if (isAdmin()) {
        return 'admin';
    } elseif (isStaff()) {
        return 'staff';
    }
    return 'guest';
}

/**
 * Check if the current user has a specific permission
 */
function hasPermission($permission) {
    // Define permissions for each role
    $rolePermissions = [
        'admin' => [
            'view_users',
            'add_staff',
            'edit_staff',
            'delete_staff',
            'view_activity_logs',
            'manage_settings',
        ],
        'staff' => [
            'view_dashboard',
            'view_documents',
            'upload_documents',
            'edit_profile',
        ],
    ];

    $userRole = getUserRole();
    return in_array($permission, $rolePermissions[$userRole] ?? []);
}

/**
 * Redirect unauthorized users
 */
function requirePermission($permission) {
    if (!isLoggedIn() || !hasPermission($permission)) {
        $_SESSION['error_message'] = 'You do not have permission to access this page.';
        header('Location: dashboard.php');
        exit();
    }
}

?>

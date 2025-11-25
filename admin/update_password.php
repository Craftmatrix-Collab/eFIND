<?php
session_start();
include 'includes/config.php';
include 'includes/logger.php'; // optional, safe if present

// idempotent helper for logging profile/password changes
if (!function_exists('logProfileUpdate')) {
    function logProfileUpdate($userId, $userName, $userRole, $action, $description, $conn) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        $insertSql = "INSERT INTO activity_logs (user_id, user_name, user_role, action, description, ip_address)
                      VALUES (?, ?, ?, ?, ?, ?)";
        if ($insertStmt = $conn->prepare($insertSql)) {
            $insertStmt->bind_param(
                "isssss",
                $userId,
                $userName,
                $userRole,
                $action,
                $description,
                $ip
            );
            $insertStmt->execute();
            $insertStmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: profile.php");
    exit;
}

// ensure user authenticated
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Unauthorized access. Please login first.';
    header("Location: profile.php");
    exit;
}

$userId = intval($_SESSION['admin_id'] ?? $_SESSION['user_id']);
$isAdmin = isset($_SESSION['admin_id']);
$table = $isAdmin ? 'admin_users' : 'users';
$userRole = $isAdmin ? 'admin' : 'staff';

// Get posted data (trimmed)
$current = trim($_POST['current_password'] ?? '');
$new = trim($_POST['new_password'] ?? '');
$confirm = trim($_POST['confirm_password'] ?? '');

// Basic validation with multibyte-safe length and Unicode-aware regex
if ($current === '' || $new === '' || $confirm === '') {
    $_SESSION['error'] = 'All password fields are required.';
    header("Location: profile.php");
    exit;
}
if ($new !== $confirm) {
    $_SESSION['error'] = 'New passwords do not match.';
    header("Location: profile.php");
    exit;
}
if (mb_strlen($new, 'UTF-8') < 8) {
    $_SESSION['error'] = 'Password must be at least 8 characters long.';
    header("Location: profile.php");
    exit;
}
if (!preg_match('/\p{N}/u', $new)) {
    $_SESSION['error'] = 'Password must include at least one number.';
    header("Location: profile.php");
    exit;
}
if (!preg_match('/[\p{P}\p{S}]/u', $new)) {
    $_SESSION['error'] = 'Password must include at least one special character.';
    header("Location: profile.php");
    exit;
}

// Fetch existing hash
$selectSql = "SELECT id, full_name, username, password FROM $table WHERE id = ? LIMIT 1";
if (!($selectStmt = $conn->prepare($selectSql))) {
    $_SESSION['error'] = "Database error: " . $conn->error;
    header("Location: profile.php");
    exit;
}
$selectStmt->bind_param("i", $userId);
$selectStmt->execute();
$res = $selectStmt->get_result();
$user = $res->fetch_assoc();
$selectStmt->close();

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header("Location: profile.php");
    exit;
}

// Verify current password
$hash = $user['password'] ?? '';
if (!password_verify($current, $hash)) {
    $_SESSION['error'] = "Current password is incorrect.";
    header("Location: profile.php");
    exit;
}

// Hash new password and update
$newHash = password_hash($new, PASSWORD_DEFAULT);
$updateSql = "UPDATE $table SET password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?";
if (!($updateStmt = $conn->prepare($updateSql))) {
    $_SESSION['error'] = "Database error: " . $conn->error;
    header("Location: profile.php");
    exit;
}
$updateStmt->bind_param("si", $newHash, $userId);
if ($updateStmt->execute()) {
    $updateStmt->close();

    // Prepare user display name for log
    $userName = $user['full_name'] ?? $user['username'] ?? 'Unknown';

    // Log the password change
    $description = ($isAdmin ? 'Administrator' : 'Staff') . " changed their password.";
    logProfileUpdate($userId, $userName, $userRole, 'password_change', $description, $conn);

    $_SESSION['success'] = "Password changed successfully.";
    header("Location: profile.php");
    exit;
} else {
    $updateStmt->close();
    $_SESSION['error'] = "Failed to update password: " . $conn->error;
    header("Location: profile.php");
    exit;
}
?>
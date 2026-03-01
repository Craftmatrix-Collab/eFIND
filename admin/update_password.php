<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/logger.php';

function resolvePasswordRedirectTarget($default = 'dashboard.php') {
    $candidate = trim((string)($_POST['redirect'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
    if ($candidate === '') {
        return $default;
    }

    $candidate = str_replace(["\r", "\n"], '', $candidate);
    $parts = parse_url($candidate);
    if ($parts === false) {
        return $default;
    }

    if (!empty($parts['host']) && !empty($_SERVER['HTTP_HOST']) && strcasecmp((string)$parts['host'], (string)$_SERVER['HTTP_HOST']) !== 0) {
        return $default;
    }

    $path = trim((string)($parts['path'] ?? ''));
    if ($path === '') {
        return $default;
    }

    $file = basename($path);
    if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $file)) {
        return $default;
    }

    if ($file === 'update_password.php' || $file === 'logout.php' || $file === 'login.php') {
        return $default;
    }

    $query = trim((string)($parts['query'] ?? ''));
    return $query !== '' ? ($file . '?' . $query) : $file;
}

$redirectTarget = resolvePasswordRedirectTarget();

if (!isLoggedIn()) {
    $_SESSION['error'] = 'Your session has expired. Please log in again.';
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectTarget);
    exit;
}

if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    $_SESSION['error'] = 'Invalid security token. Please refresh and try again.';
    header('Location: ' . $redirectTarget);
    exit;
}

$currentPassword = (string)($_POST['current_password'] ?? '');
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    $_SESSION['error'] = 'All password fields are required.';
    header('Location: ' . $redirectTarget);
    exit;
}

if (strlen($newPassword) < 8) {
    $_SESSION['error'] = 'Password must be at least 8 characters long.';
    header('Location: ' . $redirectTarget);
    exit;
}

if (!preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
    $_SESSION['error'] = 'Password must include at least one number and one special character.';
    header('Location: ' . $redirectTarget);
    exit;
}

if (!hash_equals($newPassword, $confirmPassword)) {
    $_SESSION['error'] = 'New passwords do not match.';
    header('Location: ' . $redirectTarget);
    exit;
}

$isAdminSession = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true && isset($_SESSION['admin_id']);
$accountType = $isAdminSession ? 'admin' : 'staff';
$table = $isAdminSession ? 'admin_users' : 'users';
$passwordColumn = $isAdminSession ? 'password_hash' : 'password';
$accountId = $isAdminSession
    ? (int)$_SESSION['admin_id']
    : (int)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);

if ($accountId <= 0) {
    $_SESSION['error'] = 'Invalid account context. Please log in again.';
    header('Location: login.php');
    exit;
}

$lookupStmt = $conn->prepare("SELECT {$passwordColumn} FROM {$table} WHERE id = ? LIMIT 1");
if (!$lookupStmt) {
    $_SESSION['error'] = 'Unable to process password update right now.';
    header('Location: ' . $redirectTarget);
    exit;
}

$lookupStmt->bind_param('i', $accountId);
$lookupStmt->execute();
$row = $lookupStmt->get_result()->fetch_assoc();
$lookupStmt->close();

if (!$row || !password_verify($currentPassword, (string)$row[$passwordColumn])) {
    $_SESSION['error'] = 'Current password is incorrect.';
    header('Location: ' . $redirectTarget);
    exit;
}

if (password_verify($newPassword, (string)$row[$passwordColumn])) {
    $_SESSION['error'] = 'New password must be different from your current password.';
    header('Location: ' . $redirectTarget);
    exit;
}

$newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
$supportsPasswordChangedAt = function_exists('ensurePasswordChangedAtColumn') && ensurePasswordChangedAtColumn($conn, $table);
$updateSql = $supportsPasswordChangedAt
    ? "UPDATE {$table} SET {$passwordColumn} = ?, password_changed_at = NOW() WHERE id = ? LIMIT 1"
    : "UPDATE {$table} SET {$passwordColumn} = ? WHERE id = ? LIMIT 1";
$updateStmt = $conn->prepare($updateSql);

if (!$updateStmt) {
    $_SESSION['error'] = 'Unable to process password update right now.';
    header('Location: ' . $redirectTarget);
    exit;
}

$updateStmt->bind_param('si', $newPasswordHash, $accountId);
$updated = $updateStmt->execute();
$updateStmt->close();

if (!$updated) {
    $_SESSION['error'] = 'Failed to update password. Please try again.';
    header('Location: ' . $redirectTarget);
    exit;
}

if (function_exists('logActivity')) {
    logActivity(
        'password_change',
        'Password changed successfully',
        "Account type: {$accountType}; Account ID: {$accountId}"
    );
}

$_SESSION['success'] = 'Password updated successfully.';
header('Location: ' . $redirectTarget);
exit;


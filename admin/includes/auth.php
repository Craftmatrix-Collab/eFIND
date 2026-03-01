<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('PRIMARY_LOGIN_SESSION_TABLE')) {
    define('PRIMARY_LOGIN_SESSION_TABLE', 'primary_login_sessions');
}

function getAuthConnection() {
    return (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
}

function ensurePrimaryLoginSessionTable(mysqli $conn) {
    static $initialized = false;

    if ($initialized) {
        return true;
    }

    $sql = "CREATE TABLE IF NOT EXISTS " . PRIMARY_LOGIN_SESSION_TABLE . " (
        account_key   VARCHAR(64)  PRIMARY KEY,
        account_type  VARCHAR(20)  NOT NULL,
        account_id    INT          NOT NULL,
        username      VARCHAR(100) NOT NULL DEFAULT '',
        session_token VARCHAR(128) NOT NULL,
        session_id    VARCHAR(128) NOT NULL DEFAULT '',
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_account_identity (account_type, account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $initialized = $conn->query($sql) === true;
    if (!$initialized) {
        error_log('Failed to ensure primary login session table: ' . $conn->error);
    }

    return $initialized;
}

function buildPrimaryAccountKey($accountType, $accountId) {
    return strtolower((string)$accountType) . ':' . (int)$accountId;
}

function resolveAccountTable($accountType) {
    $type = strtolower((string)$accountType);
    if ($type === 'admin') {
        return 'admin_users';
    }
    if ($type === 'staff') {
        return 'users';
    }
    return null;
}

function updateAccountLastLogin(mysqli $conn, $accountType, $accountId) {
    $table = resolveAccountTable($accountType);
    $accountId = (int)$accountId;

    if ($table === null || $accountId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE {$table} SET last_login = NOW() WHERE id = ? LIMIT 1");
    if (!$stmt) {
        error_log("Failed to prepare last_login update for {$table}: " . $conn->error);
        return false;
    }

    $stmt->bind_param('i', $accountId);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log("Failed to update last_login for {$table}#{$accountId}: " . $stmt->error);
    }
    $stmt->close();

    return $ok;
}

function getAccountLastActiveTimestamp(mysqli $conn, $accountType, $accountId, $fallbackLastLogin = null) {
    $accountId = (int)$accountId;
    $fallbackValue = is_string($fallbackLastLogin) ? trim($fallbackLastLogin) : '';
    if ($fallbackValue === '0000-00-00 00:00:00') {
        $fallbackValue = '';
    }

    if ($accountId <= 0 || !ensurePrimaryLoginSessionTable($conn)) {
        return $fallbackValue !== '' ? $fallbackValue : null;
    }

    $accountKey = buildPrimaryAccountKey($accountType, $accountId);
    $stmt = $conn->prepare("SELECT updated_at FROM " . PRIMARY_LOGIN_SESSION_TABLE . " WHERE account_key = ? LIMIT 1");
    if (!$stmt) {
        return $fallbackValue !== '' ? $fallbackValue : null;
    }

    $stmt->bind_param('s', $accountKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sessionUpdatedAt = trim((string)($row['updated_at'] ?? ''));
    if ($sessionUpdatedAt === '' || $sessionUpdatedAt === '0000-00-00 00:00:00') {
        return $fallbackValue !== '' ? $fallbackValue : null;
    }

    if ($fallbackValue === '') {
        return $sessionUpdatedAt;
    }

    $sessionTs = strtotime($sessionUpdatedAt);
    $fallbackTs = strtotime($fallbackValue);
    if ($sessionTs === false) {
        return $fallbackValue;
    }
    if ($fallbackTs === false || $sessionTs > $fallbackTs) {
        return $sessionUpdatedAt;
    }

    return $fallbackValue;
}

function getPrimaryAccountContext() {
    $isAdminSession = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    if ($isAdminSession && isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id'])) {
        return [
            'account_type' => 'admin',
            'account_id' => (int)$_SESSION['admin_id'],
            'username' => (string)($_SESSION['admin_username'] ?? $_SESSION['username'] ?? ''),
        ];
    }

    $isStaffSession = (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true)
        || (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);
    if ($isStaffSession) {
        $staffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? null;
        if ($staffId !== null && is_numeric($staffId)) {
            return [
                'account_type' => 'staff',
                'account_id' => (int)$staffId,
                'username' => (string)($_SESSION['staff_username'] ?? $_SESSION['username'] ?? ''),
            ];
        }
    }

    return null;
}

function clearPrimaryLoginMarkers() {
    unset($_SESSION['primary_account_key'], $_SESSION['primary_session_token']);
}

function destroyAuthSession() {
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 3600,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }
}

function registerPrimaryLoginSession(mysqli $conn, $accountType, $accountId, $username = '') {
    if ($accountId <= 0 || !ensurePrimaryLoginSessionTable($conn)) {
        return null;
    }

    $accountKey = buildPrimaryAccountKey($accountType, $accountId);
    $token = bin2hex(random_bytes(32));
    $sessionId = session_id();

    $sql = "INSERT INTO " . PRIMARY_LOGIN_SESSION_TABLE . " (account_key, account_type, account_id, username, session_token, session_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                account_type = VALUES(account_type),
                account_id = VALUES(account_id),
                username = VALUES(username),
                session_token = VALUES(session_token),
                session_id = VALUES(session_id),
                updated_at = NOW()";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Failed to prepare primary login session upsert: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('ssisss', $accountKey, $accountType, $accountId, $username, $token, $sessionId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        error_log('Failed to store primary login session: ' . $conn->error);
        return null;
    }

    $_SESSION['primary_account_key'] = $accountKey;
    $_SESSION['primary_session_token'] = $token;
    return $token;
}

function logoutPrimaryLoginSession(mysqli $conn) {
    $accountKey = (string)($_SESSION['primary_account_key'] ?? '');
    $token = (string)($_SESSION['primary_session_token'] ?? '');

    if ($accountKey === '' || $token === '' || !ensurePrimaryLoginSessionTable($conn)) {
        clearPrimaryLoginMarkers();
        return;
    }

    $stmt = $conn->prepare("DELETE FROM " . PRIMARY_LOGIN_SESSION_TABLE . " WHERE account_key = ? AND session_token = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $accountKey, $token);
        $stmt->execute();
        $stmt->close();
    }

    clearPrimaryLoginMarkers();
}

function validatePrimaryLoginSession(mysqli $conn) {
    $context = getPrimaryAccountContext();
    $sessionToken = (string)($_SESSION['primary_session_token'] ?? '');

    if (!$context || $sessionToken === '' || !ensurePrimaryLoginSessionTable($conn)) {
        destroyAuthSession();
        return false;
    }

    $accountKey = buildPrimaryAccountKey($context['account_type'], $context['account_id']);
    $_SESSION['primary_account_key'] = $accountKey;

    $stmt = $conn->prepare("SELECT session_token FROM " . PRIMARY_LOGIN_SESSION_TABLE . " WHERE account_key = ? LIMIT 1");
    if (!$stmt) {
        error_log('Failed to prepare primary login session validation: ' . $conn->error);
        destroyAuthSession();
        return false;
    }

    $stmt->bind_param('s', $accountKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !hash_equals((string)$row['session_token'], $sessionToken)) {
        destroyAuthSession();
        return false;
    }

    $touch = $conn->prepare("UPDATE " . PRIMARY_LOGIN_SESSION_TABLE . " SET session_id = ?, updated_at = NOW() WHERE account_key = ? AND session_token = ?");
    if ($touch) {
        $currentSessionId = session_id();
        $touch->bind_param('sss', $currentSessionId, $accountKey, $sessionToken);
        $touch->execute();
        $touch->close();
    }

    return true;
}

/**
 * Check if a user is logged in (admin or staff)
 */
function isLoggedIn() {
    $isAdminSession = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    $isStaffSession = (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true)
        || (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

    if (!$isAdminSession && !$isStaffSession) {
        return false;
    }

    $conn = getAuthConnection();
    if (!$conn) {
        return true;
    }

    return validatePrimaryLoginSession($conn);
}

/**
 * Check if the logged-in user is an admin
 */
function isAdmin() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Check if the logged-in user is a superadmin
 */
function isSuperAdmin() {
    $isAdminSession = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    $isStaffSession = (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true)
        || (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

    if (!$isAdminSession && !$isStaffSession) {
        return false;
    }

    $sessionRole = strtolower((string)($_SESSION['role'] ?? ($_SESSION['staff_role'] ?? '')));
    $adminUsername = strtolower((string)($_SESSION['admin_username'] ?? ''));
    $staffUsername = strtolower((string)($_SESSION['staff_username'] ?? ($_SESSION['username'] ?? '')));

    return $sessionRole === 'superadmin' || $adminUsername === 'superadmin' || $staffUsername === 'superadmin';
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

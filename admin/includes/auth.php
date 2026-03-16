<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('PRIMARY_LOGIN_SESSION_TABLE')) {
    define('PRIMARY_LOGIN_SESSION_TABLE', 'primary_login_sessions');
}

if (!defined('PRIMARY_INACTIVITY_TIMEOUT_SECONDS')) {
    define('PRIMARY_INACTIVITY_TIMEOUT_SECONDS', 1800);
}

if (!defined('PRIMARY_BROWSER_EXIT_GRACE_SECONDS')) {
    define('PRIMARY_BROWSER_EXIT_GRACE_SECONDS', PRIMARY_INACTIVITY_TIMEOUT_SECONDS);
}

if (!defined('PASSWORD_RESET_RATE_LIMIT_TABLE')) {
    define('PASSWORD_RESET_RATE_LIMIT_TABLE', 'password_reset_rate_limit_events');
}

function getAuthConnection() {
    return (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
}

function supportsBrowserExitPendingColumn(mysqli $conn): bool {
    static $supports = null;
    if ($supports !== null) {
        return $supports;
    }

    $columnCheck = $conn->query("SHOW COLUMNS FROM " . PRIMARY_LOGIN_SESSION_TABLE . " LIKE 'browser_exit_pending_at'");
    if (!$columnCheck) {
        error_log('Failed to inspect browser_exit_pending_at column: ' . $conn->error);
        $supports = false;
        return false;
    }

    if ((int)$columnCheck->num_rows > 0) {
        $supports = true;
        return true;
    }

    $supports = $conn->query(
        "ALTER TABLE " . PRIMARY_LOGIN_SESSION_TABLE . " ADD COLUMN browser_exit_pending_at DATETIME NULL AFTER session_id"
    ) === true;

    if (!$supports) {
        error_log('Failed to add browser_exit_pending_at column: ' . $conn->error);
    }

    return $supports;
}

function isBrowserExitPendingExpired($pendingAt): bool {
    $pendingValue = trim((string)$pendingAt);
    if ($pendingValue === '' || $pendingValue === '0000-00-00 00:00:00') {
        return false;
    }

    $pendingTs = strtotime($pendingValue);
    if ($pendingTs === false) {
        return false;
    }

    return (time() - $pendingTs) > PRIMARY_BROWSER_EXIT_GRACE_SECONDS;
}

function isPrimarySessionInactive($lastActiveAt): bool {
    $lastActiveValue = trim((string)$lastActiveAt);
    if ($lastActiveValue === '' || $lastActiveValue === '0000-00-00 00:00:00') {
        return false;
    }

    $lastActiveTs = strtotime($lastActiveValue);
    if ($lastActiveTs === false) {
        return false;
    }

    return (time() - $lastActiveTs) > PRIMARY_INACTIVITY_TIMEOUT_SECONDS;
}

function primaryLoginSessionHasUsersForeignKey(mysqli $conn): bool {
    $table = PRIMARY_LOGIN_SESSION_TABLE;
    $stmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = 'account_id'
           AND REFERENCED_TABLE_NAME = 'users'
         LIMIT 1"
    );
    if (!$stmt) {
        error_log('Failed to prepare primary login session FK check: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function primaryLoginSessionHasAccountIdIndex(mysqli $conn): bool {
    $table = PRIMARY_LOGIN_SESSION_TABLE;
    $stmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = 'account_id'
         LIMIT 1"
    );
    if (!$stmt) {
        error_log('Failed to prepare primary login session index check: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function ensurePrimaryLoginSessionUsersForeignKey(mysqli $conn): bool {
    static $checked = false;
    if ($checked) {
        return true;
    }

    $table = PRIMARY_LOGIN_SESSION_TABLE;
    if (!$conn->query("DELETE pls FROM {$table} pls LEFT JOIN users u ON u.id = pls.account_id WHERE u.id IS NULL")) {
        error_log('Failed to clean orphan primary login sessions: ' . $conn->error);
        return false;
    }

    if (!primaryLoginSessionHasAccountIdIndex($conn)) {
        if (!$conn->query("ALTER TABLE {$table} ADD INDEX idx_account_id (account_id)")) {
            error_log('Failed to add primary login session account index: ' . $conn->error);
            return false;
        }
    }

    if (!primaryLoginSessionHasUsersForeignKey($conn)) {
        $fkSql = "ALTER TABLE {$table}
                  ADD CONSTRAINT fk_primary_login_sessions_user
                  FOREIGN KEY (account_id) REFERENCES users(id)
                  ON UPDATE CASCADE
                  ON DELETE CASCADE";
        if (!$conn->query($fkSql)) {
            error_log('Failed to add primary login session users FK: ' . $conn->error);
            return false;
        }
    }

    $checked = true;
    return true;
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
        browser_exit_pending_at DATETIME NULL,
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_account_identity (account_type, account_id),
        INDEX idx_account_id (account_id),
        CONSTRAINT fk_primary_login_sessions_user
            FOREIGN KEY (account_id) REFERENCES users(id)
            ON UPDATE CASCADE
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $initialized = $conn->query($sql) === true;
    if (!$initialized) {
        error_log('Failed to ensure primary login session table: ' . $conn->error);
        return false;
    }

    if (!supportsBrowserExitPendingColumn($conn)) {
        error_log('Browser-exit pending logout tracking is unavailable.');
    }

    if (!ensurePrimaryLoginSessionUsersForeignKey($conn)) {
        error_log('Primary login sessions are missing users foreign key protections.');
    }

    return true;
}

function buildPrimaryAccountKey($accountType, $accountId) {
    return strtolower((string)$accountType) . ':' . (int)$accountId;
}

function resolveAccountTable($accountType) {
    $type = strtolower((string)$accountType);
    if ($type === 'admin') {
        return 'users';
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

function ensurePasswordChangedAtColumn(mysqli $conn, $table) {
    static $checkedTables = [];

    if (!in_array($table, ['users'], true)) {
        return false;
    }

    if (array_key_exists($table, $checkedTables)) {
        return $checkedTables[$table];
    }

    $columnCheck = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'password_changed_at'");
    if ($columnCheck && (int)$columnCheck->num_rows > 0) {
        $checkedTables[$table] = true;
        return true;
    }

    $addColumn = $conn->query("ALTER TABLE {$table} ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL");
    if (!$addColumn) {
        error_log("Failed to ensure password_changed_at on {$table}: " . $conn->error);
        $checkedTables[$table] = false;
        return false;
    }

    $checkedTables[$table] = true;
    return true;
}

function getPasswordResetClientIp(): string {
    $rawIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($rawIp === '' || strlen($rawIp) > 45) {
        return '0.0.0.0';
    }

    if (!preg_match('/^[0-9a-fA-F:.]+$/', $rawIp)) {
        return '0.0.0.0';
    }

    return $rawIp;
}

function normalizePasswordResetEmail(string $email): string {
    return strtolower(trim($email));
}

function ensurePasswordResetSecurityColumns(mysqli $conn, $table = 'users'): bool {
    static $checkedTables = [];
    $table = trim((string)$table);

    if (!in_array($table, ['users'], true)) {
        return false;
    }

    if (array_key_exists($table, $checkedTables)) {
        return $checkedTables[$table];
    }

    $migrations = [
        'reset_token' => "ALTER TABLE {$table} ADD COLUMN reset_token VARCHAR(255) NULL DEFAULT NULL",
        'reset_expires' => "ALTER TABLE {$table} ADD COLUMN reset_expires DATETIME NULL DEFAULT NULL",
        'reset_token_hash' => "ALTER TABLE {$table} ADD COLUMN reset_token_hash VARCHAR(255) NULL DEFAULT NULL",
        'reset_challenge_id' => "ALTER TABLE {$table} ADD COLUMN reset_challenge_id CHAR(64) NULL DEFAULT NULL",
        'reset_challenge_expires' => "ALTER TABLE {$table} ADD COLUMN reset_challenge_expires DATETIME NULL DEFAULT NULL",
        'reset_proof_hash' => "ALTER TABLE {$table} ADD COLUMN reset_proof_hash VARCHAR(255) NULL DEFAULT NULL",
        'reset_proof_expires' => "ALTER TABLE {$table} ADD COLUMN reset_proof_expires DATETIME NULL DEFAULT NULL",
        'reset_verified_at' => "ALTER TABLE {$table} ADD COLUMN reset_verified_at DATETIME NULL DEFAULT NULL",
    ];

    $allMigrationsApplied = true;
    foreach ($migrations as $columnName => $migrationSql) {
        $columnCheck = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$columnName}'");
        if (!$columnCheck) {
            error_log("Failed to inspect {$table}.{$columnName}: " . $conn->error);
            $allMigrationsApplied = false;
            continue;
        }

        if ((int)$columnCheck->num_rows > 0) {
            continue;
        }

        if (!$conn->query($migrationSql)) {
            error_log("Failed to add {$table}.{$columnName}: " . $conn->error);
            $allMigrationsApplied = false;
        }
    }

    $checkedTables[$table] = $allMigrationsApplied;
    return $allMigrationsApplied;
}

function ensurePasswordResetRateLimitTable(mysqli $conn): bool {
    static $initialized = null;
    if ($initialized !== null) {
        return $initialized;
    }

    $sql = "CREATE TABLE IF NOT EXISTS " . PASSWORD_RESET_RATE_LIMIT_TABLE . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action VARCHAR(32) NOT NULL,
        email VARCHAR(255) NOT NULL DEFAULT '',
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        challenge_id VARCHAR(64) NOT NULL DEFAULT '',
        was_successful TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_prrle_action_created (action, created_at),
        INDEX idx_prrle_action_email_created (action, email, created_at),
        INDEX idx_prrle_action_challenge_created (action, challenge_id, created_at),
        INDEX idx_prrle_action_ip_created (action, ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $initialized = $conn->query($sql) === true;
    if (!$initialized) {
        error_log('Failed to ensure password reset rate-limit table: ' . $conn->error);
    }

    return $initialized;
}

function getPasswordResetAttemptCounts(
    mysqli $conn,
    string $action,
    string $email,
    string $ipAddress,
    int $windowSeconds,
    string $challengeId = ''
): array {
    $defaultCounts = ['email' => 0, 'ip' => 0];
    if (!ensurePasswordResetRateLimitTable($conn)) {
        return $defaultCounts;
    }

    $action = substr(trim($action), 0, 32);
    if ($action === '') {
        return $defaultCounts;
    }

    $email = normalizePasswordResetEmail($email);
    $ipAddress = trim($ipAddress);
    $windowSeconds = max(1, (int)$windowSeconds);

    $hasChallenge = preg_match('/^[a-f0-9]{64}$/', $challengeId) === 1;
    if ($hasChallenge) {
        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN email = ? AND challenge_id = ? THEN 1 ELSE 0 END), 0) AS email_count,
                    COALESCE(SUM(CASE WHEN ip_address = ? THEN 1 ELSE 0 END), 0) AS ip_count
                FROM " . PASSWORD_RESET_RATE_LIMIT_TABLE . "
                WHERE action = ?
                  AND created_at >= (NOW() - INTERVAL {$windowSeconds} SECOND)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Failed to prepare challenge attempt count query: ' . $conn->error);
            return $defaultCounts;
        }

        $stmt->bind_param('ssss', $email, $challengeId, $ipAddress, $action);
    } else {
        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN email = ? THEN 1 ELSE 0 END), 0) AS email_count,
                    COALESCE(SUM(CASE WHEN ip_address = ? THEN 1 ELSE 0 END), 0) AS ip_count
                FROM " . PASSWORD_RESET_RATE_LIMIT_TABLE . "
                WHERE action = ?
                  AND created_at >= (NOW() - INTERVAL {$windowSeconds} SECOND)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Failed to prepare attempt count query: ' . $conn->error);
            return $defaultCounts;
        }

        $stmt->bind_param('sss', $email, $ipAddress, $action);
    }

    if (!$stmt->execute()) {
        error_log('Failed to execute attempt count query: ' . $stmt->error);
        $stmt->close();
        return $defaultCounts;
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'email' => (int)($row['email_count'] ?? 0),
        'ip' => (int)($row['ip_count'] ?? 0),
    ];
}

function logPasswordResetAttempt(
    mysqli $conn,
    string $action,
    string $email,
    string $ipAddress,
    bool $wasSuccessful,
    string $challengeId = ''
): void {
    if (!ensurePasswordResetRateLimitTable($conn)) {
        return;
    }

    $action = substr(trim($action), 0, 32);
    if ($action === '') {
        return;
    }

    $email = normalizePasswordResetEmail($email);
    $ipAddress = trim($ipAddress);
    if ($ipAddress === '' || strlen($ipAddress) > 45) {
        $ipAddress = '0.0.0.0';
    }

    if (preg_match('/^[a-f0-9]{64}$/', $challengeId) !== 1) {
        $challengeId = '';
    }

    $successFlag = $wasSuccessful ? 1 : 0;
    $stmt = $conn->prepare(
        "INSERT INTO " . PASSWORD_RESET_RATE_LIMIT_TABLE . " (action, email, ip_address, challenge_id, was_successful)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log('Failed to prepare reset attempt insert: ' . $conn->error);
        return;
    }

    $stmt->bind_param('ssssi', $action, $email, $ipAddress, $challengeId, $successFlag);
    if (!$stmt->execute()) {
        error_log('Failed to insert reset attempt event: ' . $stmt->error);
    }
    $stmt->close();
}

function updateAccountPasswordChangedAt(mysqli $conn, $accountType, $accountId) {
    $table = resolveAccountTable($accountType);
    $accountId = (int)$accountId;

    if ($table === null || $accountId <= 0) {
        return false;
    }

    if (!ensurePasswordChangedAtColumn($conn, $table)) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE {$table} SET password_changed_at = NOW() WHERE id = ? LIMIT 1");
    if (!$stmt) {
        error_log("Failed to prepare password_changed_at update for {$table}: " . $conn->error);
        return false;
    }

    $stmt->bind_param('i', $accountId);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log("Failed to update password_changed_at for {$table}#{$accountId}: " . $stmt->error);
    }
    $stmt->close();

    return $ok;
}

function getAccountPasswordChangedTimestamp(mysqli $conn, $accountType, $accountId, $fallbackCreatedAt = null) {
    $table = resolveAccountTable($accountType);
    $accountId = (int)$accountId;
    $fallbackValue = is_string($fallbackCreatedAt) ? trim($fallbackCreatedAt) : '';
    if ($fallbackValue === '0000-00-00 00:00:00') {
        $fallbackValue = '';
    }

    if ($table === null || $accountId <= 0 || !ensurePasswordChangedAtColumn($conn, $table)) {
        return $fallbackValue !== '' ? $fallbackValue : null;
    }

    $stmt = $conn->prepare("SELECT password_changed_at FROM {$table} WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return $fallbackValue !== '' ? $fallbackValue : null;
    }

    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $passwordChangedAt = trim((string)($row['password_changed_at'] ?? ''));
    if ($passwordChangedAt === '' || $passwordChangedAt === '0000-00-00 00:00:00') {
        return $fallbackValue !== '' ? $fallbackValue : null;
    }

    if ($fallbackValue === '') {
        return $passwordChangedAt;
    }

    $passwordChangedTs = strtotime($passwordChangedAt);
    $fallbackTs = strtotime($fallbackValue);
    if ($passwordChangedTs === false) {
        return $fallbackValue;
    }
    if ($fallbackTs === false || $passwordChangedTs >= $fallbackTs) {
        return $passwordChangedAt;
    }

    return $fallbackValue;
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

    $supportsBrowserExitPending = supportsBrowserExitPendingColumn($conn);
    if ($supportsBrowserExitPending) {
        $sql = "INSERT INTO " . PRIMARY_LOGIN_SESSION_TABLE . " (account_key, account_type, account_id, username, session_token, session_id, browser_exit_pending_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NULL, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    account_type = VALUES(account_type),
                    account_id = VALUES(account_id),
                    username = VALUES(username),
                    session_token = VALUES(session_token),
                    session_id = VALUES(session_id),
                    browser_exit_pending_at = NULL,
                    updated_at = NOW()";
    } else {
        $sql = "INSERT INTO " . PRIMARY_LOGIN_SESSION_TABLE . " (account_key, account_type, account_id, username, session_token, session_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    account_type = VALUES(account_type),
                    account_id = VALUES(account_id),
                    username = VALUES(username),
                    session_token = VALUES(session_token),
                    session_id = VALUES(session_id),
                    updated_at = NOW()";
    }
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

function markPrimaryLoginSessionPendingBrowserExit(mysqli $conn): bool {
    if (!ensurePrimaryLoginSessionTable($conn) || !supportsBrowserExitPendingColumn($conn)) {
        return false;
    }

    $accountKey = (string)($_SESSION['primary_account_key'] ?? '');
    $token = (string)($_SESSION['primary_session_token'] ?? '');
    if ($accountKey === '' || $token === '') {
        return false;
    }

    $stmt = $conn->prepare(
        "UPDATE " . PRIMARY_LOGIN_SESSION_TABLE . " SET browser_exit_pending_at = NOW() WHERE account_key = ? AND session_token = ? LIMIT 1"
    );
    if (!$stmt) {
        error_log('Failed to prepare browser-exit pending update: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('ss', $accountKey, $token);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('Failed to mark browser-exit pending logout: ' . $stmt->error);
    }
    $stmt->close();

    return $ok;
}

function invalidatePrimaryLoginSessionRecord(mysqli $conn, string $accountKey, string $sessionToken): void {
    $invalidateStmt = $conn->prepare(
        "DELETE FROM " . PRIMARY_LOGIN_SESSION_TABLE . " WHERE account_key = ? AND session_token = ? LIMIT 1"
    );
    if ($invalidateStmt) {
        $invalidateStmt->bind_param('ss', $accountKey, $sessionToken);
        $invalidateStmt->execute();
        $invalidateStmt->close();
    }
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

    $supportsBrowserExitPending = supportsBrowserExitPendingColumn($conn);
    $selectSql = $supportsBrowserExitPending
        ? "SELECT session_token, browser_exit_pending_at, updated_at FROM " . PRIMARY_LOGIN_SESSION_TABLE . " WHERE account_key = ? LIMIT 1"
        : "SELECT session_token, updated_at FROM " . PRIMARY_LOGIN_SESSION_TABLE . " WHERE account_key = ? LIMIT 1";
    $stmt = $conn->prepare($selectSql);
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

    if (isPrimarySessionInactive($row['updated_at'] ?? null)) {
        invalidatePrimaryLoginSessionRecord($conn, $accountKey, $sessionToken);
        destroyAuthSession();
        return false;
    }

    if ($supportsBrowserExitPending && isBrowserExitPendingExpired($row['browser_exit_pending_at'] ?? null)) {
        invalidatePrimaryLoginSessionRecord($conn, $accountKey, $sessionToken);
        destroyAuthSession();
        return false;
    }

    $touchSql = $supportsBrowserExitPending
        ? "UPDATE " . PRIMARY_LOGIN_SESSION_TABLE . " SET session_id = ?, browser_exit_pending_at = NULL, updated_at = NOW() WHERE account_key = ? AND session_token = ?"
        : "UPDATE " . PRIMARY_LOGIN_SESSION_TABLE . " SET session_id = ?, updated_at = NOW() WHERE account_key = ? AND session_token = ?";
    $touch = $conn->prepare($touchSql);
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
function normalizeAuthRoleToken($value): string {
    $normalized = strtolower(trim((string)$value));
    return preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';
}

function isSuperAdminRoleToken($value): bool {
    return normalizeAuthRoleToken($value) === 'superadmin';
}

function isSuperAdmin() {
    static $resolvedIsSuperAdmin = null;
    if ($resolvedIsSuperAdmin !== null) {
        return $resolvedIsSuperAdmin;
    }

    $isAdminSession = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    $isStaffSession = (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true)
        || (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

    if (!$isAdminSession && !$isStaffSession) {
        $resolvedIsSuperAdmin = false;
        return false;
    }

    $sessionRole = (string)($_SESSION['role'] ?? ($_SESSION['staff_role'] ?? ''));
    $adminUsername = strtolower(trim((string)($_SESSION['admin_username'] ?? '')));
    if (isSuperAdminRoleToken($sessionRole) || ($isAdminSession && $adminUsername === 'superadmin')) {
        $resolvedIsSuperAdmin = true;
        return true;
    }

    if ($isAdminSession) {
        $adminSessionUsername = trim((string)($_SESSION['admin_username'] ?? ''));
        $conn = getAuthConnection();
        if ($conn && $adminSessionUsername !== '') {
            $lookupRoleStmt = $conn->prepare("SELECT role FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
            if ($lookupRoleStmt) {
                $lookupRoleStmt->bind_param('s', $adminSessionUsername);
                if ($lookupRoleStmt->execute()) {
                    $lookupRoleRow = $lookupRoleStmt->get_result()->fetch_assoc();
                    $lookupRoleStmt->close();
                    if ($lookupRoleRow && isSuperAdminRoleToken($lookupRoleRow['role'] ?? '')) {
                        $resolvedIsSuperAdmin = true;
                        return true;
                    }
                } else {
                    error_log('Failed to resolve admin superadmin role mapping: ' . $lookupRoleStmt->error);
                    $lookupRoleStmt->close();
                }
            } else {
                error_log('Failed to prepare admin superadmin role mapping query: ' . $conn->error);
            }
        }
    }

    $resolvedIsSuperAdmin = false;
    return false;
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

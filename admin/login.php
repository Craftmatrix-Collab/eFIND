<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include('includes/config.php');
include('includes/auth.php');
include('includes/logger.php'); // Include the logger

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

// Safe redirect: only allow relative paths on the same host
function getSafeRedirect() {
    $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '';
    if ($redirect) {
        $decoded = urldecode($redirect);
        // Strip any CR/LF to prevent HTTP header injection
        $decoded = str_replace(["\r", "\n"], '', $decoded);
        // Only allow paths that start with exactly one "/" followed by a non-slash character.
        // This blocks: external URLs (https://...), protocol-relative URLs (//evil.com),
        // and bare filenames without a leading slash.
        if (!preg_match('#^/[^/]#', $decoded)) {
            return 'dashboard.php';
        }
        return $decoded;
    }
    return 'dashboard.php';
}

if (!defined('LOGIN_MAX_FAILED_ATTEMPTS')) {
    define('LOGIN_MAX_FAILED_ATTEMPTS', 5);
}

if (!defined('LOGIN_FAILURE_WINDOW_SECONDS')) {
    define('LOGIN_FAILURE_WINDOW_SECONDS', 900);
}

if (!defined('LOGIN_LOCK_DURATION_LEVEL_1_MINUTES')) {
    define('LOGIN_LOCK_DURATION_LEVEL_1_MINUTES', 3);
}

if (!defined('LOGIN_LOCK_DURATION_LEVEL_2_MINUTES')) {
    define('LOGIN_LOCK_DURATION_LEVEL_2_MINUTES', 3);
}

if (!defined('LOGIN_LOCK_DURATION_LEVEL_3_MINUTES')) {
    define('LOGIN_LOCK_DURATION_LEVEL_3_MINUTES', 3);
}

function getLoginLockDurationMinutes($lockLevel) {
    $level = max(1, (int)$lockLevel);
    if ($level <= 1) {
        return (int)LOGIN_LOCK_DURATION_LEVEL_1_MINUTES;
    }
    if ($level === 2) {
        return (int)LOGIN_LOCK_DURATION_LEVEL_2_MINUTES;
    }
    return (int)LOGIN_LOCK_DURATION_LEVEL_3_MINUTES;
}

function formatLoginLockRemainingTime($secondsRemaining) {
    $seconds = max(0, (int)$secondsRemaining);
    if ($seconds <= 60) {
        return 'less than a minute';
    }
    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' minute(s)';
    }
    $hours = (int)floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    if ($remainingMinutes === 0) {
        return $hours . ' hour(s)';
    }
    return $hours . ' hour(s) and ' . $remainingMinutes . ' minute(s)';
}

function buildAccountLockedErrorMessage($secondsRemaining = null, $lockoutUntil = null, $lockLevel = null) {
    $seconds = $secondsRemaining === null ? null : max(0, (int)$secondsRemaining);
    $untilText = '';
    $formattedUntil = trim((string)$lockoutUntil);
    if ($formattedUntil !== '' && strtotime($formattedUntil) !== false) {
        $untilText = ' (until ' . date('M d, Y h:i A', strtotime($formattedUntil)) . ')';
    }

    if ($seconds === null || $seconds === 0) {
        return 'Your account is temporarily locked. Please wait around 3 minutes and try again.';
    }

    $remainingText = formatLoginLockRemainingTime($seconds);
    return "Your account is temporarily locked due to repeated failed login attempts. It will automatically unlock in {$remainingText}{$untilText}.";
}

function ensureLoginAccountLockColumns() {
    global $conn;

    static $initialized = false;
    if ($initialized || !isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    foreach (['users'] as $table) {
        $failedAttemptsCol = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'failed_login_attempts'");
        if (!$failedAttemptsCol || (int)$failedAttemptsCol->num_rows === 0) {
            if (!$conn->query("ALTER TABLE {$table} ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0")) {
                error_log("Failed to add failed_login_attempts column on {$table}: " . $conn->error);
            }
        }

        $accountLockedCol = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'account_locked'");
        if (!$accountLockedCol || (int)$accountLockedCol->num_rows === 0) {
            if (!$conn->query("ALTER TABLE {$table} ADD COLUMN account_locked TINYINT(1) NOT NULL DEFAULT 0")) {
                error_log("Failed to add account_locked column on {$table}: " . $conn->error);
            }
        }

        $lockedAtCol = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'account_locked_at'");
        if (!$lockedAtCol || (int)$lockedAtCol->num_rows === 0) {
            if (!$conn->query("ALTER TABLE {$table} ADD COLUMN account_locked_at DATETIME NULL")) {
                error_log("Failed to add account_locked_at column on {$table}: " . $conn->error);
            }
        }

        $windowStartCol = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'failed_window_started_at'");
        if (!$windowStartCol || (int)$windowStartCol->num_rows === 0) {
            if (!$conn->query("ALTER TABLE {$table} ADD COLUMN failed_window_started_at DATETIME NULL")) {
                error_log("Failed to add failed_window_started_at column on {$table}: " . $conn->error);
            }
        }

        $lockLevelCol = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'lockout_level'");
        if (!$lockLevelCol || (int)$lockLevelCol->num_rows === 0) {
            if (!$conn->query("ALTER TABLE {$table} ADD COLUMN lockout_level INT NOT NULL DEFAULT 0")) {
                error_log("Failed to add lockout_level column on {$table}: " . $conn->error);
            }
        }

        $lockoutUntilCol = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'lockout_until'");
        if (!$lockoutUntilCol || (int)$lockoutUntilCol->num_rows === 0) {
            if (!$conn->query("ALTER TABLE {$table} ADD COLUMN lockout_until DATETIME NULL")) {
                error_log("Failed to add lockout_until column on {$table}: " . $conn->error);
            }
        }
    }

    $initialized = true;
}

function getLoginAccountByUsername($username) {
    global $conn;

    $username = trim((string)$username);
    if ($username === '' || !isset($conn) || !($conn instanceof mysqli)) {
        return null;
    }

    ensureLoginAccountLockColumns();

    $stmt = $conn->prepare("SELECT id, username, role, failed_login_attempts, account_locked, lockout_until, lockout_level, failed_window_started_at FROM users WHERE username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return [
                'id' => (int)($row['id'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'user_role' => (string)($row['role'] ?? 'staff'),
                'user_type' => 'users',
                'failed_login_attempts' => (int)($row['failed_login_attempts'] ?? 0),
                'account_locked' => (int)($row['account_locked'] ?? 0),
                'lockout_until' => (string)($row['lockout_until'] ?? ''),
                'lockout_level' => (int)($row['lockout_level'] ?? 0),
                'failed_window_started_at' => (string)($row['failed_window_started_at'] ?? ''),
            ];
        }
        $stmt->close();
    }

    return null;
}

function clearExpiredLoginLock($table, $userId) {
    global $conn;

    if (!in_array($table, ['users'], true) || (int)$userId <= 0 || !isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    ensureLoginAccountLockColumns();

    $stmt = $conn->prepare("UPDATE {$table} SET failed_login_attempts = 0, account_locked = 0, account_locked_at = NULL, failed_window_started_at = NULL, lockout_until = NULL WHERE id = ? LIMIT 1");
    if ($stmt) {
        $uid = (int)$userId;
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    }
}

function resetFailedLoginAttempts($table, $userId) {
    global $conn;

    if (!in_array($table, ['users'], true) || (int)$userId <= 0 || !isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    ensureLoginAccountLockColumns();

    $stmt = $conn->prepare("UPDATE {$table} SET failed_login_attempts = 0, account_locked = 0, account_locked_at = NULL, failed_window_started_at = NULL, lockout_until = NULL, lockout_level = 0 WHERE id = ? LIMIT 1");
    if ($stmt) {
        $uid = (int)$userId;
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    }
}

function getCurrentLoginLockState($table, $userId) {
    global $conn;

    $maxAttempts = (int)LOGIN_MAX_FAILED_ATTEMPTS;
    $state = [
        'is_locked' => false,
        'remaining_attempts' => $maxAttempts,
        'seconds_remaining' => 0,
        'lockout_until' => null,
        'lock_level' => 0,
    ];

    if (!in_array($table, ['users'], true) || (int)$userId <= 0 || !isset($conn) || !($conn instanceof mysqli)) {
        return $state;
    }

    ensureLoginAccountLockColumns();

    $uid = (int)$userId;
    $lookup = $conn->prepare("SELECT failed_login_attempts, account_locked, failed_window_started_at, lockout_until, lockout_level FROM {$table} WHERE id = ? LIMIT 1");
    if (!$lookup) {
        return $state;
    }
    $lookup->bind_param("i", $uid);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$row) {
        return $state;
    }

    $now = time();
    $failedAttempts = (int)($row['failed_login_attempts'] ?? 0);
    $windowStartedAt = trim((string)($row['failed_window_started_at'] ?? ''));
    $windowStartedTs = $windowStartedAt !== '' ? strtotime($windowStartedAt) : false;
    $windowIsActive = $windowStartedTs !== false
        && $windowStartedTs > 0
        && (($now - $windowStartedTs) <= (int)LOGIN_FAILURE_WINDOW_SECONDS);

    if (!$windowIsActive && (int)($row['account_locked'] ?? 0) !== 1 && $failedAttempts > 0) {
        $windowReset = $conn->prepare("UPDATE {$table} SET failed_login_attempts = 0, failed_window_started_at = NULL WHERE id = ? LIMIT 1");
        if ($windowReset) {
            $windowReset->bind_param("i", $uid);
            $windowReset->execute();
            $windowReset->close();
        }
        $failedAttempts = 0;
    }

    $lockLevel = max(0, (int)($row['lockout_level'] ?? 0));
    if ((int)($row['account_locked'] ?? 0) === 1) {
        $lockoutUntil = trim((string)($row['lockout_until'] ?? ''));
        $lockoutTs = $lockoutUntil !== '' ? strtotime($lockoutUntil) : false;

        if ($lockoutTs !== false && $lockoutTs > $now) {
            return [
                'is_locked' => true,
                'remaining_attempts' => 0,
                'seconds_remaining' => max(0, $lockoutTs - $now),
                'lockout_until' => $lockoutUntil,
                'lock_level' => max(1, $lockLevel),
            ];
        }

        if ($lockoutTs === false) {
            // Legacy rows may have account_locked=1 with no lockout_until value.
            // Treat these as stale lock states to avoid permanent lockout.
            error_log("Detected stale lock state without lockout_until for {$table}#{$uid}; resetting lock.");
            resetFailedLoginAttempts($table, $uid);
            $failedAttempts = 0;
            $lockLevel = 0;
        } elseif ($lockoutTs <= $now) {
            clearExpiredLoginLock($table, $uid);
            $failedAttempts = 0;
        } else {
            return [
                'is_locked' => true,
                'remaining_attempts' => 0,
                'seconds_remaining' => 0,
                'lockout_until' => $lockoutUntil !== '' ? $lockoutUntil : null,
                'lock_level' => max(1, $lockLevel),
            ];
        }
    }

    $state['remaining_attempts'] = max(0, $maxAttempts - $failedAttempts);
    $state['lock_level'] = $lockLevel;
    return $state;
}

function registerFailedLoginAttempt($table, $userId) {
    global $conn;

    $maxAttempts = (int)LOGIN_MAX_FAILED_ATTEMPTS;
    $state = [
        'is_locked' => false,
        'remaining_attempts' => $maxAttempts,
        'seconds_remaining' => 0,
        'lockout_until' => null,
        'lock_level' => 0,
    ];

    if (!in_array($table, ['users'], true) || (int)$userId <= 0 || !isset($conn) || !($conn instanceof mysqli)) {
        return $state;
    }

    ensureLoginAccountLockColumns();

    $uid = (int)$userId;
    $activeLock = getCurrentLoginLockState($table, $uid);
    if (!empty($activeLock['is_locked'])) {
        return $activeLock;
    }

    $lookup = $conn->prepare("SELECT failed_login_attempts, failed_window_started_at, lockout_level FROM {$table} WHERE id = ? LIMIT 1");
    if (!$lookup) {
        return $state;
    }

    $lookup->bind_param("i", $uid);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$row) {
        return $state;
    }

    $now = time();
    $windowStartedAt = trim((string)($row['failed_window_started_at'] ?? ''));
    $windowStartedTs = $windowStartedAt !== '' ? strtotime($windowStartedAt) : false;
    $windowIsActive = $windowStartedTs !== false
        && $windowStartedTs > 0
        && (($now - $windowStartedTs) <= (int)LOGIN_FAILURE_WINDOW_SECONDS);

    $currentAttempts = $windowIsActive ? (int)($row['failed_login_attempts'] ?? 0) : 0;
    $windowStartForStore = $windowIsActive
        ? date('Y-m-d H:i:s', $windowStartedTs)
        : date('Y-m-d H:i:s', $now);

    $newAttempts = $currentAttempts + 1;
    $shouldLock = $newAttempts >= $maxAttempts;
    $currentLockLevel = max(0, (int)($row['lockout_level'] ?? 0));

    if ($shouldLock) {
        $newLockLevel = min(3, max(1, $currentLockLevel + 1));
        $lockMinutes = getLoginLockDurationMinutes($newLockLevel);
        $secondsRemaining = max(60, $lockMinutes * 60);
        $lockoutUntil = date('Y-m-d H:i:s', $now + $secondsRemaining);

        $update = $conn->prepare("UPDATE {$table} SET failed_login_attempts = ?, account_locked = 1, account_locked_at = NOW(), failed_window_started_at = NOW(), lockout_level = ?, lockout_until = ? WHERE id = ? LIMIT 1");
        if ($update) {
            $update->bind_param("iisi", $newAttempts, $newLockLevel, $lockoutUntil, $uid);
            $update->execute();
            $update->close();
        }

        return [
            'is_locked' => true,
            'remaining_attempts' => 0,
            'seconds_remaining' => $secondsRemaining,
            'lockout_until' => $lockoutUntil,
            'lock_level' => $newLockLevel,
        ];
    }

    $update = $conn->prepare("UPDATE {$table} SET failed_login_attempts = ?, account_locked = 0, account_locked_at = NULL, failed_window_started_at = ?, lockout_until = NULL WHERE id = ? LIMIT 1");
    if ($update) {
        $update->bind_param("isi", $newAttempts, $windowStartForStore, $uid);
        $update->execute();
        $update->close();
    }

    return [
        'is_locked' => false,
        'remaining_attempts' => max(0, $maxAttempts - $newAttempts),
        'seconds_remaining' => 0,
        'lockout_until' => null,
        'lock_level' => $currentLockLevel,
    ];
}

function buildWrongPasswordErrorMessage($remainingAttempts) {
    $remaining = max(0, (int)$remainingAttempts);
    if ($remaining <= 0) {
        return buildAccountLockedErrorMessage();
    }

    $windowMinutes = max(1, (int)floor((int)LOGIN_FAILURE_WINDOW_SECONDS / 60));
    $attemptWord = $remaining === 1 ? 'attempt' : 'attempts';
    return "Invalid username or password. You have {$remaining} {$attemptWord} remaining within {$windowMinutes} minutes before your account is temporarily locked.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize inputs
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];

    // Basic validation
    if (empty($username) || empty($password)) {
        $error = "Both username and password are required.";
        logLoginAttempt($username, $user_ip, 'FAILED', 'Empty credentials');
        logLoginActivity(null, 'failed_login', 'Login attempt with empty credentials', 'system', $user_ip, "Username: $username");
    } else {
        ensureLoginAccountLockColumns();
        $accountState = getLoginAccountByUsername($username);
        $accountLockState = null;
        if ($accountState) {
            $accountLockState = getCurrentLoginLockState((string)$accountState['user_type'], (int)$accountState['id']);
        }

        if ($accountState && !empty($accountLockState['is_locked'])) {
            $error = buildAccountLockedErrorMessage(
                $accountLockState['seconds_remaining'] ?? null,
                $accountLockState['lockout_until'] ?? null,
                $accountLockState['lock_level'] ?? null
            );
            $lockDetails = 'Account locked';
            if (!empty($accountLockState['seconds_remaining'])) {
                $lockDetails .= ' (' . formatLoginLockRemainingTime((int)$accountLockState['seconds_remaining']) . ' remaining)';
            }
            logLoginAttempt($username, $user_ip, 'FAILED', $lockDetails, $accountState['id'], $accountState['user_role']);
            logLoginActivity($accountState['id'], 'failed_login', 'Login blocked due to locked account', 'system', $user_ip, "Username: $username", $accountState['username'], $accountState['user_role']);
        } else {
            $query = "SELECT id, username, password, full_name, profile_picture, role, COALESCE(email_verified, 1) AS email_verified, failed_login_attempts, account_locked FROM users WHERE username = ?";
            $stmt = $conn->prepare($query);

            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    $accountRole = strtolower(trim((string)($user['role'] ?? 'staff')));
                    if ($accountRole === 'administrator') {
                        $accountRole = 'admin';
                    }
                    if ($accountRole === '') {
                        $accountRole = 'staff';
                    }
                    $isAdminRole = in_array($accountRole, ['admin', 'superadmin'], true);
                    $loginAccountType = $isAdminRole ? 'admin' : 'staff';
                    $lockState = getCurrentLoginLockState('users', (int)$user['id']);

                    if (!empty($lockState['is_locked'])) {
                        $error = buildAccountLockedErrorMessage(
                            $lockState['seconds_remaining'] ?? null,
                            $lockState['lockout_until'] ?? null,
                            $lockState['lock_level'] ?? null
                        );
                        $lockDetails = 'Account locked';
                        if (!empty($lockState['seconds_remaining'])) {
                            $lockDetails .= ' (' . formatLoginLockRemainingTime((int)$lockState['seconds_remaining']) . ' remaining)';
                        }
                        logLoginAttempt($username, $user_ip, 'FAILED', $lockDetails, $user['id'], $accountRole);
                        logLoginActivity($user['id'], 'failed_login', 'Login blocked due to locked account', 'system', $user_ip, "Username: $username", $user['username'], $accountRole);
                    } elseif (password_verify($password, $user['password'])) {
                        resetFailedLoginAttempts('users', (int)$user['id']);

                        if ($isAdminRole && empty($user['email_verified'])) {
                            $error = 'Your email address is not verified. Please check your inbox for the verification link, or <a href="resend-verification.php">resend it</a>.';
                            logLoginAttempt($username, $user_ip, 'FAILED', 'Email not verified', $user['id'], $accountRole);
                            logLoginActivity($user['id'], 'failed_login', 'Email not verified', 'system', $user_ip, "Username: $username", $user['username'], $accountRole);
                        } else {
                            updateAccountLastLogin($conn, $loginAccountType, (int)$user['id']);
                            session_regenerate_id(true);

                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['full_name'] = $user['full_name'];
                            $_SESSION['profile_picture'] = $user['profile_picture'];
                            $_SESSION['role'] = $accountRole;
                            $_SESSION['logged_in'] = true;

                            if ($isAdminRole) {
                                $_SESSION['admin_id'] = $user['id'];
                                $_SESSION['admin_username'] = $user['username'];
                                $_SESSION['admin_full_name'] = $user['full_name'];
                                $_SESSION['admin_profile_picture'] = $user['profile_picture'];
                                $_SESSION['admin_logged_in'] = true;

                                unset(
                                    $_SESSION['staff_id'],
                                    $_SESSION['staff_username'],
                                    $_SESSION['staff_full_name'],
                                    $_SESSION['staff_profile_picture'],
                                    $_SESSION['staff_role'],
                                    $_SESSION['staff_logged_in']
                                );
                            } else {
                                $_SESSION['staff_id'] = $user['id'];
                                $_SESSION['staff_username'] = $user['username'];
                                $_SESSION['staff_full_name'] = $user['full_name'];
                                $_SESSION['staff_profile_picture'] = $user['profile_picture'];
                                $_SESSION['staff_role'] = $accountRole;
                                $_SESSION['staff_logged_in'] = true;

                                unset(
                                    $_SESSION['admin_id'],
                                    $_SESSION['admin_username'],
                                    $_SESSION['admin_full_name'],
                                    $_SESSION['admin_profile_picture'],
                                    $_SESSION['admin_logged_in']
                                );
                            }

                            $primaryToken = registerPrimaryLoginSession($conn, $loginAccountType, (int)$user['id'], (string)$user['username']);
                            if ($primaryToken === null) {
                                $error = "Unable to start secure session. Please try again.";
                                logLoginAttempt($username, $user_ip, 'FAILED', 'Primary session initialization failed', $user['id'], $accountRole);
                                logLoginActivity($user['id'], 'failed_login', 'Primary session initialization failed', 'system', $user_ip, "Username: $username", $user['username'], $accountRole);
                                session_unset();
                                session_destroy();
                            } else {
                                $successLabel = $isAdminRole ? 'Admin login' : 'Staff login';
                                $activityLabel = $isAdminRole ? 'Admin user logged in successfully' : 'User logged in successfully';
                                logLoginAttempt($username, $user_ip, 'SUCCESS', $successLabel, $user['id'], $accountRole);
                                logLoginActivity($user['id'], 'login', $activityLabel, 'system', $user_ip, "User: {$user['full_name']}, Role: {$accountRole}", $user['username'], $accountRole);

                                $_SESSION['show_login_welcome_modal'] = true;
                                header("Location: " . getSafeRedirect());
                                exit();
                            }
                        }
                    } else {
                        $attemptState = registerFailedLoginAttempt('users', (int)$user['id']);
                        $details = !empty($attemptState['is_locked']) ? 'Invalid password - account locked' : 'Invalid password';
                        if (!empty($attemptState['is_locked']) && !empty($attemptState['seconds_remaining'])) {
                            $details .= ' (' . formatLoginLockRemainingTime((int)$attemptState['seconds_remaining']) . ')';
                        }
                        logLoginAttempt($username, $user_ip, 'FAILED', $details, $user['id'], $accountRole);
                        logLoginActivity($user['id'], 'failed_login', 'Invalid password', 'system', $user_ip, "Username: $username", $user['username'], $accountRole);
                        $error = !empty($attemptState['is_locked'])
                            ? buildAccountLockedErrorMessage(
                                $attemptState['seconds_remaining'] ?? null,
                                $attemptState['lockout_until'] ?? null,
                                $attemptState['lock_level'] ?? null
                            )
                            : buildWrongPasswordErrorMessage($attemptState['remaining_attempts']);
                    }
                } else {
                    logLoginAttempt($username, $user_ip, 'FAILED', 'Username not found');
                    logLoginActivity(null, 'failed_login', 'Username not found', 'system', $user_ip, "Username: $username");
                    $error = "Invalid username or password.";
                }

                $stmt->close();
            } else {
                $error = "Database error. Please try again later.";
                logLoginAttempt($username, $user_ip, 'FAILED', 'Database error');
                logLoginActivity(null, 'failed_login', 'Database error during login', 'system', $user_ip, "Username: $username");
            }
        }
    }
    
    // $conn->close();
}

/**
 * Log login attempts
 */
function logLoginAttempt($username, $ip_address, $status, $details = '', $user_id = null, $user_role = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("INSERT INTO login_logs (username, ip_address, user_agent, login_time, status, details, user_id, user_role) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("sssssis", $username, $ip_address, $_SERVER['HTTP_USER_AGENT'], $status, $details, $user_id, $user_role);
            $stmt->execute();
            $stmt->close();
        }
        
        // Also log to file using your existing logger
        $log_message = sprintf(
            "LOGIN_ATTEMPT: User: %s, IP: %s, Status: %s, Details: %s, Role: %s",
            $username,
            $ip_address,
            $status,
            $details,
            $user_role ?? 'unknown'
        );
        
        error_log($log_message, 3, __DIR__ . '/logs/login_attempts.log');
        
    } catch (Exception $e) {
        // Fallback to file logging if database logging fails
        error_log("Login log error: " . $e->getMessage());
    }
}

/**
 * Log activity to activity_logs table
 */
function logLoginActivity($user_id, $action, $description, $document_type = 'system', $ip_address = null, $details = null, $known_username = null, $user_role = null) {
    global $conn;
    
    try {
        // Use the provided username directly when available.
        $user_name = $known_username;

        // Only do a DB lookup if username was not passed in
        if (!$user_name && $user_id) {
            $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
            if ($user_stmt) {
                $user_stmt->bind_param("i", $user_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_result->num_rows > 0) {
                    $user_name = $user_result->fetch_assoc()['username'];
                }
                $user_stmt->close();
            }
        }
        
        $ip_address = $ip_address ?: $_SERVER['REMOTE_ADDR'];
        
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_name, user_role, action, description, document_type, details, ip_address, log_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if ($stmt) {
            $stmt->bind_param("isssssss", $user_id, $user_name, $user_role, $action, $description, $document_type, $details, $ip_address);
            $stmt->execute();
            $stmt->close();
        }
        
    } catch (Exception $e) {
        // Fallback to file logging if database logging fails
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * Check if login logs table exists, create if not
 */
function checkLoginLogsTable() {
    global $conn;
    
    $checkTableQuery = "SHOW TABLES LIKE 'login_logs'";
    $tableResult = $conn->query($checkTableQuery);
    
    if ($tableResult->num_rows == 0) {
        $createTableQuery = "CREATE TABLE login_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            login_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('SUCCESS', 'FAILED') NOT NULL,
            details TEXT,
            user_id INT NULL,
            user_role VARCHAR(50) NULL,
            INDEX idx_username (username),
            INDEX idx_login_time (login_time),
            INDEX idx_status (status),
            INDEX idx_ip (ip_address)
        )";
        
        if (!$conn->query($createTableQuery)) {
            error_log("Failed to create login_logs table: " . $conn->error);
        }
    }
}

/**
 * Check if activity logs table exists, create if not
 */
function checkActivityLogsTable() {
    global $conn;
    
    $checkTableQuery = "SHOW TABLES LIKE 'activity_logs'";
    $tableResult = $conn->query($checkTableQuery);
    
    if ($tableResult->num_rows == 0) {
        $createTableQuery = "CREATE TABLE activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            user_name VARCHAR(255) NULL,
            user_role VARCHAR(50) NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT NULL,
            document_type VARCHAR(100) NULL,
            document_id INT NULL,
            details TEXT NULL,
            file_path VARCHAR(500) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            log_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_log_time (log_time),
            INDEX idx_document_type (document_type),
            INDEX idx_ip_address (ip_address)
        )";
        
        if (!$conn->query($createTableQuery)) {
            error_log("Failed to create activity_logs table: " . $conn->error);
        }
    }
}

// Check and create necessary tables if needed
checkLoginLogsTable();
checkActivityLogsTable();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - eFIND System</title>
    <link rel="icon" type="image/png" href="images/eFind_logo.png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-blue: #4361ee;
            --secondary-blue: #3a0ca3;
            --light-blue: #e8f0fe;
            --accent-orange: #ff6d00;
            --dark-gray: #2b2d42;
            --medium-gray: #8d99ae;
            --light-gray: #f8f9fa;
            --white: #ffffff;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        /* MOBILE FIRST - Base styles for mobile devices */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: radial-gradient(circle at 20% 20%, #edf2ff 0%, #e4e8f0 45%, #dbe3f7 100%);
            color: var(--dark-gray);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 10px;
            overflow-x: hidden;
            font-size: 16px; /* Prevents zoom on iOS */
        }

        .login-wrapper {
            display: flex;
            flex-direction: column;
            background-color: rgba(255, 255, 255, 0.92);
            border-radius: 16px;
            border: 1px solid rgba(67, 97, 238, 0.12);
            box-shadow: 0 16px 40px rgba(24, 34, 79, 0.18);
            backdrop-filter: blur(6px);
            overflow: hidden;
            width: 100%;
            max-width: 100%;
            position: relative;
            z-index: 1;
            transition: transform 0.3s ease;
        }

        .login-wrapper:active {
            transform: scale(0.98);
        }

        .logo-section {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            padding: 20px 15px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: var(--white);
            text-align: center;
            position: relative;
            overflow: hidden;
            min-height: 180px;
        }

        .logo-caption {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-top: 10px;
            margin-bottom: 0;
            z-index: 2;
            max-width: 260px;
            line-height: 1.35;
        }

        .logo-section::before {
            content: "";
            position: absolute;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            top: -80px;
            right: -80px;
        }

        .logo-section::after {
            content: "";
            position: absolute;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            bottom: -40px;
            left: -40px;
        }

        .logo-section img {
            max-width: 100%;
            width: 140px;
            height: auto;
            margin-bottom: 12px;
            z-index: 2;
            transition: transform 0.3s ease;
        }

        .logo-section:active img {
            transform: scale(0.95);
        }

        .logo-section h1 {
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            margin-bottom: 0;
            font-size: 1.3rem;
            z-index: 2;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            line-height: 1.3;
        }

        .login-section {
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
        }

        .branding {
            margin-bottom: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            width: 100%;
        }

        .efind-logo {
            height: 80px;
            width: 80px;
            margin-bottom: 15px;
            object-fit: contain;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
            transition: all 0.3s ease;
        }

        .efind-logo:active {
            transform: scale(0.95);
        }

        .brand-name {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            color: var(--secondary-blue);
            font-size: 1.8rem;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
            position: relative;
            display: inline-block;
        }

        .brand-name::after {
            content: "";
            position: absolute;
            bottom: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background: var(--accent-orange);
            border-radius: 2px;
        }

        .brand-subtitle {
            color: var(--medium-gray);
            font-size: 0.9rem;
            font-style: italic;
            margin-top: 12px;
            padding: 0 10px;
            line-height: 1.4;
        }

        .login-form {
            width: 100%;
            max-width: 100%;
            position: relative;
            padding: 16px 14px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 14px;
            border: 1px solid rgba(67, 97, 238, 0.14);
            box-shadow: 0 10px 24px rgba(43, 45, 66, 0.08);
        }

        .login-form::before {
            display: none; /* Hide decorative border on mobile */
        }

        .form-helper-text {
            color: #6f7aa5;
            font-size: 0.82rem;
            margin-bottom: 16px;
            line-height: 1.45;
        }

        .form-control {
            height: 48px;
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 16px;
            font-size: 16px; /* Prevents iOS zoom */
            transition: all 0.3s;
            background-color: var(--light-gray);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
            width: 100%;
            -webkit-appearance: none; /* Remove iOS styling */
        }

        .input-control {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7b84ad;
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s ease;
            z-index: 2;
        }

        .input-control .form-control {
            padding-left: 42px;
        }

        .input-control.password-input .form-control {
            padding-right: 52px;
        }

        .input-control:focus-within .input-icon {
            color: var(--primary-blue);
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            background-color: var(--white);
            outline: none;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-gray);
            font-size: 0.9rem;
            text-align: left;
            display: block;
            position: relative;
            padding-left: 12px;
        }

        .form-label::before {
            content: "";
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 5px;
            height: 5px;
            background: var(--primary-blue);
            border-radius: 50%;
        }

        .btn-login {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            transition: all 0.3s;
            margin-top: 10px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
            letter-spacing: 0.5px;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
            min-height: 48px; /* Touch-friendly size */
        }

        .btn-login:active {
            transform: scale(0.98);
            box-shadow: 0 2px 10px rgba(67, 97, 238, 0.3);
        }

        .btn-login::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }

        .btn-login:active::before {
            left: 100%;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--medium-gray);
            transition: all 0.2s;
            background: rgba(0, 0, 0, 0.05);
            border: none;
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            -webkit-tap-highlight-color: transparent;
            z-index: 3;
        }

        .password-toggle-icon:active {
            color: var(--primary-blue);
            background: rgba(67, 97, 238, 0.15);
            transform: translateY(-50%) scale(0.95);
        }

        .caps-lock-warning {
            display: none;
            text-align: left;
            font-size: 0.78rem;
            margin-top: 6px;
            color: #b45309;
        }

        .forgot-password {
            text-align: center;
            margin-top: 8px;
            margin-bottom: 15px;
        }

        .forgot-password a {
            color: var(--medium-gray);
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.2s;
            position: relative;
            padding: 8px;
            display: inline-block;
            -webkit-tap-highlight-color: transparent;
        }

        .forgot-password a:active {
            color: var(--primary-blue);
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            color: var(--medium-gray);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .register-link a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            position: relative;
            padding: 8px;
            display: inline-block;
            -webkit-tap-highlight-color: transparent;
        }

        .register-link a:active {
            color: var(--secondary-blue);
        }

        .alert {
            margin-bottom: 15px;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 0.85rem;
            text-align: left;
            border-left: 4px solid #dc3545;
            background-color: rgba(220, 53, 69, 0.1);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            word-wrap: break-word;
        }

        .security-notice {
            background-color: rgba(67, 97, 238, 0.1);
            border-left: 4px solid var(--primary-blue);
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 15px;
            font-size: 0.8rem;
            text-align: left;
            line-height: 1.4;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .security-notice i {
            color: var(--primary-blue);
            margin-right: 0;
            margin-top: 1px;
        }

        .floating-shapes {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
            pointer-events: none;
        }

        .shape {
            position: absolute;
            opacity: 0.08;
        }

        .shape-1 {
            width: 80px;
            height: 80px;
            background: var(--primary-blue);
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            top: 5%;
            left: 5%;
            animation: float 15s infinite ease-in-out;
        }

        .shape-2 {
            width: 60px;
            height: 60px;
            background: var(--accent-orange);
            border-radius: 50%;
            bottom: 10%;
            right: 5%;
            animation: float 12s infinite ease-in-out reverse;
        }

        .shape-3 {
            width: 90px;
            height: 90px;
            background: var(--secondary-blue);
            border-radius: 50% 20% 50% 30%;
            top: 40%;
            right: 10%;
            animation: float 18s infinite ease-in-out;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-15px) rotate(3deg); }
        }

        /* TABLET BREAKPOINT - 768px and up */
        @media (min-width: 768px) {
            body {
                padding: 20px;
            }

            .login-wrapper {
                flex-direction: row;
                border-radius: 24px;
                max-width: 1000px;
                min-height: 600px;
            }

            .login-wrapper:hover {
                transform: translateY(-5px);
            }

            .logo-section {
                flex: 1;
                padding: 40px 30px;
                min-height: auto;
            }

            .logo-section img {
                width: 180px;
                margin-bottom: 20px;
            }

            .logo-section:hover img {
                transform: scale(1.05);
            }

            .logo-section h1 {
                font-size: 2rem;
            }

            .login-section {
                flex: 1;
                padding: 50px 35px;
            }

            .branding {
                margin-bottom: 35px;
            }

            .efind-logo {
                height: 100px;
                width: 100px;
                margin-bottom: 18px;
            }

            .efind-logo:hover {
                transform: rotate(5deg) scale(1.1);
            }

            .brand-name {
                font-size: 2.3rem;
            }

            .brand-name::after {
                width: 60px;
                height: 4px;
            }

            .brand-name:hover::after {
                width: 100px;
            }

            .brand-subtitle {
                font-size: 1rem;
            }

            .login-form {
                max-width: 400px;
                padding: 20px 18px;
                border-radius: 18px;
            }

            .login-form::before {
                display: block;
                content: "";
                position: absolute;
                top: -10px;
                left: -10px;
                right: -10px;
                bottom: -10px;
                border: 2px dashed rgba(67, 97, 238, 0.2);
                border-radius: 16px;
                z-index: -1;
                pointer-events: none;
                animation: rotate 60s linear infinite;
            }

            @keyframes rotate {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .form-control {
                height: 50px;
                border-radius: 12px;
            }

            .btn-login {
                font-size: 1.1rem;
                border-radius: 12px;
            }

            .btn-login:hover {
                transform: translateY(-3px);
                box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
            }

            .btn-login:hover::before {
                left: 100%;
            }

            .password-toggle-icon {
                width: 32px;
                height: 32px;
            }

            .password-toggle-icon:hover {
                color: var(--primary-blue);
                background: rgba(67, 97, 238, 0.1);
            }

            .forgot-password a {
                font-size: 0.9rem;
            }

            .forgot-password a::after {
                content: "";
                position: absolute;
                bottom: 0;
                left: 8px;
                width: 0;
                height: 1px;
                background: var(--primary-blue);
                transition: width 0.3s;
            }

            .forgot-password a:hover::after {
                width: calc(100% - 16px);
            }

            .register-link {
                font-size: 0.95rem;
                margin-top: 25px;
            }

            .register-link a::after {
                content: "";
                position: absolute;
                bottom: 0;
                left: 8px;
                width: 0;
                height: 2px;
                background: var(--accent-orange);
                transition: width 0.3s;
            }

            .register-link a:hover::after {
                width: calc(100% - 16px);
            }

            .shape-1 {
                width: 100px;
                height: 100px;
                top: 10%;
                left: 10%;
            }

            .shape-2 {
                width: 80px;
                height: 80px;
                bottom: 15%;
                right: 10%;
            }

            .shape-3 {
                width: 120px;
                height: 120px;
                top: 50%;
                right: 20%;
            }

            .logo-caption {
                font-size: 0.92rem;
                max-width: 300px;
            }

            @keyframes float {
                0%, 100% { transform: translateY(0) rotate(0deg); }
                50% { transform: translateY(-20px) rotate(5deg); }
            }
        }

        /* DESKTOP BREAKPOINT - 1024px and up */
        @media (min-width: 1024px) {
            .login-wrapper {
                max-width: 1100px;
                min-height: 650px;
            }

            .logo-section {
                padding: 40px;
            }

            .logo-section img {
                width: 200px;
                margin-bottom: 30px;
            }

            .logo-section h1 {
                font-size: 2.5rem;
            }

            .login-section {
                padding: 60px 40px;
            }

            .branding {
                margin-bottom: 40px;
            }

            .efind-logo {
                height: 120px;
                width: 120px;
                margin-bottom: 20px;
            }

            .brand-name {
                font-size: 2.8rem;
                letter-spacing: 1px;
            }

            .brand-subtitle {
                font-size: 1.1rem;
            }

            .alert {
                font-size: 0.95rem;
                padding: 12px 16px;
            }

            .security-notice {
                font-size: 0.85rem;
                padding: 10px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <div class="login-wrapper">
        <div class="logo-section">
            <img src="images/eFind_logo5.png" alt="Poblacion South Logo">
            <h1>Welcome to eFIND System</h1>
            <p class="logo-caption">Secure access portal for barangay document intelligence and records management.</p>
        </div>
        
        <div class="login-section">
            <div class="branding">
                <img src="images/logo_pbsth.png" alt="eFIND Logo" class="efind-logo">
                <div class="brand-name">Login Portal</div>
                <p class="brand-subtitle">Please enter your credentials to access the dashboard</p>
            </div>

            <div class="login-form">
                <?php if (isset($_SESSION['password_reset_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        Password reset successful! Please login with your new password.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['password_reset_success']); ?>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="security-notice">
                    <i class="fas fa-shield-alt"></i>
                    All login attempts are logged. After <?php echo (int)LOGIN_MAX_FAILED_ATTEMPTS; ?> wrong passwords within <?php echo max(1, (int)floor((int)LOGIN_FAILURE_WINDOW_SECONDS / 60)); ?> minutes, your account is temporarily locked with longer lock durations for repeated lockouts. Use Forgot Password to unlock immediately, or wait for lock expiry.
                </div>

                <form method="post" action="login.php">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect'] ?? $_POST['redirect'] ?? ''); ?>">
                    <p class="form-helper-text">Sign in with your official account credentials to continue.</p>
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-control">
                            <span class="input-icon"><i class="fas fa-user"></i></span>
                            <input type="text" class="form-control" id="username" name="username" autocomplete="username" required
                                   placeholder="Enter your username"
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>
                    </div>

                    <div class="mb-3 password-toggle">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-control password-input">
                            <span class="input-icon"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required placeholder="Enter your password">
                            <button type="button" class="password-toggle-icon" id="togglePassword" aria-label="Show password" aria-pressed="false">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="caps-lock-warning" id="capsLockWarning">
                            <i class="fas fa-exclamation-triangle me-1"></i>Caps Lock is ON.
                        </div>
                    </div>

                    <div class="forgot-password">
                        <a href="forgot-password.php">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn btn-login" name="login">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </button>

                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Password toggle functionality
        const passwordInput = document.getElementById('password');
        const togglePasswordButton = document.getElementById('togglePassword');
        const togglePasswordIcon = togglePasswordButton ? togglePasswordButton.querySelector('i') : null;
        const capsLockWarning = document.getElementById('capsLockWarning');

        if (togglePasswordButton && passwordInput && togglePasswordIcon) {
            togglePasswordButton.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    togglePasswordIcon.classList.replace('fa-eye', 'fa-eye-slash');
                    togglePasswordButton.setAttribute('aria-label', 'Hide password');
                    togglePasswordButton.setAttribute('aria-pressed', 'true');
                } else {
                    passwordInput.type = 'password';
                    togglePasswordIcon.classList.replace('fa-eye-slash', 'fa-eye');
                    togglePasswordButton.setAttribute('aria-label', 'Show password');
                    togglePasswordButton.setAttribute('aria-pressed', 'false');
                }
            });
        }

        if (passwordInput && capsLockWarning) {
            const updateCapsLockWarning = function(event) {
                const isCapsLockOn = event.getModifierState && event.getModifierState('CapsLock');
                capsLockWarning.style.display = isCapsLockOn ? 'block' : 'none';
            };
            passwordInput.addEventListener('keyup', updateCapsLockWarning);
            passwordInput.addEventListener('keydown', updateCapsLockWarning);
            passwordInput.addEventListener('blur', function() {
                capsLockWarning.style.display = 'none';
            });
        }

        // Focus on username field when page loads
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });

        // Form validation
        const loginForm = document.querySelector('.login-form form');
        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                const username = document.getElementById('username').value.trim();
                const password = passwordInput.value.trim();
                
                if (!username || !password) {
                    e.preventDefault();
                    alert('Both username and password are required!');
                    return false;
                }

                const loginButton = loginForm.querySelector('.btn-login');
                if (loginButton) {
                    loginButton.disabled = true;
                    loginButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing in...';
                }
                
                return true;
            });
        }

        // Floating shapes animation
        const shapes = document.querySelectorAll('.shape');
        shapes.forEach(shape => {
            const randomX = Math.random() * 20 - 10;
            const randomY = Math.random() * 20 - 10;
            const randomDelay = Math.random() * 5;
            shape.style.transform = `translate(${randomX}px, ${randomY}px)`;
            shape.style.animationDelay = `${randomDelay}s`;
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>

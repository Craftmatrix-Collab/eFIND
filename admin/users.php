<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Include configuration files
include(__DIR__ . '/includes/auth.php');
include(__DIR__ . '/includes/config.php');
include(__DIR__ . '/includes/logger.php');
require_once __DIR__ . '/includes/resend_delivery_helper.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/image_compression_helper.php';
require_once __DIR__ . '/includes/minio_helper.php';
$passwordPolicy = getPasswordPolicyClientConfig();

// Check if user is logged in - redirect to login if not
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}
if (!isAdmin() && !(function_exists('isSuperAdmin') && isSuperAdmin())) {
    $_SESSION['error'] = 'You do not have permission to access this page.';
    header('Location: dashboard.php');
    exit();
}
$is_superadmin_users_page = function_exists('isSuperAdmin') && isSuperAdmin();
$current_users_page_actor_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (int)($_SESSION['user_id'] ?? 0);
$current_users_page_actor_type = 'users';

function normalizeManagedProfileRole($role): string
{
    $normalized = strtolower(trim((string)$role));
    return in_array($normalized, ['superadmin', 'admin', 'staff', 'viewer'], true) ? $normalized : '';
}

function managedProfileRoleRank(string $role): int
{
    static $ranks = [
        'viewer' => 1,
        'staff' => 2,
        'admin' => 3,
        'superadmin' => 4,
    ];
    $normalizedRole = normalizeManagedProfileRole($role);
    return $ranks[$normalizedRole] ?? 0;
}

function resolveCurrentManagedProfileRole(int $currentActorId, bool $isSuperadmin): string
{
    global $conn;

    if ($isSuperadmin) {
        return 'superadmin';
    }

    if (isset($conn) && $conn instanceof mysqli && $currentActorId > 0) {
        $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if ($roleStmt) {
            $roleStmt->bind_param("i", $currentActorId);
            if ($roleStmt->execute()) {
                $roleRow = $roleStmt->get_result()->fetch_assoc();
                $roleStmt->close();
                $dbRole = normalizeManagedProfileRole($roleRow['role'] ?? '');
                if ($dbRole !== '') {
                    return $dbRole;
                }
            } else {
                error_log('Users page actor role lookup failed: ' . $roleStmt->error);
                $roleStmt->close();
            }
        } else {
            error_log('Users page actor role prepare failed: ' . $conn->error);
        }
    }

    $sessionRole = normalizeManagedProfileRole($_SESSION['role'] ?? ($_SESSION['staff_role'] ?? ''));
    if ($sessionRole !== '') {
        return $sessionRole;
    }

    if (function_exists('isAdmin') && isAdmin()) {
        return 'admin';
    }
    if (function_exists('isStaff') && isStaff()) {
        return 'staff';
    }

    return '';
}

function canEditManagedProfile(
    int $targetId,
    string $targetType,
    string $targetRole,
    int $currentActorId,
    string $currentActorType,
    string $currentActorRole
): bool {
    if ($targetId <= 0 || !in_array($targetType, ['users'], true)) {
        return false;
    }

    $normalizedActorRole = normalizeManagedProfileRole($currentActorRole);
    if ($normalizedActorRole === '') {
        return false;
    }

    $isSelfEdit = $currentActorId > 0 && $targetId === $currentActorId && $targetType === $currentActorType;
    if ($isSelfEdit) {
        return true;
    }

    if ($normalizedActorRole === 'staff') {
        return false;
    }

    $normalizedTargetRole = normalizeManagedProfileRole($targetRole);
    if ($normalizedTargetRole === '') {
        return false;
    }

    return managedProfileRoleRank($normalizedTargetRole) < managedProfileRoleRank($normalizedActorRole);
}

function canAssignManagedRole(int $targetId, int $currentActorId, string $targetRole, string $currentActorRole): bool
{
    $normalizedTargetRole = normalizeManagedProfileRole($targetRole);
    $normalizedActorRole = normalizeManagedProfileRole($currentActorRole);
    if ($normalizedTargetRole === '' || $normalizedActorRole === '') {
        return false;
    }

    $targetRank = managedProfileRoleRank($normalizedTargetRole);
    $actorRank = managedProfileRoleRank($normalizedActorRole);
    $isSelfEdit = $currentActorId > 0 && $targetId === $currentActorId;

    if ($isSelfEdit) {
        return $targetRank <= $actorRank;
    }

    return $targetRank < $actorRank;
}

$current_users_page_actor_role = resolveCurrentManagedProfileRole($current_users_page_actor_id, $is_superadmin_users_page);
$can_superadmin_manage_admin_staff_roles = $current_users_page_actor_role === 'superadmin';

function ensureUsersAccountLockColumns(string $tableName): void
{
    global $conn;

    if (!in_array($tableName, ['users'], true) || !isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    $migrations = [
        'failed_login_attempts' => "ALTER TABLE {$tableName} ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0",
        'account_locked' => "ALTER TABLE {$tableName} ADD COLUMN account_locked TINYINT(1) NOT NULL DEFAULT 0",
        'account_locked_at' => "ALTER TABLE {$tableName} ADD COLUMN account_locked_at DATETIME NULL",
        'failed_window_started_at' => "ALTER TABLE {$tableName} ADD COLUMN failed_window_started_at DATETIME NULL",
        'lockout_until' => "ALTER TABLE {$tableName} ADD COLUMN lockout_until DATETIME NULL",
        'lockout_level' => "ALTER TABLE {$tableName} ADD COLUMN lockout_level INT NOT NULL DEFAULT 0",
    ];

    foreach ($migrations as $columnName => $migrationSql) {
        $columnCheck = $conn->query("SHOW COLUMNS FROM {$tableName} LIKE '{$columnName}'");
        if (!$columnCheck || (int)$columnCheck->num_rows === 0) {
            if (!$conn->query($migrationSql)) {
                error_log("Users lock column migration failed ({$tableName}.{$columnName}): " . $conn->error);
            }
        }
    }
}

// ── AJAX: Send email verification OTP ────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'send_verify_otp') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit();
    }
    // Check email not already taken
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    if (!$chk) {
        error_log('Send OTP email-check prepare failed: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
    $chk->bind_param("s", $email);
    if (!$chk->execute()) {
        error_log('Send OTP email-check execute failed: ' . $chk->error);
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
        exit();
    }
    $chk->close();

    $configIssue = efind_validate_resend_otp_config();
    if ($configIssue !== null) {
        echo json_encode(['success' => false, 'message' => $configIssue]);
        exit();
    }

    $otp = sprintf("%06d", random_int(0, 999999));
    efind_clear_otp_session_state([
        'add_user_verify_otp',
        'add_user_verify_email',
        'add_user_verify_expires',
        'add_user_verified_email',
    ]);
    require_once __DIR__ . '/vendor/autoload.php';
    try {
        $resend = \Resend::client(trim((string)RESEND_API_KEY));
        $resend->emails->send([
            'from'    => FROM_EMAIL,
            'to'      => [$email],
            'subject' => 'Email Verification OTP – eFIND System',
            'html'    => "
                <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px'>
                    <div style='background:linear-gradient(135deg,#4361ee,#3a0ca3);color:#fff;padding:24px;border-radius:10px 10px 0 0;text-align:center'>
                        <h2 style='margin:0'>Email Verification</h2>
                    </div>
                    <div style='background:#f8f9fa;padding:24px;border-radius:0 0 10px 10px'>
                        <p>An administrator is adding you as a new user in the <strong>eFIND System</strong>.</p>
                        <p>Please share this OTP with the administrator to verify your email address:</p>
                        <div style='background:#fff;border:2px dashed #4361ee;border-radius:8px;padding:20px;text-align:center;margin:20px 0'>
                            <p style='margin:0;color:#666;font-size:13px'>Your OTP Code</p>
                            <div style='font-size:36px;font-weight:bold;color:#4361ee;letter-spacing:8px;margin-top:6px'>{$otp}</div>
                        </div>
                        <p style='color:#666;font-size:13px'>This code expires in <strong>10 minutes</strong>. If you did not expect this, please ignore.</p>
                    </div>
                </div>"
        ]);
        $_SESSION['add_user_verify_otp'] = $otp;
        $_SESSION['add_user_verify_email'] = $email;
        $_SESSION['add_user_verify_expires'] = time() + 600; // 10 min

        echo json_encode(['success' => true, 'message' => 'OTP sent to ' . htmlspecialchars($email)]);
    } catch (Throwable $e) {
        efind_clear_otp_session_state([
            'add_user_verify_otp',
            'add_user_verify_email',
            'add_user_verify_expires',
            'add_user_verified_email',
        ]);
        error_log('Resend Error (add user verify): ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => efind_resend_otp_error_message($e)]);
    }
    exit();
}

// ── AJAX: Check email verification OTP ───────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'check_verify_otp') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }
    $email = trim($_POST['email'] ?? '');
    $otp   = trim($_POST['otp']   ?? '');
    if (
        empty($_SESSION['add_user_verify_otp']) ||
        $_SESSION['add_user_verify_email'] !== $email ||
        time() > ($_SESSION['add_user_verify_expires'] ?? 0)
    ) {
        echo json_encode(['success' => false, 'message' => 'OTP expired or invalid. Please request a new one.']);
        exit();
    }
    if ($_SESSION['add_user_verify_otp'] !== $otp) {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
        exit();
    }
    // Mark email as verified in session
    $_SESSION['add_user_verified_email'] = $email;
    unset($_SESSION['add_user_verify_otp'], $_SESSION['add_user_verify_expires']);
    echo json_encode(['success' => true, 'message' => 'Email verified successfully!']);
    exit();
}

// ── AJAX: Send email verification OTP (Edit User Modal) ──────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'send_edit_verify_otp') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $email = trim($_POST['email'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);
    $userType = trim($_POST['user_type'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit();
    }
    if ($userId <= 0 || !in_array($userType, ['users'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid user context.']);
        exit();
    }

    $table = 'users';
    $targetStmt = $conn->prepare("SELECT email, role FROM $table WHERE id = ?");
    if (!$targetStmt) {
        error_log('Edit OTP target-user prepare failed: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
    $targetStmt->bind_param("i", $userId);
    if (!$targetStmt->execute()) {
        error_log('Edit OTP target-user execute failed: ' . $targetStmt->error);
        $targetStmt->close();
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
    $targetResult = $targetStmt->get_result();
    $targetUser = $targetResult->fetch_assoc();
    $targetStmt->close();
    if (!$targetUser) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit();
    }
    if (!canEditManagedProfile(
        $userId,
        $userType,
        (string)($targetUser['role'] ?? ''),
        $current_users_page_actor_id,
        $current_users_page_actor_type,
        $current_users_page_actor_role
    )) {
        echo json_encode(['success' => false, 'message' => 'You are not authorized to edit this profile.']);
        exit();
    }

    // Check email not already taken by another account
    $chk = $conn->prepare("SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1");
    if (!$chk) {
        error_log('Edit OTP duplicate-check prepare failed: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
    $chk->bind_param("si", $email, $userId);
    if (!$chk->execute()) {
        error_log('Edit OTP duplicate-check execute failed: ' . $chk->error);
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
        exit();
    }
    $chk->close();

    $configIssue = efind_validate_resend_otp_config();
    if ($configIssue !== null) {
        echo json_encode(['success' => false, 'message' => $configIssue]);
        exit();
    }

    $otp = sprintf("%06d", random_int(0, 999999));
    efind_clear_otp_session_state([
        'edit_user_verify_otp',
        'edit_user_verify_email',
        'edit_user_verify_expires',
        'edit_user_verify_user_id',
        'edit_user_verify_user_type',
        'edit_user_verified_email',
        'edit_user_verified_user_id',
        'edit_user_verified_user_type',
    ]);

    require_once __DIR__ . '/vendor/autoload.php';
    try {
        $resend = \Resend::client(trim((string)RESEND_API_KEY));
        $resend->emails->send([
            'from'    => FROM_EMAIL,
            'to'      => [$email],
            'subject' => 'Email Verification OTP – eFIND System',
            'html'    => "
                <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px'>
                    <div style='background:linear-gradient(135deg,#4361ee,#3a0ca3);color:#fff;padding:24px;border-radius:10px 10px 0 0;text-align:center'>
                        <h2 style='margin:0'>Email Verification</h2>
                    </div>
                    <div style='background:#f8f9fa;padding:24px;border-radius:0 0 10px 10px'>
                        <p>An administrator is updating your account details in the <strong>eFIND System</strong>.</p>
                        <p>Please share this OTP with the administrator to verify your updated email address:</p>
                        <div style='background:#fff;border:2px dashed #4361ee;border-radius:8px;padding:20px;text-align:center;margin:20px 0'>
                            <p style='margin:0;color:#666;font-size:13px'>Your OTP Code</p>
                            <div style='font-size:36px;font-weight:bold;color:#4361ee;letter-spacing:8px;margin-top:6px'>{$otp}</div>
                        </div>
                        <p style='color:#666;font-size:13px'>This code expires in <strong>10 minutes</strong>. If you did not expect this, please ignore.</p>
                    </div>
                </div>"
        ]);
        $_SESSION['edit_user_verify_otp'] = $otp;
        $_SESSION['edit_user_verify_email'] = $email;
        $_SESSION['edit_user_verify_expires'] = time() + 600; // 10 min
        $_SESSION['edit_user_verify_user_id'] = $userId;
        $_SESSION['edit_user_verify_user_type'] = $userType;

        echo json_encode(['success' => true, 'message' => 'OTP sent to ' . htmlspecialchars($email)]);
    } catch (Throwable $e) {
        efind_clear_otp_session_state([
            'edit_user_verify_otp',
            'edit_user_verify_email',
            'edit_user_verify_expires',
            'edit_user_verify_user_id',
            'edit_user_verify_user_type',
            'edit_user_verified_email',
            'edit_user_verified_user_id',
            'edit_user_verified_user_type',
        ]);
        error_log('Resend Error (edit user verify): ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => efind_resend_otp_error_message($e)]);
    }
    exit();
}

// ── AJAX: Check email verification OTP (Edit User Modal) ─────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'check_edit_verify_otp') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);
    $userType = trim($_POST['user_type'] ?? '');

    if ($userId <= 0 || !in_array($userType, ['users'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid user context.']);
        exit();
    }

    $table = 'users';
    $targetRoleStmt = $conn->prepare("SELECT role FROM $table WHERE id = ? LIMIT 1");
    if (!$targetRoleStmt) {
        error_log('Edit OTP role-check prepare failed: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
    $targetRoleStmt->bind_param("i", $userId);
    if (!$targetRoleStmt->execute()) {
        error_log('Edit OTP role-check execute failed: ' . $targetRoleStmt->error);
        $targetRoleStmt->close();
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
    $targetRoleRow = $targetRoleStmt->get_result()->fetch_assoc();
    $targetRoleStmt->close();
    if (!$targetRoleRow) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit();
    }
    if (!canEditManagedProfile(
        $userId,
        $userType,
        (string)($targetRoleRow['role'] ?? ''),
        $current_users_page_actor_id,
        $current_users_page_actor_type,
        $current_users_page_actor_role
    )) {
        echo json_encode(['success' => false, 'message' => 'You are not authorized to edit this profile.']);
        exit();
    }

    if (!preg_match('/^\d{6}$/', $otp)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid 6-digit OTP.']);
        exit();
    }

    if (
        empty($_SESSION['edit_user_verify_otp']) ||
        empty($_SESSION['edit_user_verify_email']) ||
        empty($_SESSION['edit_user_verify_user_id']) ||
        empty($_SESSION['edit_user_verify_user_type']) ||
        strcasecmp((string)$_SESSION['edit_user_verify_email'], $email) !== 0 ||
        (int)$_SESSION['edit_user_verify_user_id'] !== $userId ||
        (string)$_SESSION['edit_user_verify_user_type'] !== $userType ||
        time() > ($_SESSION['edit_user_verify_expires'] ?? 0)
    ) {
        echo json_encode(['success' => false, 'message' => 'OTP expired or invalid. Please request a new one.']);
        exit();
    }

    if ((string)$_SESSION['edit_user_verify_otp'] !== $otp) {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
        exit();
    }

    $_SESSION['edit_user_verified_email'] = $email;
    $_SESSION['edit_user_verified_user_id'] = $userId;
    $_SESSION['edit_user_verified_user_type'] = $userType;
    unset(
        $_SESSION['edit_user_verify_otp'],
        $_SESSION['edit_user_verify_email'],
        $_SESSION['edit_user_verify_expires'],
        $_SESSION['edit_user_verify_user_id'],
        $_SESSION['edit_user_verify_user_type']
    );
    echo json_encode(['success' => true, 'message' => 'Email verified successfully!']);
    exit();
}
// ─────────────────────────────────────────────────────────────────────────────

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Verify database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Authentication check
if (!isLoggedIn()) {
    header("Location: /index.php");
    exit();
}

// Initialize variables
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }
    $id = (int)$_GET['id'];
    $user_type = isset($_GET['user_type']) ? $_GET['user_type'] : '';
    if (!in_array($user_type, ['users'], true)) {
        $_SESSION['error'] = "Invalid user type.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Determine which table to delete from
    $table = 'users';
    $targetLookupSql = "SELECT username, role FROM $table WHERE id = ?";
    $delStmt = $conn->prepare($targetLookupSql);
    if (!$delStmt) {
        $_SESSION['error'] = "Error checking delete permission: " . $conn->error;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $delStmt->bind_param("i", $id);
    $delStmt->execute();
    $delRow = $delStmt->get_result()->fetch_assoc();
    $delStmt->close();

    if (!$delRow) {
        $_SESSION['error'] = "User not found.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $targetRole = strtolower((string)($delRow['role'] ?? ''));
    $canDeleteTarget = $can_superadmin_manage_admin_staff_roles
        ? in_array($targetRole, ['admin', 'staff'], true)
        : ($targetRole === 'staff');
    if (!$canDeleteTarget) {
        $_SESSION['error'] = "You do not have permission to delete this user.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $deletedUsername = $delRow['username'] ?? "ID:$id";
    $stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        logActivity('user_delete', "User deleted: $deletedUsername", "Table: $table | ID: $id");
        $_SESSION['success'] = "User deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting user: " . $stmt->error;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }
    if (isset($_POST['add_user'])) {
        $full_name = trim($_POST['full_name']);
        $contact_number = trim($_POST['contact_number']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role']);
        $profile_picture = '';

        // Handle file upload if present
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png'];
            $file_type = $_FILES['profile_picture']['type'];
            if (!in_array($file_type, $allowed_types)) {
                $_SESSION['error'] = "Only JPG and PNG files are allowed.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                if ($file_ext === '') {
                    $file_ext = ($file_type === 'image/png') ? 'png' : 'jpg';
                }
                $file_name = 'user_' . str_replace('.', '', uniqid('', true)) . '.' . $file_ext;
                $object_name = 'profiles/' . date('Y/m/') . $file_name;
                $content_type = MinioS3Client::getMimeType($_FILES['profile_picture']['name']);
                $minioClient = new MinioS3Client();
                $uploadResult = $minioClient->uploadFile($_FILES['profile_picture']['tmp_name'], $object_name, $content_type);
                if (!empty($uploadResult['success'])) {
                    $profile_picture = (string)$uploadResult['url'];
                } else {
                    $_SESSION['error'] = "Failed to upload profile picture.";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            }
        }

        // Validate inputs
        $passwordValidation = validatePasswordPolicy($password);
        if (empty($full_name) || empty($email) || empty($username) || empty($password) || empty($role)) {
            $_SESSION['error'] = "Full Name, Email, Username, Password, and Role are required fields.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } elseif (!$passwordValidation['is_valid']) {
            $_SESSION['error'] = $passwordValidation['message'];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } elseif (empty($_SESSION['add_user_verified_email']) || $_SESSION['add_user_verified_email'] !== $email) {
            $_SESSION['error'] = "Please verify the email address before adding the user.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // Insert new user with email_verified = 1
            $created_at = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO users (full_name, contact_number, email, username, password, role, profile_picture, email_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)");
            $stmt->bind_param("ssssssss", $full_name, $contact_number, $email, $username, $hashed_password, $role, $profile_picture, $created_at);
            if ($stmt->execute()) {
                $newUserId = $conn->insert_id;
                logActivity('user_create', "New user created: $username", "Role: $role | Email: $email", $newUserId);
                unset($_SESSION['add_user_verified_email'], $_SESSION['add_user_verify_email']);
                $_SESSION['success'] = "User added successfully!";
            } else {
                $_SESSION['error'] = "Error adding user: " . $stmt->error;
            }
            $stmt->close();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } elseif (isset($_POST['update_user'])) {
        $id = (int)$_POST['user_id'];
        $user_type = isset($_POST['user_type']) ? $_POST['user_type'] : '';
        $full_name = trim($_POST['full_name']);
        $contact_number = trim($_POST['contact_number']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $newPasswordRaw = trim((string)($_POST['password'] ?? ''));
        $password = null;
        $role = trim($_POST['role']);
        $profile_picture = '';
        $normalizedRequestedRole = normalizeManagedProfileRole($role);

        // Validate inputs
        if (!in_array($user_type, ['users'], true)) {
            $_SESSION['error'] = "Invalid user type.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } elseif ($normalizedRequestedRole === '') {
            $_SESSION['error'] = "Invalid role selected.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } elseif (empty($full_name) || empty($email) || empty($username) || empty($role)) {
            $_SESSION['error'] = "Full Name, Email, Username, and Role are required fields.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $role = $normalizedRequestedRole;
            if ($newPasswordRaw !== '') {
                $passwordValidation = validatePasswordPolicy($newPasswordRaw);
                if (!$passwordValidation['is_valid']) {
                    $_SESSION['error'] = $passwordValidation['message'];
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
                $password = password_hash($newPasswordRaw, PASSWORD_DEFAULT);
            }

            $dupStmt = $conn->prepare("SELECT 1 FROM users WHERE (email = ? OR username = ?) AND id <> ? LIMIT 1");
            $dupStmt->bind_param("ssi", $email, $username, $id);
            $dupStmt->execute();
            $dupStmt->store_result();
            $duplicateExists = $dupStmt->num_rows > 0;
            $dupStmt->close();

            if ($duplicateExists) {
                $_SESSION['error'] = "Email or username is already in use by another account.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }

            // Determine which table to update
            $table = 'users';
            $password_field = 'password';

            $existsStmt = $conn->prepare("SELECT email, profile_picture, role FROM $table WHERE id = ?");
            $existsStmt->bind_param("i", $id);
            $existsStmt->execute();
            $existsResult = $existsStmt->get_result();
            $currentUser = $existsResult->fetch_assoc();
            $existsStmt->close();
            if (!$currentUser) {
                $_SESSION['error'] = "User not found.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            if (!canEditManagedProfile(
                $id,
                $user_type,
                (string)($currentUser['role'] ?? ''),
                $current_users_page_actor_id,
                $current_users_page_actor_type,
                $current_users_page_actor_role
            )) {
                $_SESSION['error'] = "You are not authorized to edit this profile.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            if (!canAssignManagedRole($id, $current_users_page_actor_id, $role, $current_users_page_actor_role)) {
                $_SESSION['error'] = "You are not authorized to assign that role.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            $currentEmail = trim((string)($currentUser['email'] ?? ''));
            $emailChanged = strcasecmp($currentEmail, $email) !== 0;
            $isEditEmailVerified = !empty($_SESSION['edit_user_verified_email'])
                && !empty($_SESSION['edit_user_verified_user_id'])
                && !empty($_SESSION['edit_user_verified_user_type'])
                && strcasecmp((string)$_SESSION['edit_user_verified_email'], $email) === 0
                && (int)$_SESSION['edit_user_verified_user_id'] === $id
                && (string)$_SESSION['edit_user_verified_user_type'] === $user_type;
            if ($emailChanged && !$isEditEmailVerified) {
                $_SESSION['error'] = "Please verify the updated email address before saving changes.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            $profile_picture = $currentUser['profile_picture'] ?? '';

            // Handle file upload if present (only after validating target user)
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png'];
                $file_type = $_FILES['profile_picture']['type'];
                if (!in_array($file_type, $allowed_types)) {
                    $_SESSION['error'] = "Only JPG and PNG files are allowed.";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                } else {
                    $minioClient = new MinioS3Client();

                    // Delete old file if exists
                    if (!empty($profile_picture)) {
                        $oldObjectName = $minioClient->extractObjectNameFromUrl((string)$profile_picture);
                        if (!empty($oldObjectName)) {
                            $minioClient->deleteFile($oldObjectName);
                        } else {
                            @unlink(__DIR__ . '/uploads/profiles/' . basename((string)$profile_picture));
                        }
                    }

                    $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                    if ($file_ext === '') {
                        $file_ext = ($file_type === 'image/png') ? 'png' : 'jpg';
                    }
                    $file_name = 'user_' . $id . '_' . str_replace('.', '', uniqid('', true)) . '.' . $file_ext;
                    $object_name = 'profiles/' . date('Y/m/') . $file_name;
                    $content_type = MinioS3Client::getMimeType($_FILES['profile_picture']['name']);
                    $uploadResult = $minioClient->uploadFile($_FILES['profile_picture']['tmp_name'], $object_name, $content_type);
                    if (!empty($uploadResult['success'])) {
                        $profile_picture = (string)$uploadResult['url'];
                    } else {
                        $_SESSION['error'] = "Failed to upload profile picture.";
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    }
                }
            }

            if ($password) {
                $stmt = $conn->prepare("UPDATE $table SET full_name = ?, contact_number = ?, email = ?, username = ?, $password_field = ?, role = ?, profile_picture = ? WHERE id = ?");
                $stmt->bind_param("sssssssi", $full_name, $contact_number, $email, $username, $password, $role, $profile_picture, $id);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET full_name = ?, contact_number = ?, email = ?, username = ?, role = ?, profile_picture = ? WHERE id = ?");
                $stmt->bind_param("ssssssi", $full_name, $contact_number, $email, $username, $role, $profile_picture, $id);
            }
            if ($stmt->execute()) {
                $userRoleDisplay = $role;
                logActivity('user_update', "User updated: $username", "Role: $userRoleDisplay | Email: $email", $id);
                unset(
                    $_SESSION['edit_user_verified_email'],
                    $_SESSION['edit_user_verified_user_id'],
                    $_SESSION['edit_user_verified_user_type'],
                    $_SESSION['edit_user_verify_otp'],
                    $_SESSION['edit_user_verify_email'],
                    $_SESSION['edit_user_verify_expires'],
                    $_SESSION['edit_user_verify_user_id'],
                    $_SESSION['edit_user_verify_user_type']
                );
                $_SESSION['success'] = "User updated successfully!";
            } else {
                $_SESSION['error'] = "Error updating user: " . $stmt->error;
            }
            $stmt->close();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Handle GET request for fetching user data
if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['id'])) {
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF token validation failed.']);
        exit();
    }
    $id = (int)$_GET['id'];
    $userType = isset($_GET['user_type']) ? $_GET['user_type'] : '';
    if (!in_array($userType, ['users'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid user type']);
        exit();
    }
    $table = 'users';
    $stmt = $conn->prepare("SELECT *, '$table' as user_type FROM $table WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    if ($user) {
        if (!canEditManagedProfile(
            $id,
            $userType,
            (string)($user['role'] ?? ''),
            $current_users_page_actor_id,
            $current_users_page_actor_type,
            $current_users_page_actor_role
        )) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'You are not authorized to edit this profile.']);
            exit();
        }
        header('Content-Type: application/json');
        echo json_encode($user);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'User not found']);
        exit();
    }
}

// Handle search, pagination, and sort functionality
$search_query = isset($_GET['search_query']) ? trim($_GET['search_query']) : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'full_name_asc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$table_limit = isset($_GET['table_limit']) ? intval($_GET['table_limit']) : 5;
$valid_limits = [5, 10, 25, 50, 100];
if (!in_array($table_limit, $valid_limits)) {
    $table_limit = 5;
}
$offset = ($page - 1) * $table_limit;

// Initialize parameters and types for search
$params = [];
$types = '';
$where_clauses = [];
$users_base_where = "1=1";

// Add search condition if search query is provided
if (!empty($search_query)) {
    $search_like = "%" . $search_query . "%";
    $where_clauses[] = "(full_name LIKE ? OR email LIKE ? OR username LIKE ? OR contact_number LIKE ?)";
    // Parameters for first query (users table)
    $params = array_merge($params, [$search_like, $search_like, $search_like, $search_like]);
    $types .= 'ssss';
}

// Build the query
$query = "SELECT id, full_name, contact_number, email, username, role, profile_picture, last_login, created_at, updated_at, COALESCE(email_verified, 1) AS email_verified_status, 'users' as user_type FROM users";
$query .= " WHERE " . $users_base_where;
if (!empty($where_clauses)) {
    $query .= " AND " . implode(" AND ", $where_clauses);
}

// Validate and set sort parameter
$valid_sorts = [
    'full_name_asc' => 'full_name ASC',
    'full_name_desc' => 'full_name DESC',
    'email_asc' => 'email ASC',
    'email_desc' => 'email DESC',
    'username_asc' => 'username ASC',
    'username_desc' => 'username DESC',
    'role_asc' => 'role ASC',
    'role_desc' => 'role DESC'
];

// Use validated sort or default
$sort_clause = $valid_sorts[$sort_by] ?? 'full_name ASC';

// Add sorting
$query .= " ORDER BY " . $sort_clause;

// Add pagination parameters (always present)
$query .= " LIMIT ? OFFSET ?";
$params[] = $table_limit;
$params[] = $offset;
$types .= 'ii';

// Prepare and execute the query
$stmt = $conn->prepare($query);
if (!empty($params) && !empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Fetch total count for pagination
$count_query = "SELECT COUNT(*) as total FROM users";
$count_query .= " WHERE " . $users_base_where;
if (!empty($where_clauses)) {
    $count_query .= " AND " . implode(" AND ", $where_clauses);
}
$count_stmt = $conn->prepare($count_query);
if (!empty($params) && !empty($types)) {
    // For count query, we need to remove the LIMIT parameters but keep search parameters
    $countParams = [];
    $countTypes = '';
    // Only include search parameters (not pagination parameters)
    for ($i = 0; $i < count($params) - 2; $i++) {
        $countParams[] = $params[$i];
        $countTypes .= substr($types, $i, 1);
    }
    if (!empty($countParams)) {
        $count_stmt->bind_param($countTypes, ...$countParams);
    }
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_row = $count_result->fetch_assoc();
$total_users = $total_row ? $total_row['total'] : 0;
$total_pages = ceil($total_users / $table_limit);
$count_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - eFIND System</title>
    <link rel="icon" type="image/png" href="images/eFind_logo.png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&display=swap" rel="stylesheet">
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
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa, #e4e8f0);
            color: var(--dark-gray);
            min-height: 100vh;
            padding-top: 70px;
        }
        .management-container {
            margin-left: 250px;
            padding: 20px;
            margin-top: 0;
            transition: all 0.3s;
            margin-bottom: 60px;
        }
        @media (max-width: 992px) {
            .management-container {
                margin-left: 0;
                padding: 15px;
                margin-bottom: 60px;
            }
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: sticky;
            top: 70px;
            background: linear-gradient(135deg, #f5f7fa, #e4e8f0);
            padding: 15px 0;
            border-bottom: 2px solid var(--light-blue);
            flex-wrap: wrap;
            gap: 15px;
            z-index: 100;
        }
        .page-title {
            font-family: 'Montserrat', sans-serif;
            color: var(--secondary-blue);
            font-weight: 700;
            margin: 0;
            position: relative;
        }
        .page-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -17px;
            width: 60px;
            height: 4px;
            background: var(--accent-orange);
            border-radius: 2px;
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }
        .btn-secondary-custom {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }
        .btn-secondary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }
        .search-box {
            position: relative;
            margin-bottom: 20px;
            width: 100%;
        }
        .search-box input {
            padding-left: 40px;
            border-radius: 8px;
            border: 2px solid var(--light-blue);
            box-shadow: none;
        }
        .search-box i {
            position: absolute;
            left: 15px;
            top: 12px;
            color: var(--medium-gray);
        }
        .users-search-form .row {
            align-items: center;
        }
        .users-search-form .search-box {
            margin-bottom: 0;
        }
        .users-search-form .search-box i {
            top: 50%;
            transform: translateY(-50%);
        }
        .users-search-form .form-control,
        .users-search-form .form-select,
        .users-search-form .users-search-btn {
            height: 44px;
        }
        .users-search-form .users-sort-select {
            font-size: 0.85rem;
        }
        .users-search-form .users-search-btn {
            white-space: nowrap;
            min-width: 0;
        }
       .table-container {
            background-color: var(--white);
            border-radius: 16px;
            box-shadow: var(--box-shadow);
            padding: 0;
            margin-bottom: 0;
            overflow: hidden;
            position: relative;
            z-index: 0;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            min-height: 0px;
            max-height: calc(100vh - 400px);
            overflow-y: auto;
            display: block;
        }
        .table {
            margin-bottom: 0;
            width: 100%;
            min-width: 1200px;
            table-layout: fixed;
        }
        .table th {
            background-color: var(--primary-blue);
            color: var(--white);
            font-weight: 600;
            border: none;
            padding: 12px 15px;
            position: sticky;
            top: 0px;
        }
        .table td {
            vertical-align: middle;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 5px 5px;
            height: 48px;
            overflow: hidden;
            word-break: break-word;
        }
        .filler-row td {
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            pointer-events: none;
            background-color: transparent !important;
        }
        .table tr:hover td {
            background-color: rgba(67, 97, 238, 0.05);
        }
        .email-unverified {
            color: #dc3545 !important;
            text-decoration: underline;
            text-decoration-color: #dc3545;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            min-width: 35px;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .action-btn i {
            margin-right: 0;
            font-size: 1rem;
        }
        .btn-view {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        .btn-view:hover {
            background-color: rgba(40, 167, 69, 0.2);
        }
        .btn-edit {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            border: 1px solid rgba(13, 110, 253, 0.3);
        }
        .btn-edit:hover {
            background-color: rgba(13, 110, 253, 0.2);
        }
        .btn-delete {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        .btn-delete:hover {
            background-color: rgba(220, 53, 69, 0.2);
        }
        .tooltip-inner {
            font-size: 0.8rem;
        }
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: var(--box-shadow);
        }
        .modal-header {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: var(--white);
            border-top-left-radius: 16px !important;
            border-top-right-radius: 16px !important;
            border-bottom: none;
        }
        .modal-title {
            font-weight: 600;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid var(--light-blue);
            padding: 10px 15px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .file-upload {
            border: 2px dashed var(--light-blue);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background-color: rgba(67, 97, 238, 0.05);
        }
        .file-upload:hover {
            background-color: rgba(67, 97, 238, 0.1);
        }
        .password-strength {
            height: 5px;
            border-radius: 3px;
            margin-top: 8px;
            background: #e9ecef;
            overflow: hidden;
        }
        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s ease;
        }
        .password-strength-weak { background: #dc3545; width: 33%; }
        .password-strength-medium { background: #ffc107; width: 66%; }
        .password-strength-strong { background: #28a745; width: 100%; }
        .password-requirements {
            margin: 8px 0 0;
            padding-left: 18px;
            font-size: 0.85rem;
            color: var(--medium-gray);
        }
        .password-requirements li.met {
            color: #198754;
        }
        .current-file {
            margin-top: 10px;
            font-size: 0.9rem;
            color: var(--medium-gray);
        }
        .current-file a {
            color: var(--primary-blue);
            text-decoration: none;
        }
        .current-file a:hover {
            text-decoration: underline;
        }
        .floating-shapes {
            position: fixed;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }
        .shape {
            position: absolute;
            opacity: 0.1;
            transition: all 10s linear;
        }
        .shape-1 {
            width: 150px;
            height: 150px;
            background: var(--primary-blue);
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            top: 10%;
            left: 10%;
            animation: float 15s infinite ease-in-out;
        }
        .shape-2 {
            width: 100px;
            height: 100px;
            background: var(--accent-orange);
            border-radius: 50%;
            bottom: 15%;
            right: 10%;
            animation: float 12s infinite ease-in-out reverse;
        }
        .shape-3 {
            width: 180px;
            height: 180px;
            background: var(--secondary-blue);
            border-radius: 50% 20% 50% 30%;
            top: 50%;
            right: 20%;
            animation: float 18s infinite ease-in-out;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }
        .alert-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: var(--box-shadow);
            border-radius: 8px;
            overflow: hidden;
        }
        .alert-success {
            background-color: rgba(40, 167, 69, 0.9);
            border-left: 4px solid #28a745;
        }
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.9);
            border-left: 4px solid #dc3545;
        }
        .alert-danger .btn-close {
            color: white;
        }
        .alert-danger i {
            color: white;
        }
        /* Sticky Pagination Container */
        .pagination-container {
            position: sticky;
            bottom: 0;
            background: var(--white);
            padding: 15px 20px;
            margin-top: 0;
            border-top: 2px solid var(--light-blue);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 100;
            border-radius: 0 0 16px 16px;
        }
        .pagination-info {
            font-weight: 600;
            color: var(--secondary-blue);
            background-color: rgba(67, 97, 238, 0.1);
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid var(--light-blue);
        }
        /* Pagination Styles */
        .pagination {
            margin-bottom: 0;
            justify-content: center;
        }
        .page-link {
            border: 1px solid var(--light-blue);
            color: var(--primary-blue);
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 6px;
            margin: 0 3px;
            transition: all 0.3s;
        }
        .page-link:hover {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            transform: translateY(-2px);
        }
        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            border-color: var(--primary-blue);
            color: white;
        }
        .page-item.disabled .page-link {
            color: var(--medium-gray);
            background-color: var(--light-gray);
            border-color: var(--light-blue);
        }
        /* Ensure arrows are always visible and styled */
        .page-item:first-child .page-link,
        .page-item:last-child .page-link {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            font-weight: 600;
        }
        .page-item:first-child .page-link:hover,
        .page-item:last-child .page-link:hover {
            background-color: var(--secondary-blue);
            transform: translateY(-2px);
        }
        .page-item:first-child.disabled .page-link,
        .page-item:last-child.disabled .page-link {
            background-color: var(--medium-gray);
            border-color: var(--medium-gray);
            color: var(--light-gray);
        }
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .management-container {
                margin-top: 70px;
                padding: 15px;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                top: 60px;
            }
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                max-height: calc(100vh - 350px);
            }
            .action-buttons {
                flex-direction: row;
                gap: 5px;
            }
            .action-btn {
                min-width: 35px;
                font-size: 0.8rem;
                padding: 5px 8px;
            }
            .pagination-container {
                padding: 10px 15px;
            }
            .pagination-info {
                font-size: 0.9rem;
                padding: 6px 10px;
            }
            .page-link {
                padding: 6px 12px;
                font-size: 0.9rem;
            }
            .d-flex.justify-content-between {
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
        }
        .table-info {
            padding: 10px 20px;
            background-color: var(--light-blue);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            border-bottom: 1px solid var(--light-blue);
            font-weight: 600;
            color: var(--secondary-blue);
        }
        /* Sidebar Base */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 250px;
            background: linear-gradient(135deg, #1a3a8f, #1e40af);
            color: white;
            z-index: 1000;
            transition: all 0.3s;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }
        /* Sidebar Header */
        .sidebar-header {
            padding: 20px;
            position: relative;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        .logo-container {
            display: flex;
            justify-content: center;
        }
        .sidebar-logo {
            width: 150px;
            height: 150px;
            object-fit: contain;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.15);
            padding: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s;
        }
        .sidebar-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .sidebar-subtitle {
            font-size: 0.85rem;
            opacity: 0.8;
        }
        /* Sidebar Menu */
        .sidebar-menu {
            padding: 15px;
        }
        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu ul li {
            margin-bottom: 8px;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s;
        }
        .sidebar-menu ul li a {
            display: block;
            padding: 10px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
        }
        .sidebar-menu ul li a i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
            margin-right: 10px;
            transition: all 0.3s;
        }
        /* Hover and active states */
        .sidebar-menu ul li:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        .sidebar-menu ul li:hover a {
            color: #fff;
            font-weight: 600;
        }
        .sidebar-menu ul li:hover a i {
            transform: scale(1.1);
        }
        .sidebar-menu ul li.active {
            background-color: rgba(255, 255, 255, 0.9);
        }
        .sidebar-menu ul li.active a {
            color: #1a3a8f;
            font-weight: 700;
        }
        /* Toggle Button */
        #sidebarToggle {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(255, 255, 255, 0.2);
            border: none;
            color: #fff;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        #sidebarToggle:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
            color: #fff;
        }
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 0;
                overflow: hidden;
            }
            .sidebar.active {
                width: 250px;
            }
        }
        @media print {
            body * {
                visibility: hidden;
            }
            .print-container, .print-container * {
                visibility: visible;
            }
            .print-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            th {
                background-color: #4361ee !important;
                color: white !important;
            }
        }
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--white);
            padding: 15px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            margin-left: 250px;
        }
        @media (max-width: 992px) {
            footer {
                margin-left: 0;
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
    <?php include(__DIR__ . '/includes/sidebar.php'); ?>
    <?php include(__DIR__ . '/includes/navbar.php'); ?>
    <div class="management-container">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Users Management</h1>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-plus me-1"></i> Add User
                    </button>
                </div>
            </div>
            <!-- Search Form -->
            <form method="GET" action="users.php" class="mb-0 users-search-form">
                <div class="row g-2">
                    <div class="col-lg-8 col-md-7 col-12">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search_query" id="searchInput" class="form-control" placeholder="Search users..." value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-sm-8 col-12">
                        <select name="sort_by" id="sort_by" class="form-select users-sort-select" onchange="updateSort()">
                            <option value="full_name_asc" <?php echo $sort_by === 'full_name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            <option value="full_name_desc" <?php echo $sort_by === 'full_name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                            <option value="email_asc" <?php echo $sort_by === 'email_asc' ? 'selected' : ''; ?>>Email (A-Z)</option>
                            <option value="email_desc" <?php echo $sort_by === 'email_desc' ? 'selected' : ''; ?>>Email (Z-A)</option>
                            <option value="username_asc" <?php echo $sort_by === 'username_asc' ? 'selected' : ''; ?>>Username (A-Z)</option>
                            <option value="username_desc" <?php echo $sort_by === 'username_desc' ? 'selected' : ''; ?>>Username (Z-A)</option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-2 col-sm-4 col-12">
                        <button type="submit" class="btn btn-primary-custom w-100 users-search-btn">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                    </div>
                </div>
                <!-- Hidden input for sort_by -->
                <input type="hidden" name="sort_by" id="hiddenSortBy" value="<?php echo htmlspecialchars($sort_by); ?>">
            </form>
            <!-- Table Info -->
            <div class="table-info d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-users me-2"></i>
                    Showing <?php echo count($users); ?> of <?php echo $total_users; ?> users
                    <?php if (!empty($search_query)): ?>
                        <span class="text-muted ms-2">(Filtered results)</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Users Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle text-center">
                        <thead class="table-dark">
                            <tr>
                                <th style="width:5%">ID</th>
                                <th style="width:18%">Full Name</th>
                                <th style="width:12%">Contact Number</th>
                                <th style="width:18%">Email</th>
                                <th style="width:12%">Username</th>
                                <th style="width:8%">Role</th>
                                <th style="width:8%">Profile Picture</th>
                                <th style="width:10%">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No users found</td>
                                </tr>
                            <?php else: ?>
                                <?php $row_num = $offset + 1; ?>
                                <?php foreach ($users as $user): ?>
                                    <tr data-id="<?php echo $user['id']; ?>">
                                        <td><?php echo $row_num++; ?></td>
                                        <td class="full-name text-start"><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td class="contact-number"><?php echo htmlspecialchars($user['contact_number']); ?></td>
                                        <?php $isEmailVerified = ((int)($user['email_verified_status'] ?? 1) === 1); ?>
                                        <td class="email<?php echo $isEmailVerified ? '' : ' email-unverified'; ?>"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="username"><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td class="role">
                                            <span class="badge bg-<?php
                                                switch($user['role']) {
                                                    case 'admin': echo 'danger'; break;
                                                    case 'staff': echo 'primary'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $profilePicturePath = trim((string)($user['profile_picture'] ?? ''));
                                            $profilePictureSrc = 'images/profile.jpg';
                                            if ($profilePicturePath !== '') {
                                                if (preg_match('#^(https?:)?//#i', $profilePicturePath) || stripos($profilePicturePath, 'data:image/') === 0) {
                                                    $profilePictureSrc = $profilePicturePath;
                                                } elseif (preg_match('#^/?images/#i', $profilePicturePath)) {
                                                    $profilePictureSrc = ltrim($profilePicturePath, '/');
                                                } else {
                                                    $profilePictureSrc = 'uploads/profiles/' . basename($profilePicturePath);
                                                }
                                            }
                                            if (strpos($profilePictureSrc, 'data:') !== 0 && !preg_match('#^images/#i', $profilePictureSrc)) {
                                                $profilePictureSrc .= (strpos($profilePictureSrc, '?') === false ? '?t=' : '&t=') . time();
                                            }
                                            ?>
                                            <img src="<?php echo htmlspecialchars($profilePictureSrc); ?>"
                                                 alt="Profile Picture"
                                                 class="rounded-circle"
                                                 width="40"
                                                 height="40"
                                                 onerror="this.onerror=null; this.src='images/profile.jpg';">
                                        </td>
                                        <td>
                                            <?php
                                            $targetRole = strtolower((string)($user['role'] ?? ''));
                                            $targetUserType = (string)($user['user_type'] ?? 'users');
                                            $canEditRow = canEditManagedProfile(
                                                (int)$user['id'],
                                                $targetUserType,
                                                $targetRole,
                                                $current_users_page_actor_id,
                                                $current_users_page_actor_type,
                                                $current_users_page_actor_role
                                            );
                                            $canDeleteRow = $can_superadmin_manage_admin_staff_roles
                                                ? in_array($targetRole, ['admin', 'staff'], true)
                                                : ($targetRole === 'staff');
                                            ?>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <?php if ($canEditRow): ?>
                                                    <button class="btn btn-sm btn-outline-primary p-1 edit-btn"
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-user-type="<?php echo htmlspecialchars($targetUserType); ?>"
                                                            data-bs-toggle="tooltip"
                                                            data-bs-placement="top"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($canDeleteRow): ?>
                                                    <a href="?action=delete&id=<?php echo $user['id']; ?>&user_type=<?php echo urlencode($targetUserType); ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>"
                                                       class="btn btn-sm btn-outline-danger p-1"
                                                       onclick="return confirm('Are you sure you want to delete this user?');"
                                                       data-bs-toggle="tooltip"
                                                       data-bs-placement="top"
                                                       title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php
                                $filled = count($users);
                                for ($i = $filled; $i < $table_limit; $i++): ?>
                                    <tr class="filler-row"><td colspan="8">&nbsp;</td></tr>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Sticky Pagination -->
            <?php if ($total_users > 5): ?>
            <div class="pagination-container">
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <!-- Previous Page -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" aria-label="Previous">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </span>
                            </li>
                        <?php endif; ?>
                        <!-- Page Numbers -->
                        <?php
                        // Show limited page numbers for better UX
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        // Show first page if not in initial range
                        if ($start_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                            </li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <!-- Show last page if not in current range -->
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                            </li>
                        <?php endif; ?>
                        <!-- Next Page -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" aria-label="Next">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Alert Messages -->
    <?php if (!empty($success)): ?>
        <div class="alert-message alert-success alert-dismissible fade show">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle me-2"></i>
                <div><?php echo $success; ?></div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert-message alert-danger alert-dismissible fade show">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-2"></i>
                <div><?php echo $error; ?></div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>
    <!-- Modal for Add New User -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Add New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addUserForm" method="POST" action="" enctype="multipart/form-data" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="text" name="autofill_username" class="d-none" tabindex="-1" aria-hidden="true" autocomplete="username">
                        <input type="password" name="autofill_password" class="d-none" tabindex="-1" aria-hidden="true" autocomplete="current-password">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required autocomplete="off">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="contact_number" class="form-label">Contact Number</label>
                                <input type="text" class="form-control" id="contact_number" name="contact_number" autocomplete="off" inputmode="numeric">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="addUserEmail" class="form-label">Email <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="email" class="form-control" id="addUserEmail" name="email" required placeholder="user@example.com" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                                    <button class="btn btn-outline-primary" type="button" id="sendOtpBtn">
                                        <i class="fas fa-paper-plane me-1"></i> Send OTP
                                    </button>
                                </div>
                                <div id="emailVerifiedBadge" class="mt-1 d-none">
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Email Verified</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                            </div>
                        </div>
                        <div class="row" id="otpSection" style="display:none !important">
                            <div class="col-12 mb-3">
                                <label class="form-label">Enter OTP <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="otpInput" maxlength="6" placeholder="6-digit OTP code" inputmode="numeric">
                                    <button class="btn btn-outline-success" type="button" id="verifyOtpBtn">
                                        <i class="fas fa-check me-1"></i> Verify
                                    </button>
                                </div>
                                <small class="text-muted" id="otpTimer"></small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="addUserPassword" class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="addUserPassword" name="password" required autocomplete="new-password">
                                    <button class="btn btn-outline-secondary toggle-password" type="button">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted"><?php echo htmlspecialchars($passwordPolicy['hint']); ?></small>
                                <div class="password-strength">
                                    <div class="password-strength-bar" id="addUserStrengthBar"></div>
                                </div>
                                <ul class="password-requirements mb-0">
                                    <li id="addUserReqLength"><?php echo htmlspecialchars($passwordPolicy['requirements']['length']); ?></li>
                                    <li id="addUserReqUppercase"><?php echo htmlspecialchars($passwordPolicy['requirements']['uppercase']); ?></li>
                                    <li id="addUserReqNumber"><?php echo htmlspecialchars($passwordPolicy['requirements']['number']); ?></li>
                                    <li id="addUserReqSpecial"><?php echo htmlspecialchars($passwordPolicy['requirements']['special']); ?></li>
                                </ul>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="superadmin">Superadmin</option>
                                    <option value="admin">Admin</option>
                                    <option value="staff">Staff</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Profile Picture (JPG, PNG)</label>
                            <div class="file-upload">
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png">
                                <small class="text-muted">Max file size: 5MB</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_user" class="btn btn-primary-custom" id="addUserSubmitBtn" disabled>
                                <i class="fas fa-user-plus me-1"></i> Add User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal for Edit User -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editUserForm" method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="user_id" id="editUserId">
                        <input type="hidden" id="editOriginalEmail" value="">
                        <input type="hidden" name="existing_profile_picture" id="editExistingProfilePicture">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editFullName" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editFullName" name="full_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editContactNumber" class="form-label">Contact Number</label>
                                <input type="text" class="form-control" id="editContactNumber" name="contact_number">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editEmail" class="form-label">Email <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="email" class="form-control" id="editEmail" name="email" required>
                                    <button class="btn btn-outline-primary" type="button" id="editSendOtpBtn">
                                        <i class="fas fa-paper-plane me-1"></i> Send OTP
                                    </button>
                                </div>
                                <div id="editEmailVerifiedBadge" class="mt-1 d-none">
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Email Verified</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editUsername" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editUsername" name="username" required>
                            </div>
                        </div>
                        <div class="row" id="editOtpSection" style="display:none !important">
                            <div class="col-12 mb-3">
                                <label class="form-label">Enter OTP <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="editOtpInput" maxlength="6" placeholder="6-digit OTP code" inputmode="numeric">
                                    <button class="btn btn-outline-success" type="button" id="editVerifyOtpBtn">
                                        <i class="fas fa-check me-1"></i> Verify
                                    </button>
                                </div>
                                <small class="text-muted" id="editOtpTimer"></small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editPassword" class="form-label">Password (Leave blank to keep current)</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="editPassword" name="password">
                                    <button class="btn btn-outline-secondary toggle-password" type="button">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted"><?php echo htmlspecialchars($passwordPolicy['hint']); ?></small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <?php if ($can_superadmin_manage_admin_staff_roles): ?>
                                    <label for="editRole" class="form-label">Role <span class="text-danger">*</span></label>
                                    <select class="form-select" id="editRole" name="role" required>
                                        <option value="superadmin">Superadmin</option>
                                        <option value="admin">Admin</option>
                                        <option value="staff">Staff</option>
                                    </select>
                                <?php else: ?>
                                    <label for="editRoleDisplay" class="form-label">Role</label>
                                    <div id="editRoleDisplay" class="form-control-plaintext fw-semibold text-capitalize">-</div>
                                    <input type="hidden" id="editRole" name="role" value="">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Profile Picture (JPG, PNG)</label>
                            <div class="file-upload">
                                <input type="file" class="form-control" id="editProfilePicture" name="profile_picture" accept=".jpg,.jpeg,.png">
                                <small class="text-muted">Max file size: 5MB</small>
                            </div>
                            <div id="currentProfilePictureInfo" class="current-file"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update_user" class="btn btn-primary-custom" id="editUserSubmitBtn" disabled>Update User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to update sort parameter and submit form
        function updateSort() {
            const sortBySelect = document.getElementById('sort_by');
            const hiddenSortBy = document.getElementById('hiddenSortBy');
            hiddenSortBy.value = sortBySelect.value;
            sortBySelect.closest('form').submit();
        }
        // Function to update table limit
        function updateTableLimit(selectElement) {
            const limit = selectElement.value;
            const url = new URL(window.location.href);
            url.searchParams.set('table_limit', limit);
            url.searchParams.set('page', 1); // Reset to first page when changing limit
            window.location.href = url.toString();
        }
        function normalizeProfilePicturePath(path) {
            if (!path) return 'images/profile.jpg';
            if (/^(https?:)?\/\//i.test(path) || path.startsWith('data:')) return path;
            if (/^\/?images\//i.test(path)) return path.replace(/^\/+/, '');
            const normalizedPath = path.startsWith('uploads/profiles/') ? path : `uploads/profiles/${path.replace(/^\/+/, '')}`;
            return normalizedPath || 'images/profile.jpg';
        }
        const usersPasswordPolicy = <?php echo json_encode($passwordPolicy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function evaluateUsersPasswordChecks(passwordValue) {
            return {
                length: passwordValue.length >= usersPasswordPolicy.minLength,
                uppercase: /[A-Z]/.test(passwordValue),
                number: /[0-9]/.test(passwordValue),
                special: /[^A-Za-z0-9]/.test(passwordValue)
            };
        }

        function resolveUsersPasswordStrengthClass(checks) {
            const score = [checks.length, checks.uppercase, checks.number, checks.special].filter(Boolean).length;
            if (score <= 1) {
                return 'password-strength-weak';
            }
            if (score <= 3) {
                return 'password-strength-medium';
            }
            return 'password-strength-strong';
        }

        function updateAddUserPasswordIndicator(passwordValue) {
            const checks = evaluateUsersPasswordChecks(passwordValue);
            const strengthBar = document.getElementById('addUserStrengthBar');

            document.getElementById('addUserReqLength')?.classList.toggle('met', checks.length);
            document.getElementById('addUserReqUppercase')?.classList.toggle('met', checks.uppercase);
            document.getElementById('addUserReqNumber')?.classList.toggle('met', checks.number);
            document.getElementById('addUserReqSpecial')?.classList.toggle('met', checks.special);

            if (strengthBar) {
                strengthBar.className = 'password-strength-bar';
                if (passwordValue.length > 0) {
                    strengthBar.classList.add(resolveUsersPasswordStrengthClass(checks));
                }
            }

            return checks;
        }

        function resetAddUserPasswordIndicator() {
            const strengthBar = document.getElementById('addUserStrengthBar');
            if (strengthBar) {
                strengthBar.className = 'password-strength-bar';
            }
            ['addUserReqLength', 'addUserReqUppercase', 'addUserReqNumber', 'addUserReqSpecial'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) {
                    el.classList.remove('met');
                }
            });
        }

        function isUsersPasswordPolicySatisfied(checks) {
            return checks.length && checks.uppercase && checks.number && checks.special;
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert-message');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
            // Toggle password visibility
            document.querySelectorAll('.toggle-password').forEach(button => {
                button.addEventListener('click', function() {
                    const input = this.parentElement.querySelector('input');
                    const icon = this.querySelector('i');
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.replace('fa-eye', 'fa-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    }
                });
            });
            const addUserPasswordInput = document.getElementById('addUserPassword');
            if (addUserPasswordInput) {
                addUserPasswordInput.addEventListener('input', function() {
                    updateAddUserPasswordIndicator(this.value || '');
                });
            }

            const addUserForm = document.getElementById('addUserForm');
            if (addUserForm) {
                addUserForm.addEventListener('submit', function(e) {
                    const passwordInput = document.getElementById('addUserPassword');
                    const checks = updateAddUserPasswordIndicator(passwordInput ? passwordInput.value : '');
                    if (!isUsersPasswordPolicySatisfied(checks)) {
                        e.preventDefault();
                        alert(usersPasswordPolicy.hint);
                        return false;
                    }
                    return true;
                });
            }

            const addUserModal = document.getElementById('addUserModal');
            if (addUserModal) {
                addUserModal.addEventListener('shown.bs.modal', function() {
                    if (!addUserForm) {
                        return;
                    }
                    addUserForm.reset();
                    window.setTimeout(function () {
                        ['full_name', 'contact_number', 'addUserEmail', 'username', 'addUserPassword'].forEach(function (fieldId) {
                            const field = document.getElementById(fieldId);
                            if (field) {
                                field.value = '';
                            }
                        });
                    }, 0);
                });
                addUserModal.addEventListener('hidden.bs.modal', function() {
                    resetAddUserPasswordIndicator();
                });
            }
            // Edit button functionality
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const userType = this.getAttribute('data-user-type');
                    fetch(`?action=get_user&id=${id}&user_type=${userType}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`)
                        .then(response => response.json())
                        .then(user => {
                            if (user.error) {
                                alert('Error loading user data: ' + user.error);
                                return;
                            }
                            document.getElementById('editUserId').value = user.id;
                            document.getElementById('editFullName').value = user.full_name;
                            document.getElementById('editContactNumber').value = user.contact_number || '';
                            document.getElementById('editEmail').value = user.email;
                            document.getElementById('editOriginalEmail').value = user.email;
                            document.getElementById('editUsername').value = user.username;
                            const roleValue = (user.role || '').toString().trim().toLowerCase();
                            document.getElementById('editRole').value = roleValue;
                            const roleDisplay = document.getElementById('editRoleDisplay');
                            if (roleDisplay) {
                                roleDisplay.textContent = roleValue || '-';
                            }
                            document.getElementById('editExistingProfilePicture').value = user.profile_picture || '';
                            // Store user_type in a hidden field
                            let userTypeField = document.getElementById('editUserType');
                            if (!userTypeField) {
                                userTypeField = document.createElement('input');
                                userTypeField.type = 'hidden';
                                userTypeField.id = 'editUserType';
                                userTypeField.name = 'user_type';
                                document.getElementById('editUserForm').appendChild(userTypeField);
                            }
                            userTypeField.value = user.user_type;
                            const currentProfilePictureInfo = document.getElementById('currentProfilePictureInfo');
                            const normalizedProfilePicture = normalizeProfilePicturePath(user.profile_picture || '');
                            const profilePictureSrc = /^\/?images\//i.test(normalizedProfilePicture) || normalizedProfilePicture.startsWith('data:')
                                ? normalizedProfilePicture
                                : `${normalizedProfilePicture}${normalizedProfilePicture.includes('?') ? '&' : '?'}t=${new Date().getTime()}`;
                            currentProfilePictureInfo.innerHTML = `
                                <strong>Current Profile Picture:</strong><br>
                                <img src="${profilePictureSrc}"
                                     alt="Profile Picture"
                                     class="rounded-circle mt-2"
                                     width="60"
                                     height="60"
                                     onerror="this.onerror=null; this.src='images/profile.jpg';">
                            `;
                            const editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
                            editModal.show();
                        })
                        .catch(error => {
                            console.error('Error fetching user:', error);
                            alert('Error loading user data.');
                        });
                });
            });
            // Add scroll event listener for sticky header
            window.addEventListener('scroll', function() {
                const pageHeader = document.querySelector('.page-header');
                if (pageHeader) {
                    if (window.scrollY > 100) {
                        pageHeader.style.background = 'var(--white)';
                        pageHeader.style.boxShadow = 'var(--box-shadow)';
                        pageHeader.style.padding = '15px 20px';
                        pageHeader.style.margin = '0 -20px 20px -20px';
                    } else {
                        pageHeader.style.background = 'linear-gradient(135deg, #f5f7fa, #e4e8f0)';
                        pageHeader.style.boxShadow = 'none';
                        pageHeader.style.padding = '15px 0';
                        pageHeader.style.margin = '0 0 20px 0';
                    }
                }
            });
            // Update table responsive height based on window size
            function updateTableHeight() {
                const tableResponsive = document.querySelector('.table-responsive');
                if (tableResponsive) {
                    const windowHeight = window.innerHeight;
                    tableResponsive.style.maxHeight = `calc(${windowHeight}px - 400px)`;
                }
            }
            window.addEventListener('resize', updateTableHeight);
            window.addEventListener('load', updateTableHeight);
        });
    </script>

    <script>
    // ── Email Verification OTP (Add User Modal) ──────────────────────────────
    (function () {
        const csrfToken   = '<?php echo $_SESSION['csrf_token']; ?>';
        const usersAjaxEndpoint = window.location.pathname || 'users';
        let otpTimer      = null;
        let verifiedEmail = '';

        const emailInput       = document.getElementById('addUserEmail');
        const sendOtpBtn       = document.getElementById('sendOtpBtn');
        const otpSection       = document.getElementById('otpSection');
        const otpInput         = document.getElementById('otpInput');
        const verifyOtpBtn     = document.getElementById('verifyOtpBtn');
        const verifiedBadge    = document.getElementById('emailVerifiedBadge');
        const submitBtn        = document.getElementById('addUserSubmitBtn');
        const otpTimerEl       = document.getElementById('otpTimer');

        if (!sendOtpBtn) return; // guard for other pages

        // Reset modal state when it closes
        document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function () {
            clearOtpTimer();
            otpSection.style.setProperty('display', 'none', 'important');
            verifiedBadge.classList.add('d-none');
            submitBtn.disabled = true;
            otpInput.value = '';
            emailInput.disabled = false;
            verifiedEmail = '';
        });

        // Reset verification if email changes after verification
        emailInput.addEventListener('input', function () {
            if (verifiedEmail && this.value !== verifiedEmail) {
                verifiedEmail = '';
                verifiedBadge.classList.add('d-none');
                submitBtn.disabled = true;
                otpSection.style.setProperty('display', 'none', 'important');
                otpInput.value = '';
                clearOtpTimer();
            }
        });

        sendOtpBtn.addEventListener('click', function () {
            const email = emailInput.value.trim();
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address first.');
                return;
            }
            sendOtpBtn.disabled = true;
            sendOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';

            fetch(usersAjaxEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'send_verify_otp', email: email, csrf_token: csrfToken })
            })
            .then(async r => {
                const responseText = await r.text();
                if (!r.ok) {
                    throw new Error('Request failed. Please refresh and try again.');
                }
                try {
                    return JSON.parse(responseText);
                } catch (e) {
                    throw new Error('Unexpected server response. Please refresh and try again.');
                }
            })
            .then(data => {
                sendOtpBtn.disabled = false;
                sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Resend OTP';
                if (data.success) {
                    otpSection.style.removeProperty('display');
                    otpInput.value = '';
                    otpInput.focus();
                    startOtpTimer(600);
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch((err) => {
                sendOtpBtn.disabled = false;
                sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send OTP';
                showToast(err.message || 'Network error. Please try again.', 'danger');
            });
        });

        verifyOtpBtn.addEventListener('click', function () {
            const email = emailInput.value.trim();
            const otp   = otpInput.value.trim();
            if (otp.length !== 6 || !/^\d{6}$/.test(otp)) {
                showToast('Please enter the 6-digit OTP.', 'warning');
                return;
            }
            verifyOtpBtn.disabled = true;
            verifyOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';

            fetch(usersAjaxEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'check_verify_otp', email: email, otp: otp, csrf_token: csrfToken })
            })
            .then(async r => {
                const responseText = await r.text();
                if (!r.ok) {
                    throw new Error('Request failed. Please refresh and try again.');
                }
                try {
                    return JSON.parse(responseText);
                } catch (e) {
                    throw new Error('Unexpected server response. Please refresh and try again.');
                }
            })
            .then(data => {
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.innerHTML = '<i class="fas fa-check me-1"></i> Verify';
                if (data.success) {
                    verifiedEmail = email;
                    otpSection.style.setProperty('display', 'none', 'important');
                    verifiedBadge.classList.remove('d-none');
                    submitBtn.disabled = false;
                    clearOtpTimer();
                    showToast('Email verified! You can now add the user.', 'success');
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch((err) => {
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.innerHTML = '<i class="fas fa-check me-1"></i> Verify';
                showToast(err.message || 'Network error. Please try again.', 'danger');
            });
        });

        function startOtpTimer(seconds) {
            clearOtpTimer();
            let remaining = seconds;
            otpTimerEl.textContent = `OTP expires in ${remaining}s`;
            otpTimer = setInterval(function () {
                remaining--;
                if (remaining <= 0) {
                    clearOtpTimer();
                    otpTimerEl.textContent = 'OTP expired. Please request a new one.';
                    otpTimerEl.style.color = '#dc3545';
                } else {
                    otpTimerEl.textContent = `OTP expires in ${remaining}s`;
                    otpTimerEl.style.color = '';
                }
            }, 1000);
        }

        function clearOtpTimer() {
            if (otpTimer) { clearInterval(otpTimer); otpTimer = null; }
            otpTimerEl.textContent = '';
        }

        function showToast(message, type) {
            const id = 'toast_' + Date.now();
            const toast = document.createElement('div');
            toast.id = id;
            toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed bottom-0 end-0 m-3`;
            toast.style.zIndex = 9999;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
            document.body.appendChild(toast);
            new bootstrap.Toast(toast, { delay: 4000 }).show();
            toast.addEventListener('hidden.bs.toast', () => toast.remove());
        }
    })();
    </script>

    <script>
    // ── Email Verification OTP (Edit User Modal) ─────────────────────────────
    (function () {
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const usersAjaxEndpoint = window.location.pathname || 'users';
        let otpTimer = null;
        let originalEmail = '';
        let verifiedEmail = '';

        const modalEl = document.getElementById('editUserModal');
        const fullNameInput = document.getElementById('editFullName');
        const contactNumberInput = document.getElementById('editContactNumber');
        const emailInput = document.getElementById('editEmail');
        const originalEmailInput = document.getElementById('editOriginalEmail');
        const usernameInput = document.getElementById('editUsername');
        const roleInput = document.getElementById('editRole');
        const passwordInput = document.getElementById('editPassword');
        const profilePictureInput = document.getElementById('editProfilePicture');
        const userIdInput = document.getElementById('editUserId');
        const sendOtpBtn = document.getElementById('editSendOtpBtn');
        const otpSection = document.getElementById('editOtpSection');
        const otpInput = document.getElementById('editOtpInput');
        const verifyOtpBtn = document.getElementById('editVerifyOtpBtn');
        const verifiedBadge = document.getElementById('editEmailVerifiedBadge');
        const submitBtn = document.getElementById('editUserSubmitBtn');
        const otpTimerEl = document.getElementById('editOtpTimer');
        let originalSnapshot = {
            fullName: '',
            contactNumber: '',
            email: '',
            username: '',
            role: ''
        };

        if (!modalEl || !fullNameInput || !contactNumberInput || !emailInput || !usernameInput || !roleInput || !passwordInput || !profilePictureInput || !sendOtpBtn || !otpSection || !otpInput || !verifyOtpBtn || !submitBtn || !otpTimerEl) {
            return;
        }

        function normalizeValue(value) {
            return (value || '').trim();
        }

        function normalizedEmail(value) {
            return (value || '').trim().toLowerCase();
        }

        function getUserType() {
            const field = document.getElementById('editUserType');
            return field ? (field.value || '').trim() : '';
        }

        function captureOriginalSnapshot() {
            originalSnapshot = {
                fullName: normalizeValue(fullNameInput.value),
                contactNumber: normalizeValue(contactNumberInput.value),
                email: normalizedEmail(emailInput.value),
                username: normalizeValue(usernameInput.value),
                role: normalizeValue(roleInput.value).toLowerCase()
            };
            originalEmail = originalSnapshot.email;
        }

        function hasAnyEditableChanges() {
            if (normalizeValue(fullNameInput.value) !== originalSnapshot.fullName) {
                return true;
            }
            if (normalizeValue(contactNumberInput.value) !== originalSnapshot.contactNumber) {
                return true;
            }
            if (normalizedEmail(emailInput.value) !== originalSnapshot.email) {
                return true;
            }
            if (normalizeValue(usernameInput.value) !== originalSnapshot.username) {
                return true;
            }
            if (normalizeValue(roleInput.value).toLowerCase() !== originalSnapshot.role) {
                return true;
            }
            if (normalizeValue(passwordInput.value) !== '') {
                return true;
            }
            if (profilePictureInput.files && profilePictureInput.files.length > 0) {
                return true;
            }
            return false;
        }

        function emailChanged() {
            return normalizedEmail(emailInput.value) !== normalizedEmail(originalEmail);
        }

        function clearOtpTimer() {
            if (otpTimer) {
                clearInterval(otpTimer);
                otpTimer = null;
            }
            otpTimerEl.textContent = '';
            otpTimerEl.style.color = '';
        }

        function hideOtpSection() {
            otpSection.style.setProperty('display', 'none', 'important');
            otpInput.value = '';
            clearOtpTimer();
        }

        function syncSubmitState() {
            if (!hasAnyEditableChanges()) {
                submitBtn.disabled = true;
                return;
            }
            if (!emailChanged()) {
                submitBtn.disabled = false;
                return;
            }
            submitBtn.disabled = normalizedEmail(verifiedEmail) !== normalizedEmail(emailInput.value);
        }

        function startOtpTimer(seconds) {
            clearOtpTimer();
            let remaining = seconds;
            otpTimerEl.textContent = `OTP expires in ${remaining}s`;
            otpTimer = setInterval(function () {
                remaining--;
                if (remaining <= 0) {
                    clearOtpTimer();
                    otpTimerEl.textContent = 'OTP expired. Please request a new one.';
                    otpTimerEl.style.color = '#dc3545';
                } else {
                    otpTimerEl.textContent = `OTP expires in ${remaining}s`;
                }
            }, 1000);
        }

        function showToast(message, type) {
            const id = 'toast_' + Date.now();
            const toast = document.createElement('div');
            toast.id = id;
            toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed bottom-0 end-0 m-3`;
            toast.style.zIndex = 9999;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
            document.body.appendChild(toast);
            new bootstrap.Toast(toast, { delay: 4000 }).show();
            toast.addEventListener('hidden.bs.toast', () => toast.remove());
        }

        function parseJsonResponse(response) {
            return response.text().then(function (responseText) {
                if (!response.ok) {
                    throw new Error('Request failed. Please refresh and try again.');
                }
                try {
                    return JSON.parse(responseText);
                } catch (e) {
                    throw new Error('Unexpected server response. Please refresh and try again.');
                }
            });
        }

        modalEl.addEventListener('shown.bs.modal', function () {
            if (passwordInput) {
                passwordInput.value = '';
            }
            if (profilePictureInput) {
                profilePictureInput.value = '';
            }
            originalEmail = (originalEmailInput ? originalEmailInput.value : '').trim();
            verifiedEmail = '';
            if (verifiedBadge) {
                verifiedBadge.classList.add('d-none');
            }
            sendOtpBtn.disabled = false;
            sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send OTP';
            hideOtpSection();
            captureOriginalSnapshot();
            syncSubmitState();
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            originalEmail = '';
            verifiedEmail = '';
            if (verifiedBadge) {
                verifiedBadge.classList.add('d-none');
            }
            sendOtpBtn.disabled = false;
            sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send OTP';
            hideOtpSection();
            submitBtn.disabled = true;
        });

        emailInput.addEventListener('input', function () {
            if (!emailChanged()) {
                verifiedEmail = '';
                if (verifiedBadge) {
                    verifiedBadge.classList.add('d-none');
                }
                sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send OTP';
                hideOtpSection();
            } else if (normalizedEmail(verifiedEmail) !== normalizedEmail(emailInput.value)) {
                if (verifiedBadge) {
                    verifiedBadge.classList.add('d-none');
                }
                sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send OTP';
                hideOtpSection();
            }
            syncSubmitState();
        });

        [fullNameInput, contactNumberInput, usernameInput, roleInput, passwordInput].forEach(function (field) {
            field.addEventListener('input', syncSubmitState);
            field.addEventListener('change', syncSubmitState);
        });
        profilePictureInput.addEventListener('change', syncSubmitState);

        sendOtpBtn.addEventListener('click', function () {
            const email = emailInput.value.trim();
            const userId = parseInt(userIdInput.value || '0', 10);
            const userType = getUserType();

            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showToast('Please enter a valid email address first.', 'warning');
                return;
            }
            if (!emailChanged()) {
                showToast('Email is unchanged. You can save without OTP.', 'info');
                syncSubmitState();
                return;
            }
            if (!userId || !['users'].includes(userType)) {
                showToast('Invalid user context. Please reopen the modal.', 'danger');
                return;
            }

            sendOtpBtn.disabled = true;
            sendOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
            verifiedEmail = '';

            fetch(usersAjaxEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'send_edit_verify_otp',
                    email: email,
                    user_id: String(userId),
                    user_type: userType,
                    csrf_token: csrfToken
                })
            })
            .then(parseJsonResponse)
            .then(data => {
                sendOtpBtn.disabled = false;
                sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Resend OTP';
                if (data.success) {
                    otpSection.style.removeProperty('display');
                    otpInput.value = '';
                    otpInput.focus();
                    startOtpTimer(600);
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(err => {
                sendOtpBtn.disabled = false;
                sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send OTP';
                showToast(err.message || 'Network error. Please try again.', 'danger');
            });
        });

        verifyOtpBtn.addEventListener('click', function () {
            const email = emailInput.value.trim();
            const otp = otpInput.value.trim();
            const userId = parseInt(userIdInput.value || '0', 10);
            const userType = getUserType();

            if (!emailChanged()) {
                showToast('Email is unchanged. OTP verification is not required.', 'info');
                syncSubmitState();
                return;
            }
            if (!otp || !/^\d{6}$/.test(otp)) {
                showToast('Please enter the 6-digit OTP.', 'warning');
                return;
            }
            if (!userId || !['users'].includes(userType)) {
                showToast('Invalid user context. Please reopen the modal.', 'danger');
                return;
            }

            verifyOtpBtn.disabled = true;
            verifyOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';

            fetch(usersAjaxEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'check_edit_verify_otp',
                    email: email,
                    otp: otp,
                    user_id: String(userId),
                    user_type: userType,
                    csrf_token: csrfToken
                })
            })
            .then(parseJsonResponse)
            .then(data => {
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.innerHTML = '<i class="fas fa-check me-1"></i> Verify';
                if (data.success) {
                    verifiedEmail = email;
                    otpSection.style.setProperty('display', 'none', 'important');
                    if (verifiedBadge) {
                        verifiedBadge.classList.remove('d-none');
                    }
                    clearOtpTimer();
                    syncSubmitState();
                    showToast('Email verified! You can now update the user.', 'success');
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(err => {
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.innerHTML = '<i class="fas fa-check me-1"></i> Verify';
                showToast(err.message || 'Network error. Please try again.', 'danger');
            });
        });
    })();
    </script>
    <footer class="bg-white p-3">
        <?php include(__DIR__ . '/includes/footer.php'); ?>
    </footer>
</body>
</html>

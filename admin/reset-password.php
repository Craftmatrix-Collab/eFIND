<?php
session_start();
require_once __DIR__ . '/includes/auth.php';
include('includes/config.php');
require_once __DIR__ . '/includes/password_policy.php';

$error = '';
$message = '';
$passwordPolicy = getPasswordPolicyClientConfig();
$otpFlowTable = 'users';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function clearResetPasswordSessionState(): void {
    unset(
        $_SESSION['otp_verified'],
        $_SESSION['reset_user_id'],
        $_SESSION['user_table'],
        $_SESSION['reset_email'],
        $_SESSION['reset_proof'],
        $_SESSION['reset_challenge_id'],
        $_SESSION['password_reset_notice']
    );
}

function fetchResetPasswordContext(mysqli $conn, int $userId): ?array {
    $stmt = $conn->prepare(
        "SELECT id, email, reset_challenge_id, reset_proof_hash, reset_proof_expires
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        error_log('Reset password context prepare failed: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        error_log('Reset password context execute failed: ' . $stmt->error);
        $stmt->close();
        return null;
    }

    $context = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $context;
}

function isResetPasswordContextValid(?array $context, string $email, string $challengeId, string $proof): bool {
    if (!$context || $email === '' || $challengeId === '' || $proof === '') {
        return false;
    }

    $dbEmail = function_exists('normalizePasswordResetEmail')
        ? normalizePasswordResetEmail((string)($context['email'] ?? ''))
        : strtolower(trim((string)($context['email'] ?? '')));
    $dbChallenge = trim((string)($context['reset_challenge_id'] ?? ''));
    $dbProofHash = (string)($context['reset_proof_hash'] ?? '');
    $dbProofExpires = trim((string)($context['reset_proof_expires'] ?? ''));

    if (
        $dbEmail === '' ||
        !hash_equals($dbEmail, $email) ||
        $dbChallenge === '' ||
        !hash_equals($dbChallenge, $challengeId) ||
        $dbProofHash === '' ||
        $dbProofExpires === '' ||
        strtotime($dbProofExpires) < time()
    ) {
        return false;
    }

    return password_verify($proof, $dbProofHash);
}

function ensurePasswordResetAccountLockColumns($tableName) {
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
                error_log("Reset-password lock column migration failed ({$tableName}.{$columnName}): " . $conn->error);
            }
        }
    }
}

if (isset($_SESSION['password_reset_notice'])) {
    $message = (string)$_SESSION['password_reset_notice'];
    unset($_SESSION['password_reset_notice']);
}

$reset_user_id = (int)($_SESSION['reset_user_id'] ?? 0);
$reset_email = function_exists('normalizePasswordResetEmail')
    ? normalizePasswordResetEmail((string)($_SESSION['reset_email'] ?? ''))
    : strtolower(trim((string)($_SESSION['reset_email'] ?? '')));
$reset_challenge_id = trim((string)($_SESSION['reset_challenge_id'] ?? ''));
$reset_proof = trim((string)($_SESSION['reset_proof'] ?? ''));
$hasOtpSession = isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true;

if (
    !$hasOtpSession ||
    $reset_user_id <= 0 ||
    filter_var($reset_email, FILTER_VALIDATE_EMAIL) === false ||
    preg_match('/^[a-f0-9]{64}$/', $reset_challenge_id) !== 1 ||
    preg_match('/^[a-f0-9]{64}$/', $reset_proof) !== 1
) {
    clearResetPasswordSessionState();
    header('Location: forgot-password.php');
    exit();
}

if (!function_exists('ensurePasswordResetSecurityColumns') || !ensurePasswordResetSecurityColumns($conn, $otpFlowTable)) {
    clearResetPasswordSessionState();
    header('Location: forgot-password.php');
    exit();
}

$resetContext = fetchResetPasswordContext($conn, $reset_user_id);
if (!isResetPasswordContextValid($resetContext, $reset_email, $reset_challenge_id, $reset_proof)) {
    clearResetPasswordSessionState();
    header('Location: forgot-password.php?expired=1');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        $error = 'Invalid security token. Please refresh and try again.';
    }

    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if (empty($error) && (empty($password) || empty($confirm_password))) {
        $error = "Both fields are required.";
    } elseif (empty($error) && $password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (empty($error)) {
        $passwordValidation = validatePasswordPolicy($password);
        if (!$passwordValidation['is_valid']) {
            $error = $passwordValidation['message'];
        }
    }

    if (empty($error)) {
        $resetContext = fetchResetPasswordContext($conn, $reset_user_id);
        if (!isResetPasswordContextValid($resetContext, $reset_email, $reset_challenge_id, $reset_proof)) {
            $error = 'Your reset session has expired. Please request a new OTP.';
        }
    }

    if (empty($error)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $pass_column = 'password';
        ensurePasswordResetAccountLockColumns($otpFlowTable);
        
        // Update password, clear reset artifacts, and unlock account state.
        $update_query = "UPDATE $otpFlowTable
                         SET $pass_column = ?,
                             reset_token = NULL,
                             reset_token_hash = NULL,
                             reset_expires = NULL,
                             reset_challenge_id = NULL,
                             reset_challenge_expires = NULL,
                             reset_proof_hash = NULL,
                             reset_proof_expires = NULL,
                             reset_verified_at = NULL,
                             failed_login_attempts = 0,
                             account_locked = 0,
                             account_locked_at = NULL,
                             failed_window_started_at = NULL,
                             lockout_until = NULL,
                             lockout_level = 0
                         WHERE id = ?
                           AND reset_challenge_id = ?
                           AND reset_proof_expires > NOW()
                         LIMIT 1";
        $stmt = $conn->prepare($update_query);
        
        if ($stmt) {
            $stmt->bind_param("sis", $hashed_password, $reset_user_id, $reset_challenge_id);
            
            if ($stmt->execute() && $stmt->affected_rows === 1) {
                if (function_exists('updateAccountPasswordChangedAt')) {
                    updateAccountPasswordChangedAt(
                        $conn,
                        'staff',
                        (int)$reset_user_id
                    );
                }

                if (defined('PRIMARY_LOGIN_SESSION_TABLE') && function_exists('ensurePrimaryLoginSessionTable') && ensurePrimaryLoginSessionTable($conn)) {
                    $accountKey = buildPrimaryAccountKey('staff', (int)$reset_user_id);
                    $revokeStmt = $conn->prepare("DELETE FROM " . PRIMARY_LOGIN_SESSION_TABLE . " WHERE account_key = ? LIMIT 1");
                    if ($revokeStmt) {
                        $revokeStmt->bind_param('s', $accountKey);
                        if (!$revokeStmt->execute()) {
                            error_log('Failed to revoke primary session after password reset: ' . $revokeStmt->error);
                        }
                        $revokeStmt->close();
                    } else {
                        error_log('Failed to prepare primary session revoke query: ' . $conn->error);
                    }
                }

                clearResetPasswordSessionState();
                session_regenerate_id(true);
                $_SESSION['password_reset_success'] = true;
                header("Location: login.php");
                exit();
            } else {
                $error = "Failed to update password. Please try again.";
            }
            
            $stmt->close();
        } else {
            $error = "Database error. Please try again later.";
        }
    }
    
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - eFIND System</title>
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
            --success-green: #28a745;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa, #e4e8f0);
            color: var(--dark-gray);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .reset-wrapper {
            background-color: var(--white);
            border-radius: 24px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
            padding: 50px 40px;
            text-align: center;
        }

        .reset-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--success-green), #20a042);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .reset-icon i {
            color: white;
            font-size: 2.5rem;
        }

        h2 {
            font-family: 'Montserrat', sans-serif;
            color: var(--secondary-blue);
            margin-bottom: 15px;
            font-size: 2rem;
        }

        .subtitle {
            color: var(--medium-gray);
            margin-bottom: 30px;
            font-size: 0.95rem;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-gray);
            font-size: 0.95rem;
            text-align: left;
            display: block;
        }

        .form-control {
            height: 50px;
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 12px 20px;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: var(--light-gray);
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            background-color: var(--white);
            outline: none;
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--medium-gray);
            transition: color 0.3s;
        }

        .password-toggle:hover {
            color: var(--primary-blue);
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
            transition: all 0.3s;
        }

        .password-strength-weak { background: #dc3545; width: 33%; }
        .password-strength-medium { background: #ffc107; width: 66%; }
        .password-strength-strong { background: #28a745; width: 100%; }

        .btn-reset {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
            transition: all 0.3s;
            margin-top: 20px;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-reset:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }

        .requirements {
            text-align: left;
            background: var(--light-blue);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.85rem;
        }

        .requirements ul {
            margin: 0;
            padding-left: 20px;
        }

        .requirements li {
            color: var(--medium-gray);
            margin-bottom: 5px;
        }

        .requirements li.met {
            color: var(--success-green);
        }

        .alert {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.95rem;
            margin-bottom: 20px;
            text-align: left;
        }

        .notice-card {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .notice-card i {
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <div class="reset-wrapper">
        <div class="reset-icon">
            <i class="fas fa-key"></i>
        </div>

        <h2>Reset Password</h2>
        <p class="subtitle">Create a strong password to complete your secure reset session</p>

        <?php if ($message): ?>
            <div class="alert alert-info" role="alert">
                <div class="notice-card">
                    <i class="fas fa-circle-info"></i>
                    <div><?php echo $message; ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <div class="notice-card">
                    <i class="fas fa-triangle-exclamation"></i>
                    <div><?php echo $error; ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div id="clientNotice" class="alert alert-danger d-none" role="alert"></div>

        <div class="requirements">
            <strong>Password Requirements:</strong>
            <ul id="requirements">
                <li id="req-length"><?php echo htmlspecialchars($passwordPolicy['requirements']['length']); ?></li>
                <li id="req-uppercase"><?php echo htmlspecialchars($passwordPolicy['requirements']['uppercase']); ?></li>
                <li id="req-number"><?php echo htmlspecialchars($passwordPolicy['requirements']['number']); ?></li>
                <li id="req-special"><?php echo htmlspecialchars($passwordPolicy['requirements']['special']); ?></li>
            </ul>
        </div>

        <form method="post" action="reset-password.php" id="resetPasswordForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <div class="password-wrapper">
                    <input type="password" class="form-control" id="password" name="password" required>
                    <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                </div>
                <div class="password-strength">
                    <div class="password-strength-bar" id="strengthBar"></div>
                </div>
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    <i class="fas fa-eye password-toggle" id="toggleConfirmPassword"></i>
                </div>
            </div>

            <button type="submit" class="btn btn-reset" name="reset_password">
                <i class="fas fa-lock me-2"></i>Reset Password
            </button>
        </form>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        const resetPasswordPolicy = <?php echo json_encode($passwordPolicy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function evaluatePasswordPolicy(passwordValue) {
            return {
                length: passwordValue.length >= resetPasswordPolicy.minLength,
                uppercase: /[A-Z]/.test(passwordValue),
                number: /[0-9]/.test(passwordValue),
                special: /[^A-Za-z0-9]/.test(passwordValue)
            };
        }

        function calculateStrength(checks) {
            const score = [checks.length, checks.uppercase, checks.number, checks.special].filter(Boolean).length;
            if (score <= 1) {
                return 'password-strength-weak';
            }
            if (score <= 3) {
                return 'password-strength-medium';
            }
            return 'password-strength-strong';
        }

        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const confirmPassword = document.getElementById('confirm_password');
        const form = document.getElementById('resetPasswordForm');
        const clientNotice = document.getElementById('clientNotice');

        function showClientNotice(message) {
            clientNotice.textContent = message;
            clientNotice.classList.remove('d-none');
        }

        function clearClientNotice() {
            clientNotice.textContent = '';
            clientNotice.classList.add('d-none');
        }

        togglePassword.addEventListener('click', function() {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        toggleConfirmPassword.addEventListener('click', function() {
            const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPassword.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // Password strength checker
        password.addEventListener('input', function() {
            clearClientNotice();
            const value = this.value;
            const strengthBar = document.getElementById('strengthBar');
            const checks = evaluatePasswordPolicy(value);

            document.getElementById('req-length').classList.toggle('met', checks.length);
            document.getElementById('req-uppercase').classList.toggle('met', checks.uppercase);
            document.getElementById('req-number').classList.toggle('met', checks.number);
            document.getElementById('req-special').classList.toggle('met', checks.special);

            strengthBar.className = 'password-strength-bar';
            if (value.length > 0) {
                strengthBar.classList.add(calculateStrength(checks));
            }
        });

        // Form validation
        form.addEventListener('submit', function(e) {
            clearClientNotice();
            const pass = password.value;
            const confirmPass = confirmPassword.value;

            const checks = evaluatePasswordPolicy(pass);
            if (!checks.length || !checks.uppercase || !checks.number || !checks.special) {
                e.preventDefault();
                showClientNotice(resetPasswordPolicy.hint);
                return false;
            }

            if (pass !== confirmPass) {
                e.preventDefault();
                showClientNotice('Passwords do not match.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>

<?php
session_start();
require_once __DIR__ . '/includes/auth.php';
include('includes/config.php');
require_once __DIR__ . '/vendor/autoload.php';

if (!defined('RESET_OTP_LENGTH')) {
    define('RESET_OTP_LENGTH', 6);
}

if (!defined('RESET_OTP_EXPIRY_SECONDS')) {
    define('RESET_OTP_EXPIRY_SECONDS', 900);
}

if (!defined('OTP_VERIFY_MAX_ATTEMPTS_PER_CHALLENGE')) {
    define('OTP_VERIFY_MAX_ATTEMPTS_PER_CHALLENGE', 5);
}

if (!defined('OTP_VERIFY_MAX_ATTEMPTS_PER_IP')) {
    define('OTP_VERIFY_MAX_ATTEMPTS_PER_IP', 25);
}

if (!defined('OTP_VERIFY_WINDOW_SECONDS')) {
    define('OTP_VERIFY_WINDOW_SECONDS', 900);
}

if (!defined('OTP_RESEND_MAX_ATTEMPTS_PER_EMAIL')) {
    define('OTP_RESEND_MAX_ATTEMPTS_PER_EMAIL', 3);
}

if (!defined('OTP_RESEND_MAX_ATTEMPTS_PER_IP')) {
    define('OTP_RESEND_MAX_ATTEMPTS_PER_IP', 10);
}

if (!defined('OTP_RESEND_WINDOW_SECONDS')) {
    define('OTP_RESEND_WINDOW_SECONDS', 900);
}

if (!defined('RESET_PROOF_EXPIRY_SECONDS')) {
    define('RESET_PROOF_EXPIRY_SECONDS', 900);
}

function clearPasswordResetSessionContext(): void {
    unset(
        $_SESSION['reset_email'],
        $_SESSION['user_table'],
        $_SESSION['reset_challenge_id'],
        $_SESSION['otp_verified'],
        $_SESSION['otp_attempts'],
        $_SESSION['reset_user_id'],
        $_SESSION['reset_proof'],
        $_SESSION['password_reset_notice']
    );
}

$error = '';
$message = '';
$otpLength = (int)RESET_OTP_LENGTH;
$sessionEmail = function_exists('normalizePasswordResetEmail')
    ? normalizePasswordResetEmail((string)($_SESSION['reset_email'] ?? ''))
    : strtolower(trim((string)($_SESSION['reset_email'] ?? '')));
$sessionChallengeId = trim((string)($_SESSION['reset_challenge_id'] ?? ''));

if (
    $sessionEmail === '' ||
    filter_var($sessionEmail, FILTER_VALIDATE_EMAIL) === false ||
    preg_match('/^[a-f0-9]{64}$/', $sessionChallengeId) !== 1
) {
    clearPasswordResetSessionContext();
    header('Location: forgot-password.php');
    exit();
}

if (!function_exists('ensurePasswordResetSecurityColumns') || !ensurePasswordResetSecurityColumns($conn, 'users')) {
    $error = 'Password reset verification is temporarily unavailable. Please contact the system administrator.';
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$clientIp = function_exists('getPasswordResetClientIp')
    ? getPasswordResetClientIp()
    : trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

$verifyCounts = function_exists('getPasswordResetAttemptCounts')
    ? getPasswordResetAttemptCounts(
        $conn,
        'otp_verify',
        $sessionEmail,
        $clientIp,
        OTP_VERIFY_WINDOW_SECONDS,
        $sessionChallengeId
    )
    : ['email' => 0, 'ip' => 0];
$attemptsUsed = (int)$verifyCounts['email'];
$attemptsRemaining = max(0, OTP_VERIFY_MAX_ATTEMPTS_PER_CHALLENGE - $attemptsUsed);
$isVerificationLocked = $attemptsRemaining <= 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify'])) {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please try again.';
    } elseif ($isVerificationLocked) {
        clearPasswordResetSessionContext();
        $_SESSION['fp_error'] = 'Too many failed OTP attempts. Please request a new code.';
        header('Location: forgot-password.php');
        exit();
    } elseif ((int)$verifyCounts['ip'] >= OTP_VERIFY_MAX_ATTEMPTS_PER_IP) {
        $error = 'Too many OTP verification attempts from this network. Please wait and try again.';
    } else {
        $otp = trim((string)($_POST['otp'] ?? ''));
        if ($otp === '') {
            $error = 'OTP is required.';
        } elseif (strlen($otp) !== $otpLength || !ctype_digit($otp)) {
            $error = "Please enter a valid {$otpLength}-digit OTP.";
        } else {
            $lookupStmt = $conn->prepare(
                "SELECT id, full_name, reset_token_hash, reset_expires, reset_challenge_id, reset_challenge_expires
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );

            if (!$lookupStmt) {
                $error = 'Database error. Please try again later.';
                error_log('Verify OTP lookup prepare failed: ' . $conn->error);
            } else {
                $lookupStmt->bind_param('s', $sessionEmail);
                if (!$lookupStmt->execute()) {
                    $error = 'Database error. Please try again later.';
                    error_log('Verify OTP lookup execute failed: ' . $lookupStmt->error);
                } else {
                    $user = $lookupStmt->get_result()->fetch_assoc();

                    $hasValidChallenge = $user
                        && !empty($user['reset_challenge_id'])
                        && hash_equals((string)$user['reset_challenge_id'], $sessionChallengeId);
                    $isOtpExpired = !$user || empty($user['reset_expires']) || strtotime((string)$user['reset_expires']) < time();
                    $isChallengeExpired = !$user || empty($user['reset_challenge_expires']) || strtotime((string)$user['reset_challenge_expires']) < time();
                    $tokenHash = (string)($user['reset_token_hash'] ?? '');
                    $otpMatches = $tokenHash !== '' && password_verify($otp, $tokenHash);

                    if (!$hasValidChallenge || $isOtpExpired || $isChallengeExpired || !$otpMatches) {
                        if (function_exists('logPasswordResetAttempt')) {
                            logPasswordResetAttempt($conn, 'otp_verify', $sessionEmail, $clientIp, false, $sessionChallengeId);
                        }

                        $verifyCounts = function_exists('getPasswordResetAttemptCounts')
                            ? getPasswordResetAttemptCounts(
                                $conn,
                                'otp_verify',
                                $sessionEmail,
                                $clientIp,
                                OTP_VERIFY_WINDOW_SECONDS,
                                $sessionChallengeId
                            )
                            : ['email' => $attemptsUsed + 1, 'ip' => 0];
                        $attemptsUsed = (int)$verifyCounts['email'];
                        $attemptsRemaining = max(0, OTP_VERIFY_MAX_ATTEMPTS_PER_CHALLENGE - $attemptsUsed);
                        $isVerificationLocked = $attemptsRemaining <= 0;

                        if ($isVerificationLocked) {
                            clearPasswordResetSessionContext();
                            $_SESSION['fp_error'] = 'Too many failed OTP attempts. Please request a new code.';
                            header('Location: forgot-password.php');
                            exit();
                        }

                        if ($isOtpExpired || $isChallengeExpired) {
                            $error = 'OTP has expired. Please request a new one using the resend button.';
                        } else {
                            $error = "Invalid OTP. You have {$attemptsRemaining} attempt(s) remaining.";
                        }
                    } else {
                        $userId = (int)$user['id'];
                        $resetProof = bin2hex(random_bytes(32));
                        $resetProofHash = password_hash($resetProof, PASSWORD_DEFAULT);
                        $proofExpiresAt = date('Y-m-d H:i:s', time() + RESET_PROOF_EXPIRY_SECONDS);

                        $updateStmt = $conn->prepare(
                            "UPDATE users
                             SET reset_proof_hash = ?,
                                 reset_proof_expires = ?,
                                 reset_verified_at = NOW(),
                                 reset_token_hash = NULL,
                                 reset_token = NULL,
                                 reset_expires = NULL
                             WHERE id = ?
                               AND reset_challenge_id = ?
                               AND reset_challenge_expires > NOW()
                             LIMIT 1"
                        );

                        if (!$updateStmt) {
                            $error = 'Database error. Please try again later.';
                            error_log('Verify OTP proof update prepare failed: ' . $conn->error);
                        } else {
                            $updateStmt->bind_param('ssis', $resetProofHash, $proofExpiresAt, $userId, $sessionChallengeId);
                            if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
                                $error = 'Unable to verify OTP right now. Please request a new code.';
                                error_log('Verify OTP proof update execute failed: ' . $updateStmt->error);
                            }
                            $updateStmt->close();
                        }

                        if (empty($error)) {
                            if (function_exists('logPasswordResetAttempt')) {
                                logPasswordResetAttempt($conn, 'otp_verify', $sessionEmail, $clientIp, true, $sessionChallengeId);
                            }
                            $_SESSION['reset_user_id'] = $userId;
                            $_SESSION['otp_verified'] = true;
                            $_SESSION['reset_proof'] = $resetProof;
                            $_SESSION['password_reset_notice'] = 'OTP verified. Create your new password below.';
                            session_regenerate_id(true);
                            header('Location: reset-password.php');
                            exit();
                        }
                    }
                }
                $lookupStmt->close();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please try again.';
    } else {
        $resendCounts = function_exists('getPasswordResetAttemptCounts')
            ? getPasswordResetAttemptCounts(
                $conn,
                'otp_resend',
                $sessionEmail,
                $clientIp,
                OTP_RESEND_WINDOW_SECONDS
            )
            : ['email' => 0, 'ip' => 0];

        if (
            (int)$resendCounts['email'] >= OTP_RESEND_MAX_ATTEMPTS_PER_EMAIL ||
            (int)$resendCounts['ip'] >= OTP_RESEND_MAX_ATTEMPTS_PER_IP
        ) {
            $error = 'Too many OTP resend requests. Please wait before requesting another code.';
            if (function_exists('logPasswordResetAttempt')) {
                logPasswordResetAttempt($conn, 'otp_resend', $sessionEmail, $clientIp, false);
            }
        } else {
            $lookupStmt = $conn->prepare(
                "SELECT id, full_name, reset_challenge_id, reset_challenge_expires
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );

            if (!$lookupStmt) {
                $error = 'Database error. Please try again later.';
                error_log('Verify OTP resend lookup prepare failed: ' . $conn->error);
            } else {
                $lookupStmt->bind_param('s', $sessionEmail);
                if (!$lookupStmt->execute()) {
                    $error = 'Database error. Please try again later.';
                    error_log('Verify OTP resend lookup execute failed: ' . $lookupStmt->error);
                } else {
                    $user = $lookupStmt->get_result()->fetch_assoc();
                    if (
                        !$user ||
                        empty($user['reset_challenge_id']) ||
                        !hash_equals((string)$user['reset_challenge_id'], $sessionChallengeId) ||
                        empty($user['reset_challenge_expires']) ||
                        strtotime((string)$user['reset_challenge_expires']) < time()
                    ) {
                        clearPasswordResetSessionContext();
                        $_SESSION['fp_error'] = 'Your reset request has expired. Please start again.';
                        header('Location: forgot-password.php');
                        exit();
                    }

                    $maxOtpValue = (10 ** $otpLength) - 1;
                    $newOtp = str_pad((string)random_int(0, $maxOtpValue), $otpLength, '0', STR_PAD_LEFT);
                    $newOtpHash = password_hash($newOtp, PASSWORD_DEFAULT);
                    $newChallengeId = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + RESET_OTP_EXPIRY_SECONDS);
                    $userId = (int)$user['id'];

                    $updateStmt = $conn->prepare(
                        "UPDATE users
                         SET reset_token = NULL,
                             reset_token_hash = ?,
                             reset_expires = ?,
                             reset_challenge_id = ?,
                             reset_challenge_expires = ?,
                             reset_proof_hash = NULL,
                             reset_proof_expires = NULL,
                             reset_verified_at = NULL
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if (!$updateStmt) {
                        $error = 'Database error. Please try again later.';
                        error_log('Verify OTP resend update prepare failed: ' . $conn->error);
                    } else {
                        $updateStmt->bind_param('ssssi', $newOtpHash, $expiresAt, $newChallengeId, $expiresAt, $userId);
                        if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
                            $error = 'Unable to resend OTP right now. Please try again.';
                            error_log('Verify OTP resend update execute failed: ' . $updateStmt->error);
                        } else {
                            // Keep session and database challenge IDs synchronized even if email delivery fails.
                            $_SESSION['reset_challenge_id'] = $newChallengeId;
                            unset($_SESSION['otp_verified'], $_SESSION['reset_user_id'], $_SESSION['reset_proof'], $_SESSION['password_reset_notice']);
                            $sessionChallengeId = $newChallengeId;
                        }
                        $updateStmt->close();
                    }

                    if (empty($error)) {
                        try {
                            if (trim((string)RESEND_API_KEY) === '') {
                                throw new RuntimeException('RESEND_API_KEY is not configured.');
                            }
                            $resend = \Resend::client(RESEND_API_KEY);
                            $recipientName = htmlspecialchars((string)($user['full_name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
                            $htmlContent = "
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                    .header { background: linear-gradient(135deg, #4361ee, #3a0ca3); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                                    .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                                    .otp-box { background: white; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; border: 2px dashed #4361ee; }
                                    .otp-code { font-size: 32px; font-weight: bold; color: #4361ee; letter-spacing: 5px; }
                                    .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <div class='header'>
                                        <h1>Password Reset OTP</h1>
                                    </div>
                                    <div class='content'>
                                        <p>Hello {$recipientName},</p>
                                        <p>We received a request to reset your password for your eFIND System account.</p>
                                        <div class='otp-box'>
                                            <p style='margin: 0; color: #666;'>Your OTP Code:</p>
                                            <div class='otp-code'>{$newOtp}</div>
                                        </div>
                                        <p><strong>This code will expire in 15 minutes.</strong></p>
                                        <p>If you didn't request this, please ignore this email.</p>
                                        <div class='footer'>
                                            <p>&copy; " . date('Y') . " eFIND System - Barangay Poblacion South</p>
                                        </div>
                                    </div>
                                </div>
                            </body>
                            </html>";

                            $resend->emails->send([
                                'from' => FROM_EMAIL,
                                'to' => [$sessionEmail],
                                'subject' => 'Password Reset OTP - eFIND System',
                                'html' => $htmlContent
                            ]);

                            $message = 'A new OTP has been sent to your email.';

                            if (function_exists('logPasswordResetAttempt')) {
                                logPasswordResetAttempt($conn, 'otp_resend', $sessionEmail, $clientIp, true);
                            }
                        } catch (Throwable $e) {
                            $error = 'Failed to resend OTP email. Please contact the system administrator.';
                            error_log('Resend Error in verify-otp resend: ' . $e->getMessage());
                            if (function_exists('logPasswordResetAttempt')) {
                                logPasswordResetAttempt($conn, 'otp_resend', $sessionEmail, $clientIp, false);
                            }
                        }
                    } elseif (function_exists('logPasswordResetAttempt')) {
                        logPasswordResetAttempt($conn, 'otp_resend', $sessionEmail, $clientIp, false);
                    }
                }
                $lookupStmt->close();
            }
        }
    }
}

$verifyCounts = function_exists('getPasswordResetAttemptCounts')
    ? getPasswordResetAttemptCounts(
        $conn,
        'otp_verify',
        $sessionEmail,
        $clientIp,
        OTP_VERIFY_WINDOW_SECONDS,
        $sessionChallengeId
    )
    : ['email' => 0, 'ip' => 0];
$attemptsUsed = (int)$verifyCounts['email'];
$attemptsRemaining = max(0, OTP_VERIFY_MAX_ATTEMPTS_PER_CHALLENGE - $attemptsUsed);
$isVerificationLocked = $attemptsRemaining <= 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - eFIND System</title>
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

        .verify-wrapper {
            background-color: var(--white);
            border-radius: 24px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
            padding: 50px 40px;
            text-align: center;
        }

        .verify-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .verify-icon i {
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

        .email-display {
            background: var(--light-blue);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 500;
            color: var(--primary-blue);
        }

        .otp-input-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 30px 0;
        }

        .otp-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            transition: all 0.3s;
        }

        .otp-input:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            outline: none;
        }

        .btn-verify {
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

        .btn-verify:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }

        .btn-resend {
            background: transparent;
            border: 2px solid var(--primary-blue);
            color: var(--primary-blue);
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
            margin-top: 15px;
        }

        .btn-resend:hover {
            background: var(--primary-blue);
            color: white;
        }

        .back-link {
            margin-top: 25px;
            color: var(--medium-gray);
            font-size: 0.95rem;
        }

        .back-link a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
        }

        .back-link a:hover {
            color: var(--secondary-blue);
            text-decoration: underline;
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

        .attempt-chip {
            display: inline-block;
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary-blue);
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="verify-wrapper">
        <div class="verify-icon">
            <i class="fas fa-shield-alt"></i>
        </div>

        <h2>Verify OTP</h2>
        <p class="subtitle">Enter the <?php echo (int)$otpLength; ?>-digit code sent to your email</p>

        <div class="email-display">
            <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($sessionEmail); ?>
        </div>

        <?php if (isset($message) && $message): ?>
            <div class="alert alert-success" role="alert">
                <div class="notice-card">
                    <i class="fas fa-circle-check"></i>
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

        <div class="attempt-chip">
            <i class="fas fa-shield-halved me-1"></i><?php echo (int)$attemptsRemaining; ?> verification attempt(s) remaining
        </div>

        <div id="clientNotice" class="alert alert-danger d-none" role="alert"></div>

        <form method="post" action="verify-otp.php" id="otpForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="otp-input-group">
                <?php for ($digitIndex = 0; $digitIndex < $otpLength; $digitIndex++): ?>
                    <input type="text" class="form-control otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                <?php endfor; ?>
            </div>

            <input type="hidden" name="otp" id="otpValue">

            <button type="submit" class="btn btn-verify" name="verify" <?php echo $isVerificationLocked ? 'disabled' : ''; ?>>
                <i class="fas fa-check-circle me-2"></i>Verify OTP
            </button>
        </form>

        <form method="post" action="verify-otp.php">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <button type="submit" class="btn btn-resend" name="resend_otp">
                <i class="fas fa-redo me-2"></i>Resend OTP
            </button>
        </form>

        <div class="back-link">
            <a href="forgot-password.php"><i class="fas fa-arrow-left me-2"></i>Back to Forgot Password</a>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        const otpLength = <?php echo (int)$otpLength; ?>;
        const inputs = document.querySelectorAll('.otp-input');
        const form = document.getElementById('otpForm');
        const otpValue = document.getElementById('otpValue');
        const clientNotice = document.getElementById('clientNotice');

        function showClientNotice(message) {
            clientNotice.textContent = message;
            clientNotice.classList.remove('d-none');
        }

        function clearClientNotice() {
            clientNotice.textContent = '';
            clientNotice.classList.add('d-none');
        }

        inputs.forEach((input, index) => {
            input.addEventListener('input', function(e) {
                const value = e.target.value;
                clearClientNotice();
                
                // Only allow digits
                if (!/^\d$/.test(value)) {
                    e.target.value = '';
                    return;
                }

                // Move to next input
                if (value && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }

                // Update hidden input with complete OTP
                updateOTPValue();
            });

            input.addEventListener('keydown', function(e) {
                // Handle backspace
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    inputs[index - 1].focus();
                }
            });

            input.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasteData = e.clipboardData.getData('text').trim();
                
                if (new RegExp(`^\\d{${otpLength}}$`).test(pasteData)) {
                    pasteData.split('').forEach((char, i) => {
                        if (inputs[i]) {
                            inputs[i].value = char;
                        }
                    });
                    inputs[inputs.length - 1].focus();
                    updateOTPValue();
                }
            });
        });

        function updateOTPValue() {
            const otp = Array.from(inputs).map(input => input.value).join('');
            otpValue.value = otp;
        }

        form.addEventListener('submit', function(e) {
            clearClientNotice();
            updateOTPValue();
            if (otpValue.value.length !== otpLength) {
                e.preventDefault();
                showClientNotice(`Please enter all ${otpLength} digits.`);
            }
        });

        // Auto-focus first input
        if (inputs.length > 0) {
            inputs[0].focus();
        }
    </script>
</body>
</html>

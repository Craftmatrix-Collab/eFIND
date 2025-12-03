<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include('includes/config.php');
include('includes/resend.php');

// Redirect if not in 2FA verification state
if (!isset($_SESSION['pending_2fa_user_id'])) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['verify_otp'])) {
        $otp = trim($_POST['otp']);
        $userId = $_SESSION['pending_2fa_user_id'];
        $userType = $_SESSION['pending_2fa_user_type'];
        
        if (verifyOTP($userId, $otp, $userType)) {
            // OTP verified - complete login
            if ($userType == 'admin') {
                $_SESSION['admin_id'] = $_SESSION['pending_admin_id'];
                $_SESSION['admin_username'] = $_SESSION['pending_admin_username'];
                $_SESSION['admin_full_name'] = $_SESSION['pending_admin_full_name'];
                $_SESSION['admin_profile_picture'] = $_SESSION['pending_admin_profile_picture'];
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['user_id'] = $_SESSION['pending_admin_id'];
                $_SESSION['role'] = 'admin';
                $_SESSION['full_name'] = $_SESSION['pending_admin_full_name'];
                $_SESSION['profile_picture'] = $_SESSION['pending_admin_profile_picture'];
            }
            
            // Clear pending session data
            unset($_SESSION['pending_2fa_user_id']);
            unset($_SESSION['pending_2fa_user_type']);
            unset($_SESSION['pending_admin_id']);
            unset($_SESSION['pending_admin_username']);
            unset($_SESSION['pending_admin_full_name']);
            unset($_SESSION['pending_admin_profile_picture']);
            
            logActivity($userId, '2fa_verified', '2FA verification successful', 'system', $_SERVER['REMOTE_ADDR']);
            
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid or expired verification code.";
            logActivity($userId, '2fa_failed', '2FA verification failed', 'system', $_SERVER['REMOTE_ADDR']);
        }
    } elseif (isset($_POST['resend_otp'])) {
        $userId = $_SESSION['pending_2fa_user_id'];
        $userType = $_SESSION['pending_2fa_user_type'];
        $email = $_SESSION['pending_user_email'];
        $name = $_SESSION['pending_user_name'];
        
        $otp = generateOTP();
        if (storeOTP($userId, $otp, $userType) && sendOTPEmail($email, $name, $otp)) {
            $success = "A new verification code has been sent to your email.";
        } else {
            $error = "Failed to resend verification code. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - eFIND Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .verification-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
            max-width: 450px;
            width: 100%;
        }
        .otp-input {
            font-size: 24px;
            text-align: center;
            letter-spacing: 10px;
            font-weight: bold;
        }
        .verification-icon {
            font-size: 48px;
            color: #667eea;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="verification-card">
        <div class="verification-icon">
            <i class="fas fa-shield-alt"></i>
        </div>
        <h3 class="text-center mb-4">Two-Factor Authentication</h3>
        <p class="text-center text-muted mb-4">
            We've sent a verification code to your email address. Please enter it below.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-4">
                <label for="otp" class="form-label">Verification Code</label>
                <input type="text" 
                       class="form-control otp-input" 
                       id="otp" 
                       name="otp" 
                       maxlength="6" 
                       pattern="[0-9]{6}" 
                       required 
                       autofocus
                       placeholder="000000">
            </div>

            <button type="submit" name="verify_otp" class="btn btn-primary w-100 mb-3">
                Verify Code
            </button>
        </form>

        <form method="POST" action="" class="text-center">
            <button type="submit" name="resend_otp" class="btn btn-link">
                Resend Code
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="index.php" class="btn btn-link text-muted">Cancel Login</a>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
</body>
</html>

<?php
session_start();
include('includes/config.php');
require_once __DIR__ . '/vendor/autoload.php';

use Resend\Resend;

// Check if email is set in session
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot-password.php");
    exit();
}

$error = '';
$attempts = isset($_SESSION['otp_attempts']) ? $_SESSION['otp_attempts'] : 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify'])) {
    $otp = trim($_POST['otp']);
    $email = $_SESSION['reset_email'];

    if (empty($otp)) {
        $error = "OTP is required.";
    } elseif (strlen($otp) != 6 || !ctype_digit($otp)) {
        $error = "Please enter a valid 6-digit OTP.";
    } else {
        // Check OTP in database
        $query = "SELECT id, username, reset_token, reset_expires FROM admin_users WHERE email = ?";
        $stmt = $conn->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                
                // Check if OTP has expired
                if (strtotime($user['reset_expires']) < time()) {
                    $error = "OTP has expired. Please request a new one.";
                    unset($_SESSION['reset_email']);
                } elseif ($user['reset_token'] === $otp) {
                    // OTP is correct
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['otp_verified'] = true;
                    unset($_SESSION['otp_attempts']);
                    
                    // Redirect to reset password page
                    header("Location: reset-password.php");
                    exit();
                } else {
                    $attempts++;
                    $_SESSION['otp_attempts'] = $attempts;
                    
                    if ($attempts >= 5) {
                        $error = "Too many failed attempts. Please request a new OTP.";
                        unset($_SESSION['reset_email']);
                        unset($_SESSION['otp_attempts']);
                    } else {
                        $error = "Invalid OTP. You have " . (5 - $attempts) . " attempts remaining.";
                    }
                }
            } else {
                $error = "Invalid session. Please try again.";
                unset($_SESSION['reset_email']);
            }
            
            $stmt->close();
        } else {
            $error = "Database error. Please try again later.";
        }
    }
    
    $conn->close();
}

// Resend OTP functionality
if (isset($_POST['resend_otp'])) {
    $email = $_SESSION['reset_email'];
    
    // Generate new OTP
    $otp = sprintf("%06d", mt_rand(0, 999999));
    $expires = date("Y-m-d H:i:s", strtotime('+15 minutes'));
    
    // Update OTP in database
    $update_query = "UPDATE admin_users SET reset_token = ?, reset_expires = ? WHERE email = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("sss", $otp, $expires, $email);
    $update_stmt->execute();
    $update_stmt->close();
    
    // Get user details for email
    $query = "SELECT full_name FROM admin_users WHERE email = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Send OTP via Resend
    try {
        $resend = Resend::client(RESEND_API_KEY);
        
        $html_content = "
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
                    <p>Hello " . htmlspecialchars($user['full_name']) . ",</p>
                    <p>We received a request to reset your password for your eFIND System account.</p>
                    <div class='otp-box'>
                        <p style='margin: 0; color: #666;'>Your OTP Code:</p>
                        <div class='otp-code'>" . $otp . "</div>
                    </div>
                    <p><strong>This code will expire in 15 minutes.</strong></p>
                    <p>If you didn't request this, please ignore this email.</p>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " eFIND System - Barangay Poblacion South</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $resend->emails->send([
            'from' => FROM_EMAIL,
            'to' => [$email],
            'subject' => 'Password Reset OTP - eFIND System',
            'html' => $html_content
        ]);
        
        $message = "A new OTP has been sent to your email.";
        $_SESSION['otp_attempts'] = 0;
        
    } catch (Exception $e) {
        $error = "Failed to resend OTP. Please try again later.";
        error_log("Resend Error: " . $e->getMessage());
    }
    
    $conn->close();
}
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
        }
    </style>
</head>
<body>
    <div class="verify-wrapper">
        <div class="verify-icon">
            <i class="fas fa-shield-alt"></i>
        </div>

        <h2>Verify OTP</h2>
        <p class="subtitle">Enter the 6-digit code sent to your email</p>

        <div class="email-display">
            <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($_SESSION['reset_email']); ?>
        </div>

        <?php if (isset($message) && $message): ?>
            <div class="alert alert-success">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="verify-otp.php" id="otpForm">
            <div class="otp-input-group">
                <input type="text" class="form-control otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                <input type="text" class="form-control otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                <input type="text" class="form-control otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                <input type="text" class="form-control otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                <input type="text" class="form-control otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                <input type="text" class="form-control otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
            </div>

            <input type="hidden" name="otp" id="otpValue">

            <button type="submit" class="btn btn-verify" name="verify">
                <i class="fas fa-check-circle me-2"></i>Verify OTP
            </button>
        </form>

        <form method="post" action="verify-otp.php">
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
        const inputs = document.querySelectorAll('.otp-input');
        const form = document.getElementById('otpForm');
        const otpValue = document.getElementById('otpValue');

        inputs.forEach((input, index) => {
            input.addEventListener('input', function(e) {
                const value = e.target.value;
                
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
                
                if (/^\d{6}$/.test(pasteData)) {
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
            updateOTPValue();
            if (otpValue.value.length !== 6) {
                e.preventDefault();
                alert('Please enter all 6 digits');
            }
        });

        // Auto-focus first input
        inputs[0].focus();
    </script>
</body>
</html>

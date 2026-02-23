<?php
session_start();
include('includes/config.php');
require_once __DIR__ . '/vendor/autoload.php';

$message = '';
$error   = '';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid request. Please try again.";
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            $error = "Email address is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Look up unverified admin account
            $stmt = $conn->prepare("SELECT id, full_name, is_verified FROM admin_users WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                $user   = $result->num_rows === 1 ? $result->fetch_assoc() : null;
                $stmt->close();
            }

            if ($user && !$user['is_verified']) {
                // Generate a fresh token valid for 24 hours
                $new_token  = bin2hex(random_bytes(32));
                $new_expiry = date("Y-m-d H:i:s", strtotime('+24 hours'));

                $upd = $conn->prepare("UPDATE admin_users SET verification_token = ?, token_expiry = ? WHERE id = ?");
                $upd->bind_param("ssi", $new_token, $new_expiry, $user['id']);
                $upd->execute();
                $upd->close();

                $app_url     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
                $verify_link = $app_url . '/verify-email.php?token=' . $new_token;

                try {
                    $resend = \Resend::client(RESEND_API_KEY);

                    $html = "
                    <!DOCTYPE html><html><head><style>
                        body{font-family:Arial,sans-serif;line-height:1.6;color:#333;}
                        .container{max-width:600px;margin:0 auto;padding:20px;}
                        .header{background:linear-gradient(135deg,#4361ee,#3a0ca3);color:white;padding:30px;text-align:center;border-radius:10px 10px 0 0;}
                        .content{background:#f8f9fa;padding:30px;border-radius:0 0 10px 10px;}
                        .btn{display:inline-block;background:#4361ee;color:white;padding:14px 32px;text-decoration:none;border-radius:8px;font-weight:bold;margin:20px 0;}
                        .link{color:#666;font-size:13px;word-break:break-all;}
                        .footer{text-align:center;margin-top:20px;color:#666;font-size:12px;}
                    </style></head><body>
                    <div class='container'>
                        <div class='header'><h1>Verify Your Email</h1></div>
                        <div class='content'>
                            <p>Hello " . htmlspecialchars($user['full_name']) . ",</p>
                            <p>Here is your new email verification link for the eFIND System.</p>
                            <div style='text-align:center;'>
                                <a href='" . $verify_link . "' class='btn'>Verify Email Address</a>
                            </div>
                            <p class='link'>Or copy and paste this link:<br>" . $verify_link . "</p>
                            <p><strong>This link will expire in 24 hours.</strong></p>
                            <div class='footer'><p>&copy; " . date('Y') . " eFIND System - Barangay Poblacion South</p></div>
                        </div>
                    </div></body></html>";

                    $resend->emails->send([
                        'from'    => FROM_EMAIL,
                        'to'      => [$email],
                        'subject' => 'Email Verification (Resent) - eFIND System',
                        'html'    => $html,
                    ]);

                    $message = "A new verification link has been sent to <strong>" . htmlspecialchars($email) . "</strong>. Please check your inbox.";
                } catch (Exception $e) {
                    error_log("Resend Error in resend-verification: " . $e->getMessage());
                    $error = "Failed to send the email. Please contact the system administrator.";
                }
            } else {
                // Prevent enumeration — same message whether email not found or already verified
                $message = "If this email belongs to an unverified account, a new verification link has been sent.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Verification - eFIND System</title>
    <link rel="icon" type="image/png" href="images/eFind_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
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
            --box-shadow: 0 10px 30px rgba(0,0,0,0.1);
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
        .wrapper {
            background: var(--white);
            border-radius: 24px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
            max-width: 800px;
            width: 100%;
            display: flex;
        }
        .wrapper:hover { transform: translateY(-5px); transition: transform .5s ease; }
        .logo-section {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: var(--white);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .logo-section::before {
            content: ""; position: absolute;
            width: 300px; height: 300px;
            background: rgba(255,255,255,.1); border-radius: 50%;
            top: -100px; right: -100px;
        }
        .logo-section::after {
            content: ""; position: absolute;
            width: 200px; height: 200px;
            background: rgba(255,255,255,.08); border-radius: 50%;
            bottom: -50px; left: -50px;
        }
        .logo-section img { max-width: 80%; height: auto; margin-bottom: 30px; z-index: 2; }
        .logo-section h1 { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 2.5rem; z-index: 2; }
        .form-section {
            flex: 1;
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .efind-logo { height: 80px; width: 80px; margin-bottom: 20px; object-fit: contain; }
        .brand-name {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            color: var(--secondary-blue);
            font-size: 2rem;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }
        .brand-subtitle { color: var(--medium-gray); font-size: .9rem; font-style: italic; margin-top: 10px; }
        .form-wrapper { width: 100%; max-width: 360px; margin-top: 30px; }
        .form-control {
            height: 50px; border-radius: 12px; border: 2px solid #e9ecef;
            padding: 12px 20px; font-size: 1rem; transition: all .3s;
            background: var(--light-gray);
        }
        .form-control:focus { border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(67,97,238,.2); background: var(--white); }
        .form-label { font-weight: 600; font-size: .95rem; text-align: left; display: block; margin-bottom: 8px; }
        .btn-send {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white; border: none; border-radius: 12px;
            padding: 14px; font-weight: 600; font-size: 1.1rem;
            width: 100%; margin-top: 20px;
            box-shadow: 0 4px 15px rgba(67,97,238,.3);
            transition: all .3s;
        }
        .btn-send:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(67,97,238,.4); }
        .back-link { margin-top: 20px; color: var(--medium-gray); font-size: .95rem; }
        .back-link a { color: var(--primary-blue); text-decoration: none; font-weight: 500; }
        .back-link a:hover { color: var(--secondary-blue); }
        .alert { border-radius: 12px; padding: 12px 16px; font-size: .95rem; margin-bottom: 20px; text-align: left; }
        .alert-success { border-left: 4px solid #28a745; background: rgba(40,167,69,.1); }
        .alert-danger  { border-left: 4px solid #dc3545; background: rgba(220,53,69,.1); }
        @media(max-width:768px) {
            .wrapper { flex-direction: column; }
            .logo-section, .form-section { padding: 30px 20px; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="logo-section">
            <img src="images/eFind_logo5.png" alt="eFIND Logo">
            <h1>Resend Verification</h1>
            <p>eFIND System</p>
        </div>

        <div class="form-section">
            <img src="images/logo_pbsth.png" alt="eFIND Logo" class="efind-logo">
            <div class="brand-name">Verify Email</div>
            <p class="brand-subtitle">Resend your verification link</p>

            <?php if ($message): ?>
                <div class="alert alert-success w-100" style="max-width:360px;">
                    <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger w-100" style="max-width:360px;">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="form-wrapper">
                <form method="post" action="resend-verification.php">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" required
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <button type="submit" class="btn btn-send" name="resend">
                        <i class="fas fa-paper-plane me-2"></i>Resend Verification Email
                    </button>
                </form>

                <div class="back-link">
                    <a href="login.php"><i class="fas fa-arrow-left me-2"></i>Back to Login</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>

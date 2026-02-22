<?php
session_start();
include('includes/config.php');

$status = 'error';
$message = 'Invalid or missing verification token.';

$token = trim($_GET['token'] ?? '');

if (!empty($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    // Look up the token in admin_users
    $stmt = $conn->prepare("SELECT id, full_name, is_verified, token_expiry FROM admin_users WHERE verification_token = ?");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user['is_verified']) {
                $status = 'already';
                $message = 'Your email address is already verified. You can log in.';
            } elseif (strtotime($user['token_expiry']) < time()) {
                $status = 'expired';
                $message = 'Your verification link has expired. Please register again or contact the administrator.';
            } else {
                // Mark as verified and clear the token
                $upd = $conn->prepare("UPDATE admin_users SET is_verified = 1, verification_token = NULL, token_expiry = NULL WHERE id = ?");
                $upd->bind_param("i", $user['id']);
                if ($upd->execute()) {
                    $status = 'success';
                    $message = 'Your email address has been verified successfully! You can now log in.';
                } else {
                    $message = 'Verification failed due to a database error. Please try again.';
                }
                $upd->close();
            }
        } else {
            $message = 'Invalid verification link. It may have already been used.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - eFIND System</title>
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
            overflow-x: hidden;
        }
        .verify-wrapper {
            background-color: var(--white);
            border-radius: 24px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
            max-width: 800px;
            width: 100%;
            min-height: 400px;
            position: relative;
            z-index: 1;
            transition: transform 0.5s ease;
            display: flex;
        }
        .verify-wrapper:hover { transform: translateY(-5px); }
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
            content: "";
            position: absolute;
            width: 300px; height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            top: -100px; right: -100px;
        }
        .logo-section::after {
            content: "";
            position: absolute;
            width: 200px; height: 200px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
            bottom: -50px; left: -50px;
        }
        .logo-section img { max-width: 80%; height: auto; margin-bottom: 30px; z-index: 2; transition: transform 0.5s ease; }
        .logo-section:hover img { transform: scale(1.05); }
        .logo-section h1 { font-family: 'Montserrat', sans-serif; font-weight: 700; margin-bottom: 10px; font-size: 2.5rem; z-index: 2; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .verify-section {
            flex: 1;
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .branding { margin-bottom: 30px; display: flex; flex-direction: column; align-items: center; }
        .efind-logo { height: 80px; width: 80px; margin-bottom: 20px; object-fit: contain; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1)); transition: all 0.3s ease; }
        .efind-logo:hover { transform: rotate(5deg) scale(1.1); }
        .brand-name {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            color: var(--secondary-blue);
            font-size: 2rem;
            margin-bottom: 5px;
            letter-spacing: 1px;
            position: relative;
            display: inline-block;
        }
        .brand-name::after {
            content: "";
            position: absolute;
            bottom: -8px; left: 50%; transform: translateX(-50%);
            width: 60px; height: 4px;
            background: var(--accent-orange);
            border-radius: 2px;
            transition: width 0.3s ease;
        }
        .brand-name:hover::after { width: 100px; }
        .brand-subtitle { color: var(--medium-gray); font-size: 0.9rem; font-style: italic; margin-top: 10px; }
        .status-icon { font-size: 4rem; margin-bottom: 20px; }
        .status-icon.success { color: #28a745; }
        .status-icon.error  { color: #dc3545; }
        .status-icon.info   { color: #4361ee; }
        .status-message { font-size: 1rem; color: var(--dark-gray); margin-bottom: 25px; }
        .btn-login {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white; border: none;
            border-radius: 12px; padding: 12px 30px;
            font-weight: 600; font-size: 1rem;
            text-decoration: none; display: inline-block;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(67,97,238,0.3);
        }
        .btn-login:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(67,97,238,0.4); color: white; }
        .back-link { margin-top: 20px; color: var(--medium-gray); font-size: 0.95rem; }
        .back-link a { color: var(--primary-blue); text-decoration: none; font-weight: 500; transition: color 0.2s; }
        .back-link a:hover { color: var(--secondary-blue); }
        .floating-shapes { position: absolute; width: 100%; height: 100%; overflow: hidden; z-index: -1; }
        .shape { position: absolute; opacity: 0.1; }
        .shape-1 { width: 100px; height: 100px; background: var(--primary-blue); border-radius: 30% 70% 70% 30%/30% 30% 70% 70%; top: 10%; left: 10%; animation: float 15s infinite ease-in-out; }
        .shape-2 { width: 80px; height: 80px; background: var(--accent-orange); border-radius: 50%; bottom: 15%; right: 10%; animation: float 12s infinite ease-in-out reverse; }
        .shape-3 { width: 120px; height: 120px; background: var(--secondary-blue); border-radius: 50% 20% 50% 30%; top: 50%; right: 20%; animation: float 18s infinite ease-in-out; }
        @keyframes float { 0%,100%{transform:translateY(0) rotate(0deg)} 50%{transform:translateY(-20px) rotate(5deg)} }
        @media (max-width: 768px) {
            .verify-wrapper { flex-direction: column; min-height: auto; }
            .logo-section { padding: 30px 20px; }
            .verify-section { padding: 40px 20px; }
            .brand-name { font-size: 1.8rem; }
            .efind-logo { height: 70px; width: 70px; }
        }
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <div class="verify-wrapper">
        <div class="logo-section">
            <img src="images/eFind_logo5.png" alt="Poblacion South Logo">
            <h1>Email Verification</h1>
            <p>eFIND System</p>
        </div>

        <div class="verify-section">
            <div class="branding">
                <img src="images/logo_pbsth.png" alt="eFIND Logo" class="efind-logo">
                <div class="brand-name">Verify Email</div>
                <p class="brand-subtitle">Account activation</p>
            </div>

            <?php if ($status === 'success' || $status === 'already'): ?>
                <div class="status-icon success"><i class="fas fa-check-circle"></i></div>
                <p class="status-message"><?php echo htmlspecialchars($message); ?></p>
                <a href="login.php" class="btn-login"><i class="fas fa-sign-in-alt me-2"></i>Go to Login</a>
            <?php elseif ($status === 'expired'): ?>
                <div class="status-icon error"><i class="fas fa-clock"></i></div>
                <p class="status-message"><?php echo htmlspecialchars($message); ?></p>
                <div class="back-link"><a href="register.php"><i class="fas fa-user-plus me-1"></i>Register again</a></div>
            <?php else: ?>
                <div class="status-icon error"><i class="fas fa-times-circle"></i></div>
                <p class="status-message"><?php echo htmlspecialchars($message); ?></p>
                <div class="back-link"><a href="login.php">Back to Login</a></div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const shapes = document.querySelectorAll('.shape');
        shapes.forEach(shape => {
            shape.style.animationDelay = `${Math.random() * 5}s`;
        });
    </script>
</body>
</html>

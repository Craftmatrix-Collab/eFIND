<?php
session_start();
include('includes/config.php');
include('includes/logger.php'); // Include the logger

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

// Redirect if already logged in
if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    // Sanitize inputs
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];

    // Basic validation
    if (empty($username) || empty($password)) {
        $error = "Both username and password are required.";
        logLoginAttempt($username, $user_ip, 'FAILED', 'Empty credentials');
        logActivity(null, 'failed_login', 'Login attempt with empty credentials', 'system', $user_ip, "Username: $username");
    } else {
        // Try admin login first
        $query = "SELECT id, username, password_hash, full_name, profile_picture FROM admin_users WHERE username = ?";
        $stmt = $conn->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password_hash'])) {
                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    // Set session variables for admin
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_username'] = $user['username'];
                    $_SESSION['admin_full_name'] = $user['full_name'];
                    $_SESSION['admin_profile_picture'] = $user['profile_picture'];
                    $_SESSION['admin_logged_in'] = true;

                    // Set these for compatibility
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = 'admin';
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['profile_picture'] = $user['profile_picture'];

                    // Log successful admin login
                    logLoginAttempt($username, $user_ip, 'SUCCESS', 'Admin login', $user['id'], 'admin');
                    logActivity($user['id'], 'login', 'Admin user logged in successfully', 'system', $user_ip, "Admin: {$user['full_name']}");

                    // Redirect to dashboard
                    header("Location: dashboard.php");
                    exit();
                } else {
                    // Log failed admin login attempt
                    logLoginAttempt($username, $user_ip, 'FAILED', 'Invalid password');
                    logActivity(null, 'failed_login', 'Invalid password for admin login', 'system', $user_ip, "Username: $username");
                    $error = "Invalid username or password.";
                }
            }
            $stmt->close();
        }

        // If admin login failed, try staff login using your users table
        $query = "SELECT id, username, password, full_name, profile_picture, role FROM users WHERE username = ?";
        $stmt = $conn->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                
                // Verify password (using the 'password' column from your table)
                if (password_verify($password, $user['password'])) {
                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    // Set session variables for staff
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['profile_picture'] = $user['profile_picture'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logged_in'] = true;

                    // For backward compatibility with admin sessions
                    $_SESSION['staff_id'] = $user['id'];
                    $_SESSION['staff_username'] = $user['username'];
                    $_SESSION['staff_full_name'] = $user['full_name'];
                    $_SESSION['staff_profile_picture'] = $user['profile_picture'];
                    $_SESSION['staff_role'] = $user['role'];
                    $_SESSION['staff_logged_in'] = true;

                    // Log successful staff login
                    logLoginAttempt($username, $user_ip, 'SUCCESS', 'Staff login', $user['id'], $user['role']);
                    logActivity($user['id'], 'login', 'User logged in successfully', 'system', $user_ip, "User: {$user['full_name']}, Role: {$user['role']}");

                    // Redirect to dashboard
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = "Invalid username or password.";
                    logLoginAttempt($username, $user_ip, 'FAILED', 'Invalid password');
                    logActivity(null, 'failed_login', 'Invalid password for user login', 'system', $user_ip, "Username: $username");
                }
            } else {
                $error = "Invalid username or password.";
                logLoginAttempt($username, $user_ip, 'FAILED', 'Username not found');
                logActivity(null, 'failed_login', 'Username not found', 'system', $user_ip, "Username: $username");
            }
            
            $stmt->close();
        } else {
            $error = "Database error. Please try again later.";
            logLoginAttempt($username, $user_ip, 'FAILED', 'Database error');
            logActivity(null, 'failed_login', 'Database error during login', 'system', $user_ip, "Username: $username");
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
function logActivity($user_id, $action, $description, $document_type = 'system', $ip_address = null, $details = null) {
    global $conn;
    
    try {
        $user_name = null;
        // If user_id is provided, get the username
        if ($user_id) {
            $user_query = "SELECT username FROM users WHERE id = ? UNION SELECT username FROM admin_users WHERE id = ?";
            $user_stmt = $conn->prepare($user_query);
            $user_stmt->bind_param("ii", $user_id, $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_result->num_rows > 0) {
                $user_data = $user_result->fetch_assoc();
                $user_name = $user_data['username'];
            }
            $user_stmt->close();
        }
        
        $ip_address = $ip_address ?: $_SERVER['REMOTE_ADDR'];
        
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_name, action, description, document_type, details, ip_address, log_time) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if ($stmt) {
            $stmt->bind_param("issssss", $user_id, $user_name, $action, $description, $document_type, $details, $ip_address);
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
            action VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            document_type VARCHAR(100) NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NOT NULL,
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
            overflow-x: hidden;
        }

        .login-wrapper {
            display: flex;
            background-color: var(--white);
            border-radius: 24px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
            max-width: 1100px;
            width: 100%;
            min-height: 650px;
            position: relative;
            z-index: 1;
            transition: transform 0.5s ease;
        }

        .login-wrapper:hover {
            transform: translateY(-5px);
        }

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
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            top: -100px;
            right: -100px;
        }

        .logo-section::after {
            content: "";
            position: absolute;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            bottom: -50px;
            left: -50px;
        }

        .logo-section img {
            max-width: 80%;
            height: auto;
            margin-bottom: 30px;
            z-index: 2;
            transition: transform 0.5s ease;
        }

        .logo-section:hover img {
            transform: scale(1.05);
        }

        .logo-section h1 {
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 2.5rem;
            z-index: 2;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .login-section {
            flex: 1;
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
        }

        .branding {
            margin-bottom: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .efind-logo {
            height: 120px;
            width: 120px;
            margin-bottom: 20px;
            object-fit: contain;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
            transition: all 0.3s ease;
        }

        .efind-logo:hover {
            transform: rotate(5deg) scale(1.1);
            filter: drop-shadow(0 6px 8px rgba(0, 0, 0, 0.15));
        }

        .brand-name {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            color: var(--secondary-blue);
            font-size: 2.8rem;
            margin-bottom: 5px;
            letter-spacing: 1px;
            position: relative;
            display: inline-block;
        }

        .brand-name::after {
            content: "";
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background: var(--accent-orange);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .brand-name:hover::after {
            width: 100px;
        }

        .brand-subtitle {
            color: var(--medium-gray);
            font-size: 1.1rem;
            font-style: italic;
            margin-top: 15px;
        }

        .login-form {
            width: 100%;
            max-width: 380px;
            position: relative;
        }

        .login-form::before {
            content: "";
            position: absolute;
            top: -20px;
            left: -20px;
            right: -20px;
            bottom: -20px;
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
            border: 2px solid #e9ecef;
            padding: 12px 20px;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: var(--light-gray);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            background-color: var(--white);
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-gray);
            font-size: 0.95rem;
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
            width: 6px;
            height: 6px;
            background: var(--primary-blue);
            border-radius: 50%;
        }

        .btn-login {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
            transition: all 0.3s;
            margin-top: 10px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
            letter-spacing: 0.5px;
        }

        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
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

        .btn-login:hover::before {
            left: 100%;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle-icon {
            position: absolute;
            right: 15px;
            top: 70%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--medium-gray);
            transition: all 0.2s;
            background: rgba(0, 0, 0, 0.05);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .password-toggle-icon:hover {
            color: var(--primary-blue);
            background: rgba(67, 97, 238, 0.1);
        }

        .forgot-password {
            text-align: center;
            margin-top: 5px;
            margin-bottom: 20px;
        }

        .forgot-password a {
            color: var(--medium-gray);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.2s;
            position: relative;
            padding-bottom: 2px;
        }

        .forgot-password a::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 1px;
            background: var(--primary-blue);
            transition: width 0.3s;
        }

        .forgot-password a:hover {
            color: var(--primary-blue);
        }

        .forgot-password a:hover::after {
            width: 100%;
        }

        .register-link {
            text-align: center;
            margin-top: 25px;
            color: var(--medium-gray);
            font-size: 0.95rem;
        }

        .register-link a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            position: relative;
        }

        .register-link a::after {
            content: "";
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent-orange);
            transition: width 0.3s;
        }

        .register-link a:hover {
            color: var(--secondary-blue);
        }

        .register-link a:hover::after {
            width: 100%;
        }

        .alert {
            margin-bottom: 20px;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.95rem;
            text-align: left;
            border-left: 4px solid #dc3545;
            background-color: rgba(220, 53, 69, 0.1);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .security-notice {
            background-color: rgba(67, 97, 238, 0.1);
            border-left: 4px solid var(--primary-blue);
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            text-align: center;
        }

        .security-notice i {
            color: var(--primary-blue);
            margin-right: 5px;
        }

        .floating-shapes {
            position: absolute;
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
            width: 100px;
            height: 100px;
            background: var(--primary-blue);
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            top: 10%;
            left: 10%;
            animation: float 15s infinite ease-in-out;
        }

        .shape-2 {
            width: 80px;
            height: 80px;
            background: var(--accent-orange);
            border-radius: 50%;
            bottom: 15%;
            right: 10%;
            animation: float 12s infinite ease-in-out reverse;
        }

        .shape-3 {
            width: 120px;
            height: 120px;
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

        @media (max-width: 768px) {
            .login-wrapper {
                flex-direction: column;
                min-height: auto;
            }
            
            .logo-section {
                padding: 30px 20px;
            }
            
            .login-section {
                padding: 40px 20px;
            }

            .brand-name {
                font-size: 2.2rem;
            }

            .efind-logo {
                height: 90px;
                width: 90px;
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
        </div>
        
        <div class="login-section">
            <div class="branding">
                <img src="images/logo_pbsth.png" alt="eFIND Logo" class="efind-logo">
                <div class="brand-name">Login Portal</div>
                <p class="brand-subtitle">Please enter your credentials to access the dashboard</p>
            </div>

            <div class="login-form">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="security-notice">
                    <i class="fas fa-shield-alt"></i>
                    All login attempts are logged for security purposes.
                </div>

                <form method="post" action="login.php">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" required
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>

                    <div class="mb-3 password-toggle">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <i class="fas fa-eye password-toggle-icon" id="togglePassword"></i>
                    </div>

                    <div class="forgot-password">
                        <a href="forgot-password.php">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn btn-login" name="login">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </button>

                    <div class="register-link">
                        Don't have an account? <a href="register.php">Request access</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Password toggle functionality
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this;
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });

        // Focus on username field when page loads
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();
            
            if (!username || !password) {
                e.preventDefault();
                alert('Both username and password are required!');
                return false;
            }
            
            return true;
        });

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
<?php
session_start();
include('includes/config.php');

$error = '';
$message = '';

// Validate token from GET
$token = trim($_GET['token'] ?? '');
$user_table_param = trim($_GET['table'] ?? 'admin_users');

// Whitelist allowed tables
$allowed_tables = ['admin_users', 'users'];
if (!in_array($user_table_param, $allowed_tables)) {
    $user_table_param = 'admin_users';
}

if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    header("Location: forgot-password.php");
    exit();
}

// Look up user by token
$lookup = $conn->prepare("SELECT id FROM $user_table_param WHERE reset_token = ? AND reset_expires > NOW()");
if (!$lookup) {
    header("Location: forgot-password.php");
    exit();
}
$lookup->bind_param("s", $token);
$lookup->execute();
$lookup_result = $lookup->get_result();
if ($lookup_result->num_rows !== 1) {
    header("Location: forgot-password.php?expired=1");
    exit();
}
$reset_user = $lookup_result->fetch_assoc();
$reset_user_id = $reset_user['id'];
$lookup->close();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($password) || empty($confirm_password)) {
        $error = "Both fields are required.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        // admin_users stores password in 'password_hash'; staff users table uses 'password'
        $pass_column = ($user_table_param === 'admin_users') ? 'password_hash' : 'password';
        
        // Update password and clear reset token
        $update_query = "UPDATE $user_table_param SET $pass_column = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        
        if ($stmt) {
            $stmt->bind_param("si", $hashed_password, $reset_user_id);
            
            if ($stmt->execute()) {
                // Set success message and redirect
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
        }
    </style>
</head>
<body>
    <div class="reset-wrapper">
        <div class="reset-icon">
            <i class="fas fa-key"></i>
        </div>

        <h2>Reset Password</h2>
        <p class="subtitle">Create a new strong password for your account</p>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="requirements">
            <strong>Password Requirements:</strong>
            <ul id="requirements">
                <li id="req-length">At least 8 characters</li>
                <li id="req-uppercase">One uppercase letter</li>
                <li id="req-lowercase">One lowercase letter</li>
                <li id="req-number">One number</li>
            </ul>
        </div>

        <form method="post" action="reset-password.php?token=<?php echo htmlspecialchars($token); ?>&table=<?php echo htmlspecialchars($user_table_param); ?>">
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
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const confirmPassword = document.getElementById('confirm_password');

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
            const value = this.value;
            const strengthBar = document.getElementById('strengthBar');
            
            let strength = 0;
            
            // Check length
            const lengthReq = document.getElementById('req-length');
            if (value.length >= 8) {
                strength++;
                lengthReq.classList.add('met');
            } else {
                lengthReq.classList.remove('met');
            }
            
            // Check uppercase
            const uppercaseReq = document.getElementById('req-uppercase');
            if (/[A-Z]/.test(value)) {
                strength++;
                uppercaseReq.classList.add('met');
            } else {
                uppercaseReq.classList.remove('met');
            }
            
            // Check lowercase
            const lowercaseReq = document.getElementById('req-lowercase');
            if (/[a-z]/.test(value)) {
                strength++;
                lowercaseReq.classList.add('met');
            } else {
                lowercaseReq.classList.remove('met');
            }
            
            // Check number
            const numberReq = document.getElementById('req-number');
            if (/[0-9]/.test(value)) {
                strength++;
                numberReq.classList.add('met');
            } else {
                numberReq.classList.remove('met');
            }
            
            // Update strength bar
            strengthBar.className = 'password-strength-bar';
            if (strength <= 2) {
                strengthBar.classList.add('password-strength-weak');
            } else if (strength === 3) {
                strengthBar.classList.add('password-strength-medium');
            } else if (strength === 4) {
                strengthBar.classList.add('password-strength-strong');
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const pass = password.value;
            const confirmPass = confirmPassword.value;
            
            if (pass.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
            
            if (pass !== confirmPass) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>

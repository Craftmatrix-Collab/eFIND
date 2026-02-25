<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration files
include(__DIR__ . '/includes/auth.php');
include(__DIR__ . '/includes/config.php');

// Check if user is logged in - redirect to login if not
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Fetch user details from the database
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
$is_admin = isset($_SESSION['admin_id']);
$table = $is_admin ? 'admin_users' : 'users';

$query = "SELECT * FROM $table WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header('Location: dashboard.php');
    exit();
}

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get success or error messages
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - eFIND System</title>
    <link rel="icon" type="image/png" href="images/eFind_logo.png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&display=swap" rel="stylesheet">
    
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
            position: relative;
            min-height: calc(100vh - 130px);
        }

        @media (max-width: 992px) {
            .management-container {
                margin-left: 0;
                padding: 15px;
                margin-bottom: 60px;
            }
        }

        .page-header {
            margin-bottom: 30px;
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
            bottom: -10px;
            width: 60px;
            height: 4px;
            background: var(--accent-orange);
            border-radius: 2px;
        }

        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: var(--white);
            border: none;
            padding: 1rem 1.5rem;
        }

        .card-body {
            padding: 2rem;
        }

        .profile-picture {
            width: 200px;
            height: 200px;
            object-fit: cover;
            border: 3px solid var(--primary-blue);
        }

        .profile-picture-placeholder {
            width: 200px;
            height: 200px;
            background-color: var(--light-gray);
            border: 3px solid var(--light-blue);
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
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

        .alert-container {
            min-width: 400px;
            max-width: 600px;
            animation: slideInDown 0.5s ease-out;
        }

        .alert {
            border: none;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border-left: 4px solid;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left-color: #dc3545;
        }

        @keyframes slideInDown {
            from {
                transform: translate(-50%, -100%);
                opacity: 0;
            }
            to {
                transform: translate(-50%, 0);
                opacity: 1;
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
    <?php include(__DIR__ . '/includes/sidebar.php'); ?>
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <div class="management-container">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Edit Profile</h1>
            </div>

            <!-- Alert Messages -->
            <?php if (!empty($success)): ?>
                <div class="alert-container position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">
                    <div class="alert alert-success alert-dismissible fade show shadow-lg" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle me-2 fs-5"></i>
                            <div class="fw-semibold"><?php echo $success; ?></div>
                            <button type="button" class="btn-close ms-3" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert-container position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">
                    <div class="alert alert-danger alert-dismissible fade show shadow-lg" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-circle me-2 fs-5"></i>
                            <div class="fw-semibold"><?php echo $error; ?></div>
                            <button type="button" class="btn-close ms-3" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Profile Edit Form -->
            <form id="editProfileForm" action="update_profile.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" id="original_email" value="<?php echo htmlspecialchars($user['email']); ?>">
                
                <div class="row">
                    <div class="col-lg-4">
                        <div class="card mb-4">
                            <div class="card-body text-center">
                                <div class="profile-picture-container mb-3">
                                    <?php
                                    $profile_picture_raw = trim((string)($user['profile_picture'] ?? ''));
                                    $profile_picture_src = '';
                                    if ($profile_picture_raw !== '') {
                                        if (preg_match('#^(https?:)?//#i', $profile_picture_raw) || stripos($profile_picture_raw, 'data:image/') === 0) {
                                            $profile_picture_src = $profile_picture_raw;
                                        } else {
                                            $profile_picture_src = 'uploads/profiles/' . basename($profile_picture_raw);
                                        }
                                        if (strpos($profile_picture_src, 'data:') !== 0) {
                                            $profile_picture_src .= (strpos($profile_picture_src, '?') === false ? '?t=' : '&t=') . time();
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($profile_picture_src)): ?>
                                        <img id="profileImagePreview"
                                             src="<?php echo htmlspecialchars($profile_picture_src); ?>"
                                             class="img-thumbnail rounded-circle profile-picture"
                                             alt="Profile Picture"
                                             onerror="this.onerror=null;this.src='images/eFind_logo.png';">
                                    <?php else: ?>
                                        <div id="profileImagePreview" class="profile-picture-placeholder rounded-circle d-flex align-items-center justify-content-center mx-auto">
                                            <i class="fas fa-user fa-4x text-secondary"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-3">
                                    <label for="profile_picture" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-camera me-2"></i>Change Photo
                                        <input type="file" id="profile_picture" name="profile_picture"
                                               accept="image/*" class="d-none">
                                    </label>
                                    <small class="text-muted d-block">JPEG or PNG, max 2MB</small>
                                </div>
                                <div class="alert alert-warning small mb-0">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Recommended size: 200x200 pixels
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profile Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="full_name" name="full_name"
                                               value="<?php echo htmlspecialchars($user['full_name'] ?? $user['name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="username" name="username"
                                               value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="email" class="form-control" id="email" name="email"
                                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                            <button class="btn btn-outline-primary" type="button" id="profileSendOtpBtn">
                                                <i class="fas fa-paper-plane me-1"></i> Send OTP
                                            </button>
                                        </div>
                                        <div id="profileEmailVerifiedBadge" class="mt-1 d-none">
                                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Email Verified</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="contact_number" class="form-label">Contact Number</label>
                                        <input type="tel" class="form-control" id="contact_number" name="contact_number"
                                               value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>">
                                        <small class="text-muted">Format: +639XXXXXXXXX or 09XXXXXXXXX</small>
                                    </div>
                                </div>
                                <div class="row" id="profileEmailOtpSection" style="display:none !important">
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Enter OTP <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="profileEmailOtpInput" maxlength="6" placeholder="6-digit OTP code" inputmode="numeric">
                                            <button class="btn btn-outline-success" type="button" id="profileVerifyEmailOtpBtn">
                                                <i class="fas fa-check me-1"></i> Verify
                                            </button>
                                        </div>
                                        <small class="text-muted" id="profileEmailOtpTimer"></small>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info mt-4">
                                    <i class="fas fa-info-circle me-2"></i>
                                    To change your password, please contact the administrator or use the forgot password feature.
                                </div>

                                <div class="d-flex gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary-custom" id="saveProfileSubmitBtn">
                                        <i class="fas fa-save me-1"></i> Save Changes
                                    </button>
                                    <a href="dashboard.php" class="btn btn-secondary-custom">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Preview profile picture before upload
        document.getElementById('profile_picture').addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                // Validate file size
                if (file.size > 2 * 1024 * 1024) { // 2MB
                    alert('File size exceeds 2MB limit. Please choose a smaller file.');
                    this.value = '';
                    return;
                }
                
                // Validate file type
                const validTypes = ['image/jpeg', 'image/png'];
                if (!validTypes.includes(file.type)) {
                    alert('Only JPEG and PNG files are allowed.');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('profileImagePreview');
                    if (preview.tagName === 'IMG') {
                        preview.src = e.target.result;
                    } else {
                        // Replace the div with an img element
                        const img = document.createElement('img');
                        img.id = 'profileImagePreview';
                        img.src = e.target.result;
                        img.className = 'img-thumbnail rounded-circle profile-picture';
                        img.alt = 'Profile Preview';
                        preview.parentNode.replaceChild(img, preview);
                    }
                };
                reader.readAsDataURL(file);
            }
        });

        // Email verification OTP for changed email
        (function () {
            const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
            const emailInput = document.getElementById('email');
            const originalEmailInput = document.getElementById('original_email');
            const sendOtpBtn = document.getElementById('profileSendOtpBtn');
            const otpSection = document.getElementById('profileEmailOtpSection');
            const otpInput = document.getElementById('profileEmailOtpInput');
            const verifyOtpBtn = document.getElementById('profileVerifyEmailOtpBtn');
            const verifiedBadge = document.getElementById('profileEmailVerifiedBadge');
            const otpTimerEl = document.getElementById('profileEmailOtpTimer');
            const saveBtn = document.getElementById('saveProfileSubmitBtn');
            let otpTimer = null;
            let verifiedEmail = '';

            if (!emailInput || !originalEmailInput || !sendOtpBtn || !otpSection || !otpInput || !verifyOtpBtn || !otpTimerEl || !saveBtn) {
                return;
            }

            function normalizedEmail(value) {
                return (value || '').trim().toLowerCase();
            }

            function emailChanged() {
                return normalizedEmail(emailInput.value) !== normalizedEmail(originalEmailInput.value);
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

            function syncSaveState() {
                if (!emailChanged()) {
                    saveBtn.disabled = false;
                    return;
                }
                saveBtn.disabled = normalizedEmail(verifiedEmail) !== normalizedEmail(emailInput.value);
            }

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
                syncSaveState();
            });

            sendOtpBtn.addEventListener('click', function () {
                const email = emailInput.value.trim();
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    alert('Please enter a valid email address first.');
                    return;
                }
                if (!emailChanged()) {
                    alert('Email is unchanged. OTP is not required.');
                    syncSaveState();
                    return;
                }

                sendOtpBtn.disabled = true;
                sendOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
                verifiedEmail = '';
                syncSaveState();

                fetch('update_profile.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        action: 'send_profile_verify_otp',
                        email: email,
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
                        alert(data.message);
                    } else {
                        alert(data.message);
                    }
                    syncSaveState();
                })
                .catch(err => {
                    sendOtpBtn.disabled = false;
                    sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send OTP';
                    alert(err.message || 'Network error. Please try again.');
                    syncSaveState();
                });
            });

            verifyOtpBtn.addEventListener('click', function () {
                const email = emailInput.value.trim();
                const otp = otpInput.value.trim();
                if (!emailChanged()) {
                    alert('Email is unchanged. OTP verification is not required.');
                    syncSaveState();
                    return;
                }
                if (!otp || !/^\d{6}$/.test(otp)) {
                    alert('Please enter the 6-digit OTP.');
                    return;
                }

                verifyOtpBtn.disabled = true;
                verifyOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';

                fetch('update_profile.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        action: 'check_profile_verify_otp',
                        email: email,
                        otp: otp,
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
                        alert('Email verified! You can now save your profile.');
                    } else {
                        alert(data.message);
                    }
                    syncSaveState();
                })
                .catch(err => {
                    verifyOtpBtn.disabled = false;
                    verifyOtpBtn.innerHTML = '<i class="fas fa-check me-1"></i> Verify';
                    alert(err.message || 'Network error. Please try again.');
                    syncSaveState();
                });
            });

            hideOtpSection();
            syncSaveState();
        })();

        // Form validation
        document.getElementById('editProfileForm').addEventListener('submit', function(e) {
            const contactNumber = document.getElementById('contact_number').value.trim();
            
            if (contactNumber && !/^(\+63|0)9\d{9}$/.test(contactNumber.replace(/\s/g, ''))) {
                alert('Please enter a valid Philippine mobile number (e.g., +639XXXXXXXXX or 09XXXXXXXXX)');
                e.preventDefault();
                return false;
            }
            
            const email = document.getElementById('email').value.trim();
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address');
                e.preventDefault();
                return false;
            }

            const originalEmail = (document.getElementById('original_email')?.value || '').trim().toLowerCase();
            const emailChanged = email.toLowerCase() !== originalEmail;
            const verifiedBadge = document.getElementById('profileEmailVerifiedBadge');
            const isVerified = verifiedBadge && !verifiedBadge.classList.contains('d-none');
            if (emailChanged && !isVerified) {
                alert('Please verify your updated email address before saving changes.');
                e.preventDefault();
                return false;
            }
        });

        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });

        // Remove alert container when alert is closed
        document.addEventListener('closed.bs.alert', function (event) {
            const alertContainer = event.target.closest('.alert-container');
            if (alertContainer) {
                setTimeout(() => {
                    alertContainer.remove();
                }, 300);
            }
        });
    </script>

    <footer class="bg-white p-3">
        <?php include(__DIR__ . '/includes/footer.php'); ?>
    </footer>
    
    <!-- AI Chatbot Widget -->
    <?php include(__DIR__ . '/includes/chatbot_widget.php'); ?>
</body>
</html>

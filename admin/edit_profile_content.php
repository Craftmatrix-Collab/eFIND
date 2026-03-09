<?php
require_once __DIR__ . '/includes/auth.php';
include_once __DIR__ . '/includes/config.php';

if (!isLoggedIn()) {
    die('<div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>Unauthorized access. Please login first.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
         </div>');
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Determine user type and fetch data
try {
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
    $is_admin = isset($_SESSION['admin_id']);
    $table = $is_admin ? 'admin_users' : 'users';
    $query = "SELECT id, full_name, username, email, contact_number, profile_picture
              FROM $table
              WHERE id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare profile query.');
    }

    if (!$stmt->bind_param("i", $user_id)) {
        throw new RuntimeException('Unable to bind profile query parameters.');
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to execute profile query.');
    }

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    if (!$user) {
        throw new Exception("Profile not found.");
    }
} catch (Throwable $e) {
    error_log('Edit Profile Load Exception: ' . $e->getMessage());
    $publicMessage = $e->getMessage() === 'Profile not found.'
        ? 'Profile not found.'
        : 'Unable to load edit form right now. Please refresh and try again.';
    die('<div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>' . htmlspecialchars($publicMessage) . '
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
         </div>');
}
?>
<form id="editProfileForm" enctype="multipart/form-data">
    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" id="original_email" value="<?php echo htmlspecialchars($user['email']); ?>">
    <div class="row">
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-body text-center">
                    <h6 class="profile-photo-title">Profile Photo</h6>
                    <p class="profile-photo-subtitle">Choose a clear square image for best quality.</p>
                    <div class="profile-picture-container mb-3">
                        <?php
                        $profile_picture_raw = trim((string)($user['profile_picture'] ?? ''));
                        $profile_picture_src = 'images/profile.jpg';
                        if ($profile_picture_raw !== '') {
                            if (preg_match('#^(https?:)?//#i', $profile_picture_raw) || stripos($profile_picture_raw, 'data:image/') === 0) {
                                $profile_picture_src = $profile_picture_raw;
                            } elseif (preg_match('#^/?images/#i', $profile_picture_raw)) {
                                $profile_picture_src = ltrim($profile_picture_raw, '/');
                            } else {
                                $profile_picture_src = 'uploads/profiles/' . basename($profile_picture_raw);
                            }
                        }
                        if (strpos($profile_picture_src, 'data:') !== 0 && !preg_match('#^images/#i', $profile_picture_src)) {
                            $profile_picture_src .= (strpos($profile_picture_src, '?') === false ? '?t=' : '&t=') . time();
                        }
                        ?>
                        <img id="profileImagePreview"
                             src="<?php echo htmlspecialchars($profile_picture_src); ?>"
                             class="img-thumbnail rounded-circle profile-picture"
                             alt="Profile Picture"
                             onerror="this.onerror=null;this.src='images/profile.jpg';">
                    </div>
                    <div class="mb-3">
                        <label for="profile_picture" class="btn btn-outline-primary btn-change-photo w-100">
                            <i class="fas fa-camera me-2"></i>Change Photo
                            <input type="file" id="profile_picture" name="profile_picture"
                                   accept="image/*" class="d-none">
                        </label>
                        <small class="text-muted d-block">JPEG or PNG, max 2MB</small>
                    </div>
                    <div class="profile-photo-note small mb-0 p-2">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Recommended size: 200x200 pixels
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profile Information</h5>
                </div>
                <div class="card-body">
                    <p class="form-intro">Keep your account details updated and verify email changes using OTP before saving.</p>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <div class="field-shell">
                                <span class="field-icon"><i class="fas fa-id-card"></i></span>
                                <input type="text" class="form-control" id="full_name" name="full_name"
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <div class="field-shell">
                                <span class="field-icon"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control" id="username" name="username"
                                       value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <div class="input-group profile-email-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
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
                            <div class="field-shell">
                                <span class="field-icon"><i class="fas fa-phone"></i></span>
                                <input type="tel" class="form-control" id="contact_number" name="contact_number"
                                       value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>">
                            </div>
                            <small class="text-muted">Format: +639XXXXXXXXX or 09XXXXXXXXX</small>
                        </div>
                    </div>
                    <div class="row" id="profileEmailOtpSection" style="display:none !important">
                        <div class="col-12 mb-3">
                            <div class="otp-verification-box">
                                <label class="form-label">Enter OTP <span class="text-danger">*</span></label>
                                <div class="input-group profile-email-group">
                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    <input type="text" class="form-control" id="profileEmailOtpInput" maxlength="6" placeholder="6-digit OTP code" inputmode="numeric">
                                    <button class="btn btn-outline-success" type="button" id="profileVerifyEmailOtpBtn">
                                        <i class="fas fa-check me-1"></i> Verify
                                    </button>
                                </div>
                                <small class="text-muted" id="profileEmailOtpTimer"></small>
                            </div>
                        </div>
                    </div>
                    <div class="profile-static-note mt-4 p-3">
                        <i class="fas fa-info-circle me-2"></i>
                        To change your password, please use the <a href="#" class="alert-link" data-bs-toggle="modal" data-bs-target="#changePasswordModal">password change form</a>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
<style>
    .profile-picture {
        width: 200px;
        height: 200px;
        object-fit: cover;
        border: 3px solid #4361ee;
        box-shadow: 0 10px 24px rgba(41, 58, 130, 0.24);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .profile-picture-placeholder {
        width: 200px;
        height: 200px;
        background-color: #f8f9fa;
        border: 3px solid #dee2e6;
    }
    .profile-picture:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 28px rgba(41, 58, 130, 0.3);
    }
    .profile-photo-title {
        font-weight: 700;
        color: #3a0ca3;
        margin-bottom: 4px;
    }
    .profile-photo-subtitle {
        font-size: 0.85rem;
        color: #6f7897;
        margin-bottom: 14px;
    }
    .btn-change-photo {
        border-radius: 10px;
        border-color: rgba(67, 97, 238, 0.45);
        color: #4361ee;
        font-weight: 600;
    }
    .btn-change-photo:hover {
        background: #4361ee;
        color: #fff;
        border-color: #4361ee;
    }
    .form-label {
        font-weight: 500;
    }
    .form-intro {
        color: #5f6988;
        font-size: 0.9rem;
        margin-bottom: 16px;
    }
    .field-shell {
        position: relative;
    }
    .field-shell .field-icon {
        position: absolute;
        left: 13px;
        top: 50%;
        transform: translateY(-50%);
        color: #7780a8;
        font-size: 0.92rem;
        pointer-events: none;
    }
    .field-shell .form-control {
        padding-left: 38px;
    }
    .field-shell:focus-within .field-icon {
        color: #4361ee;
    }
    .profile-email-group .input-group-text {
        border: 1px solid #dee2f2;
        border-right: 0;
        border-radius: 10px 0 0 10px;
        background: #f4f7ff;
        color: #6b76a8;
    }
    .profile-email-group .form-control {
        border-left: 0;
    }
    .profile-email-group .btn {
        border-radius: 0 10px 10px 0;
        font-weight: 600;
    }
    .otp-verification-box {
        border: 1px dashed rgba(67, 97, 238, 0.45);
        border-radius: 12px;
        background: rgba(232, 240, 254, 0.45);
        padding: 14px;
    }
    .profile-photo-note {
        border-radius: 10px;
        border: 1px solid rgba(255, 193, 7, 0.32);
        background: rgba(255, 193, 7, 0.12);
    }
    .profile-static-note {
        border-radius: 10px;
        border: 1px solid rgba(13, 202, 240, 0.24);
        background: rgba(13, 202, 240, 0.08);
    }
    .card-header h5 {
        font-weight: 600;
    }
</style>
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
    const saveBtn = document.getElementById('saveProfileChanges') || document.querySelector('#editProfileForm button[type="submit"]');
    let otpTimer = null;
    let verifiedEmail = '';

    if (!emailInput || !originalEmailInput || !sendOtpBtn || !otpSection || !otpInput || !verifyOtpBtn || !otpTimerEl) {
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

    function notify(message, type) {
        if (typeof showToast === 'function') {
            showToast(message, type === 'success' ? 'success' : 'error');
        } else {
            alert(message);
        }
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
        if (!saveBtn) {
            return;
        }
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
            notify('Please enter a valid email address first.', 'danger');
            return;
        }
        if (!emailChanged()) {
            notify('Email is unchanged. OTP is not required.', 'danger');
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
                notify(data.message, 'success');
            } else {
                notify(data.message, 'danger');
            }
            syncSaveState();
        })
        .catch(err => {
            sendOtpBtn.disabled = false;
            sendOtpBtn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send OTP';
            notify(err.message || 'Network error. Please try again.', 'danger');
            syncSaveState();
        });
    });

    verifyOtpBtn.addEventListener('click', function () {
        const email = emailInput.value.trim();
        const otp = otpInput.value.trim();
        if (!emailChanged()) {
            notify('Email is unchanged. OTP verification is not required.', 'danger');
            syncSaveState();
            return;
        }
        if (!otp || !/^\d{6}$/.test(otp)) {
            notify('Please enter the 6-digit OTP.', 'danger');
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
                notify('Email verified! You can now save your profile.', 'success');
            } else {
                notify(data.message, 'danger');
            }
            syncSaveState();
        })
        .catch(err => {
            verifyOtpBtn.disabled = false;
            verifyOtpBtn.innerHTML = '<i class="fas fa-check me-1"></i> Verify';
            notify(err.message || 'Network error. Please try again.', 'danger');
            syncSaveState();
        });
    });

    hideOtpSection();
    syncSaveState();
})();
// Phone number formatting
document.getElementById('contact_number').addEventListener('input', function(e) {
    // Remove all non-digit characters
    let value = this.value.replace(/\D/g, '');
    // Format based on input
    if (value.startsWith('63') && value.length > 2) {
        this.value = '+' + value.substring(0, 3) + ' ' + value.substring(3, 6) + ' ' +
                     value.substring(6, 9) + ' ' + value.substring(9, 13);
    } else if (value.startsWith('0') && value.length > 1) {
        this.value = value.substring(0, 1) + ' ' + value.substring(1, 5) + ' ' +
                     value.substring(5, 9) + ' ' + value.substring(9, 13);
    } else {
        this.value = value;
    }
});
</script>

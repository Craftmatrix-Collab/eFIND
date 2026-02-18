<?php
// Start session and check authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug: Log session state (remove in production)
error_log("Profile Access - Session ID: " . session_id());
error_log("Profile Access - admin_id: " . (isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : 'NOT SET'));
error_log("Profile Access - user_id: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    error_log("Profile Access DENIED - No valid session found");
    die('<div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <strong>Unauthorized Access</strong><br>
            Your session may have expired. Please <a href="login.php" class="alert-link">login again</a>.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
         </div>');
}

// Include database connection
include 'includes/config.php';

// Determine user type and fetch data
try {
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
    $is_admin = isset($_SESSION['admin_id']);
    $table = $is_admin ? 'admin_users' : 'users';
    
    // Debug logging
    error_log("Fetching profile - User ID: $user_id, Table: $table, Is Admin: " . ($is_admin ? 'YES' : 'NO'));
    
    // Check database connection
    if (!$conn || !$conn->ping()) {
        throw new Exception("Database connection lost. Please try again.");
    }
    
    $query = "SELECT id, full_name, username, email, contact_number, profile_picture, last_login, created_at, updated_at, password_changed_at
              FROM $table
              WHERE id = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        error_log("Profile Query Prepare Failed: " . $conn->error);
        throw new Exception("Database query error. Please contact administrator.");
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        error_log("Profile NOT FOUND for user_id: $user_id in table: $table");
        throw new Exception("Profile not found. Your account may have been deleted.");
    }
    
    error_log("Profile loaded successfully for user: " . $user['username']);
    
} catch (Exception $e) {
    error_log("Profile Load Exception: " . $e->getMessage());
    die('<div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
         </div>');
}
?>
<div class="row">
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-body text-center">
                <div class="profile-picture-container mb-3">
                <?php if (!empty($user['profile_picture']) && file_exists("uploads/profiles/" . $user['profile_picture'])): ?>
                    <img src="uploads/profiles/<?php echo htmlspecialchars($user['profile_picture']); ?>"
                        class="img-thumbnail rounded-circle profile-picture"
                        alt="Profile Picture"
                        onerror="this.onerror=null; this.src='assets/img/default-profile.png';">
                <?php else: ?>
                    <div class="profile-picture-placeholder rounded-circle d-flex align-items-center justify-content-center">
                        <i class="fas fa-user fa-4x text-secondary"></i>
                    </div>
                <?php endif; ?>
            </div>
                <h4 class="mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h4>
                <p class="text-muted mb-3"><?php echo $is_admin ? 'Administrator' : 'Staff'; ?></p>
                <div class="card bg-light p-3">
                    <div class="d-flex justify-content-between small">
                        <span>Member since:</span>
                        <span class="text-primary"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></span>
                    </div>
                    <div class="d-flex justify-content-between small">
    <span>Last active:</span>
    <span class="text-primary">
        <?php echo !empty($user['last_login']) ? date('M d, Y h:i A', strtotime($user['last_login'])) : 'Never logged in'; ?>
    </span>
</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-user-circle me-2"></i>Personal Information</h5>
                <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                    <i class="fas fa-edit"></i>
                </button>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <h6 class="small text-muted mb-1">Full Name</h6>
                        <p><?php echo htmlspecialchars($user['full_name']); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6 class="small text-muted mb-1">Username</h6>
                        <p><?php echo htmlspecialchars($user['username']); ?></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <h6 class="small text-muted mb-1">Email Address</h6>
                        <p><?php echo htmlspecialchars($user['email']); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6 class="small text-muted mb-1">Contact Number</h6>
                        <p><?php echo !empty($user['contact_number']) ? htmlspecialchars($user['contact_number']) : 'Not set'; ?></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <h6 class="small text-muted mb-1">Account Created</h6>
                        <p><?php echo date('F j, Y, g:i a', strtotime($user['created_at'])); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6 class="small text-muted mb-1">Last Updated</h6>
                        <p><?php echo date('F j, Y, g:i a', strtotime($user['updated_at'])); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Account Security</h5>
            </div>
            <div class="card-body">
                <div class="row align-items-center mb-3">
                    <div class="col-md-8">
                        <h6 class="mb-1">Password</h6>
                        <p class="small text-muted mb-0">Last changed:
                            <?php echo !empty($user['password_changed_at']) ?
                                 date('M j, Y', strtotime($user['password_changed_at'])) : 'Unknown'; ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-end">
                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                            <i class="fas fa-key me-1"></i> Change Password
                        </button>
                    </div>
                </div>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i> For security reasons, we recommend changing your password every 90 days.
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="changePasswordModalLabel"><i class="fas fa-key me-2"></i>Change Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="passwordChangeForm" action="update_password.php" method="post">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="currentPassword" class="form-label">Current Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="newPassword" class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="newPassword" name="new_password" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Minimum 8 characters with at least one number and one special character</div>
                    </div>
                    <div class="mb-3">
                        <label for="confirmPassword" class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
<style>
    .profile-picture {
        width: 180px;
        height: 180px;
        object-fit: cover;
        border: 3px solid #4361ee;
    }
    .profile-picture-placeholder {
        width: 180px;
        height: 180px;
        background-color: #f8f9fa;
        border: 3px solid #dee2e6;
    }
    .card-header h5 {
        font-weight: 600;
    }
    .toggle-password {
        border-left: none;
    }
    .toggle-password:hover {
        background-color: #e9ecef;
    }
</style>
<script>
// Toggle password visibility
document.querySelectorAll('.toggle-password').forEach(button => {
    button.addEventListener('click', function() {
        const input = this.parentNode.querySelector('input');
        const icon = this.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});
// Password change form validation
document.getElementById('passwordChangeForm').addEventListener('submit', function(e) {
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    // Basic validation
    if (newPassword.length < 8) {
        e.preventDefault();
        alert('Password must be at least 8 characters long.');
        return false;
    }
    if (!/[0-9]/.test(newPassword) || !/[^A-Za-z0-9]/.test(newPassword)) {
        e.preventDefault();
        alert('Password must contain at least one number and one special character.');
        return false;
    }
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('New passwords do not match.');
        return false;
    }
    return true;
});
</script>

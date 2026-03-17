<?php
// Fetch profile data directly for the modal (no AJAX needed)
require_once __DIR__ . '/password_policy.php';
require_once __DIR__ . '/profile_picture_helper.php';
$_navbar_password_policy = getPasswordPolicyClientConfig();
$_navbar_profile = null;
$_navbar_last_active = null;
$_navbar_password_changed = null;
$_navbar_role_label = 'Staff';
$_navbar_session_role = strtolower((string)($_SESSION['role'] ?? ($_SESSION['staff_role'] ?? '')));
if ($_navbar_session_role === 'superadmin' || (function_exists('isSuperAdmin') && isSuperAdmin())) {
    $_navbar_role_label = 'Superadmin';
} elseif (isset($_SESSION['admin_id']) || in_array($_navbar_session_role, ['admin', 'administrator'], true)) {
    $_navbar_role_label = 'Administrator';
} elseif ($_navbar_session_role !== '') {
    $_navbar_role_label = ucwords(str_replace('_', ' ', $_navbar_session_role));
}
$_navbar_delete_confirmation_word = 'STAFF';
if ($_navbar_session_role === 'superadmin' || (function_exists('isSuperAdmin') && isSuperAdmin())) {
    $_navbar_delete_confirmation_word = 'SUPERADMIN';
} elseif (isset($_SESSION['admin_id']) || in_array($_navbar_session_role, ['admin', 'administrator'], true)) {
    $_navbar_delete_confirmation_word = 'ADMIN';
}
if (isset($conn) && (isset($_SESSION['admin_id']) || isset($_SESSION['user_id']))) {
    $_navbar_uid   = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
    $_navbar_table = 'users';
    $_navbar_select_fields = "id, full_name, username, email, contact_number, profile_picture, last_login, created_at, updated_at, COALESCE(email_verified, 0) AS is_verified";
    $_navbar_stmt  = $conn->prepare("SELECT {$_navbar_select_fields} FROM {$_navbar_table} WHERE id = ?");
    if ($_navbar_stmt) {
        $_navbar_stmt->bind_param("i", $_navbar_uid);
        $_navbar_stmt->execute();
        $_navbar_profile = $_navbar_stmt->get_result()->fetch_assoc();
        $_navbar_stmt->close();
    }

    if ($_navbar_profile && function_exists('getAccountLastActiveTimestamp')) {
        $_navbar_last_active = getAccountLastActiveTimestamp(
            $conn,
            isset($_SESSION['admin_id']) ? 'admin' : 'staff',
            (int)$_navbar_profile['id'],
            $_navbar_profile['last_login'] ?? null
        );
    } elseif (!empty($_navbar_profile['last_login'])) {
        $_navbar_last_active = $_navbar_profile['last_login'];
    }

    if ($_navbar_profile && function_exists('getAccountPasswordChangedTimestamp')) {
        $_navbar_password_changed = getAccountPasswordChangedTimestamp(
            $conn,
            isset($_SESSION['admin_id']) ? 'admin' : 'staff',
            (int)$_navbar_profile['id'],
            $_navbar_profile['created_at'] ?? null
        );
    } elseif (!empty($_navbar_profile['created_at'])) {
        $_navbar_password_changed = $_navbar_profile['created_at'];
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top" style="background-color: #1a3a8f; margin-left: 250px; margin-top: 0; z-index: 1050;">
    <div class="container-fluid">
        <!-- Logo and Address -->
        <div class="d-flex align-items-center">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img
                    src="images/eFind_logo5.png"
                    alt="Barangay Poblacion South Logo"
                    height="50"
                    class="d-inline-block align-top me-2"
                    style="object-fit: contain; width: auto;"
                >
                <div class="d-flex flex-column">
                    <span class="text-white fw-bold" style="font-size: 1.1rem;">eFIND: Electronic Full-text Integrated Navigation for Documents</span>
                    <small class="text-white-50" style="font-size: 0.75rem;">
                       A Document Archive: Faster and Reliable Search
                    </small>
                </div>
            </a>
        </div>

        <!-- Profile Dropdown -->
        <ul class="navbar-nav ms-auto">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="cursor: pointer;">
                   <?php
            // Check if session variables exist before using them
            $full_name = $_SESSION['full_name'] ?? 'Admin';
            $profile_path = efind_resolve_profile_picture_src($_SESSION['profile_picture'] ?? '');
            echo '<img src="' . htmlspecialchars($profile_path) . '" alt="Profile Picture" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;" onerror="this.onerror=null;this.src=\'images/profile.jpg\';">';
            ?>
                    <span class="text-white"><?php echo htmlspecialchars($full_name); ?></span>
                </a>
                <?php
                // Generate security tokens if missing
                if (!isset($_SESSION['logout_token'])) {
                    $_SESSION['logout_token'] = bin2hex(random_bytes(32));
                }
                if (!isset($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
                $canAccessDocumentBackup = function_exists('isSuperAdmin') && isSuperAdmin();
                ?>
                <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="profileDropdown">
                    <li><a class="dropdown-item" href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#helpModal"><i class="fas fa-circle-question me-2"></i>Help</a></li>
                    <?php if ($canAccessDocumentBackup): ?>
                    <li>
                        <form method="post" action="backup_documents.php" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <button type="submit" class="dropdown-item">
                                <i class="fas fa-database me-2" aria-hidden="true"></i>Backup Documents
                            </button>
                        </form>
                    </li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php?token=<?php echo urlencode($_SESSION['logout_token']); ?>" aria-label="Logout from your account"><i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i>Logout</a></li>
                </ul>
            </li>
        </ul>
    </div>
</nav>

<!-- Toast Container for Notifications -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 11000;">
    <div id="toastContainer"></div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="helpModalLabel"><i class="fas fa-circle-question me-2"></i>Help</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Need help with the system? Message a developer directly via Gmail:</p>
                <ul class="mb-0">
                    <li><a href="https://mail.google.com/mail/?view=cm&fs=1&to=eys.acads@gmail.com" target="_blank" rel="noopener">eys.acads@gmail.com</a></li>
                    <li><a href="https://mail.google.com/mail/?view=cm&fs=1&to=sierra.pacilan1@gmail.com" target="_blank" rel="noopener">sierra.pacilan1@gmail.com</a></li>
                    <li><a href="https://mail.google.com/mail/?view=cm&fs=1&to=erwinbartolome4@gmail.com" target="_blank" rel="noopener">erwinbartolome4@gmail.com</a></li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Profile View Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="profileModalLabel">User Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="profileModalBody">
                <?php if ($_navbar_profile): ?>
                <?php
                $_np = $_navbar_profile;
                $_np_last_active = $_navbar_last_active;
                $_np_password_changed = $_navbar_password_changed;
                $_np_role_label = $_navbar_role_label;
                $_np_is_email_verified = isset($_np['is_verified']) && (int)$_np['is_verified'] === 1;
                ?>
                <div class="row">
                    <div class="col-lg-4">
                        <div class="card mb-4">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <?php
                                    $_np_profile_path = efind_resolve_profile_picture_src($_np['profile_picture'] ?? '');
                                    ?>
                                    <img src="<?php echo htmlspecialchars($_np_profile_path); ?>"
                                         class="img-thumbnail rounded-circle"
                                         style="width:150px;height:150px;object-fit:cover;border:3px solid #4361ee;"
                                         alt="Profile Picture"
                                         onerror="this.onerror=null;this.src='images/profile.jpg';">
                                </div>
                                <h4 class="mb-1"><?php echo htmlspecialchars($_np['full_name']); ?></h4>
                                <p class="text-muted mb-3"><?php echo htmlspecialchars($_np_role_label); ?></p>
                                <div class="card bg-light p-3">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span>Member since:</span>
                                        <span class="text-primary"><?php echo date('F j, Y', strtotime($_np['created_at'])); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between small">
                                        <span>Last active:</span>
                                        <span class="text-primary"><?php echo !empty($_np_last_active) ? date('M d, Y h:i A', strtotime($_np_last_active)) : 'Never'; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-user-circle me-2"></i>Personal Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <h6 class="small text-muted mb-1">Full Name</h6>
                                        <p><?php echo htmlspecialchars($_np['full_name']); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6 class="small text-muted mb-1">Username</h6>
                                        <p><?php echo htmlspecialchars($_np['username']); ?></p>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <h6 class="small text-muted mb-1">Email Address</h6>
                                        <p class="d-flex align-items-center gap-2 mb-0">
                                            <span><?php echo htmlspecialchars($_np['email']); ?></span>
                                            <?php if ($_np_is_email_verified): ?>
                                                <span class="text-success" title="Email Verified"><i class="fas fa-check-circle"></i></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6 class="small text-muted mb-1">Contact Number</h6>
                                        <p><?php echo !empty($_np['contact_number']) ? htmlspecialchars($_np['contact_number']) : 'Not set'; ?></p>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <h6 class="small text-muted mb-1">Account Created</h6>
                                        <p><?php echo date('F j, Y, g:i a', strtotime($_np['created_at'])); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <h6 class="small text-muted mb-1">Last Updated</h6>
                                        <p><?php echo date('F j, Y, g:i a', strtotime($_np['updated_at'])); ?></p>
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
                                        <p class="small text-muted mb-0">Last changed: <?php echo !empty($_np_password_changed) ? date('M d, Y h:i A', strtotime($_np_password_changed)) : 'Not available'; ?></p>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#changePasswordModal" data-bs-dismiss="modal">
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
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Could not load profile. Please <a href="login.php">login again</a>.
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteSelfProfileModal" data-bs-dismiss="modal">
                    <i class="fas fa-trash-alt me-1"></i>Delete
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal" data-bs-dismiss="modal">Edit Profile</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Self Profile Modal -->
<div class="modal fade" id="deleteSelfProfileModal" tabindex="-1" aria-labelledby="deleteSelfProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteSelfProfileModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Delete Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">This will permanently delete your profile and immediately sign you out.</p>
                <p class="mb-2">
                    Type <span class="fw-bold text-danger"><?php echo htmlspecialchars($_navbar_delete_confirmation_word); ?></span> to confirm.
                </p>
                <input
                    type="text"
                    class="form-control"
                    id="selfDeleteConfirmationInput"
                    placeholder="Type <?php echo htmlspecialchars($_navbar_delete_confirmation_word); ?>"
                    autocomplete="off"
                    spellcheck="false">
                <div id="selfDeleteError" class="alert alert-danger d-none mt-3 mb-0" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmSelfDeleteBtn" disabled>Delete Profile</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editProfileModalLabel">Edit Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="editProfileModalBody">
                <!-- Content will be loaded via AJAX -->
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p>Loading edit form...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveProfileChanges">Save Changes</button>
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
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'dashboard.php'); ?>">
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
                        <div class="form-text"><?php echo htmlspecialchars($_navbar_password_policy['hint']); ?></div>
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

<!-- Add Staff Modal -->
<div class="modal fade" id="add_staffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form id="addStaffForm" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="addStaffModalLabel"><i class="fas fa-user-plus me-2"></i> Add Staff</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="full_name" name="full_name" required>
          </div>
          <div class="mb-3">
            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" class="form-control" id="email" name="email" required>
          </div>
          <div class="mb-3">
            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="username" name="username" required>
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" class="form-control" id="password" name="password" required>
              <button class="btn btn-outline-secondary toggle-password" type="button">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <div class="form-text"><?php echo htmlspecialchars($_navbar_password_policy['hint']); ?></div>
            <div class="password-strength">
              <div class="password-strength-bar" id="addStaffStrengthBar"></div>
            </div>
            <ul class="password-requirements mb-0">
              <li id="addStaffReqLength"><?php echo htmlspecialchars($_navbar_password_policy['requirements']['length']); ?></li>
              <li id="addStaffReqUppercase"><?php echo htmlspecialchars($_navbar_password_policy['requirements']['uppercase']); ?></li>
              <li id="addStaffReqNumber"><?php echo htmlspecialchars($_navbar_password_policy['requirements']['number']); ?></li>
              <li id="addStaffReqSpecial"><?php echo htmlspecialchars($_navbar_password_policy['requirements']['special']); ?></li>
            </ul>
          </div>
          <div class="mb-3">
            <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
            <select class="form-select" id="role" name="role" required>
              <option value="">Select role</option>
              <option value="admin">Admin</option>
              <option value="staff">Staff</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Profile Picture (JPG, PNG)</label>
            <div class="file-upload">
              <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png">
              <small class="text-muted">Max file size: 5MB</small>
            </div>
          </div>
          <div id="addStaffMessage" class="mt-2"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary-custom">Add Staff</button>
        </div>
      </div>
    </form>
  </div>
</div>


<style>
/* Profile Dropdown Styles */
.dropdown-menu {
    min-width: 200px;
    border-radius: 8px;
    border: none;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
    margin-top: 10px;
    z-index: 10500 !important; /* Higher than navbar (1050) and alerts (9999) */
    position: absolute !important;
}

.dropdown-item {
    padding: 10px 20px;
    transition: all 0.2s;
    cursor: pointer;
}

.dropdown-item:hover {
    background-color: #e8f0fe;
    color: #4361ee;
    transform: translateX(5px);
}

.dropdown-item:active {
    background-color: #d1e3fc;
    color: #3651d4;
}

.dropdown-item i {
    width: 20px;
    transition: transform 0.2s;
}

.dropdown-item:hover i {
    transform: scale(1.1);
}

.nav-link.dropdown-toggle {
    transition: all 0.3s;
    padding: 8px 15px;
}

.nav-link.dropdown-toggle:hover {
    background-color: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    transform: translateY(-2px);
}

.nav-link.dropdown-toggle:active {
    transform: translateY(0);
}

/* Fix dropdown toggle arrow */
.dropdown-toggle::after {
    margin-left: 8px;
    vertical-align: middle;
    transition: transform 0.3s;
}

.dropdown-toggle[aria-expanded="true"]::after {
    transform: rotate(180deg);
}

/* Smooth dropdown animation */
.dropdown-menu.show {
    animation: dropdownFadeIn 0.2s ease-in-out;
}

@keyframes dropdownFadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Mobile/Responsive adjustments */
@media (max-width: 992px) {
    .navbar {
        margin-left: 0 !important;
    }
    
    .dropdown-menu {
        min-width: 180px;
        right: 10px !important;
        left: auto !important;
    }
}

@media (max-width: 768px) {
    .nav-link.dropdown-toggle {
        padding: 6px 10px;
        font-size: 0.9rem;
    }
    
    .dropdown-menu {
        min-width: 160px;
        font-size: 0.9rem;
    }
    
    .dropdown-item {
        padding: 8px 15px;
    }
    
    .nav-link.dropdown-toggle img,
    .nav-link.dropdown-toggle i {
        width: 32px;
        height: 32px;
        font-size: 1.3rem;
    }
}

/* Ensure dropdown doesn't get cut off */
.navbar-nav {
    position: static;
}

.nav-item.dropdown {
    position: relative;
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
    transition: all 0.3s ease;
}

.password-strength-weak { background: #dc3545; width: 33%; }
.password-strength-medium { background: #ffc107; width: 66%; }
.password-strength-strong { background: #28a745; width: 100%; }

.password-requirements {
    margin: 8px 0 0;
    padding-left: 18px;
    font-size: 0.85rem;
    color: #6c757d;
}

.password-requirements li.met {
    color: #198754;
}
</style>
<script>
const navbarPasswordPolicy = <?php echo json_encode($_navbar_password_policy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function evaluateNavbarPasswordChecks(passwordValue) {
    return {
        length: passwordValue.length >= navbarPasswordPolicy.minLength,
        uppercase: /[A-Z]/.test(passwordValue),
        number: /[0-9]/.test(passwordValue),
        special: /[^A-Za-z0-9]/.test(passwordValue)
    };
}

function resolveNavbarStrengthClass(checks) {
    const score = [checks.length, checks.uppercase, checks.number, checks.special].filter(Boolean).length;
    if (score <= 1) {
        return 'password-strength-weak';
    }
    if (score <= 3) {
        return 'password-strength-medium';
    }
    return 'password-strength-strong';
}

function updateAddStaffPasswordIndicator(passwordValue) {
    const checks = evaluateNavbarPasswordChecks(passwordValue);
    const strengthBar = document.getElementById('addStaffStrengthBar');

    document.getElementById('addStaffReqLength')?.classList.toggle('met', checks.length);
    document.getElementById('addStaffReqUppercase')?.classList.toggle('met', checks.uppercase);
    document.getElementById('addStaffReqNumber')?.classList.toggle('met', checks.number);
    document.getElementById('addStaffReqSpecial')?.classList.toggle('met', checks.special);

    if (strengthBar) {
        strengthBar.className = 'password-strength-bar';
        if (passwordValue.length > 0) {
            strengthBar.classList.add(resolveNavbarStrengthClass(checks));
        }
    }

    return checks;
}

function isNavbarPasswordPolicySatisfied(checks) {
    return checks.length && checks.uppercase && checks.number && checks.special;
}

function resetAddStaffPasswordIndicator() {
    const strengthBar = document.getElementById('addStaffStrengthBar');
    if (strengthBar) {
        strengthBar.className = 'password-strength-bar';
    }
    ['addStaffReqLength', 'addStaffReqUppercase', 'addStaffReqNumber', 'addStaffReqSpecial'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) {
            el.classList.remove('met');
        }
    });
}

function setupAddStaffPasswordIndicator() {
    const addStaffForm = document.getElementById('addStaffForm');
    if (!addStaffForm) {
        return;
    }
    const passwordInput = addStaffForm.querySelector('input[name="password"]');
    if (!passwordInput) {
        return;
    }

    passwordInput.addEventListener('input', function() {
        updateAddStaffPasswordIndicator(this.value || '');
    });

    const addStaffModal = document.getElementById('add_staffModal');
    if (addStaffModal) {
        addStaffModal.addEventListener('hidden.bs.modal', function() {
            resetAddStaffPasswordIndicator();
        });
    }
}

// Toast notification function
function showToast(message, type = 'success') {
    const bgColor = type === 'success' ? 'bg-success' : 'bg-danger';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    const toast = $(`
        <div class="toast align-items-center text-white ${bgColor} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas ${icon} me-2"></i>${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `);
    $('#toastContainer').append(toast);
    const bsToast = new bootstrap.Toast(toast[0], { delay: 4000 });
    bsToast.show();
    
    // Remove from DOM after hidden
    toast.on('hidden.bs.toast', function() {
        $(this).remove();
    });
}

function normalizeNavbarProfilePicturePath(path) {
    if (!path) return 'images/profile.jpg';
    if (path.startsWith('data:')) {
        return path;
    }
    if (/^\/?images\//i.test(path)) {
        return path.replace(/^\/+/, '');
    }
    if (/^(https?:)?\/\//i.test(path)) {
        try {
            const normalizedUrl = new URL(path, window.location.origin);
            const localHosts = ['localhost', '127.0.0.1', '::1', 'minio', 'host.docker.internal'];
            if (localHosts.includes(normalizedUrl.hostname.toLowerCase())) {
                normalizedUrl.hostname = window.location.hostname;
                if (window.location.protocol === 'https:' && normalizedUrl.protocol === 'http:') {
                    normalizedUrl.protocol = 'https:';
                }
            }
            return normalizedUrl.toString();
        } catch (error) {
            return path;
        }
    }
    const normalizedPath = path.replace(/^\/+/, '');
    if (/^uploads\//i.test(normalizedPath)) {
        return normalizedPath;
    }
    const file = normalizedPath.split('/').pop();
    return file ? `uploads/profiles/${file}` : 'images/profile.jpg';
}

const browserExitCsrfToken = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? '')); ?>;
const inactivityAutoLogoutUrl = <?php echo json_encode('logout.php?token=' . urlencode((string)($_SESSION['logout_token'] ?? ''))); ?>;
const selfDeleteConfirmationWord = <?php echo json_encode((string)$_navbar_delete_confirmation_word); ?>;
const inactivityAutoLogoutMs = 30 * 60 * 1000;
let inactivityAutoLogoutTimer = null;

function resetInactivityAutoLogoutTimer() {
    if (!inactivityAutoLogoutUrl) {
        return;
    }
    if (inactivityAutoLogoutTimer) {
        clearTimeout(inactivityAutoLogoutTimer);
    }
    inactivityAutoLogoutTimer = setTimeout(function() {
        window.location.href = inactivityAutoLogoutUrl;
    }, inactivityAutoLogoutMs);
}

function initInactivityAutoLogout() {
    if (window.__efindInactivityAutoLogoutInitialized) {
        return;
    }
    window.__efindInactivityAutoLogoutInitialized = true;

    ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function(eventName) {
        window.addEventListener(eventName, resetInactivityAutoLogoutTimer, { capture: true });
    });
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            resetInactivityAutoLogoutTimer();
        }
    });

    resetInactivityAutoLogoutTimer();
}

function markBrowserExitPendingLogout() {
    if (!browserExitCsrfToken || window.__efindBrowserExitMarked === true || !navigator.sendBeacon) {
        return;
    }

    const payload = new URLSearchParams();
    payload.append('csrf_token', browserExitCsrfToken);
    window.__efindBrowserExitMarked = true;
    navigator.sendBeacon('mark_browser_exit.php', payload);
}

window.addEventListener('pagehide', function() {
    markBrowserExitPendingLogout();
}, { capture: true });
initInactivityAutoLogout();

function initNavbarJQueryHandlers() {
    $(document).ready(function() {
        function loadEditProfileForm(routeIndex = 0) {
            const routes = ['edit_profile_content', 'edit_profile_content.php'];
            const route = routes[routeIndex] || routes[routes.length - 1];
            $.ajax({
                url: route,
                type: 'GET',
                cache: false,
                success: function(response) {
                    $('#editProfileModalBody').html(response);
                },
                error: function(xhr, status, error) {
                    if (routeIndex + 1 < routes.length) {
                        loadEditProfileForm(routeIndex + 1);
                        return;
                    }

                    const httpStatus = xhr && xhr.status ? ` (HTTP ${xhr.status})` : '';
                    let reason = 'Please try again.';
                    if (status === 'timeout') {
                        reason = 'The request timed out.';
                    } else if (xhr && xhr.status === 404) {
                        reason = 'The edit form route was not found.';
                    } else if (xhr && xhr.status === 500) {
                        reason = 'The server returned an error.';
                    } else if (xhr && xhr.status === 0) {
                        reason = 'The request was blocked or lost.';
                    }

                    console.error('Edit profile load error:', route, status, error, xhr && xhr.responseText ? xhr.responseText : '');
                    $('#editProfileModalBody').html(
                        '<div class="alert alert-danger">' +
                        '<i class="fas fa-exclamation-circle me-2"></i>' +
                        'Error loading edit form' + httpStatus + '. ' + reason + ' ' +
                        '<a href="edit_profile.php" class="alert-link">Open full edit page</a>.' +
                        '</div>'
                    );
                }
            });
        }

        // Load edit form when edit profile modal is shown
        $('#editProfileModal').on('show.bs.modal', function () {
            loadEditProfileForm(0);
        });

        // Handle save button click
        $('#saveProfileChanges').on('click', function() {
            const btn = $(this);
            const originalText = btn.html();
            
            // Show loading state
            btn.prop('disabled', true).html('<i class="spinner-border spinner-border-sm me-1"></i>Saving...');
            
            // Get form data
            var formData = new FormData(document.getElementById('editProfileForm'));
            
            // Submit via AJAX
            $.ajax({
                url: 'update_profile.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if(response.success) {
                        // Close edit modal
                        $('#editProfileModal').modal('hide');
                        
                        // Reload page to reflect updated profile in navbar modal
                        location.reload();
                        
                        // Update navbar if profile picture changed
                        if (response.profile_picture) {
                            const normalizedProfilePath = response.profile_picture_src || normalizeNavbarProfilePicturePath(response.profile_picture);
                            const profileSrc = normalizedProfilePath
                                ? normalizedProfilePath + (normalizedProfilePath.includes('?') ? '&' : '?') + 't=' + Date.now()
                                : '';
                            const profileImg = $('.nav-link.dropdown-toggle img');
                            if (profileSrc && profileImg.length) {
                                profileImg.attr('src', profileSrc);
                            } else if (profileSrc) {
                                // Replace icon with image
                                $('.nav-link.dropdown-toggle i.fa-user-circle').replaceWith(
                                    '<img src="' + profileSrc + 
                                    '" alt="Profile Picture" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;" onerror="this.onerror=null;this.src=\'images/profile.jpg\';">'
                                );
                            }
                        }
                        
                        // Update navbar name if changed
                        if (response.full_name) {
                            $('.nav-link.dropdown-toggle .text-white').text(response.full_name);
                        }
                        
                        // Show success toast
                        showToast(response.message, 'success');
                    } else {
                        // Show error toast
                        showToast('Error: ' + response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Save error:', xhr.responseText);
                    showToast('Error saving changes: ' + error, 'error');
                },
                complete: function() {
                    // Reset button state
                    btn.prop('disabled', false).html(originalText);
                }
            });
        });

        // Handle edit profile button click
        $('#profileModal').on('click', '.btn-primary', function() {
            $('#profileModal').modal('hide');
            $('#editProfileModal').modal('show');
        });

        const selfDeleteModal = $('#deleteSelfProfileModal');
        const selfDeleteInput = $('#selfDeleteConfirmationInput');
        const selfDeleteButton = $('#confirmSelfDeleteBtn');
        const selfDeleteError = $('#selfDeleteError');

        function selfDeleteInputMatchesExpected() {
            return (selfDeleteInput.val() || '').trim() === selfDeleteConfirmationWord;
        }

        function updateSelfDeleteButtonState() {
            selfDeleteButton.prop('disabled', !selfDeleteInputMatchesExpected());
        }

        function showSelfDeleteError(message) {
            selfDeleteError.text(message || 'Unable to delete profile.').removeClass('d-none');
        }

        selfDeleteModal.on('show.bs.modal', function () {
            selfDeleteInput.val('');
            selfDeleteButton.prop('disabled', true).text('Delete Profile');
            selfDeleteError.addClass('d-none').text('');
        });

        selfDeleteInput.on('input', function () {
            selfDeleteError.addClass('d-none').text('');
            updateSelfDeleteButtonState();
        });

        selfDeleteButton.on('click', function () {
            const typedWord = (selfDeleteInput.val() || '').trim();
            if (typedWord !== selfDeleteConfirmationWord) {
                showSelfDeleteError(`Please type ${selfDeleteConfirmationWord} exactly to confirm.`);
                updateSelfDeleteButtonState();
                return;
            }

            const originalButtonText = selfDeleteButton.text();
            selfDeleteButton.prop('disabled', true).html('<i class="spinner-border spinner-border-sm me-1"></i>Deleting...');

            $.ajax({
                url: 'update_profile.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    csrf_token: browserExitCsrfToken,
                    action: 'delete_self_profile',
                    confirmation_phrase: typedWord
                },
                success: function (response) {
                    if (response && response.success) {
                        const redirectTo = response.redirect || 'login.php';
                        window.location.href = redirectTo;
                        return;
                    }

                    const errorMessage = (response && response.message) ? response.message : 'Failed to delete profile.';
                    showSelfDeleteError(errorMessage);
                    showToast('Error: ' + errorMessage, 'error');
                    selfDeleteButton.prop('disabled', false).text(originalButtonText);
                },
                error: function (xhr, status, error) {
                    const responseMessage = xhr && xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : (error || 'Request failed.');
                    showSelfDeleteError(responseMessage);
                    showToast('Error: ' + responseMessage, 'error');
                    selfDeleteButton.prop('disabled', false).text(originalButtonText);
                }
            });
        });

        // --- Add Staff AJAX handler ---
        $('#addStaffForm').submit(function(e) {
            e.preventDefault();
            const passwordField = this.querySelector('input[name="password"]');
            const passwordChecks = updateAddStaffPasswordIndicator(passwordField ? passwordField.value : '');
            if (!isNavbarPasswordPolicySatisfied(passwordChecks)) {
                $('#addStaffMessage').html('<div class="alert alert-danger">' + navbarPasswordPolicy.hint + '</div>');
                return;
            }
            var formData = new FormData(this);
            $('#addStaffMessage').html('<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div> Adding staff...');
            $.ajax({
                url: 'add_staff.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast(response.message, 'success');
                        $('#addStaffForm')[0].reset();
                        resetAddStaffPasswordIndicator();
                        setTimeout(function() {
                            $('#add_staffModal').modal('hide');
                            $('#addStaffMessage').html('');
                        }, 1500);
                    } else {
                        $('#addStaffMessage').html('<div class="alert alert-danger">' + response.message + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    $('#addStaffMessage').html('<div class="alert alert-danger">An error occurred: ' + error + '</div>');
                }
            });
        });
    });
}

if (window.jQuery) {
    initNavbarJQueryHandlers();
} else {
    const navbarJqueryScript = document.createElement('script');
    navbarJqueryScript.src = 'https://code.jquery.com/jquery-3.6.0.min.js';
    navbarJqueryScript.onload = initNavbarJQueryHandlers;
    navbarJqueryScript.onerror = function() {
        console.error('Failed to load jQuery; profile editing actions are unavailable.');
    };
    document.head.appendChild(navbarJqueryScript);
}

setupAddStaffPasswordIndicator();

// Toggle password visibility
document.querySelectorAll('.toggle-password').forEach(button => {
  button.addEventListener('click', function() {
    const input = this.parentElement.querySelector('input');
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

const navbarPasswordChangeForm = document.getElementById('passwordChangeForm');
if (navbarPasswordChangeForm) {
    navbarPasswordChangeForm.addEventListener('submit', function(e) {
        const newPasswordInput = document.getElementById('newPassword');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const newPassword = newPasswordInput ? newPasswordInput.value : '';
        const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';
        const checks = evaluateNavbarPasswordChecks(newPassword);

        if (!isNavbarPasswordPolicySatisfied(checks)) {
            e.preventDefault();
            alert(navbarPasswordPolicy.hint);
            return false;
        }
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('New passwords do not match.');
            return false;
        }
        return true;
    });
}

// Initialize Bootstrap tooltips and popovers
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Ensure dropdown works properly
    const dropdownElementList = document.querySelectorAll('.dropdown-toggle');
    const dropdownList = [...dropdownElementList].map(dropdownToggleEl => new bootstrap.Dropdown(dropdownToggleEl));
    
    // Add visual feedback when dropdown opens/closes
    const profileDropdown = document.getElementById('profileDropdown');
    if (profileDropdown) {
        profileDropdown.addEventListener('shown.bs.dropdown', function () {
            this.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
        });
        
        profileDropdown.addEventListener('hidden.bs.dropdown', function () {
            this.style.backgroundColor = '';
        });
    }
    
    // Prevent dropdown from closing when clicking inside (except on links)
    document.querySelectorAll('.dropdown-menu').forEach(function(dropdown) {
        dropdown.addEventListener('click', function(e) {
            if (e.target.tagName !== 'A' && !e.target.closest('a')) {
                e.stopPropagation();
            }
        });
    });
});
</script>

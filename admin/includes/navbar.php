<?php
// Fetch profile data directly for the modal (no AJAX needed)
$_navbar_profile = null;
if (isset($conn) && (isset($_SESSION['admin_id']) || isset($_SESSION['user_id']))) {
    $_navbar_uid   = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
    $_navbar_table = isset($_SESSION['admin_id']) ? 'admin_users' : 'users';
    $_navbar_stmt  = $conn->prepare("SELECT id, full_name, username, email, contact_number, profile_picture, last_login, created_at, updated_at FROM {$_navbar_table} WHERE id = ?");
    if ($_navbar_stmt) {
        $_navbar_stmt->bind_param("i", $_navbar_uid);
        $_navbar_stmt->execute();
        $_navbar_profile = $_navbar_stmt->get_result()->fetch_assoc();
        $_navbar_stmt->close();
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
            $profile_picture = $_SESSION['profile_picture'] ?? '';
            $full_name = $_SESSION['full_name'] ?? 'Admin';
            if (!empty($profile_picture)) {
                $profile_path = (strpos($profile_picture, 'uploads/profiles/') === 0)
                    ? $profile_picture
                    : 'uploads/profiles/' . ltrim($profile_picture, '/');
                if (file_exists($profile_path)) {
                    echo '<img src="' . htmlspecialchars($profile_path) . '" alt="Profile Picture" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;">';
                } else {
                    echo '<i class="fas fa-user-circle me-2" style="font-size: 1.5rem;"></i>';
                }
            } else {
                echo '<i class="fas fa-user-circle me-2" style="font-size: 1.5rem;"></i>';
            }
            ?>
                    <span class="text-white"><?php echo htmlspecialchars($full_name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="profileDropdown">
                    <li><a class="dropdown-item" href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <?php
                    // Generate logout CSRF token if not exists
                    if (!isset($_SESSION['logout_token'])) {
                        $_SESSION['logout_token'] = bin2hex(random_bytes(32));
                    }
                    ?>
                    <!-- TEMPORARY: Using minimal logout for debugging HTTP 500 -->
                    <li><a class="dropdown-item text-danger" href="logout_minimal.php" aria-label="Logout from your account"><i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i>Logout</a></li>
                </ul>
            </li>
        </ul>
    </div>
</nav>

<!-- Toast Container for Notifications -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 11000;">
    <div id="toastContainer"></div>
</div>

<!-- Profile View Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="profileModalLabel">Admin Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="profileModalBody">
                <?php if ($_navbar_profile): ?>
                <?php $_np = $_navbar_profile; $_is_admin = isset($_SESSION['admin_id']); ?>
                <div class="row">
                    <div class="col-lg-4">
                        <div class="card mb-4">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <?php
                                    $_np_profile_file = !empty($_np['profile_picture']) ? basename((string)$_np['profile_picture']) : '';
                                    ?>
                                    <?php if (!empty($_np_profile_file) && file_exists(__DIR__ . '/../uploads/profiles/' . $_np_profile_file)): ?>
                                        <img src="uploads/profiles/<?php echo htmlspecialchars($_np_profile_file); ?>"
                                             class="img-thumbnail rounded-circle"
                                             style="width:150px;height:150px;object-fit:cover;border:3px solid #4361ee;"
                                             alt="Profile Picture">
                                    <?php else: ?>
                                        <div class="rounded-circle d-inline-flex align-items-center justify-content-center bg-light" style="width:150px;height:150px;border:3px solid #dee2e6;">
                                            <i class="fas fa-user fa-4x text-secondary"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h4 class="mb-1"><?php echo htmlspecialchars($_np['full_name']); ?></h4>
                                <p class="text-muted mb-3"><?php echo $_is_admin ? 'Administrator' : 'Staff'; ?></p>
                                <div class="card bg-light p-3">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span>Member since:</span>
                                        <span class="text-primary"><?php echo date('F j, Y', strtotime($_np['created_at'])); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between small">
                                        <span>Last active:</span>
                                        <span class="text-primary"><?php echo !empty($_np['last_login']) ? date('M d, Y h:i A', strtotime($_np['last_login'])) : 'Never'; ?></span>
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
                                        <p><?php echo htmlspecialchars($_np['email']); ?></p>
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
                                        <p class="small text-muted mb-0">Last changed: N/A</p>
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
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal" data-bs-dismiss="modal">Edit Profile</button>
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
</style>
<script>
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

$(document).ready(function() {
    // Load edit form when edit profile modal is shown
    $('#editProfileModal').on('show.bs.modal', function () {
        $.ajax({
            url: 'edit_profile_content',
            type: 'GET',
            success: function(response) {
                $('#editProfileModalBody').html(response);
            },
            error: function(xhr, status, error) {
                console.error('Edit profile load error:', xhr.responseText);
                $('#editProfileModalBody').html(
                    '<div class="alert alert-danger">' +
                    '<i class="fas fa-exclamation-circle me-2"></i>' +
                    'Error loading edit form. Please try again.' +
                    '</div>'
                );
            }
        });
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
                        const profileImg = $('.nav-link.dropdown-toggle img');
                        if (profileImg.length) {
                            profileImg.attr('src', 'uploads/profiles/' + response.profile_picture + '?t=' + Date.now());
                        } else {
                            // Replace icon with image
                            $('.nav-link.dropdown-toggle i.fa-user-circle').replaceWith(
                                '<img src="uploads/profiles/' + response.profile_picture + '?t=' + Date.now() + 
                                '" alt="Profile Picture" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;">'
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

    // --- Add Staff AJAX handler ---
    $('#addStaffForm').submit(function(e) {
        e.preventDefault();
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

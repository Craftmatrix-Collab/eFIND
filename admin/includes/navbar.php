<nav class="navbar navbar-expand-lg navbar-dark fixed-top" style="background-color: #1a3a8f; margin-left: 250px; margin-top: 0;">
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
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                       <?php
                // Check if session variables exist before using them
                $profile_picture = $_SESSION['profile_picture'] ?? '';
                $full_name = $_SESSION['full_name'] ?? 'Admin';
                if (!empty($profile_picture)) {
                    $profile_path = "uploads/profiles/" . $profile_picture;
                    if (file_exists($profile_path)) {
                        echo '<img src="' . htmlspecialchars($profile_path) . '" alt="Profile Picture" class="rounded-circle me-2" width="50" height="50">';
                    } else {
                        echo '<i class="fas fa-user-circle me-2"></i>';
                    }
                } else {
                    echo '<i class="fas fa-user-circle me-2"></i>';
                }
                ?>
                        <span><?php echo htmlspecialchars($full_name); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">Profile</a></li>
                        <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['role'] === 'admin'): ?>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Profile View Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="profileModalLabel">Admin Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="profileModalBody">
                <!-- Content will be loaded via AJAX -->
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p>Loading profile...</p>
                </div>
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


<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Load profile content when profile modal is shown
    $('#profileModal').on('show.bs.modal', function () {
        $.ajax({
            url: 'admin_profile_content.php',
            type: 'GET',
            success: function(response) {
                $('#profileModalBody').html(response);
            },
            error: function() {
                $('#profileModalBody').html('<div class="alert alert-danger">Error loading profile. Please try again.</div>');
            }
        });
    });

    // Load edit form when edit profile modal is shown
    $('#editProfileModal').on('show.bs.modal', function () {
        $.ajax({
            url: 'edit_profile_content.php',
            type: 'GET',
            success: function(response) {
                $('#editProfileModalBody').html(response);
            },
            error: function() {
                $('#editProfileModalBody').html('<div class="alert alert-danger">Error loading edit form. Please try again.</div>');
            }
        });
    });

    // Handle save button click
    $('#saveProfileChanges').on('click', function() {
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
                    // Refresh profile view
                    $('#profileModalBody').load('admin_profile_content.php');
                    // Show success message
                    alert(response.message);
                } else {
                    // Show error message
                    alert('Error: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error saving changes: ' + error);
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
                    $('#addStaffMessage').html('<div class="alert alert-success">' + response.message + '</div>');
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

// Handle Add Staff Form Submission
document.getElementById('addStaffForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  const messageDiv = document.getElementById('addStaffMessage');

  // Simple validation
  const fullName = formData.get('full_name');
  const email = formData.get('email');
  const username = formData.get('username');
  const password = formData.get('password');
  const role = formData.get('role');

  if (!fullName || !email || !username || !password || !role) {
    messageDiv.innerHTML = '<div class="alert alert-danger">All fields are required.</div>';
    return;
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    messageDiv.innerHTML = '<div class="alert alert-danger">Invalid email format.</div>';
    return;
  }

  messageDiv.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div> Adding staff...';

  fetch('add_staff.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      messageDiv.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
      document.getElementById('addStaffForm').reset();
      setTimeout(() => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('add_staffModal'));
        modal.hide();
        messageDiv.innerHTML = '';
      }, 1500);
    } else {
      messageDiv.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
    }
  })
  .catch(error => {
    messageDiv.innerHTML = `<div class="alert alert-danger">An error occurred: ${error.message}</div>`;
  });
});

</script>

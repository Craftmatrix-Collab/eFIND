<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    die('<div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>Unauthorized access. Please login first.
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
    $query = "SELECT id, full_name, username, email, contact_number, profile_picture
              FROM $table
              WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    if (!$user) {
        throw new Exception("Profile not found.");
    }
} catch (Exception $e) {
    die('<div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>' . htmlspecialchars($e->getMessage()) . '
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
         </div>');
}
?>
<form id="editProfileForm" enctype="multipart/form-data">
    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
    <div class="row">
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-body text-center">
                    <div class="profile-picture-container mb-3">
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img id="profileImagePreview"
                                 src="uploads/profiles/<?php echo htmlspecialchars($user['profile_picture']); ?>"
                                 class="img-thumbnail rounded-circle profile-picture"
                                 alt="Profile Picture"
                                 onerror="this.onerror=null;this.src='assets/img/default-profile.png';">
                        <?php else: ?>
                            <div id="profileImagePreview" class="profile-picture-placeholder rounded-circle d-flex align-items-center justify-content-center">
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
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profile Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
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
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="contact_number" class="form-label">Contact Number</label>
                            <input type="tel" class="form-control" id="contact_number" name="contact_number"
                                   value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>">
                            <small class="text-muted">Format: +639XXXXXXXXX or 09XXXXXXXXX</small>
                        </div>
                    </div>
                    <div class="alert alert-info mt-4">
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
    }
    .profile-picture-placeholder {
        width: 200px;
        height: 200px;
        background-color: #f8f9fa;
        border: 3px solid #dee2e6;
    }
    .form-label {
        font-weight: 500;
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

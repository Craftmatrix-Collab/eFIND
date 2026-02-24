<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/config.php';
require_once __DIR__ . '/includes/image_compression_helper.php';
require_once __DIR__ . '/includes/minio_helper.php';

// Check if this is an AJAX request
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Logging helper (idempotent if already defined)
if (!function_exists('logProfileUpdate')) {
    function logProfileUpdate($userId, $userName, $userRole, $action, $description, $conn) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        $insertSql = "INSERT INTO activity_logs (user_id, user_name, user_role, action, description, ip_address)
                      VALUES (?, ?, ?, ?, ?, ?)";
        if ($insertStmt = $conn->prepare($insertSql)) {
            $insertStmt->bind_param(
                "isssss",
                $userId,
                $userName,
                $userRole,
                $action,
                $description,
                $ip
            );
            $insertStmt->execute();
            $insertStmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure user is authenticated
    if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login first.']);
            exit;
        }
        $_SESSION['error'] = 'Unauthorized access. Please login first.';
        header("Location: edit_profile.php");
        exit;
    }

    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
        $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
            exit;
        }
        $_SESSION['error'] = 'Invalid security token. Please refresh and try again.';
        header("Location: edit_profile.php");
        exit;
    }

    // Validate and sanitize input
    $userId = intval($_SESSION['admin_id'] ?? $_SESSION['user_id']);
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $isAdmin = isset($_SESSION['admin_id']);
    $userRole = $isAdmin ? 'admin' : 'staff';
    $userName = $fullName ?: ($username ?: 'Unknown');
    $table = $isAdmin ? 'admin_users' : 'users';

    if ($fullName === '' || $username === '' || $email === '') {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Full name, username and email are required.']);
            exit;
        }
        $_SESSION['error'] = 'Full name, username and email are required.';
        header("Location: edit_profile.php");
        exit;
    }

    // Basic email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
            exit;
        }
        $_SESSION['error'] = 'Invalid email address.';
        header("Location: edit_profile.php");
        exit;
    }

    // Fetch current profile picture before upload
    $oldPicturePath = null;
    $oldPictureQuery = "SELECT profile_picture FROM $table WHERE id = ?";
    if ($oldStmt = $conn->prepare($oldPictureQuery)) {
        $oldStmt->bind_param("i", $userId);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        $oldData = $oldResult->fetch_assoc();
        $oldPicturePath = $oldData['profile_picture'] ?? null;
        $oldStmt->close();
    }

    // Handle profile picture upload
    $profilePicture = null;
    $uploadError = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $fileExtension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png'];

        if (in_array($fileExtension, $allowedExtensions, true)) {
            $minioClient = new MinioS3Client();
            $newFileName = 'profile_' . $userId . '_' . str_replace('.', '', uniqid('', true)) . '.' . $fileExtension;
            $objectName = 'profiles/' . date('Y/m/') . $newFileName;
            $contentType = MinioS3Client::getMimeType($_FILES['profile_picture']['name']);
            $uploadResult = $minioClient->uploadFile($_FILES['profile_picture']['tmp_name'], $objectName, $contentType);

            if (!empty($uploadResult['success'])) {
                $profilePicture = (string)$uploadResult['url'];

                // Delete old profile picture if it exists and is different
                if (!empty($oldPicturePath) && $oldPicturePath !== $profilePicture) {
                    $oldObjectName = $minioClient->extractObjectNameFromUrl((string)$oldPicturePath);
                    if (!empty($oldObjectName)) {
                        $minioClient->deleteFile($oldObjectName);
                    } else {
                        $oldFullPath = __DIR__ . '/uploads/profiles/' . basename((string)$oldPicturePath);
                        if (file_exists($oldFullPath)) {
                            unlink($oldFullPath);
                        }
                    }
                }
            } else {
                $uploadError = 'Failed to upload profile picture to storage.';
            }
        } else {
            $uploadError = 'Only JPG, JPEG, and PNG files are allowed.';
        }
    }

    if ($uploadError !== null) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $uploadError]);
            exit;
        }
        $_SESSION['error'] = $uploadError;
        header("Location: edit_profile.php");
        exit;
    }

    // Update user in database
    if ($profilePicture) {
        $query = "UPDATE $table SET full_name = ?, username = ?, email = ?, contact_number = ?, profile_picture = ?, updated_at = NOW() WHERE id = ?";
        if ($stmt = $conn->prepare($query)) {
            $stmt->bind_param("sssssi", $fullName, $username, $email, $contactNumber, $profilePicture, $userId);
        }
    } else {
        $query = "UPDATE $table SET full_name = ?, username = ?, email = ?, contact_number = ?, updated_at = NOW() WHERE id = ?";
        if ($stmt = $conn->prepare($query)) {
            $stmt->bind_param("ssssi", $fullName, $username, $email, $contactNumber, $userId);
        }
    }
    
    if (isset($stmt) && $stmt->execute()) {
        // Log the profile update
        $description = "User updated their profile information.";
        logProfileUpdate($userId, $userName, $userRole, 'profile_update', $description, $conn);

        // Update session values so UI reflects changes immediately
        $_SESSION['full_name'] = $fullName;
        $_SESSION['username'] = $username;
        
        // Update profile picture in session if a new one was uploaded
        if ($profilePicture) {
            $_SESSION['profile_picture'] = $profilePicture;
        }

        $stmt->close();
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true, 
                'message' => 'Profile updated successfully!',
                'profile_picture' => $profilePicture,
                'full_name' => $fullName
            ]);
            exit;
        }
        
        $_SESSION['success'] = "Profile updated successfully!";
        header("Location: edit_profile.php");
        exit;
    } else {
        if (isset($stmt)) $stmt->close();
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to update profile: ' . $conn->error]);
            exit;
        }
        
        $_SESSION['error'] = "Failed to update profile: " . $conn->error;
        header("Location: edit_profile.php");
        exit;
    }
} else {
    // Not a POST request — redirect back
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }
    header("Location: edit_profile.php");
    exit;
}
?>

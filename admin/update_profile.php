<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/config.php';

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

    // Validate and sanitize input
    $userId = intval($_SESSION['admin_id'] ?? $_SESSION['user_id']);
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $isAdmin = isset($_SESSION['admin_id']);
    $userRole = $isAdmin ? 'admin' : 'staff';
    $userName = $fullName ?: ($username ?: 'Unknown');

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

    // Handle profile picture upload
    $profilePicture = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/profiles/';
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileExtension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png'];
        
        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = 'profile_' . $userId . '_' . time() . '.' . $fileExtension;
            $uploadPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadPath)) {
                $profilePicture = $newFileName;
            }
        }
    }

    // Update user in database
    $table = $isAdmin ? 'admin_users' : 'users';
    
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
        if ($isAdmin) {
            $_SESSION['admin_name'] = $fullName;
            $_SESSION['admin_username'] = $username;
        } else {
            $_SESSION['full_name'] = $fullName;
            $_SESSION['username'] = $username;
        }

        $stmt->close();
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
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
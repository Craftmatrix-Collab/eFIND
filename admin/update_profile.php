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
    $action = trim((string)($_POST['action'] ?? ''));

    // ── AJAX: Send profile email verification OTP ──────────────────────────────
    if ($action === 'send_profile_verify_otp') {
        header('Content-Type: application/json');
        $emailForOtp = trim($_POST['email'] ?? '');
        if (!filter_var($emailForOtp, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
            exit;
        }

        $currentEmailStmt = $conn->prepare("SELECT email FROM $table WHERE id = ?");
        if (!$currentEmailStmt) {
            error_log('Profile OTP current-email prepare failed: ' . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
            exit;
        }
        $currentEmailStmt->bind_param("i", $userId);
        if (!$currentEmailStmt->execute()) {
            error_log('Profile OTP current-email execute failed: ' . $currentEmailStmt->error);
            $currentEmailStmt->close();
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
            exit;
        }
        $currentEmailResult = $currentEmailStmt->get_result();
        $currentEmailRow = $currentEmailResult->fetch_assoc();
        $currentEmailStmt->close();
        if (!$currentEmailRow) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        $currentEmail = trim((string)($currentEmailRow['email'] ?? ''));
        if (strcasecmp($currentEmail, $emailForOtp) === 0) {
            echo json_encode(['success' => false, 'message' => 'Email is unchanged. No OTP is required.']);
            exit;
        }

        $userType = $table;
        $dupStmt = $conn->prepare("SELECT 1 FROM users WHERE email = ? AND NOT (? = 'users' AND id = ?) UNION ALL SELECT 1 FROM admin_users WHERE email = ? AND NOT (? = 'admin_users' AND id = ?) LIMIT 1");
        if (!$dupStmt) {
            error_log('Profile OTP duplicate-check prepare failed: ' . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
            exit;
        }
        $dupStmt->bind_param("ssissi", $emailForOtp, $userType, $userId, $emailForOtp, $userType, $userId);
        if (!$dupStmt->execute()) {
            error_log('Profile OTP duplicate-check execute failed: ' . $dupStmt->error);
            $dupStmt->close();
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
            exit;
        }
        $dupStmt->store_result();
        if ($dupStmt->num_rows > 0) {
            $dupStmt->close();
            echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
            exit;
        }
        $dupStmt->close();

        $otp = sprintf("%06d", random_int(0, 999999));
        $_SESSION['profile_edit_verify_otp'] = $otp;
        $_SESSION['profile_edit_verify_email'] = $emailForOtp;
        $_SESSION['profile_edit_verify_expires'] = time() + 600; // 10 min
        $_SESSION['profile_edit_verify_user_id'] = $userId;
        $_SESSION['profile_edit_verify_user_type'] = $userType;
        unset(
            $_SESSION['profile_edit_verified_email'],
            $_SESSION['profile_edit_verified_user_id'],
            $_SESSION['profile_edit_verified_user_type']
        );

        require_once __DIR__ . '/vendor/autoload.php';
        try {
            if (trim((string)RESEND_API_KEY) === '') {
                throw new RuntimeException('RESEND_API_KEY is not configured.');
            }
            $resend = \Resend::client(RESEND_API_KEY);
            $resend->emails->send([
                'from'    => FROM_EMAIL,
                'to'      => [$emailForOtp],
                'subject' => 'Email Verification OTP – eFIND System',
                'html'    => "
                    <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px'>
                        <div style='background:linear-gradient(135deg,#4361ee,#3a0ca3);color:#fff;padding:24px;border-radius:10px 10px 0 0;text-align:center'>
                            <h2 style='margin:0'>Email Verification</h2>
                        </div>
                        <div style='background:#f8f9fa;padding:24px;border-radius:0 0 10px 10px'>
                            <p>You are updating your email in the <strong>eFIND System</strong>.</p>
                            <p>Use this OTP to verify your new email address:</p>
                            <div style='background:#fff;border:2px dashed #4361ee;border-radius:8px;padding:20px;text-align:center;margin:20px 0'>
                                <p style='margin:0;color:#666;font-size:13px'>Your OTP Code</p>
                                <div style='font-size:36px;font-weight:bold;color:#4361ee;letter-spacing:8px;margin-top:6px'>{$otp}</div>
                            </div>
                            <p style='color:#666;font-size:13px'>This code expires in <strong>10 minutes</strong>. If you did not request this change, ignore this email.</p>
                        </div>
                    </div>"
            ]);
            echo json_encode(['success' => true, 'message' => 'OTP sent to ' . htmlspecialchars($emailForOtp)]);
        } catch (Throwable $e) {
            error_log('Resend Error (profile edit verify): ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please contact the system administrator.']);
        }
        exit;
    }

    // ── AJAX: Check profile email verification OTP ─────────────────────────────
    if ($action === 'check_profile_verify_otp') {
        header('Content-Type: application/json');
        $emailForOtp = trim($_POST['email'] ?? '');
        $otp = trim($_POST['otp'] ?? '');
        $userType = $table;
        if (!filter_var($emailForOtp, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
            exit;
        }
        if (!preg_match('/^\d{6}$/', $otp)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid 6-digit OTP.']);
            exit;
        }
        if (
            empty($_SESSION['profile_edit_verify_otp']) ||
            empty($_SESSION['profile_edit_verify_email']) ||
            empty($_SESSION['profile_edit_verify_user_id']) ||
            empty($_SESSION['profile_edit_verify_user_type']) ||
            strcasecmp((string)$_SESSION['profile_edit_verify_email'], $emailForOtp) !== 0 ||
            (int)$_SESSION['profile_edit_verify_user_id'] !== $userId ||
            (string)$_SESSION['profile_edit_verify_user_type'] !== $userType ||
            time() > ($_SESSION['profile_edit_verify_expires'] ?? 0)
        ) {
            echo json_encode(['success' => false, 'message' => 'OTP expired or invalid. Please request a new one.']);
            exit;
        }
        if ((string)$_SESSION['profile_edit_verify_otp'] !== $otp) {
            echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
            exit;
        }
        $_SESSION['profile_edit_verified_email'] = $emailForOtp;
        $_SESSION['profile_edit_verified_user_id'] = $userId;
        $_SESSION['profile_edit_verified_user_type'] = $userType;
        unset(
            $_SESSION['profile_edit_verify_otp'],
            $_SESSION['profile_edit_verify_email'],
            $_SESSION['profile_edit_verify_expires'],
            $_SESSION['profile_edit_verify_user_id'],
            $_SESSION['profile_edit_verify_user_type']
        );
        echo json_encode(['success' => true, 'message' => 'Email verified successfully!']);
        exit;
    }

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

    // Fetch current email/profile picture before upload
    $oldPicturePath = null;
    $currentEmail = '';
    $oldPictureQuery = "SELECT email, profile_picture FROM $table WHERE id = ?";
    if ($oldStmt = $conn->prepare($oldPictureQuery)) {
        $oldStmt->bind_param("i", $userId);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        $oldData = $oldResult->fetch_assoc();
        $oldStmt->close();
        if (!$oldData) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit;
            }
            $_SESSION['error'] = 'User not found.';
            header("Location: edit_profile.php");
            exit;
        }
        $currentEmail = trim((string)($oldData['email'] ?? ''));
        $oldPicturePath = $oldData['profile_picture'] ?? null;
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
            exit;
        }
        $_SESSION['error'] = 'Database error. Please try again later.';
        header("Location: edit_profile.php");
        exit;
    }

    $emailChanged = strcasecmp($currentEmail, $email) !== 0;
    $isProfileEmailVerified = !empty($_SESSION['profile_edit_verified_email'])
        && !empty($_SESSION['profile_edit_verified_user_id'])
        && !empty($_SESSION['profile_edit_verified_user_type'])
        && strcasecmp((string)$_SESSION['profile_edit_verified_email'], $email) === 0
        && (int)$_SESSION['profile_edit_verified_user_id'] === $userId
        && (string)$_SESSION['profile_edit_verified_user_type'] === $table;
    if ($emailChanged && !$isProfileEmailVerified) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please verify your updated email address before saving changes.']);
            exit;
        }
        $_SESSION['error'] = 'Please verify your updated email address before saving changes.';
        header("Location: edit_profile.php");
        exit;
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
        unset(
            $_SESSION['profile_edit_verify_otp'],
            $_SESSION['profile_edit_verify_email'],
            $_SESSION['profile_edit_verify_expires'],
            $_SESSION['profile_edit_verify_user_id'],
            $_SESSION['profile_edit_verify_user_type'],
            $_SESSION['profile_edit_verified_email'],
            $_SESSION['profile_edit_verified_user_id'],
            $_SESSION['profile_edit_verified_user_type']
        );

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

<?php
require_once __DIR__ . '/includes/auth.php';
include 'includes/config.php';
require_once __DIR__ . '/includes/resend_delivery_helper.php';
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
    if (!isLoggedIn()) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login first.']);
            exit;
        }
        header("Location: login.php");
        exit;
    }

    $redirectUrl = 'edit_profile.php';

    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
        $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
            exit;
        }
        $_SESSION['error'] = 'Invalid security token. Please refresh and try again.';
        header("Location: $redirectUrl");
        exit;
    }

    // Resolve actor/target profile context
    $actorId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (int)($_SESSION['user_id'] ?? 0);
    $actorType = 'users';
    $isActorAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true && isset($_SESSION['admin_id']);
    $isActorStaffSession = isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;
    $isActorStaffOnly = $isActorStaffSession && !$isActorAdmin;
    $isActorSuperadmin = function_exists('isSuperAdmin') && isSuperAdmin();

    $requestedUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : $actorId;
    $requestedUserType = trim((string)($_POST['user_type'] ?? $actorType));
    if ($requestedUserType === '') {
        $requestedUserType = $actorType;
    }
    if ($requestedUserId <= 0 || !in_array($requestedUserType, ['users'], true)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid user context.']);
            exit;
        }
        $_SESSION['error'] = 'Invalid user context.';
        header("Location: $redirectUrl");
        exit;
    }
    if ($isActorStaffOnly && ($requestedUserId !== $actorId || $requestedUserType !== $actorType)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Staff can only edit their own profile.']);
            exit;
        }
        $_SESSION['error'] = 'Staff can only edit their own profile.';
        header("Location: $redirectUrl");
        exit;
    }
    if (!$isActorSuperadmin && ($requestedUserId !== $actorId || $requestedUserType !== $actorType)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'You can only edit your own profile.']);
            exit;
        }
        $_SESSION['error'] = 'You can only edit your own profile.';
        header("Location: $redirectUrl");
        exit;
    }

    if ($isActorSuperadmin && ($requestedUserId !== $actorId || $requestedUserType !== $actorType)) {
        $redirectUrl .= '?user_id=' . $requestedUserId . '&user_type=' . $requestedUserType;
    }

    $userId = $requestedUserId;
    $table = $requestedUserType;
    $isEditingOwnProfile = $userId === $actorId && $table === $actorType;

    // Validate and sanitize input
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $userRole = $isActorSuperadmin ? 'superadmin' : ($isActorAdmin ? 'admin' : 'staff');
    $userName = trim((string)($_SESSION['full_name'] ?? $_SESSION['admin_username'] ?? $_SESSION['staff_username'] ?? $_SESSION['username'] ?? 'Unknown'));
    $action = trim((string)($_POST['action'] ?? ''));

    // ── AJAX/POST: Delete own profile with role-based confirmation ─────────────
    if ($action === 'delete_self_profile') {
        if (!$isEditingOwnProfile) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'You can only delete your own profile.']);
                exit;
            }
            $_SESSION['error'] = 'You can only delete your own profile.';
            header("Location: $redirectUrl");
            exit;
        }

        $confirmationPhrase = trim((string)($_POST['confirmation_phrase'] ?? ''));
        $expectedPhrase = 'STAFF';
        if ($isActorSuperadmin) {
            $expectedPhrase = 'SUPERADMIN';
        } elseif ($isActorAdmin) {
            $expectedPhrase = 'ADMIN';
        }

        if ($confirmationPhrase !== $expectedPhrase) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => "Confirmation text mismatch. Please type {$expectedPhrase} exactly."
                ]);
                exit;
            }
            $_SESSION['error'] = "Confirmation text mismatch. Please type {$expectedPhrase} exactly.";
            header("Location: $redirectUrl");
            exit;
        }

        $lookupStmt = $conn->prepare("SELECT id, username, full_name, role FROM $table WHERE id = ? LIMIT 1");
        if (!$lookupStmt) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to prepare account lookup.']);
                exit;
            }
            $_SESSION['error'] = 'Failed to prepare account lookup.';
            header("Location: $redirectUrl");
            exit;
        }

        $lookupStmt->bind_param("i", $userId);
        if (!$lookupStmt->execute()) {
            $lookupStmt->close();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to verify account before deletion.']);
                exit;
            }
            $_SESSION['error'] = 'Failed to verify account before deletion.';
            header("Location: $redirectUrl");
            exit;
        }
        $targetUser = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();

        if (!$targetUser) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Profile not found or already deleted.']);
                exit;
            }
            $_SESSION['error'] = 'Profile not found or already deleted.';
            header("Location: $redirectUrl");
            exit;
        }

        $targetRole = strtolower((string)($targetUser['role'] ?? 'staff'));
        $primaryAccountType = in_array($targetRole, ['admin', 'superadmin'], true) ? 'admin' : 'staff';
        $primaryAccountKey = buildPrimaryAccountKey($primaryAccountType, $userId);

        if (!$conn->begin_transaction()) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Unable to start profile deletion transaction.']);
                exit;
            }
            $_SESSION['error'] = 'Unable to start profile deletion transaction.';
            header("Location: $redirectUrl");
            exit;
        }

        if (ensurePrimaryLoginSessionTable($conn)) {
            $deleteSessionStmt = $conn->prepare("DELETE FROM " . PRIMARY_LOGIN_SESSION_TABLE . " WHERE account_key = ?");
            if (!$deleteSessionStmt) {
                $conn->rollback();
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Failed to prepare session cleanup.']);
                    exit;
                }
                $_SESSION['error'] = 'Failed to prepare session cleanup.';
                header("Location: $redirectUrl");
                exit;
            }
            $deleteSessionStmt->bind_param("s", $primaryAccountKey);
            if (!$deleteSessionStmt->execute()) {
                $deleteSessionStmt->close();
                $conn->rollback();
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Failed to clear active sessions for this account.']);
                    exit;
                }
                $_SESSION['error'] = 'Failed to clear active sessions for this account.';
                header("Location: $redirectUrl");
                exit;
            }
            $deleteSessionStmt->close();
        }

        $deleteStmt = $conn->prepare("DELETE FROM $table WHERE id = ? LIMIT 1");
        if (!$deleteStmt) {
            $conn->rollback();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to prepare profile deletion.']);
                exit;
            }
            $_SESSION['error'] = 'Failed to prepare profile deletion.';
            header("Location: $redirectUrl");
            exit;
        }
        $deleteStmt->bind_param("i", $userId);
        if (!$deleteStmt->execute() || $deleteStmt->affected_rows < 1) {
            $deleteError = $deleteStmt->error ?: 'Profile not deleted.';
            $deleteStmt->close();
            $conn->rollback();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to delete profile: ' . $deleteError]);
                exit;
            }
            $_SESSION['error'] = 'Failed to delete profile: ' . $deleteError;
            header("Location: $redirectUrl");
            exit;
        }
        $deleteStmt->close();

        if (!$conn->commit()) {
            $conn->rollback();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to finalize profile deletion.']);
                exit;
            }
            $_SESSION['error'] = 'Failed to finalize profile deletion.';
            header("Location: $redirectUrl");
            exit;
        }

        $deletedIdentity = trim((string)($targetUser['full_name'] ?? $targetUser['username'] ?? ('ID ' . $userId)));
        logProfileUpdate($actorId, $userName, $userRole, 'profile_delete', "User deleted own profile: {$deletedIdentity}", $conn);

        clearPrimaryLoginMarkers();
        destroyAuthSession();

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Your profile has been deleted.',
                'redirect' => 'login.php'
            ]);
            exit;
        }

        header('Location: login.php');
        exit;
    }

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
        $dupStmt = $conn->prepare("SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1");
        if (!$dupStmt) {
            error_log('Profile OTP duplicate-check prepare failed: ' . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
            exit;
        }
        $dupStmt->bind_param("si", $emailForOtp, $userId);
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

        $configIssue = efind_validate_resend_otp_config();
        if ($configIssue !== null) {
            echo json_encode(['success' => false, 'message' => $configIssue]);
            exit;
        }

        $otp = sprintf("%06d", random_int(0, 999999));
        efind_clear_otp_session_state([
            'profile_edit_verify_otp',
            'profile_edit_verify_email',
            'profile_edit_verify_expires',
            'profile_edit_verify_user_id',
            'profile_edit_verify_user_type',
            'profile_edit_verified_email',
            'profile_edit_verified_user_id',
            'profile_edit_verified_user_type',
        ]);

        require_once __DIR__ . '/vendor/autoload.php';
        try {
            $resend = \Resend::client(trim((string)RESEND_API_KEY));
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
            $_SESSION['profile_edit_verify_otp'] = $otp;
            $_SESSION['profile_edit_verify_email'] = $emailForOtp;
            $_SESSION['profile_edit_verify_expires'] = time() + 600; // 10 min
            $_SESSION['profile_edit_verify_user_id'] = $userId;
            $_SESSION['profile_edit_verify_user_type'] = $userType;

            echo json_encode(['success' => true, 'message' => 'OTP sent to ' . htmlspecialchars($emailForOtp)]);
        } catch (Throwable $e) {
            efind_clear_otp_session_state([
                'profile_edit_verify_otp',
                'profile_edit_verify_email',
                'profile_edit_verify_expires',
                'profile_edit_verify_user_id',
                'profile_edit_verify_user_type',
                'profile_edit_verified_email',
                'profile_edit_verified_user_id',
                'profile_edit_verified_user_type',
            ]);
            error_log('Resend Error (profile edit verify): ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => efind_resend_otp_error_message($e)]);
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
        header("Location: $redirectUrl");
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
        header("Location: $redirectUrl");
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
            header("Location: $redirectUrl");
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
        header("Location: $redirectUrl");
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
        header("Location: $redirectUrl");
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
        header("Location: $redirectUrl");
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
        $description = $isEditingOwnProfile
            ? "User updated their profile information."
            : "Superadmin updated profile information for {$table} ID {$userId}.";
        logProfileUpdate($actorId, $userName, $userRole, 'profile_update', $description, $conn);

        // Update session values only when editing the current account
        if ($isEditingOwnProfile) {
            $_SESSION['full_name'] = $fullName;
            $_SESSION['username'] = $username;
            if ($profilePicture) {
                $_SESSION['profile_picture'] = $profilePicture;
            }
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
        header("Location: $redirectUrl");
        exit;
    } else {
        if (isset($stmt)) $stmt->close();
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to update profile: ' . $conn->error]);
            exit;
        }
        
        $_SESSION['error'] = "Failed to update profile: " . $conn->error;
        header("Location: $redirectUrl");
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

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

// Check if the user is an admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Check if the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Include your database connection
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/image_compression_helper.php';
require_once __DIR__ . '/includes/minio_helper.php';

// Sanitize and validate inputs
$full_name = htmlspecialchars(trim($_POST['full_name']));
$email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
$username = htmlspecialchars(trim($_POST['username']));
$password = $_POST['password']; // Will be hashed
$role = htmlspecialchars(trim($_POST['role']));

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

// Handle file upload
$profile_picture = '';
$profileMinioClient = null;
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $file_ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);

    // Validate file type
    $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array(strtolower($file_ext), $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, JPEG, PNG, and GIF files are allowed.']);
        exit;
    }

    $profileMinioClient = new MinioS3Client();
    $file_name = 'staff_' . str_replace('.', '', uniqid('', true)) . '.' . strtolower($file_ext);
    $object_name = 'profiles/' . date('Y/m/') . $file_name;
    $content_type = MinioS3Client::getMimeType($_FILES['profile_picture']['name']);
    $uploadResult = $profileMinioClient->uploadFile($_FILES['profile_picture']['tmp_name'], $object_name, $content_type);

    if (!empty($uploadResult['success'])) {
        $profile_picture = (string)$uploadResult['url'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload profile picture.']);
        exit;
    }
}

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert into database using prepared statement
try {
    $stmt = $conn->prepare("INSERT INTO users (full_name, email, username, password, role, profile_picture) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $conn->error);
    }

    $stmt->bind_param("ssssss", $full_name, $email, $username, $hashed_password, $role, $profile_picture);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Staff added successfully!']);
    } else {
        throw new Exception('Database execute failed: ' . $stmt->error);
    }
    $stmt->close();
} catch (Throwable $e) {
    if ($profile_picture && $profileMinioClient instanceof MinioS3Client) {
        $objectName = $profileMinioClient->extractObjectNameFromUrl((string)$profile_picture);
        if (!empty($objectName)) {
            $profileMinioClient->deleteFile($objectName);
        }
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

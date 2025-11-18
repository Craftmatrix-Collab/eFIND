<?php
session_start();
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
require_once 'db_connection.php';

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
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = 'uploads/profiles/';
    $file_ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $file_name = uniqid() . '.' . $file_ext;
    $target_file = $upload_dir . $file_name;

    // Validate file type
    $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array(strtolower($file_ext), $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, JPEG, PNG, and GIF files are allowed.']);
        exit;
    }

    // Move uploaded file
    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
        $profile_picture = $file_name;
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload profile picture.']);
        exit;
    }
}

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert into database using prepared statement
try {
    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, username, password, role, profile_picture) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$full_name, $email, $username, $hashed_password, $role, $profile_picture]);

    echo json_encode(['success' => true, 'message' => 'Staff added successfully!']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

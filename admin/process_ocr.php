<?php
// Include configuration and authentication
include(__DIR__ . '/includes/config.php');
include(__DIR__ . '/includes/auth.php');

// Check if user is logged in
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Validate file upload
if (!isset($_FILES['file'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit();
}

$file = $_FILES['file'];
$allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
$maxFileSize = 5 * 1024 * 1024; // 5MB

// Validate file type
if (!in_array($file['type'], $allowedTypes)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, and PDF are allowed.']);
    exit();
}

// Validate file size
if ($file['size'] > $maxFileSize) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'File size exceeds 5MB limit.']);
    exit();
}

// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to create upload directory.']);
        exit();
    }
}

// Generate unique filename
$fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
$fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($file['name'], '.' . $fileExt)) . '.' . $fileExt;
$targetPath = $uploadDir . $fileName;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'filePath' => 'uploads/' . $fileName,
        'fileName' => $fileName,
        'fileType' => $file['type']
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to upload file.']);
}
?>

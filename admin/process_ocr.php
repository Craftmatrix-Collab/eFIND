<?php
// Include configuration and authentication
include(__DIR__ . '/includes/config.php');
include(__DIR__ . '/includes/auth.php');
require_once __DIR__ . '/includes/minio_helper.php';

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

// Upload directly to MinIO
$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($fileExt === '') {
    $fileExt = ($file['type'] === 'image/png') ? 'png' : (($file['type'] === 'application/pdf') ? 'pdf' : 'jpg');
}
$safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$fileName = uniqid() . '_' . $safeBase . '.' . $fileExt;
$objectName = 'ocr-uploads/' . date('Y/m/') . $fileName;

$minioClient = new MinioS3Client();
$contentType = MinioS3Client::getMimeType($file['name']);
if ($contentType === 'application/octet-stream' && !empty($file['type'])) {
    $contentType = $file['type'];
}
$uploadResult = $minioClient->uploadFile($file['tmp_name'], $objectName, $contentType);

if (!empty($uploadResult['success'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'filePath' => $uploadResult['url'],
        'fileName' => $fileName,
        'fileType' => $file['type']
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to upload file to MinIO: ' . ($uploadResult['error'] ?? 'unknown error')]);
}
?>

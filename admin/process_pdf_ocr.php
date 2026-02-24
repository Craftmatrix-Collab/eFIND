<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/minio_helper.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

$file = $_FILES['file'];

// Allow PDF and common image types used for OCR uploads
$allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/bmp'];
if (!in_array($file['type'], $allowedTypes, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type']);
    exit();
}

$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($fileExt === '') {
    $fileExt = ($file['type'] === 'application/pdf') ? 'pdf' : 'jpg';
}
$uniqueFileName = uniqid() . '.' . $fileExt;
$objectName = 'ocr-uploads/' . date('Y/m/') . $uniqueFileName;

$minioClient = new MinioS3Client();
$contentType = MinioS3Client::getMimeType($file['name']);
if ($contentType === 'application/octet-stream' && !empty($file['type'])) {
    $contentType = $file['type'];
}
$uploadResult = $minioClient->uploadFile($file['tmp_name'], $objectName, $contentType);

if (empty($uploadResult['success'])) {
    echo json_encode(['success' => false, 'error' => 'Failed to upload file to MinIO: ' . ($uploadResult['error'] ?? 'unknown error')]);
    exit();
}

if ($fileExt === 'pdf') {
    // For PDF files, we'll just return the path since text extraction happens client-side
    echo json_encode([
        'success' => true,
        'filePath' => $uploadResult['url'],
        'message' => 'PDF uploaded successfully. Use OCR button to extract text.'
    ]);
} else {
    // For images, you can keep the existing Tesseract.js processing
    echo json_encode([
        'success' => true,
        'filePath' => $uploadResult['url'],
        'message' => 'Image uploaded successfully. Use OCR button to extract text.'
    ]);
}
?>

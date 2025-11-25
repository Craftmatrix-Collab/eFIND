<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

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

if (!isValidMinutesDocument($file)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type']);
    exit();
}

$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
$uniqueFileName = uniqid() . '.' . $fileExt;
$targetPath = $uploadDir . $uniqueFileName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
    exit();
}

if ($fileExt === 'pdf') {
    // For PDF files, we'll just return the path since text extraction happens client-side
    echo json_encode([
        'success' => true,
        'filePath' => 'uploads/' . $uniqueFileName,
        'message' => 'PDF uploaded successfully. Use OCR button to extract text.'
    ]);
} else {
    // For images, you can keep the existing Tesseract.js processing
    echo json_encode([
        'success' => true,
        'filePath' => 'uploads/' . $uniqueFileName,
        'message' => 'Image uploaded successfully. Use OCR button to extract text.'
    ]);
}
?>

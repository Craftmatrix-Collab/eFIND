<?php
header('Content-Type: application/json');
if (!isset($_GET['image']) || empty($_GET['image'])) {
    echo json_encode(['success' => false, 'error' => 'No image specified']);
    exit;
}
$imagePath = $_GET['image'];
$fullPath = __DIR__ . '/' . $imagePath;
if (!file_exists($fullPath)) {
    echo json_encode(['success' => false, 'error' => 'Image not found']);
    exit;
}
// Simple OCR using Tesseract (make sure tesseract is installed on server)
$cmd = 'tesseract ' . escapeshellarg($fullPath) . ' stdout 2>&1';
exec($cmd, $output, $status);
if ($status === 0) {
    $text = implode("\n", $output);
    echo json_encode(['success' => true, 'text' => $text]);
} else {
    echo json_encode(['success' => false, 'error' => 'OCR process failed.']);
}
?>
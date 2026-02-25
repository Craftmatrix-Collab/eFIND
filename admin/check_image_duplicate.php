<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/image_hash_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$documentType = normalizeImageHashDocumentType($_POST['document_type'] ?? '');
if ($documentType === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid document_type']);
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Image file is required']);
    exit;
}

$file = $_FILES['file'];
$errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($errorCode !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File upload failed with code ' . $errorCode]);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Uploaded file is not available']);
    exit;
}

$maxBytes = 12 * 1024 * 1024;
$fileSize = (int)($file['size'] ?? 0);
if ($fileSize <= 0 || $fileSize > $maxBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file size. Maximum is 12MB.']);
    exit;
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp', 'image/tiff'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string)finfo_file($finfo, $tmpPath) : '';
if ($finfo) {
    finfo_close($finfo);
}

if (!in_array($mime, $allowedMimeTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported file type for image deduplication']);
    exit;
}

if (!ensureDocumentImageHashTable($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to initialize image hash table']);
    exit;
}

if (!isset($_SESSION['image_hash_backfill']) || !is_array($_SESSION['image_hash_backfill'])) {
    $_SESSION['image_hash_backfill'] = [];
}
$lastBackfillAt = (int)($_SESSION['image_hash_backfill'][$documentType] ?? 0);
if ($lastBackfillAt <= 0 || (time() - $lastBackfillAt) > 1800) {
    backfillDocumentImageHashes($conn, $documentType, 60);
    $_SESSION['image_hash_backfill'][$documentType] = time();
}

$hash = computeAverageImageHashFromFile($tmpPath);
if ($hash === null || $hash === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Unable to generate image hash']);
    exit;
}

$matches = findMatchingImageHashes($conn, $documentType, $hash);

echo json_encode([
    'success' => true,
    'document_type' => $documentType,
    'hash' => $hash,
    'is_duplicate' => !empty($matches),
    'matches' => $matches,
]);

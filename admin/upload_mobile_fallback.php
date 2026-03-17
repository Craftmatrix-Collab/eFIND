<?php
/**
 * Server-side upload relay for mobile flow.
 *
 * Used as a fallback when browser direct PUT to MinIO fails due to
 * network/CORS/endpoint reachability constraints.
 *
 * POST multipart/form-data:
 *   - doc_type: resolutions|minutes|executive_orders
 *   - session_id: optional mobile pairing token
 *   - file: uploaded image file
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/minio_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$docType = trim((string)($_POST['doc_type'] ?? ''));
$allowedDocTypes = ['resolutions', 'minutes', 'executive_orders'];
if (!in_array($docType, $allowedDocTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid doc_type']);
    exit;
}

$mobileSession = preg_replace('/[^a-f0-9]/', '', (string)($_POST['session_id'] ?? ''));
$isMobileAuth = false;
$mobileSessionDocType = '';
if ($mobileSession !== '') {
    $conn->query("CREATE TABLE IF NOT EXISTS mobile_upload_sessions (
        session_id VARCHAR(64) PRIMARY KEY,
        doc_type VARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'waiting',
        result_id INT DEFAULT NULL,
        object_keys_json LONGTEXT DEFAULT NULL,
        image_urls_json LONGTEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $sessionStmt = $conn->prepare(
        "SELECT doc_type FROM mobile_upload_sessions WHERE session_id = ? AND status = 'waiting' LIMIT 1"
    );
    if ($sessionStmt) {
        $sessionStmt->bind_param('s', $mobileSession);
        $sessionStmt->execute();
        $sessionRow = $sessionStmt->get_result()->fetch_assoc();
        $isMobileAuth = $sessionRow !== null;
        $mobileSessionDocType = (string)($sessionRow['doc_type'] ?? '');
        $sessionStmt->close();
    }
}

if (!isLoggedIn() && !$isMobileAuth) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($isMobileAuth && $mobileSessionDocType !== '' && $mobileSessionDocType !== $docType) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session document type mismatch']);
    exit;
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File is larger than server upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE => 'File is larger than form max size.',
        UPLOAD_ERR_PARTIAL => 'File upload was interrupted.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file.',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
    ];
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $errorMessages[$uploadError] ?? ('Upload failed with code: ' . $uploadError),
    ]);
    exit;
}

$fileSize = (int)($file['size'] ?? 0);
if ($fileSize <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Uploaded file is empty']);
    exit;
}
if ($fileSize > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File exceeds 10MB mobile upload limit']);
    exit;
}

$fileName = trim((string)($file['name'] ?? ''));
if ($fileName === '') {
    $fileName = 'image.jpg';
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
$mimeToExtension = [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/bmp'  => 'bmp',
    'image/x-ms-bmp' => 'bmp',
    'image/webp' => 'webp',
];
$extensionToMime = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'bmp' => 'image/bmp',
    'webp' => 'image/webp',
];

$reportedContentType = strtolower(trim((string)($file['type'] ?? 'application/octet-stream')));
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
    $extension = $mimeToExtension[$reportedContentType] ?? '';
}
if ($extension === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported image format. Please use JPG, PNG, GIF, BMP, or WEBP.']);
    exit;
}

$baseName = trim((string)pathinfo($fileName, PATHINFO_FILENAME));
if ($baseName === '') {
    $baseName = 'image';
}
$safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $baseName);
if ($safeName === '' || $safeName === null) {
    $safeName = 'image';
}

$uniqueName = $safeName . '_' . str_replace('.', '', uniqid('', true)) . '.' . $extension;
$objectKey = $docType . '/' . date('Y/m/') . $uniqueName;
$contentType = $extensionToMime[$extension] ?? ($reportedContentType !== '' ? $reportedContentType : 'application/octet-stream');

try {
    $minio = new MinioS3Client();
    $uploadResult = $minio->uploadFile((string)$file['tmp_name'], $objectKey, $contentType);
    if (empty($uploadResult['success'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => (string)($uploadResult['error'] ?? 'Failed to upload image to object storage'),
        ]);
        exit;
    }

    $resolvedObjectKey = ltrim((string)($uploadResult['object_name'] ?? $objectKey), '/');
    if ($resolvedObjectKey === '') {
        $resolvedObjectKey = ltrim($objectKey, '/');
    }
    $publicUrl = (string)($uploadResult['url'] ?? $minio->getPublicUrl($resolvedObjectKey));
    echo json_encode([
        'success' => true,
        'object_key' => $resolvedObjectKey,
        'public_url' => $publicUrl,
    ]);
} catch (Throwable $e) {
    error_log('upload_mobile_fallback error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to upload image via secure fallback']);
}

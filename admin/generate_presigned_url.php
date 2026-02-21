<?php
/**
 * Generate a MinIO presigned PUT URL for direct browser-to-MinIO upload.
 *
 * POST /admin/generate_presigned_url.php
 * Body (JSON): { "doc_type": "resolutions"|"minutes"|"ordinances",
 *                "file_name": "document.pdf",
 *                "content_type": "application/pdf" }
 *
 * Response (JSON): { "success": true, "presigned_url": "...", "object_key": "..." }
 */
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/minio_helper.php';

header('Content-Type: application/json');

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

$body = json_decode(file_get_contents('php://input'), true);

$docType     = $body['doc_type']     ?? '';
$fileName    = $body['file_name']    ?? '';
$contentType = $body['content_type'] ?? 'application/octet-stream';

$allowedTypes = ['resolutions', 'minutes', 'ordinances'];
if (!in_array($docType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid doc_type']);
    exit;
}

if (empty($fileName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'file_name is required']);
    exit;
}

// Sanitize and build unique object key  (mirrors existing upload path convention)
$ext        = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$safeName   = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($fileName, PATHINFO_FILENAME));
$uniqueName = $safeName . '_' . uniqid() . '.' . $ext;
$objectKey  = $docType . '/' . date('Y/m/') . $uniqueName;

try {
    $minio      = new MinioS3Client();
    $presignedUrl = $minio->generatePresignedPutUrl($objectKey, 900); // 15 min

    echo json_encode([
        'success'       => true,
        'presigned_url' => $presignedUrl,
        'object_key'    => $objectKey,
        'public_url'    => $minio->getPublicUrl($objectKey),
    ]);
} catch (Exception $e) {
    error_log('generate_presigned_url error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to generate URL']);
}

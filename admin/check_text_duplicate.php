<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/text_duplicate_helper.php';

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

$documentType = normalizeTextDuplicateDocumentType($_POST['document_type'] ?? '');
if ($documentType === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid document_type']);
    exit;
}

$content = trim((string)($_POST['content'] ?? ''));
if ($content === '') {
    echo json_encode([
        'success' => true,
        'document_type' => $documentType,
        'is_duplicate' => false,
        'matches' => [],
    ]);
    exit;
}

try {
    $matches = findMatchingDocumentTextDuplicates($conn, $documentType, $content);

    echo json_encode([
        'success' => true,
        'document_type' => $documentType,
        'is_duplicate' => !empty($matches),
        'matches' => $matches,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Duplicate text check failed',
    ]);
}


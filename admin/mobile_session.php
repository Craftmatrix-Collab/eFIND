<?php
/**
 * Mobile upload session management.
 * POST (JSON {doc_type}) — desktop creates a pairing session (requires login)
 * GET  ?action=check&session=ID — desktop polls for completion (public)
 */
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Auto-create table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS mobile_upload_sessions (
    session_id  VARCHAR(64)  PRIMARY KEY,
    doc_type    VARCHAR(50)  NOT NULL DEFAULT '',
    status      VARCHAR(20)  NOT NULL DEFAULT 'waiting',
    result_id   INT          DEFAULT NULL,
    object_keys_json LONGTEXT DEFAULT NULL,
    image_urls_json  LONGTEXT DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function ensureMobileSessionPayloadColumns(mysqli $conn): void {
    $requiredColumns = [
        'object_keys_json' => "ALTER TABLE mobile_upload_sessions ADD COLUMN object_keys_json LONGTEXT DEFAULT NULL AFTER result_id",
        'image_urls_json'  => "ALTER TABLE mobile_upload_sessions ADD COLUMN image_urls_json LONGTEXT DEFAULT NULL AFTER object_keys_json",
    ];

    foreach ($requiredColumns as $column => $alterSql) {
        $check = $conn->query("SHOW COLUMNS FROM mobile_upload_sessions LIKE '{$column}'");
        if ($check && $check->num_rows === 0) {
            $conn->query($alterSql);
        }
    }
}

ensureMobileSessionPayloadColumns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create session — only logged-in desktop users
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/includes/auth.php';
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $docType = preg_replace('/[^a-z_]/', '', $body['doc_type'] ?? '');
    $allowedTypes = ['resolutions', 'minutes', 'executive_orders'];
    if (!in_array($docType, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid doc_type']);
        exit;
    }
    $sid     = bin2hex(random_bytes(16));
    $stmt    = $conn->prepare("INSERT INTO mobile_upload_sessions (session_id, doc_type) VALUES (?,?)");
    $stmt->bind_param('ss', $sid, $docType);
    $stmt->execute();
    echo json_encode(['success' => true, 'session_id' => $sid]);

} else {
    // Check status — desktop polls this
    $sid = preg_replace('/[^a-f0-9]/', '', $_GET['session'] ?? '');
    if (!$sid) { echo json_encode(['status' => 'invalid']); exit; }
    $stmt = $conn->prepare("SELECT status, result_id, doc_type, object_keys_json, image_urls_json FROM mobile_upload_sessions WHERE session_id = ?");
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $objectKeys = json_decode((string)($row['object_keys_json'] ?? ''), true);
        $imageUrls = json_decode((string)($row['image_urls_json'] ?? ''), true);
        $row['object_keys'] = is_array($objectKeys)
            ? array_values(array_filter($objectKeys, 'is_string'))
            : [];
        $row['image_urls'] = is_array($imageUrls)
            ? array_values(array_filter($imageUrls, 'is_string'))
            : [];
        unset($row['object_keys_json'], $row['image_urls_json']);
    }
    echo json_encode($row ?: ['status' => 'invalid']);
}

<?php
/**
 * Mobile upload session management.
 * POST (JSON {doc_type}) — desktop creates a pairing session (requires login)
 * GET  ?action=check&session=ID — desktop polls for completion (public)
 */
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Auto-create table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS mobile_upload_sessions (
    session_id  VARCHAR(64)  PRIMARY KEY,
    doc_type    VARCHAR(50)  NOT NULL DEFAULT '',
    status      VARCHAR(20)  NOT NULL DEFAULT 'waiting',
    result_id   INT          DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
    $sid     = bin2hex(random_bytes(16));
    $stmt    = $conn->prepare("INSERT INTO mobile_upload_sessions (session_id, doc_type) VALUES (?,?)");
    $stmt->bind_param('ss', $sid, $docType);
    $stmt->execute();
    echo json_encode(['success' => true, 'session_id' => $sid]);

} else {
    // Check status — desktop polls this
    $sid = preg_replace('/[^a-f0-9]/', '', $_GET['session'] ?? '');
    if (!$sid) { echo json_encode(['status' => 'invalid']); exit; }
    $stmt = $conn->prepare("SELECT status, result_id, doc_type FROM mobile_upload_sessions WHERE session_id = ?");
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode($row ?: ['status' => 'invalid']);
}

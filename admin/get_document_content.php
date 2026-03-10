<?php
// get_document_content.php
header('Content-Type: application/json');
include('includes/config.php');
include(__DIR__ . '/includes/logger.php');
require_once __DIR__ . '/includes/document_type_helper.php';


$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$document_type = trim((string)($_GET['document_type'] ?? ''));

try {
    $canonicalType = normalizeCanonicalDocumentType($document_type);
    $table = resolveDocumentTableByCanonicalType($canonicalType);
    if ($id <= 0 || $table === null) {
        echo json_encode(['success' => false, 'error' => 'Invalid document type.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT content FROM {$table} WHERE id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare content lookup.']);
        exit;
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row) {
        echo json_encode(['success' => true, 'content' => $row['content']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Document not found.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

<?php
// get_document_content.php
header('Content-Type: application/json');
include('includes/config.php');
include(__DIR__ . '/includes/logger.php');


$id = $_GET['id'];
$document_type = $_GET['document_type'];

try {
    if ($document_type === 'executive_order') {
        $stmt = $conn->prepare("SELECT content FROM executive_orders WHERE id = ?");
    } elseif ($document_type === 'resolution') {
        $stmt = $conn->prepare("SELECT content FROM resolutions WHERE id = ?");
    } elseif ($document_type === 'meeting') {
        $stmt = $conn->prepare("SELECT content FROM meeting_minutes WHERE id = ?");
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid document type.']);
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

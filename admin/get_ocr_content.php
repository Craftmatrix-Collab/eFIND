<?php
// Include configuration and authentication
include(__DIR__ . '/includes/config.php');
include(__DIR__ . '/includes/auth.php');

// Check if user is logged in
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Validate request
if (!isset($_GET['id']) || !is_numeric($_GET['id']) || !isset($_GET['type'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

// Sanitize input
$id = (int)$_GET['id'];
$type = trim($_GET['type']);
$validTypes = ['ordinance', 'resolution', 'meeting'];

if (!in_array($type, $validTypes)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid document type']);
    exit();
}

// Fetch OCR content from database
$stmt = $conn->prepare("SELECT ocr_content, confidence_score FROM document_ocr_content WHERE document_id = ? AND document_type = ?");
$stmt->bind_param("is", $id, $type);
$stmt->execute();
$result = $stmt->get_result();
$ocrContent = $result->fetch_assoc();
$stmt->close();

// Return result
if ($ocrContent && !empty($ocrContent['ocr_content'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'content' => $ocrContent['ocr_content'],
        'confidence' => $ocrContent['confidence_score']
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No OCR content found']);
}
?>

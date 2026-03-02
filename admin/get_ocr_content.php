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
$type = strtolower(trim((string)$_GET['type']));
$lookupTypes = [];

if (in_array($type, ['executive_order', 'executive_orders'], true)) {
    $lookupTypes = ['executive_order', 'executive_orders'];
} elseif (in_array($type, ['resolution', 'resolutions'], true)) {
    $lookupTypes = ['resolution', 'resolutions'];
} elseif (in_array($type, ['meeting', 'minutes', 'minute', 'meeting_minutes'], true)) {
    $lookupTypes = ['meeting', 'minutes', 'minute', 'meeting_minutes'];
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid document type']);
    exit();
}

// Fetch OCR content from database
$stmt = null;
if (count($lookupTypes) === 2) {
    $stmt = $conn->prepare("SELECT ocr_content, confidence_score FROM document_ocr_content WHERE document_id = ? AND document_type IN (?, ?) ORDER BY updated_at DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("iss", $id, $lookupTypes[0], $lookupTypes[1]);
    }
} else {
    $stmt = $conn->prepare("SELECT ocr_content, confidence_score FROM document_ocr_content WHERE document_id = ? AND document_type IN (?, ?, ?, ?) ORDER BY updated_at DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("issss", $id, $lookupTypes[0], $lookupTypes[1], $lookupTypes[2], $lookupTypes[3]);
    }
}

if (!$stmt) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to prepare OCR lookup.']);
    exit();
}

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

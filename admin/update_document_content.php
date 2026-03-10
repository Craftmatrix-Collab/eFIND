<?php
// update_document_content.php
header('Content-Type: application/json');
include('includes/config.php');
include(__DIR__ . '/includes/logger.php');
require_once __DIR__ . '/includes/document_type_helper.php';

// Check if required parameters are set
if (!isset($_POST['id']) || !isset($_POST['document_type']) || !isset($_POST['content'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
    exit;
}

$id = intval($_POST['id']);
$document_type = trim((string)($_POST['document_type'] ?? ''));
$content = trim($_POST['content']);

try {
    $canonical_document_type = normalizeCanonicalDocumentType($document_type);
    if ($canonical_document_type === 'executive_order') {
        $stmt = $conn->prepare("UPDATE executive_orders SET content = ? WHERE id = ?");
    } elseif ($canonical_document_type === 'resolution') {
        $stmt = $conn->prepare("UPDATE resolutions SET content = ? WHERE id = ?");
    } elseif ($canonical_document_type === 'minutes') {
        $stmt = $conn->prepare("UPDATE minutes_of_meeting SET content = ? WHERE id = ?");
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid document type.']);
        exit;
    }

    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare content update query.']);
        exit;
    }

    $ocr_document_type = normalizeOcrDocumentType($canonical_document_type);

    $stmt->bind_param("si", $content, $id);
    $stmt->execute();
    
    // Check if the update was successful
    if ($stmt->affected_rows >= 0) {
        // Also update or insert into the OCR content table if it exists
        // First, check if the document_ocr_content table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'document_ocr_content'");
        
        if ($tableCheck && $tableCheck->num_rows > 0) {
            // Table exists, proceed with OCR content update
            $checkStmt = $conn->prepare("SELECT id FROM document_ocr_content WHERE document_id = ? AND document_type = ?");
            $checkStmt->bind_param("is", $id, $ocr_document_type);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                // Update existing OCR record
                $updateOcrStmt = $conn->prepare("UPDATE document_ocr_content SET ocr_content = ?, updated_at = CURRENT_TIMESTAMP WHERE document_id = ? AND document_type = ?");
                $updateOcrStmt->bind_param("sis", $content, $id, $ocr_document_type);
                $updateOcrStmt->execute();
                $updateOcrStmt->close();
            } else {
                // Insert new OCR record
                $insertOcrStmt = $conn->prepare("INSERT INTO document_ocr_content (document_id, document_type, ocr_content, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $insertOcrStmt->bind_param("iss", $id, $ocr_document_type, $content);
                $insertOcrStmt->execute();
                $insertOcrStmt->close();
            }
            $checkStmt->close();
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Content updated successfully.',
            'affected_rows' => $stmt->affected_rows
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'error' => 'No rows updated. Document may not exist or content is the same.'
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Database error in update_document_content.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>

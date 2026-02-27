<?php
// update_executive_order_content.php
session_start();
include(__DIR__ . '/includes/config.php');
include(__DIR__ . '/includes/auth.php');

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get the data
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$content = isset($_POST['content']) ? trim($_POST['content']) : '';

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid executive_order ID']);
    exit;
}

if (empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Content cannot be empty']);
    exit;
}

try {
    // Update the executive_order content in the database
    $stmt = $conn->prepare("UPDATE executive_orders SET content = ? WHERE id = ?");
    $stmt->bind_param("si", $content, $id);
    
    if ($stmt->execute()) {
        // Log the update
        logDocumentUpdate('executive_order', 'Content updated via OCR', $id, 'OCR text content updated');
        
        echo json_encode(['success' => true, 'message' => 'Content updated successfully']);
    } else {
        throw new Exception('Database update failed: ' . $stmt->error);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Update Executive Order Content Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to update content: ' . $e->getMessage()]);
}
?>
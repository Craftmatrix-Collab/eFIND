<?php

require_once 'FileUploadManager.php';
require_once 'TextExtractor.php';

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in production

// Set JSON header
header('Content-Type: application/json');

// Handle CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize upload manager and text extractor
$uploadManager = new FileUploadManager('uploads/');
$textExtractor = new TextExtractor('uploads/');

// Handle different actions
$action = $_GET['action'] ?? $_POST['action'] ?? 'upload';

switch ($action) {
    case 'upload':
        handleUpload($uploadManager, $textExtractor);
        break;
    
    case 'extract':
        handleExtractText($textExtractor);
        break;
    
    case 'capabilities':
        handleCapabilities($textExtractor);
        break;
    
    case 'history':
        handleHistory($uploadManager);
        break;
    
    case 'delete':
        handleDelete($uploadManager);
        break;
    
    default:
        sendResponse([
            'success' => false,
            'error' => 'invalid_action',
            'message' => 'Invalid action specified.'
        ], 400);
}

/**
 * Handle file upload
 */
function handleUpload($uploadManager, $textExtractor) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse([
            'success' => false,
            'error' => 'invalid_method',
            'message' => 'Only POST method is allowed for uploads.'
        ], 405);
    }
    
    if (!isset($_FILES['file'])) {
        sendResponse([
            'success' => false,
            'error' => 'no_file',
            'message' => 'No file was uploaded. Please select a file.'
        ], 400);
    }
    
    // Get options
    $options = [
        'force' => isset($_POST['force_upload']) && $_POST['force_upload'] === '1',
        'user_id' => $_POST['user_id'] ?? $_SERVER['REMOTE_ADDR']
    ];
    
    // Process upload
    $result = $uploadManager->upload($_FILES['file'], $options);
    
    if ($result['success']) {
        // Auto-extract text if requested
        $extractText = isset($_POST['extract_text']) && $_POST['extract_text'] === '1';
        if ($extractText) {
            $filePath = 'uploads/' . $result['data']['stored_name'];
            $extractOptions = [
                'ocr' => isset($_POST['use_ocr']) ? $_POST['use_ocr'] === '1' : true,
                'lang' => $_POST['ocr_lang'] ?? 'eng'
            ];
            
            $extractResult = $textExtractor->extractText($filePath, $extractOptions);
            $result['extraction'] = $extractResult;
        }
        
        sendResponse($result, 200);
    } else {
        $statusCode = $result['error'] === 'duplicate' ? 409 : 400;
        sendResponse($result, $statusCode);
    }
}

/**
 * Handle text extraction request
 */
function handleExtractText($textExtractor) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse([
            'success' => false,
            'error' => 'invalid_method',
            'message' => 'Only POST method is allowed.'
        ], 405);
    }
    
    $fileName = $_POST['filename'] ?? null;
    
    if (!$fileName) {
        sendResponse([
            'success' => false,
            'error' => 'missing_filename',
            'message' => 'Filename is required.'
        ], 400);
    }
    
    $filePath = 'uploads/' . basename($fileName);
    
    if (!file_exists($filePath)) {
        sendResponse([
            'success' => false,
            'error' => 'file_not_found',
            'message' => 'File not found: ' . $fileName
        ], 404);
    }
    
    $options = [
        'ocr' => isset($_POST['use_ocr']) ? $_POST['use_ocr'] === '1' : true,
        'lang' => $_POST['ocr_lang'] ?? 'eng'
    ];
    
    $result = $textExtractor->extractText($filePath, $options);
    
    sendResponse($result, $result['success'] ? 200 : 400);
}

/**
 * Handle capabilities request
 */
function handleCapabilities($textExtractor) {
    $capabilities = $textExtractor->getCapabilities();
    
    sendResponse([
        'success' => true,
        'capabilities' => $capabilities
    ], 200);
}

/**
 * Handle upload history request
 */
function handleHistory($uploadManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendResponse([
            'success' => false,
            'error' => 'invalid_method',
            'message' => 'Only GET method is allowed.'
        ], 405);
    }
    
    $filter = [];
    if (isset($_GET['user_id'])) {
        $filter['user_id'] = $_GET['user_id'];
    }
    
    $history = $uploadManager->getUploadHistory($filter);
    
    sendResponse([
        'success' => true,
        'data' => $history,
        'count' => count($history)
    ], 200);
}

/**
 * Handle delete request
 */
function handleDelete($uploadManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse([
            'success' => false,
            'error' => 'invalid_method',
            'message' => 'Only POST method is allowed.'
        ], 405);
    }
    
    $uploadId = $_POST['upload_id'] ?? null;
    
    if (!$uploadId) {
        sendResponse([
            'success' => false,
            'error' => 'missing_id',
            'message' => 'Upload ID is required.'
        ], 400);
    }
    
    $result = $uploadManager->deleteUpload($uploadId);
    
    sendResponse($result, $result['success'] ? 200 : 404);
}

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

<?php

require_once 'FileUploadManager.php';
require_once 'TextExtractor.php';
require_once __DIR__ . '/admin/includes/env_loader.php';
require_once __DIR__ . '/admin/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in production

// Set JSON header
header('Content-Type: application/json');

function normalizeUploadOrigin($origin) {
    $origin = trim((string)$origin);
    if ($origin === '' || strtolower($origin) === 'null') {
        return null;
    }

    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $normalized .= ':' . (int)$parts['port'];
    }

    return $normalized;
}

function getUploadCurrentOrigin() {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return null;
    }

    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto !== '') {
        $scheme = strtolower(explode(',', $forwardedProto)[0]);
    } else {
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
    }

    return normalizeUploadOrigin($scheme . '://' . $host);
}

function resolveAllowedUploadOrigin() {
    $requestOrigin = normalizeUploadOrigin($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($requestOrigin === null) {
        return null;
    }

    $allowedOrigins = [];
    $configuredOrigins = trim((string)(getenv('UPLOAD_ALLOWED_ORIGINS') ?: getenv('CHATBOT_ALLOWED_ORIGINS') ?: ''));
    if ($configuredOrigins !== '') {
        foreach (explode(',', $configuredOrigins) as $origin) {
            $normalized = normalizeUploadOrigin($origin);
            if ($normalized !== null) {
                $allowedOrigins[$normalized] = true;
            }
        }
    }

    $sameOrigin = getUploadCurrentOrigin();
    if ($sameOrigin !== null) {
        $allowedOrigins[$sameOrigin] = true;
    }

    return isset($allowedOrigins[$requestOrigin]) ? $requestOrigin : null;
}

function getAllowedRemoteUploadHosts() {
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }

    $hostMap = [];

    $currentHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($currentHost !== '') {
        $hostMap[strtolower(preg_replace('/:\d+$/', '', $currentHost))] = true;
    }

    $configuredHosts = trim((string)(getenv('UPLOAD_ALLOWED_REMOTE_HOSTS') ?: ''));
    if ($configuredHosts !== '') {
        foreach (explode(',', $configuredHosts) as $host) {
            $host = strtolower(trim((string)$host));
            if ($host !== '') {
                $hostMap[preg_replace('/:\d+$/', '', $host)] = true;
            }
        }
    }

    $minioEndpoint = trim((string)(getenv('MINIO_ENDPOINT') ?: ''));
    if ($minioEndpoint !== '') {
        $minioParts = parse_url(str_contains($minioEndpoint, '://') ? $minioEndpoint : ('https://' . $minioEndpoint));
        $minioHost = strtolower(trim((string)($minioParts['host'] ?? '')));
        if ($minioHost !== '') {
            $hostMap[$minioHost] = true;
        }
    }

    $hosts = array_keys($hostMap);
    return $hosts;
}

function isAllowedRemoteFileUrl($url) {
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? '')));

    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return false;
    }

    return in_array($host, getAllowedRemoteUploadHosts(), true);
}

header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
$allowedUploadOrigin = resolveAllowedUploadOrigin();
if ($allowedUploadOrigin !== null) {
    header('Access-Control-Allow-Origin: ' . $allowedUploadOrigin);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!empty($_SERVER['HTTP_ORIGIN']) && $allowedUploadOrigin === null) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'origin_not_allowed',
            'message' => 'Origin not allowed.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }
    http_response_code(204);
    exit();
}

if (!empty($_SERVER['HTTP_ORIGIN']) && $allowedUploadOrigin === null) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'origin_not_allowed',
        'message' => 'Origin not allowed.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

// Initialize upload manager and text extractor
$uploadManager = new FileUploadManager('uploads/');
$textExtractor = new TextExtractor('uploads/');

// Handle different actions
$action = $_GET['action'] ?? $_POST['action'] ?? 'upload';

$publicActions = ['capabilities'];
if (!in_array($action, $publicActions, true) && !isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'unauthorized',
        'message' => 'Authentication required.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

switch ($action) {
    case 'upload':
        handleUpload($uploadManager, $textExtractor);
        break;
    
    case 'extract':
        handleExtractText($textExtractor, $uploadManager);
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
        if (!empty($result['data']['file_url'])) {
            $result['data']['file_path'] = $result['data']['file_url'];
        }

        // Auto-extract text if requested
        $extractText = isset($_POST['extract_text']) && $_POST['extract_text'] === '1';
        if ($extractText) {
            $filePath = $_FILES['file']['tmp_name'];
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
function handleExtractText($textExtractor, $uploadManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse([
            'success' => false,
            'error' => 'invalid_method',
            'message' => 'Only POST method is allowed.'
        ], 405);
    }
    
    $fileName = trim((string)($_POST['filename'] ?? $_POST['file_url'] ?? ''));
    
    if ($fileName === '') {
        sendResponse([
            'success' => false,
            'error' => 'missing_filename',
            'message' => 'Filename is required.'
        ], 400);
    }
    
    $tempFilePath = null;
    $filePath = null;

    if (preg_match('#^(https?:)?//#i', $fileName)) {
        $download = downloadRemoteFileToTemp($fileName);
        if (!$download['success']) {
            sendResponse([
                'success' => false,
                'error' => 'file_not_found',
                'message' => $download['message']
            ], 404);
        }
        $tempFilePath = $download['path'];
        $filePath = $tempFilePath;
    } else {
        // Legacy local-file support
        $legacyLocalPath = 'uploads/' . basename($fileName);
        if (file_exists($legacyLocalPath)) {
            $filePath = $legacyLocalPath;
        } else {
            $history = $uploadManager->getUploadHistory();
            $fileUrl = null;
            foreach ($history as $record) {
                if (($record['stored_name'] ?? '') === basename($fileName) && !empty($record['file_url'])) {
                    $fileUrl = (string)$record['file_url'];
                    break;
                }
            }

            if ($fileUrl) {
                $download = downloadRemoteFileToTemp($fileUrl);
                if (!$download['success']) {
                    sendResponse([
                        'success' => false,
                        'error' => 'file_not_found',
                        'message' => $download['message']
                    ], 404);
                }
                $tempFilePath = $download['path'];
                $filePath = $tempFilePath;
            }
        }
    }

    if (!$filePath || !file_exists($filePath)) {
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

    if ($tempFilePath && file_exists($tempFilePath)) {
        unlink($tempFilePath);
    }

    sendResponse($result, $result['success'] ? 200 : 400);
}

/**
 * Download a remote file to a temporary path for OCR/text extraction.
 */
function downloadRemoteFileToTemp($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return ['success' => false, 'message' => 'Remote file URL is empty.'];
    }

    if (!isAllowedRemoteFileUrl($url)) {
        return ['success' => false, 'message' => 'Remote file host is not allowed.'];
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'efind_remote_');
    if (!$tmpFile) {
        return ['success' => false, 'message' => 'Failed to allocate temporary file.'];
    }

    $downloaded = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($tmpFile, 'wb');
        if ($ch && $fp) {
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $downloaded = $httpCode >= 200 && $httpCode < 300 && filesize($tmpFile) > 0;
            curl_close($ch);
        }
        if ($fp) {
            fclose($fp);
        }
    }

    if (!$downloaded) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'follow_location' => 0
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content !== false && $content !== '') {
            file_put_contents($tmpFile, $content);
            $downloaded = filesize($tmpFile) > 0;
        }
    }

    if (!$downloaded) {
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
        return ['success' => false, 'message' => 'Failed to download remote file for extraction.'];
    }

    return ['success' => true, 'path' => $tmpFile];
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

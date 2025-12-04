<?php
/**
 * POST Request Middleware
 * Ensures that certain endpoints only accept POST requests
 * Provides CSRF protection and request validation
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Enforce POST method for the current request
 * Call this at the top of any file that should only accept POST
 */
function requirePostMethod() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); // Method Not Allowed
        header('Allow: POST');
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed. Only POST requests are accepted.'
        ]);
        exit();
    }
}

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token from POST request
 */
function validateCsrfToken() {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Require valid CSRF token for POST requests
 */
function requireCsrfToken() {
    if (!validateCsrfToken()) {
        http_response_code(403); // Forbidden
        echo json_encode([
            'success' => false,
            'error' => 'Invalid CSRF token. Please refresh and try again.'
        ]);
        exit();
    }
}

/**
 * Validate that request has JSON content type
 */
function requireJsonContent() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        http_response_code(415); // Unsupported Media Type
        echo json_encode([
            'success' => false,
            'error' => 'Content-Type must be application/json'
        ]);
        exit();
    }
}

/**
 * Get JSON POST data
 */
function getJsonPostData() {
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); // Bad Request
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON data: ' . json_last_error_msg()
        ]);
        exit();
    }
    
    return $data;
}

/**
 * Sanitize POST input
 */
function sanitizePost($key, $default = '') {
    if (!isset($_POST[$key])) {
        return $default;
    }
    return htmlspecialchars(trim($_POST[$key]), ENT_QUOTES, 'UTF-8');
}

/**
 * Require specific POST fields
 */
function requirePostFields($fields) {
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        http_response_code(400); // Bad Request
        echo json_encode([
            'success' => false,
            'error' => 'Missing required fields: ' . implode(', ', $missing)
        ]);
        exit();
    }
}

/**
 * Complete POST middleware - combines all protections
 * Usage: requirePostMiddleware(['username', 'password'], true);
 */
function requirePostMiddleware($requiredFields = [], $checkCsrf = true) {
    requirePostMethod();
    
    if ($checkCsrf) {
        requireCsrfToken();
    }
    
    if (!empty($requiredFields)) {
        requirePostFields($requiredFields);
    }
}

/**
 * Send JSON response
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

?>

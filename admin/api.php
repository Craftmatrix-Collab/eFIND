<?php
// Prevent any output before headers
ob_start();

// Error logging setup - don't display errors
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/chatbot_errors.log');

// Log the incoming request immediately for debugging
$debugLog = __DIR__ . '/logs/chatbot_debug.log';
@file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . "API Called: " . $_SERVER['REQUEST_METHOD'] . " " . ($_SERVER['REQUEST_URI'] ?? 'no-uri') . "\n", FILE_APPEND);

// Start session for user tracking
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Send headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Database connection
require_once(__DIR__ . '/includes/config.php');

// N8N Configuration - PRODUCTION WEBHOOK URL
// IMPORTANT: This is the ACTIVE production webhook
// Updated: December 2, 2025
$N8N_WEBHOOK_URL = "https://n8n-efind.craftmatrix.org/webhook/5eaeb40b-8411-43ce-bee1-c32fc14e04f1";

// NOTE: This workflow must be ACTIVE (not in test mode) in n8n dashboard
// To verify: https://n8n-efind.craftmatrix.org

class BarangayChatbotAPI {
    private $n8nWebhookUrl;
    private $logFile;
    private $db;
    
    public function __construct($webhookUrl, $dbConnection = null) {
        $this->n8nWebhookUrl = $webhookUrl;
        $this->logFile = __DIR__ . '/logs/chatbot_activity.log';
        $this->db = $dbConnection;
        
        // Create logs directory if it doesn't exist
        $logDir = __DIR__ . '/logs';
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }
    
    public function handleRequest() {
        // Parse path - handle both /api.php/endpoint and /api/endpoint formats
        $requestUri = $_SERVER['REQUEST_URI'];
        $path = parse_url($requestUri, PHP_URL_PATH);
        
        // Remove /admin/ prefix if present
        $path = str_replace('/admin/', '/', $path);
        
        // Extract endpoint after api.php
        if (strpos($path, 'api.php') !== false) {
            $parts = explode('api.php', $path);
            $endpoint = isset($parts[1]) ? $parts[1] : '/';
        } else {
            $endpoint = $path;
        }
        
        $this->log("Request received: $endpoint [" . $_SERVER['REQUEST_METHOD'] . "]");
        
        switch($endpoint) {
            case '/health':
            case '/api/health':
                return $this->healthCheck();
            case '/categories':
            case '/api/categories':
                return $this->getCategories();
            case '/chat':
            case '/api/chat':
            case '':
            case '/':
                return $this->handleChat();
            default:
                $this->log("Endpoint not found: $endpoint");
                return $this->jsonResponse(['error' => 'Endpoint not found', 'requested' => $endpoint], 404);
        }
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $logMessage = "[$timestamp] [$ip] $message\n";
        @file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
    
    private function healthCheck() {
        return $this->jsonResponse([
            'status' => 'healthy',
            'service' => 'Barangay AI Chatbot',
            'timestamp' => date('c')
        ]);
    }
    
    private function getCategories() {
        $categories = [
            "Barangay Ordinances",
            "Resolutions", 
            "Meeting Minutes",
            "Public Safety",
            "Health Services",
            "Community Programs",
            "Infrastructure Projects",
            "Environmental Policies",
            "Business Permits",
            "Social Services"
        ];
        
        return $this->jsonResponse(['categories' => $categories]);
    }
    
    private function handleChat() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->log("Invalid method: " . $_SERVER['REQUEST_METHOD']);
            return $this->jsonResponse(['error' => 'Method not allowed. Use POST.'], 405);
        }
        
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON decode error: " . json_last_error_msg());
            return $this->jsonResponse(['error' => 'Invalid JSON payload'], 400);
        }
        
        if (!isset($input['message']) || empty(trim($input['message']))) {
            $this->log("Empty message received");
            return $this->jsonResponse(['error' => 'Message is required'], 400);
        }
        
        $userMessage = trim($input['message']);
        $sessionId = $input['sessionId'] ?? 'unknown';
        $userId = $input['userId'] ?? 'guest';
        
        $this->log("Chat request - User: $userId, Session: $sessionId, Message: " . substr($userMessage, 0, 50));
        
        // Log user question to database
        $this->logChatToDatabase($userId, $userMessage, 'user_message', $sessionId);
        
        try {
            // Send to n8n webhook with full context
            $n8nResponse = $this->sendToN8N($input);
            
            $this->log("N8N response received successfully");
            
            $botResponse = $n8nResponse['output'] ?? $n8nResponse['response'] ?? $n8nResponse['message'] ?? 'Response received';
            
            // Log bot response to database
            $this->logChatToDatabase($userId, $botResponse, 'bot_response', $sessionId, $userMessage);
            
            // Standardized response format
            return $this->jsonResponse([
                'output' => $botResponse,
                'timestamp' => date('c'),
                'sources' => $n8nResponse['sources'] ?? [],
                'confidence' => $n8nResponse['confidence'] ?? 0.9,
                'sessionId' => $sessionId,
                'status' => 'success'
            ]);
            
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            $this->log("N8N Error: $errorMsg");
            error_log("N8N Connection Error: $errorMsg");
            
            // Determine if this is a webhook activation issue
            $isFourOhFour = strpos($errorMsg, '404') !== false || strpos($errorMsg, 'not registered') !== false;
            
            $fallbackMessage = $isFourOhFour 
                ? "The chatbot service is currently being configured. Please ensure the n8n workflow is activated in production mode. Contact the administrator if this persists."
                : "I'm currently experiencing technical difficulties. Please contact the barangay office directly for immediate assistance, or try again in a moment.";
            
            // Log error response to database
            $this->logChatToDatabase($userId, $fallbackMessage, 'bot_error', $sessionId, $userMessage);
            
            // Fallback response if n8n is unavailable
            return $this->jsonResponse([
                'output' => $fallbackMessage,
                'timestamp' => date('c'),
                'fallback' => true,
                'error' => $errorMsg,
                'status' => 'fallback'
            ], 200); // Return 200 to prevent client-side error handling
        }
    }
    
    private function logChatToDatabase($userId, $message, $actionType, $sessionId, $context = '') {
        if (!$this->db) {
            return;
        }
        
        // Get user information
        $userName = 'Guest';
        $userRole = 'guest';
        $userIdInt = 0;
        
        if ($userId !== 'guest' && is_numeric($userId)) {
            $userIdInt = intval($userId);
            $stmt = $this->db->prepare("SELECT username, full_name, role FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $userIdInt);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $userName = $row['full_name'] ?? $row['username'];
                    $userRole = $row['role'] ?? 'user';
                }
                $stmt->close();
            }
        } elseif (isset($_SESSION['user_id'])) {
            $userIdInt = intval($_SESSION['user_id']);
            $userName = $_SESSION['username'] ?? $_SESSION['full_name'] ?? 'User';
            $userRole = $_SESSION['role'] ?? 'user';
        }
        
        // Prepare action and description based on type
        $action = 'chatbot';
        $description = '';
        $details = '';
        
        switch ($actionType) {
            case 'user_message':
                $description = "Asked chatbot: " . substr($message, 0, 100);
                $details = "User Question: " . $message . " | Session: " . $sessionId;
                break;
            case 'bot_response':
                $description = "Chatbot responded";
                $details = "Bot Response: " . substr($message, 0, 200) . " | Context: " . substr($context, 0, 100) . " | Session: " . $sessionId;
                break;
            case 'bot_error':
                $description = "Chatbot error occurred";
                $details = "Error: " . $message . " | User Question: " . $context . " | Session: " . $sessionId;
                break;
        }
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Insert into activity_logs
        $stmt = $this->db->prepare(
            "INSERT INTO activity_logs (user_id, user_name, user_role, action, description, details, ip_address, log_time) 
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        
        if ($stmt) {
            $stmt->bind_param(
                "issssss",
                $userIdInt,
                $userName,
                $userRole,
                $action,
                $description,
                $details,
                $ipAddress
            );
            $stmt->execute();
            $stmt->close();
        }
    }
    
    private function sendToN8N($inputData) {
        // Prepare payload - support both string and object input
        if (is_string($inputData)) {
            $payload = [
                'message' => $inputData,
                'timestamp' => date('c'),
                'sessionId' => $this->getSessionId(),
                'source' => 'web_chatbot'
            ];
        } else {
            // Forward all data from client
            $payload = $inputData;
            if (!isset($payload['timestamp'])) {
                $payload['timestamp'] = date('c');
            }
            if (!isset($payload['sessionId'])) {
                $payload['sessionId'] = $this->getSessionId();
            }
            if (!isset($payload['source'])) {
                $payload['source'] = 'web_chatbot';
            }
        }
        
        $this->log("Sending to n8n: " . substr($payload['message'], 0, 50));
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->n8nWebhookUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Barangay-AI-Chatbot/1.0'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        // Log curl details for debugging
        $this->log("N8N Response - HTTP Code: $httpCode, URL: {$curlInfo['url']}");
        
        if ($curlError) {
            throw new Exception("N8N connection error: $curlError");
        }
        
        if ($httpCode !== 200) {
            $errorDetail = $response ?: "No response body";
            $this->log("N8N HTTP Error $httpCode: $errorDetail");
            throw new Exception("N8N service unavailable. HTTP Code: $httpCode. Response: $errorDetail");
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("N8N response JSON error: " . json_last_error_msg());
            // Try to return raw response if JSON parsing fails
            return ['output' => $response];
        }
        
        // Flexible response handling - accept multiple formats
        if (!$data) {
            throw new Exception("Empty response from AI service");
        }
        
        $this->log("N8N response parsed successfully");
        
        return $data;
    }
    
    private function getSessionId() {
        if (!isset($_COOKIE['chat_session'])) {
            $sessionId = uniqid('barangay_', true);
            setcookie('chat_session', $sessionId, time() + (86400 * 30), "/"); // 30 days
            return $sessionId;
        }
        return $_COOKIE['chat_session'];
    }
    
    private function jsonResponse($data, $statusCode = 200) {
        // Clear any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

// Initialize and handle request with error catching
try {
    $api = new BarangayChatbotAPI($N8N_WEBHOOK_URL, $conn ?? null);
    $api->handleRequest();
} catch (Throwable $e) {
    // Clear any output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Log the error
    error_log("Chatbot API Fatal Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    // Return proper JSON error response
    http_response_code(200); // Use 200 to prevent browser from showing generic error
    header('Content-Type: application/json');
    echo json_encode([
        'output' => 'I apologize, but I encountered a technical issue. Please try again in a moment.',
        'error' => $e->getMessage(),
        'status' => 'error',
        'timestamp' => date('c')
    ]);
    exit;
}
?>
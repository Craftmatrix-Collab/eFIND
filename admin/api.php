<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// N8N Configuration
$N8N_WEBHOOK_URL = "https://your-n8n-domain.com/webhook/barangay-chatbot";
// For development, you can use ngrok: "https://your-ngrok-url.ngrok.io/webhook/barangay-chatbot"

class BarangayChatbotAPI {
    private $n8nWebhookUrl;
    
    public function __construct($webhookUrl) {
        $this->n8nWebhookUrl = $webhookUrl;
    }
    
    public function handleRequest() {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch($path) {
            case '/api/health':
                return $this->healthCheck();
            case '/api/categories':
                return $this->getCategories();
            case '/api/chat':
                return $this->handleChat();
            default:
                return $this->jsonResponse(['error' => 'Endpoint not found'], 404);
        }
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
            return $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['message']) || empty(trim($input['message']))) {
            return $this->jsonResponse(['error' => 'Message is required'], 400);
        }
        
        $userMessage = trim($input['message']);
        
        try {
            // Send to n8n webhook
            $n8nResponse = $this->sendToN8N($userMessage);
            
            return $this->jsonResponse([
                'response' => $n8nResponse['reply'],
                'timestamp' => date('c'),
                'sources' => $n8nResponse['sources'] ?? [],
                'confidence' => $n8nResponse['confidence'] ?? 0.9
            ]);
            
        } catch (Exception $e) {
            error_log("N8N Connection Error: " . $e->getMessage());
            
            // Fallback response if n8n is unavailable
            return $this->jsonResponse([
                'response' => "I'm currently updating my knowledge base. Please contact the barangay office directly for immediate assistance, or try again later.",
                'timestamp' => date('c'),
                'fallback' => true
            ]);
        }
    }
    
    private function sendToN8N($message) {
        $payload = [
            'message' => $message,
            'timestamp' => date('c'),
            'session_id' => $this->getSessionId(),
            'source' => 'web_chatbot'
        ];
        
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
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("N8N service unavailable. HTTP Code: $httpCode");
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['reply'])) {
            throw new Exception("Invalid response from AI service");
        }
        
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
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}

// Initialize and handle request
$api = new BarangayChatbotAPI($N8N_WEBHOOK_URL);
$api->handleRequest();
?>
<?php
// Simple test script to verify chatbot API
echo "<h2>Chatbot API Diagnostic Test</h2>";

// Test 1: Check if api.php exists
$apiFile = __DIR__ . '/api.php';
echo "<h3>Test 1: File Exists</h3>";
if (file_exists($apiFile)) {
    echo "✓ api.php exists at: $apiFile<br>";
} else {
    echo "✗ api.php NOT FOUND at: $apiFile<br>";
}

// Test 2: Check logs directory
echo "<h3>Test 2: Logs Directory</h3>";
$logsDir = __DIR__ . '/logs';
if (is_dir($logsDir)) {
    echo "✓ Logs directory exists<br>";
    echo "Writable: " . (is_writable($logsDir) ? "Yes" : "No") . "<br>";
} else {
    echo "✗ Logs directory does not exist<br>";
    if (mkdir($logsDir, 0755, true)) {
        echo "✓ Created logs directory<br>";
    }
}

// Test 3: Test n8n connectivity
echo "<h3>Test 3: N8N Webhook Connectivity</h3>";
$n8nUrl = "https://n8n-efind.craftmatrix.org/webhook/5eaeb40b-8411-43ce-bee1-c32fc14e04f1";
$testPayload = json_encode([
    'message' => 'Test from diagnostic script',
    'sessionId' => 'test_' . time(),
    'timestamp' => date('c')
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $n8nUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $testPayload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "✗ CURL Error: $curlError<br>";
} else {
    echo "✓ HTTP Response Code: $httpCode<br>";
    if ($httpCode == 200) {
        echo "✓ N8N webhook is accessible<br>";
        echo "Response preview: " . htmlspecialchars(substr($response, 0, 200)) . "...<br>";
    } else {
        echo "✗ N8N returned error code: $httpCode<br>";
        echo "Response: " . htmlspecialchars($response) . "<br>";
    }
}

// Test 4: Test API endpoint directly
echo "<h3>Test 4: Direct API Call</h3>";
echo "Testing POST to api.php/chat<br>";

// Simulate API call
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/admin/api.php/chat';
$_SERVER['CONTENT_TYPE'] = 'application/json';

$testMessage = json_encode([
    'message' => 'Hello chatbot',
    'sessionId' => 'test_session_' . time(),
    'userId' => 'test_user',
    'timestamp' => date('c')
]);

// Save current output buffer
ob_start();

try {
    // Mock the input
    $_SERVER['REQUEST_METHOD'] = 'GET'; // Don't actually POST
    
    echo "To test the API, use this curl command:<br><pre>";
    echo "curl -X POST http://localhost/admin/api.php/chat \\\n";
    echo "  -H 'Content-Type: application/json' \\\n";
    echo "  -d '$testMessage'\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "✗ Error: " . htmlspecialchars($e->getMessage()) . "<br>";
}

$output = ob_get_clean();
echo $output;

// Test 5: Session check
echo "<h3>Test 5: Session Status</h3>";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "✓ Session is active<br>";
    echo "Session ID: " . session_id() . "<br>";
} else {
    echo "Session not active<br>";
    session_start();
    echo "✓ Session started<br>";
}

echo "<h3>Recommendations:</h3>";
echo "<ul>";
echo "<li>Check browser console for JavaScript errors</li>";
echo "<li>Verify the URL path in browser network tab</li>";
echo "<li>Check if Apache mod_rewrite is enabled if using clean URLs</li>";
echo "<li>Review chatbot_activity.log in logs/ directory</li>";
echo "</ul>";
?>

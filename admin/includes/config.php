<?php
// MariaDB Configuration
$servername = "72.60.233.7";
$username = "root";
$password = "3xQ7fuQVu7SyYCnu15Hj44U0wf0ozulOH2U3Ggt8shqZ1K27MuvC3tHqY9dyOZd6";
$dbname = "barangay_poblacion_south";
$port = 9008;

// Create MariaDB connection
$conn = new mysqli($servername, $username, $password, $dbname, $port);

// Check MariaDB connection
if ($conn->connect_error) {
    die("MariaDB Connection failed: " . $conn->connect_error);
}

// OpenAI API Configuration
define('OPENAI_API_KEY', 'your-openai-api-key-here');

// RAG Chatbot Configuration
define('LANGCHAIN_API_URL', 'http://localhost:8000');
define('LANGCHAIN_API_KEY', 'barangay-rag-secret-2024');
define('RAG_SIMILARITY_THRESHOLD', 0.7);
define('RAG_ENABLED', true);

// MariaDB Configuration for PDO
define('DB_HOST', 'localhost');
define('DB_NAME', 'barangay_poblacion_south');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', 3306);

// Create PDO connection for MariaDB RAG chatbot
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("MariaDB PDO Connection failed: " . $e->getMessage());
}

// Application Settings
define('APP_NAME', 'Barangay Poblacion South eFIND System');
define('APP_VERSION', '1.0');
define('CHATBOT_ENABLED', true);

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session Configuration
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
<?php
// MariaDB Configuration - Use environment variables with fallback
$servername = getenv('DB_HOST') ?: '72.60.233.70';
$username = 'root';  // Changed from 'mariadb' to 'root' - check external DB user permissions
$password = '3xQ7fuQVu7SyYCnu15Hj44U0wf0ozulOH2U3Ggt8shqZ1K27MuvC3tHqY9dyOZd6';  // Using root password
$dbname = 'barangay_poblacion_south';
$port = getenv('DB_PORT') ?: 9008;

// Set default timezone to Philippine Time (Asia/Manila = UTC+8)
date_default_timezone_set('Asia/Manila');
 
// Create MariaDB connection with improved settings
try {
    $conn = @mysqli_init();
    
    if (!$conn) {
        throw new Exception("mysqli_init failed");
    }
    
    // Set connection options to prevent timeout issues
    @mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    @mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 30);
    
    // Establish connection
    $connected = @$conn->real_connect($servername, $username, $password, $dbname, $port);
    
    if (!$connected) {
        $error = mysqli_connect_error() ? mysqli_connect_error() : "Unknown connection error";
        throw new Exception("Connection failed: " . $error);
    }
    
    // Set charset to prevent encoding issues
    if (!@$conn->set_charset("utf8mb4")) {
        error_log("Error loading character set utf8mb4: " . $conn->error);
    }
    
    // Set database session timezone to Philippine Time
    @$conn->query("SET time_zone = '+08:00'");
    
    // Enable autocommit
    @$conn->autocommit(TRUE);
    
} catch (Exception $e) {
    error_log("MariaDB Connection Error: " . $e->getMessage());
    die("Database connection failed. Please check your database configuration.");
}

// OpenAI API Configuration
define('OPENAI_API_KEY', 'your-openai-api-key-here');

// Resend API Configuration
// NOTE: 'onboarding@resend.dev' is a Resend sandbox sender that can ONLY deliver to
// the verified owner email of your Resend account. For production use, replace it with
// a sender address from a custom domain you have verified in Resend (e.g. 'noreply@yourdomain.com').
define('RESEND_API_KEY', 're_deTt6GnC_7a4nJ7x2nJePTNoNzZUkcu3e');
define('FROM_EMAIL', 'eFIND System <youremail@craftmatrix.org>');

// MariaDB Configuration for PDO
define('DB_HOST', $servername);
define('DB_NAME', $dbname);
define('DB_USER', $username);
define('DB_PASS', $password);
define('DB_PORT', $port);

// Create PDO connection for MariaDB RAG chatbot with improved error handling
try {
    $pdo_options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
        PDO::ATTR_PERSISTENT => false
    ];
    
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
        DB_USER, 
        DB_PASS,
        $pdo_options
    );
} catch (PDOException $e) {
    error_log("MariaDB PDO Connection failed: " . $e->getMessage());
    die("Database connection failed. Please check your database configuration.");
}

// Application Settings
define('APP_NAME', 'Barangay Poblacion South eFIND System');
define('APP_VERSION', '1.0');
define('CHATBOT_ENABLED', true);

// Error Reporting
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0); // Disable display errors in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// Note: Session start is handled by individual pages
// Each page calls session_start() before including this config file
?>
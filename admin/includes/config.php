<?php
require_once __DIR__ . '/env_loader.php';
// MariaDB Configuration - Use environment variables with fallback
$servername = getenv('DB_HOST') ?: '72.60.233.70';
$username = getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root');
$password = getenv('DB_PASS');
if ($password === false || $password === '') {
    $password = getenv('DB_PASSWORD') ?: '';
}
$dbname = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'barangay_poblacion_south');
$port = (int)(getenv('DB_PORT') ?: 9008);

// Set default timezone to Philippine Time (Asia/Manila = UTC+8)
date_default_timezone_set('Asia/Manila');
 
// Create MariaDB connection with improved settings
try {
    $connectionTargets = [[$servername, (int)$port]];
    // If DB_HOST is not explicitly configured, try local defaults as fallbacks.
    if (!getenv('DB_HOST')) {
        $connectionTargets[] = ['127.0.0.1', 3306];
        $connectionTargets[] = ['localhost', 3306];
        $connectionTargets[] = ['db', 3306];
    }

    $connected = false;
    $lastError = 'Unknown connection error';
    $activeHost = $servername;
    $activePort = (int)$port;

    foreach ($connectionTargets as $target) {
        [$targetHost, $targetPort] = $target;
        $conn = @mysqli_init();
        if (!$conn) {
            throw new Exception("mysqli_init failed");
        }

        // Set connection options to prevent timeout issues
        @mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        @mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 30);

        $connected = @$conn->real_connect($targetHost, $username, $password, $dbname, (int)$targetPort);
        if ($connected) {
            $activeHost = $targetHost;
            $activePort = (int)$targetPort;
            break;
        }

        $lastError = mysqli_connect_error() ?: ($conn->connect_error ?: 'Unknown connection error');
    }

    if (!$connected) {
        throw new Exception("Connection failed: " . $lastError);
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
    error_log("MariaDB Connection Error: " . $e->getMessage() . " | host=" . $servername . " port=" . $port . " db=" . $dbname . " user=" . $username);
    die("Database connection failed. Please check your database configuration.");
}

// OpenAI API Configuration
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: ''));
}

// Resend API Configuration
// NOTE: 'onboarding@resend.dev' is a Resend sandbox sender that can ONLY deliver to
// the verified owner email of your Resend account. For production use, replace it with
// a sender address from a custom domain you have verified in Resend (e.g. 'noreply@yourdomain.com').
define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');
define('FROM_EMAIL', getenv('FROM_EMAIL') ?: 'eFIND System <youremail@craftmatrix.org>');

// MariaDB Configuration for PDO
define('DB_HOST', $activeHost ?? $servername);
define('DB_NAME', $dbname);
define('DB_USER', $username);
define('DB_PASS', $password);
define('DB_PORT', $activePort ?? $port);

// Create PDO connection for MariaDB RAG chatbot with improved error handling.
// This is optional for core login/auth flows which rely on mysqli.
$pdo = null;
if (class_exists('PDO') && extension_loaded('pdo_mysql')) {
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
    } catch (Throwable $e) {
        error_log("MariaDB PDO Connection failed (non-fatal): " . $e->getMessage());
    }
} else {
    error_log("PDO MySQL extension not available; continuing with mysqli only.");
}

// Application Settings
define('APP_NAME', 'Barangay Poblacion South eFIND System');
define('APP_VERSION', '1.0');
define('CHATBOT_ENABLED', $pdo !== null);

// Error Reporting
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0); // Disable display errors in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// Note: Session start is handled by individual pages
// Each page calls session_start() before including this config file
?>

<?php
require_once __DIR__ . '/env_loader.php';

if (!function_exists('efind_first_env_value')) {
    function efind_first_env_value(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value === false) {
                continue;
            }

            $trimmed = trim((string)$value);
            if ($trimmed === '') {
                continue;
            }

            return $trimmed;
        }

        return null;
    }
}

if (!function_exists('efind_is_placeholder_secret')) {
    function efind_is_placeholder_secret(string $value): bool
    {
        return in_array(
            strtolower(trim($value)),
            ['change-me', 'changeme', 'change-this-password', 'your-password', 'your-password-here', 'your_db_password', 'password'],
            true
        );
    }
}

// MariaDB Configuration - Use environment variables with fallback
$defaultDbHost = '72.60.233.70';
$defaultDbPort = 9008;
$defaultDbName = 'barangay_poblacion_south';

$servername = efind_first_env_value(['DB_HOST']) ?: $defaultDbHost;
$username = efind_first_env_value(['DB_USER', 'DB_USERNAME']) ?: 'root';
$dbname = efind_first_env_value(['DB_NAME', 'DB_DATABASE']) ?: $defaultDbName;

$port = $defaultDbPort;
$configuredPort = efind_first_env_value(['DB_PORT']);
if ($configuredPort !== null && ctype_digit($configuredPort)) {
    $parsedPort = (int)$configuredPort;
    if ($parsedPort > 0 && $parsedPort <= 65535) {
        $port = $parsedPort;
    }
}

$passwordCandidates = [];
$placeholderPasswordCandidates = [];
$passwordKeys = [
    'DB_PASSWORD',
    'DB_PASS',
    'DB_ROOT_PASSWORD',
    'MYSQL_PASSWORD',
    'MYSQL_ROOT_PASSWORD',
    'MARIADB_PASSWORD',
    'MARIADB_ROOT_PASSWORD'
];

foreach ($passwordKeys as $passwordKey) {
    $candidate = efind_first_env_value([$passwordKey]);
    if ($candidate === null) {
        continue;
    }

    if (efind_is_placeholder_secret($candidate)) {
        if (!in_array($candidate, $placeholderPasswordCandidates, true)) {
            $placeholderPasswordCandidates[] = $candidate;
        }
        continue;
    }

    if (!in_array($candidate, $passwordCandidates, true)) {
        $passwordCandidates[] = $candidate;
    }
}

// For template/default root setups, probe blank password before placeholder values.
if (count($passwordCandidates) === 0 && strtolower($username) === 'root' && !in_array('', $passwordCandidates, true)) {
    $passwordCandidates[] = '';
}

foreach ($placeholderPasswordCandidates as $candidate) {
    if (!in_array($candidate, $passwordCandidates, true)) {
        $passwordCandidates[] = $candidate;
    }
}

if (count($passwordCandidates) === 0) {
    $passwordCandidates[] = '';
}

$password = $passwordCandidates[0];

// Set default timezone to Philippine Time (Asia/Manila = UTC+8)
date_default_timezone_set('Asia/Manila');
 
// Create MariaDB connection with improved settings
try {
    mysqli_report(MYSQLI_REPORT_OFF);

    $connectionTargets = [];
    $addConnectionTarget = static function (string $host, int $targetPort) use (&$connectionTargets): void {
        $normalizedHost = strtolower(trim($host));
        if ($normalizedHost === '' || $targetPort <= 0 || $targetPort > 65535) {
            return;
        }

        $targetKey = $normalizedHost . ':' . $targetPort;
        if (!isset($connectionTargets[$targetKey])) {
            $connectionTargets[$targetKey] = [$host, $targetPort];
        }
    };

    $addConnectionTarget($servername, (int)$port);

    $isLikelyTemplateDbConfig =
        in_array(strtolower($servername), ['127.0.0.1', 'localhost', 'db'], true) &&
        (int)$port === 3306 &&
        strtolower($username) === 'root' &&
        strtolower($dbname) === strtolower($defaultDbName);

    // When template defaults are in play, probe common deployment targets.
    if (!getenv('DB_HOST') || $isLikelyTemplateDbConfig) {
        $addConnectionTarget('127.0.0.1', 9008);
        $addConnectionTarget('127.0.0.1', 3306);
        $addConnectionTarget('localhost', 3306);
        $addConnectionTarget('db', 3306);
        $addConnectionTarget($defaultDbHost, $defaultDbPort);
    }

    $connected = false;
    $lastError = 'Unknown connection error';
    $activeHost = $servername;
    $activePort = (int)$port;
    $attemptedTargets = [];

    foreach (array_values($connectionTargets) as $target) {
        [$targetHost, $targetPort] = $target;
        foreach ($passwordCandidates as $candidatePassword) {
            $conn = mysqli_init();
            if (!$conn) {
                throw new Exception("mysqli_init failed");
            }

            // Set connection options to prevent timeout issues
            mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
            mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 30);

            $connected = @$conn->real_connect($targetHost, $username, $candidatePassword, $dbname, (int)$targetPort);
            if ($connected) {
                $activeHost = $targetHost;
                $activePort = (int)$targetPort;
                $password = $candidatePassword;
                break 2;
            }

            $lastError = mysqli_connect_error() ?: ($conn->connect_error ?: 'Unknown connection error');
            $attemptedTargets[] = $targetHost . ':' . (int)$targetPort . ' -> ' . $lastError;
            $conn->close();
        }
    }

    if (!$connected) {
        $attemptContext = implode(' | ', array_slice($attemptedTargets, -5));
        throw new Exception("Connection failed: " . $lastError . ($attemptContext !== '' ? " | attempts: " . $attemptContext : ''));
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

// Email Configuration
// NOTE: 'onboarding@resend.dev' is a Resend sandbox sender that can ONLY deliver to
// the verified owner email of your Resend account. For production use, replace it with
// a sender address from a custom domain you have verified in Resend (e.g. 'noreply@yourdomain.com').
$resendApiKey = getenv('RESEND_API_KEY');
if ($resendApiKey === false || $resendApiKey === '') {
    // Backward-compatible fallback for SMTP-style deployment variables.
    $resendApiKey = getenv('SMTP_PASSWORD') ?: '';
}

$fromEmail = getenv('FROM_EMAIL');
if ($fromEmail === false || trim((string)$fromEmail) === '') {
    $smtpFrom = getenv('SMTP_FROM') ?: (getenv('SMTP_USER') ?: (getenv('SMTP_USERNAME') ?: ''));
    if ($smtpFrom !== '') {
        $fromEmail = 'eFIND System <' . trim((string)$smtpFrom) . '>';
    }
}
if ($fromEmail === false || trim((string)$fromEmail) === '') {
    $fromEmail = 'eFIND System <youremail@craftmatrix.org>';
}

define('RESEND_API_KEY', $resendApiKey);
define('FROM_EMAIL', $fromEmail);

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

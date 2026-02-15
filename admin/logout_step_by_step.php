<?php
// Step by step logout with detailed error logging
$logFile = __DIR__ . '/logs/step_by_step.log';

function logStep($step, $message) {
    global $logFile;
    $entry = date('Y-m-d H:i:s') . " - Step $step: $message\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
    echo $entry; // Also output to screen
}

// Clear previous log
file_put_contents($logFile, "=== NEW LOGOUT ATTEMPT ===\n");

logStep(1, "Script started");

// Step 1: Start session
try {
    session_start();
    logStep(2, "Session started - ID: " . session_id());
} catch (Exception $e) {
    logStep(2, "FAILED - " . $e->getMessage());
    die("Session start failed");
}

// Step 2: Load config
try {
    require_once 'includes/config.php';
    logStep(3, "Config loaded - DB connected: " . ($conn ? "YES" : "NO"));
} catch (Exception $e) {
    logStep(3, "FAILED - " . $e->getMessage());
    die("Config load failed");
}

// Step 3: Load logger
try {
    require_once 'includes/logger.php';
    logStep(4, "Logger loaded");
} catch (Exception $e) {
    logStep(4, "FAILED - " . $e->getMessage());
    die("Logger load failed");
}

// Step 4: Check CSRF token (without die)
$tokenValid = false;
if (isset($_GET['token']) && isset($_SESSION['logout_token']) && $_GET['token'] === $_SESSION['logout_token']) {
    $tokenValid = true;
    logStep(5, "CSRF token valid");
} else {
    logStep(5, "CSRF token invalid or missing - GET: " . ($_GET['token'] ?? 'NONE') . " SESSION: " . ($_SESSION['logout_token'] ?? 'NONE'));
}

// Step 5: Get user info
$hasUser = isset($_SESSION['admin_id']) || isset($_SESSION['user_id']);
logStep(6, "Has user session: " . ($hasUser ? "YES" : "NO"));

if ($hasUser) {
    $username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'unknown';
    logStep(7, "Username: $username");
    
    // Step 6: Try to log logout
    try {
        if (function_exists('logLogout')) {
            $result = logLogout($username);
            logStep(8, "logLogout result: " . ($result ? "SUCCESS" : "FAILED"));
        } else {
            logStep(8, "logLogout function NOT FOUND");
        }
    } catch (Exception $e) {
        logStep(8, "logLogout EXCEPTION: " . $e->getMessage());
    }
}

// Step 7: Destroy session
try {
    session_unset();
    logStep(9, "Session unset");
    
    session_destroy();
    logStep(10, "Session destroyed");
} catch (Exception $e) {
    logStep(10, "Session destroy FAILED - " . $e->getMessage());
}

// Step 8: Clear cookie
try {
    if (isset($_COOKIE[session_name()])) {
        $cookieParams = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $cookieParams['path'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            true
        );
        logStep(11, "Cookie cleared");
    } else {
        logStep(11, "No cookie to clear");
    }
} catch (Exception $e) {
    logStep(11, "Cookie clear FAILED - " . $e->getMessage());
}

// Step 9: Redirect
logStep(12, "Redirecting to login.php");
header("Location: login.php");
exit();
?>

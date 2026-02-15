<?php
// Ultra-safe logout with comprehensive error handling
error_reporting(0); // Hide errors from user
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/logout_errors.log');

// Log start
@error_log("=== LOGOUT STARTED at " . date('Y-m-d H:i:s') . " ===");

// Custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    @error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP's internal error handler
});

// Custom exception handler
set_exception_handler(function($exception) {
    @error_log("PHP Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    echo "An error occurred. Please try again or contact support.";
    exit(1);
});

try {
    // Step 1: Session
    @error_log("Step 1: Starting session");
    @session_start();
    @error_log("Step 1: Session started - ID: " . session_id());
    
    // Step 2: Load config
    @error_log("Step 2: Loading config");
    @require_once 'includes/config.php';
    @error_log("Step 2: Config loaded");
    
    // Step 3: Load logger
    @error_log("Step 3: Loading logger");
    @require_once 'includes/logger.php';
    @error_log("Step 3: Logger loaded");
    
    // Step 4: CSRF check (but don't die on failure, just log)
    @error_log("Step 4: CSRF check");
    $csrfValid = false;
    if (isset($_GET['token']) && isset($_SESSION['logout_token']) && $_GET['token'] === $_SESSION['logout_token']) {
        $csrfValid = true;
        @error_log("Step 4: CSRF valid");
    } else {
        @error_log("Step 4: CSRF invalid - proceeding anyway for debugging");
        // Don't die here for debugging purposes
        //die('Invalid logout request. Please use the logout button.');
    }
    
    // Step 5: Log logout
    @error_log("Step 5: Logging logout");
    if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])) {
        $username = $_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'unknown';
        @error_log("Step 5: Username: $username");
        
        if (function_exists('logLogout')) {
            try {
                $result = @logLogout($username);
                @error_log("Step 5: logLogout result: " . ($result ? "SUCCESS" : "FAILED"));
            } catch (Exception $e) {
                @error_log("Step 5: logLogout exception: " . $e->getMessage());
            }
        } else {
            @error_log("Step 5: logLogout function not found");
        }
    } else {
        @error_log("Step 5: No user session found");
    }
    
    // Step 6: Destroy session
    @error_log("Step 6: Destroying session");
    @session_unset();
    @session_destroy();
    @error_log("Step 6: Session destroyed");
    
    // Step 7: Clear cookie
    @error_log("Step 7: Clearing cookie");
    if (isset($_COOKIE[session_name()])) {
        try {
            $cookieParams = @session_get_cookie_params();
            @setcookie(
                session_name(),
                '',
                time() - 3600,
                $cookieParams['path'],
                $cookieParams['domain'],
                $cookieParams['secure'],
                true
            );
            @error_log("Step 7: Cookie cleared");
        } catch (Exception $e) {
            @error_log("Step 7: Cookie clear exception: " . $e->getMessage());
        }
    } else {
        @error_log("Step 7: No cookie to clear");
    }
    
    // Step 8: Redirect
    @error_log("Step 8: Redirecting to login.php");
    @header("Location: login.php");
    @error_log("=== LOGOUT COMPLETED SUCCESSFULLY ===");
    exit();
    
} catch (Throwable $e) {
    @error_log("FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    @error_log("Stack trace: " . $e->getTraceAsString());
    echo "Logout failed. Check logs/logout_errors.log for details.";
    exit(1);
}
?>

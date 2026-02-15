<?php
// Absolute minimal logout - no dependencies
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/minimal_logout.log');
error_reporting(E_ALL);

file_put_contents(__DIR__ . '/logs/minimal_logout.log', date('Y-m-d H:i:s') . " - Starting minimal logout\n", FILE_APPEND);

try {
    session_start();
    file_put_contents(__DIR__ . '/logs/minimal_logout.log', date('Y-m-d H:i:s') . " - Session started\n", FILE_APPEND);
    
    session_unset();
    file_put_contents(__DIR__ . '/logs/minimal_logout.log', date('Y-m-d H:i:s') . " - Session unset\n", FILE_APPEND);
    
    session_destroy();
    file_put_contents(__DIR__ . '/logs/minimal_logout.log', date('Y-m-d H:i:s') . " - Session destroyed\n", FILE_APPEND);
    
    header("Location: login.php");
    file_put_contents(__DIR__ . '/logs/minimal_logout.log', date('Y-m-d H:i:s') . " - Redirecting\n", FILE_APPEND);
    exit();
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/logs/minimal_logout.log', date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    die("Error: " . $e->getMessage());
}
?>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
$sessionCsrfToken = (string)($_SESSION['csrf_token'] ?? '');
if ($sessionCsrfToken === '' || !hash_equals($sessionCsrfToken, $csrfToken)) {
    http_response_code(403);
    exit;
}

if (isset($conn) && $conn instanceof mysqli) {
    markPrimaryLoginSessionPendingBrowserExit($conn);
}

http_response_code(204);
exit;
?>

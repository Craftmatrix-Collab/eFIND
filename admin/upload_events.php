<?php
/**
 * Server-Sent Events endpoint for real-time upload notifications.
 * Desktop admin pages connect to this; they receive an event whenever
 * a new document is uploaded (via any method, mobile or desktop).
 *
 * GET /admin/upload_events.php
 */
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

// Disable output buffering so events stream immediately
if (ob_get_level()) ob_end_clean();
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Helper: send SSE event
function sendEvent($event, $data) {
    echo "event: $event\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// Keep-alive heartbeat every 20 s; check for new docs every 5 s
$lastCheck  = time();
$pollInterval = 5;   // seconds between DB polls
$maxRuntime  = 55;   // reconnect before PHP/nginx timeout (client auto-reconnects)

sendEvent('connected', ['message' => 'Listening for uploads…']);

$tables = [
    'resolutions'      => ['label' => 'Resolution',        'page' => 'resolutions.php'],
    'minutes_of_meeting' => ['label' => 'Minutes of Meeting', 'page' => 'minutes_of_meeting.php'],
    'executive_orders'       => ['label' => 'Executive Order',         'page' => 'executive_orders.php'],
];

$startTime = time();

while (true) {
    if (connection_aborted()) break;
    if ((time() - $startTime) >= $maxRuntime) {
        sendEvent('reconnect', ['message' => 'Reconnecting…']);
        break;
    }

    sleep($pollInterval);

    if (connection_aborted()) break;

    // Query each table for rows inserted in the last ($pollInterval + 1) seconds
    $window = $pollInterval + 1;
    foreach ($tables as $table => $meta) {
        $col = match($table) {
            'resolutions'       => 'date_posted',
            'minutes_of_meeting'=> 'date_posted',
            'executive_orders'        => 'date_posted',
        };
        // Use a narrow time window to avoid duplicate notifications
        $sql = "SELECT id, title, uploaded_by FROM `$table`
                WHERE `$col` >= DATE_SUB(NOW(), INTERVAL $window SECOND)
                ORDER BY id DESC LIMIT 5";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                sendEvent('new_upload', [
                    'doc_type'    => $table,
                    'label'       => $meta['label'],
                    'id'          => $row['id'],
                    'title'       => $row['title'],
                    'uploaded_by' => $row['uploaded_by'],
                    'page'        => $meta['page'],
                ]);
            }
        }
    }

    // Send heartbeat comment to keep connection alive
    echo ": heartbeat\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

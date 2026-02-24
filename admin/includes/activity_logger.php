<?php
if (!function_exists('logActivity')) {
    function logActivity($conn, $user_name, $action, $details) {
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_name, action, details, log_time) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $user_name, $action, $details);
        $stmt->execute();
        $stmt->close();
    }
}
?>
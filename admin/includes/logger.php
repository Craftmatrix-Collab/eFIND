<?php
// includes/logger.php

// Helper function to format timestamp for display
if (!function_exists('formatPhilippineTime')) {
    /**
     * Format a timestamp to Philippine Time
     * @param string $datetime - Database datetime string
     * @param string $format - PHP date format (default: 'M d, Y h:i:s A')
     * @return string Formatted datetime string in Philippine Time
     */
    function formatPhilippineTime($datetime, $format = 'M d, Y h:i:s A') {
        if (empty($datetime)) {
            return 'N/A';
        }
        
        // Since database is now set to Asia/Manila timezone (+08:00)
        // and PHP default timezone is also Asia/Manila,
        // we can use the standard date() function
        $timestamp = strtotime($datetime);
        
        if ($timestamp === false) {
            return 'Invalid Date';
        }
        
        return date($format, $timestamp);
    }
}

// Check if function doesn't exist to avoid redeclaration errors
if (!function_exists('checkActivityLogsTable')) {
    /**
     * Check if activity_logs table exists, create if not
     */
    function checkActivityLogsTable() {
        global $conn;
        
        $checkTableQuery = "SHOW TABLES LIKE 'activity_logs'";
        $tableResult = $conn->query($checkTableQuery);
        
        if ($tableResult->num_rows == 0) {
            $createTableQuery = "CREATE TABLE activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                user_name VARCHAR(255) NULL,
                action VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                details TEXT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT NULL,
                document_id INT NULL,
                document_type VARCHAR(100) NULL,
                log_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_action (action),
                INDEX idx_log_time (log_time),
                INDEX idx_document_type (document_type),
                INDEX idx_ip_address (ip_address)
            )";
            
            if (!$conn->query($createTableQuery)) {
                error_log("Failed to create activity_logs table: " . $conn->error);
            } else {
                error_log("Created activity_logs table successfully");
            }
        }
    }
}

if (!function_exists('logActivity')) {
    /**
     * Main activity logging function
     */
    function logActivity($action, $description = '', $details = '', $user_id = null, $document_id = null, $document_type = null) {
        global $conn;
        
        // If user_id is not provided, get from session
        if ($user_id === null && isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
        }
        
        // Get user name from session or database
        $user_name = $_SESSION['full_name'] ?? 'Unknown User';
        
        // Get IP address
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        // Get user agent for additional context
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Prepare and execute the insert statement
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_name, action, description, details, ip_address, user_agent, document_id, document_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("issssssis", $user_id, $user_name, $action, $description, $details, $ip_address, $user_agent, $document_id, $document_type);
            
            if ($stmt->execute()) {
                $stmt->close();
                return true;
            } else {
                error_log("Failed to log activity: " . $stmt->error);
                $stmt->close();
                return false;
            }
        } else {
            error_log("Failed to prepare statement for activity log");
            return false;
        }
    }
}

if (!function_exists('logUserAction')) {
    /**
     * Specific logging functions for different actions
     */
    function logUserAction($action, $target_user = '', $details = '') {
        $description = "User {$action}";
        if (!empty($target_user)) {
            $description .= ": {$target_user}";
        }
        return logActivity($action, $description, $details);
    }
}

if (!function_exists('logDocumentAction')) {
    function logDocumentAction($action, $document_type, $document_title, $document_id = null, $details = '') {
        $description = "{$document_type} {$action}: {$document_title}";
        return logActivity($action, $description, $details, null, $document_id, $document_type);
    }
}

if (!function_exists('logSystemAction')) {
    function logSystemAction($action, $description, $details = '') {
        return logActivity($action, $description, $details, null);
    }
}

if (!function_exists('logLogin')) {
    /**
     * Predefined common actions
     */
    function logLogin($username) {
        return logActivity('login', "User logged in: {$username}", "IP: {$_SERVER['REMOTE_ADDR']}");
    }
}

if (!function_exists('logLogout')) {
    function logLogout($username) {
        return logActivity('logout', "User logged out: {$username}");
    }
}

if (!function_exists('logUserCreate')) {
    function logUserCreate($new_username) {
        return logUserAction('create', $new_username, "New user account created");
    }
}

if (!function_exists('logUserUpdate')) {
    function logUserUpdate($username, $changes = '') {
        return logUserAction('update', $username, "User profile updated. Changes: {$changes}");
    }
}

if (!function_exists('logUserDelete')) {
    function logUserDelete($username) {
        return logUserAction('delete', $username, "User account deleted");
    }
}

if (!function_exists('logDocumentUpload')) {
    function logDocumentUpload($document_type, $title, $document_id = null) {
        return logDocumentAction('upload', $document_type, $title, $document_id, "Document uploaded successfully");
    }
}

if (!function_exists('logDocumentUpdate')) {
    function logDocumentUpdate($document_type, $title, $document_id = null, $changes = '') {
        return logDocumentAction('update', $document_type, $title, $document_id, "Document updated. Changes: {$changes}");
    }
}

if (!function_exists('logDocumentDelete')) {
    function logDocumentDelete($document_type, $title, $document_id = null) {
        return logDocumentAction('delete', $document_type, $title, $document_id, "Document permanently deleted");
    }
}

if (!function_exists('logDocumentView')) {
    function logDocumentView($document_type, $title, $document_id = null) {
        return logDocumentAction('view', $document_type, $title, $document_id, "Document viewed by user");
    }
}

if (!function_exists('logDocumentDownload')) {
    function logDocumentDownload($document_type, $title, $document_id = null) {
        return logDocumentAction('download', $document_type, $title, $document_id, "Document downloaded by user");
    }
}

if (!function_exists('logSearch')) {
    function logSearch($query, $results_count = 0) {
        return logActivity('search', "Search performed: {$query}", "Found {$results_count} results");
    }
}

if (!function_exists('logSettingsChange')) {
    function logSettingsChange($setting, $old_value, $new_value) {
        return logActivity('settings_update', "System settings updated: {$setting}", "Changed from '{$old_value}' to '{$new_value}'");
    }
}

// Initialize tables when this file is included
checkActivityLogsTable();
?>
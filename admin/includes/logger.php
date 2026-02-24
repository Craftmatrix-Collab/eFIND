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
                user_role VARCHAR(50) NULL,
                action VARCHAR(100) NOT NULL,
                description TEXT NULL,
                details TEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                document_id INT NULL,
                document_type VARCHAR(100) NULL,
                file_path VARCHAR(500) NULL,
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
        } else {
            // Migrate existing table: add any missing columns
            $migrations = [
                'user_role'   => "ALTER TABLE activity_logs ADD COLUMN user_role VARCHAR(50) NULL AFTER user_name",
                'file_path'   => "ALTER TABLE activity_logs ADD COLUMN file_path VARCHAR(500) NULL",
                'document_id' => "ALTER TABLE activity_logs ADD COLUMN document_id INT NULL",
                'user_agent'  => "ALTER TABLE activity_logs ADD COLUMN user_agent TEXT NULL",
            ];
            foreach ($migrations as $col => $sql) {
                $colResult = $conn->query("SHOW COLUMNS FROM activity_logs LIKE '$col'");
                if ($colResult && $colResult->num_rows == 0) {
                    if (!$conn->query($sql)) {
                        error_log("Failed to add $col to activity_logs: " . $conn->error);
                    }
                }
            }
            // Ensure description and ip_address allow NULL (in case created with NOT NULL)
            $conn->query("ALTER TABLE activity_logs MODIFY COLUMN description TEXT NULL");
            $conn->query("ALTER TABLE activity_logs MODIFY COLUMN ip_address VARCHAR(45) NULL");
        }
    }
}

if (!function_exists('checkRecycleBinTable')) {
    /**
     * Ensure recycle_bin table exists
     */
    function checkRecycleBinTable() {
        global $conn;

        $createTableQuery = "CREATE TABLE IF NOT EXISTS recycle_bin (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_table VARCHAR(100),
            original_id INT,
            data JSON,
            deleted_by VARCHAR(255),
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            restored_at TIMESTAMP NULL
        )";

        if (!$conn->query($createTableQuery)) {
            error_log("Failed to create recycle_bin table: " . $conn->error);
        }
    }
}

if (!function_exists('logActivity')) {
    /**
     * Main activity logging function
     */
    function logActivity($action, $description = '', $details = '', $user_id = null, $document_id = null, $document_type = null) {
        global $conn;

        // If user_id not provided, get from session (prefer admin_id for admins)
        if ($user_id === null) {
            $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
        }

        // Validate user_id exists in users OR admin_users (prevents nullifying valid admin IDs)
        if ($user_id !== null) {
            $found = false;
            foreach (['users', 'admin_users'] as $tbl) {
                $chk = $conn->prepare("SELECT id FROM $tbl WHERE id = ?");
                if ($chk) {
                    $chk->bind_param("i", $user_id);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        $found = true;
                    }
                    $chk->close();
                    if ($found) break;
                }
            }
            if (!$found) {
                error_log("User ID $user_id not found in users or admin_users, setting to NULL");
                $user_id = null;
            }
        }

        // Get user name and role from session
        $user_name = $_SESSION['admin_full_name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown User';
        $user_role = $_SESSION['role'] ?? (isset($_SESSION['admin_id']) ? 'admin' : null);

        // Get IP address and user agent
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_name, user_role, action, description, details, ip_address, user_agent, document_id, document_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isssssssis", $user_id, $user_name, $user_role, $action, $description, $details, $ip_address, $user_agent, $document_id, $document_type);

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
checkRecycleBinTable();
?>

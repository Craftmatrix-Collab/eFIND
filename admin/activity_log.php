<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Include configuration files
include(__DIR__ . '/includes/auth.php');
include(__DIR__ . '/includes/config.php');
include(__DIR__ . '/includes/logger.php');

// Check if user is logged in - redirect to login if not
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Verify database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Authentication check
if (!isLoggedIn()) {
    header("Location: /index.php");
    exit();
}

// Check if user has admin privileges
if (!isAdmin()) {
    header("Location: /unauthorized.php");
    exit();
}

// Handle print action
if (isset($_GET['print']) && $_GET['print'] === '1') {
    $printStartDate = $_GET['print_start_date'] ?? '';
    $printEndDate = $_GET['print_end_date'] ?? '';
    $printUserRole = $_GET['print_user_role'] ?? '';

    // Validate date range
    if (!empty($printStartDate) && !empty($printEndDate) && strtotime($printStartDate) > strtotime($printEndDate)) {
        die("End date must be after start date.");
    }

    // Build query with filters for print
    $printQuery = "SELECT al.*, u.username as user_username, au.username as admin_username
                   FROM activity_logs al
                   LEFT JOIN users u ON al.user_id = u.id
                   LEFT JOIN admin_users au ON al.user_id = au.id
                   WHERE 1=1";
    $printConditions = [];
    $printParams = [];
    $printTypes = '';

    if (!empty($printStartDate) && !empty($printEndDate)) {
        $printConditions[] = "DATE(al.log_time) BETWEEN ? AND ?";
        $printParams[] = $printStartDate;
        $printParams[] = $printEndDate;
        $printTypes .= 'ss';
    } elseif (!empty($printStartDate)) {
        $printConditions[] = "DATE(al.log_time) >= ?";
        $printParams[] = $printStartDate;
        $printTypes .= 's';
    } elseif (!empty($printEndDate)) {
        $printConditions[] = "DATE(al.log_time) <= ?";
        $printParams[] = $printEndDate;
        $printTypes .= 's';
    }

    if (!empty($printUserRole)) {
        $printConditions[] = "al.user_role = ?";
        $printParams[] = $printUserRole;
        $printTypes .= 's';
    }

    if (!empty($printConditions)) {
        $printQuery .= " AND " . implode(" AND ", $printConditions);
    }

    $printQuery .= " ORDER BY al.log_time DESC";

    $printStmt = $conn->prepare($printQuery);
    if (!empty($printParams)) {
        $printStmt->bind_param($printTypes, ...$printParams);
    }
    $printStmt->execute();
    $printResult = $printStmt->get_result();
    $printLogs = $printResult->fetch_all(MYSQLI_ASSOC);
    $printStmt->close();

    // Render printable HTML
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Activity Logs Report</title>
        <style>
            body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 12px; color: #333; line-height: 1.4; margin: 0; padding: 0; }
            .container { width: 100%; margin: 0 auto; padding: 20px; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0056b3; padding-bottom: 10px; }
            .header h1 { color: #0056b3; font-size: 20px; margin: 0; }
            .header p { color: #666; margin: 5px 0 0; font-size: 12px; }
            .date-range { text-align: center; margin-bottom: 20px; font-style: italic; color: #555; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
            th { background-color: #0056b3; color: white; font-weight: bold; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 11px; color: #666; }
            .logo { text-align: center; margin-bottom: 10px; }
            .logo img { max-height: 60px; }
            /* Enhanced Badge Colors for Action Types */
            .badge-login { background-color: #d4edda; color: #155724; }
            .badge-logout { background-color: #fff3cd; color: #856404; }
            .badge-download { background-color: #cce7ff; color: #004085; }
            .badge-upload { background-color: #d1ecf1; color: #0c5460; }
            .badge-view { background-color: #e2e3e5; color: #383d41; }
            .badge-update { background-color: #d1e7dd; color: #0f5132; }
            .badge-delete { background-color: #f8d7da; color: #721c24; }
            .badge-profile_update { background-color: #e7d1f8; color: #5a1f7c; }
            .badge-password_change { background-color: #ffeeba; color: #856404; }
            .badge-system { background-color: #e2e3e5; color: #383d41; }
            .badge-create { background-color: #cce7ff; color: #004085; }
            .badge-search { background-color: #f8f9fa; color: #212529; }
            .badge-ocr_edit { background-color: #e8d7f1; color: #4a235a; }
            .badge-ocr_update { background-color: #d6eaf8; color: #1b4f72; }
            .badge-user_create { background-color: #d5f5e3; color: #186a3b; }
            .badge-user_update { background-color: #fef9e7; color: #7d6608; }
            .badge-user_delete { background-color: #fadbd8; color: #78281f; }
            .badge-chatbot { background-color: #e3d7ff; color: #5a1f7c; }
            .user-role-admin { background-color: #e74c3c; color: white; }
            .user-role-staff { background-color: #3498db; color: white; }
            .user-role-user { background-color: #2ecc71; color: white; }
            .user-role-system { background-color: #95a5a6; color: white; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="logo">
                    <img src="images/logo_pbsth.png" alt="Company Logo" style="max-height: 60px;">
                </div>
                <h1>Activity Logs Report</h1>
            </div>';

    if (!empty($printStartDate) || !empty($printEndDate) || !empty($printUserRole)) {
        echo '<div class="date-range">';
        if (!empty($printStartDate) && !empty($printEndDate)) {
            echo '<p>Date Range: ' . date('F j, Y', strtotime($printStartDate)) . ' to ' . date('F j, Y', strtotime($printEndDate)) . '</p>';
        } elseif (!empty($printStartDate)) {
            echo '<p>From: ' . date('F j, Y', strtotime($printStartDate)) . '</p>';
        } elseif (!empty($printEndDate)) {
            echo '<p>To: ' . date('F j, Y', strtotime($printEndDate)) . '</p>';
        }
        if (!empty($printUserRole)) {
            echo '<p>User Role: ' . ucfirst($printUserRole) . '</p>';
        }
        echo '</div>';
    }

    echo '<table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Description</th>
                    <th>Document Type</th>
                    <th>Document ID</th>
                    <th>Details</th>
                    <!-- <th>IP Address</th> -->
                    <th>Date/Time</th>
                </tr>
            </thead>
            <tbody>';

    if (empty($printLogs)) {
        echo '<tr><td colspan="9" style="text-align: center;">No activity logs found for the selected criteria.</td></tr>';
    } else {
        $count = 0;
        foreach ($printLogs as $log) {
            $count++;
            // Resolve correct username: use user_role if stored, else sniff 'Admin:' prefix in details for old records
            $is_admin_log = ($log['user_role'] === 'admin') ||
                            ($log['user_role'] === null && str_starts_with($log['details'] ?? '', 'Admin:'));
            $resolved_user = $is_admin_log
                ? ($log['admin_username'] ?? $log['user_name'] ?? 'System')
                : ($log['user_username'] ?? $log['user_name'] ?? 'System');
            echo '<tr>
                <td>' . $count . '</td>
                <td>' . htmlspecialchars($resolved_user) . '</td>
                <td><span class="user-role-' . htmlspecialchars($log['user_role'] ?? 'system') . '">' . htmlspecialchars(ucfirst($log['user_role'] ?? 'System')) . '</span></td>
                <td>
                    <span class="badge ';
                    switch(strtolower($log['action'])) {
                        case 'login': echo 'badge-login'; break;
                        case 'logout': echo 'badge-logout'; break;
                        case 'download': echo 'badge-download'; break;
                        case 'upload': echo 'badge-upload'; break;
                        case 'view': echo 'badge-view'; break;
                        case 'update': echo 'badge-update'; break;
                        case 'delete': echo 'badge-delete'; break;
                        case 'profile_update': echo 'badge-profile_update'; break;
                        case 'password_change': echo 'badge-password_change'; break;
                        case 'create': echo 'badge-create'; break;
                        case 'search': echo 'badge-search'; break;
                        case 'ocr_edit': echo 'badge-ocr_edit'; break;
                        case 'ocr_update': echo 'badge-ocr_update'; break;
                        case 'user_create': echo 'badge-user_create'; break;
                        case 'user_update': echo 'badge-user_update'; break;
                        case 'user_delete': echo 'badge-user_delete'; break;
                        case 'chatbot': echo 'badge-chatbot'; break;
                        default: echo 'badge-system';
                    }
                    echo '">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $log['action']))) . '</span>
                </td>
                <td>' . htmlspecialchars($log['description'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars(ucfirst(!empty($log['document_type']) ? $log['document_type'] : (in_array($log['action'], ['chatbot', 'logout', 'login']) ? 'System' : 'N/A'))) . '</td>
                <td>' . htmlspecialchars($log['document_id'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars($log['details'] ?? 'N/A') . '</td>
                <!-- <td>' . htmlspecialchars($log['ip_address'] ?? 'N/A') . '</td> -->
                <td>' . formatPhilippineTime($log['log_time'] ?? $log['created_at']) . '</td>
            </tr>';
        }
    }

    echo '</tbody>
        </table>
        <div class="footer">
            <p>© ' . date('Y') . ' eFIND System. All rights reserved.</p>
            <p>This document was generated automatically and is for internal use only.</p>
        </div>
    </div>
    <script>
        window.onload = function() {
            window.print();
        };
    </script>
    </body>
    </html>';
    exit;
}

// Initialize variables
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

// Handle clear logs action
if (isset($_GET['action']) && $_GET['action'] === 'clear' && isset($_GET['csrf_token']) && $_GET['csrf_token'] === $_SESSION['csrf_token']) {
    $stmt = $conn->prepare("TRUNCATE TABLE activity_logs");
    if ($stmt->execute()) {
        $_SESSION['success'] = "Activity logs cleared successfully!";
    } else {
        $_SESSION['error'] = "Error clearing logs: " . $stmt->error;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle search, pagination, and sort functionality
$search_query = isset($_GET['search_query']) ? trim($_GET['search_query']) : '';
$filter_action = isset($_GET['filter_action']) ? trim($_GET['filter_action']) : '';
$filter_user = isset($_GET['filter_user']) ? trim($_GET['filter_user']) : '';
$filter_user_role = isset($_GET['filter_user_role']) ? trim($_GET['filter_user_role']) : '';
$filter_date = isset($_GET['filter_date']) ? trim($_GET['filter_date']) : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'log_time_desc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$table_limit = isset($_GET['table_limit']) ? intval($_GET['table_limit']) : 5;
$valid_limits = [5, 10, 25, 50, 100];
if (!in_array($table_limit, $valid_limits)) {
    $table_limit = 5;
}
$offset = ($page - 1) * $table_limit;

// Initialize parameters and types for search
$params = [];
$types = '';
$where_clauses = [];

// Add search condition if search query is provided
if (!empty($search_query)) {
    $search_like = "%" . $search_query . "%";
    $where_clauses[] = "(al.user_name LIKE ? OR u.username LIKE ? OR au.username LIKE ? OR al.action LIKE ? OR al.description LIKE ? OR al.document_type LIKE ? OR al.details LIKE ? OR al.user_role LIKE ?)";
    $params = array_merge($params, [$search_like, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like]);
    $types .= 'ssssssss';
}

// Add filter conditions
if (!empty($filter_action)) {
    $where_clauses[] = "al.action = ?";
    $params[] = $filter_action;
    $types .= 's';
}

if (!empty($filter_user)) {
    $where_clauses[] = "(al.user_name LIKE ? OR u.username LIKE ? OR au.username LIKE ?)";
    $params[] = "%$filter_user%";
    $params[] = "%$filter_user%";
    $params[] = "%$filter_user%";
    $types .= 'sss';
}

if (!empty($filter_user_role)) {
    $where_clauses[] = "al.user_role = ?";
    $params[] = $filter_user_role;
    $types .= 's';
}

if (!empty($filter_date)) {
    $where_clauses[] = "DATE(al.log_time) = ?";
    $params[] = $filter_date;
    $types .= 's';
}

// Build the query
$query = "SELECT al.*, u.username as user_username, au.username as admin_username
          FROM activity_logs al
          LEFT JOIN users u ON al.user_id = u.id
          LEFT JOIN admin_users au ON al.user_id = au.id
          WHERE 1=1";

if (!empty($where_clauses)) {
    $query .= " AND " . implode(" AND ", $where_clauses);
}

// Validate and set sort parameter
$valid_sorts = [
    'log_time_desc' => 'al.log_time DESC',
    'log_time_asc' => 'al.log_time ASC',
    'user_name_asc' => 'al.user_name ASC',
    'user_name_desc' => 'al.user_name DESC',
    'user_role_asc' => 'al.user_role ASC',
    'user_role_desc' => 'al.user_role DESC',
    'action_asc' => 'al.action ASC',
    'action_desc' => 'al.action DESC',
    'document_type_asc' => 'al.document_type ASC',
    'document_type_desc' => 'al.document_type DESC'
];

// Use validated sort or default
$sort_clause = $valid_sorts[$sort_by] ?? 'al.log_time DESC';

// Add sorting
$query .= " ORDER BY " . $sort_clause;

// Add pagination parameters (always present)
$query .= " LIMIT ? OFFSET ?";
$params[] = $table_limit;
$params[] = $offset;
$types .= 'ii';

// Prepare and execute the query
$stmt = $conn->prepare($query);
if (!empty($params) && !empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$logs = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Fetch total count for pagination
$count_query = "SELECT COUNT(*) as total FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id LEFT JOIN admin_users au ON al.user_id = au.id WHERE 1=1";
if (!empty($where_clauses)) {
    $count_query .= " AND " . implode(" AND ", $where_clauses);
}

$count_stmt = $conn->prepare($count_query);
if (!empty($params) && !empty($types)) {
    // For count query, remove the LIMIT parameters but keep search parameters
    $countParams = [];
    $countTypes = '';
    for ($i = 0; $i < count($params) - 2; $i++) {
        $countParams[] = $params[$i];
        $countTypes .= substr($types, $i, 1);
    }
    if (!empty($countParams)) {
        $count_stmt->bind_param($countTypes, ...$countParams);
    }
}

$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_row = $count_result->fetch_assoc();
$total_logs = $total_row ? $total_row['total'] : 0;
$total_pages = ceil($total_logs / $table_limit);
$count_stmt->close();

// Get unique actions for filter dropdown
$actions_query = "SELECT DISTINCT action FROM activity_logs ORDER BY action";
$actions_result = $conn->query($actions_query);
$unique_actions = [];
while ($row = $actions_result->fetch_assoc()) {
    $unique_actions[] = $row['action'];
}

// Add this function near the top of the file after the existing includes
function logDocumentDownload($documentId, $documentType, $filePath = null) {
    global $conn;

    // Resolve current user from multiple possible session keys
    $userId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['id'] ?? ($_SESSION['user']['id'] ?? null);
    $userName = $_SESSION['username'] ?? $_SESSION['full_name'] ?? ($_SESSION['user']['username'] ?? ($_SESSION['user']['name'] ?? null));
    $userRole = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? (isset($_SESSION['admin_id']) ? 'admin' : null);

    // If name/role missing, try to fetch from DB
    if ((!$userName || !$userRole) && $userId) {
        // Try users table then admin_users
        $tables = ['users', 'admin_users'];
        foreach ($tables as $t) {
            $q = "SELECT COALESCE(full_name, username) AS name, COALESCE(role, '') AS role FROM $t WHERE id = ? LIMIT 1";
            if ($stmt = $conn->prepare($q)) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    if (empty($userName) && !empty($row['name'])) $userName = $row['name'];
                    if (empty($userRole) && !empty($row['role'])) $userRole = $row['role'];
                }
                $stmt->close();
                if ($userName && $userRole) break;
            }
        }
    }

    // Final fallbacks
    $userId = $userId ?? 0;
    $userName = $userName ?? 'System';
    $userRole = $userRole ?? 'system';

    // Get document details per type
    $documentTitle = '';
    $details = '';
    $docId = intval($documentId);

    if ($documentType === 'ordinances') {
        $q = "SELECT title, ordinance_number, ordinance_date FROM ordinances WHERE id = ? LIMIT 1";
        if ($stmt = $conn->prepare($q)) {
            $stmt->bind_param("i", $docId);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($row = $r->fetch_assoc()) {
                $documentTitle = $row['title'] ?? 'Ordinance';
                $details = 'Ordinance #: ' . ($row['ordinance_number'] ?? 'N/A') . ' | Date: ' . ($row['ordinance_date'] ?? 'N/A');
            }
            $stmt->close();
        }
    } elseif ($documentType === 'resolutions') {
        $q = "SELECT title, resolution_number, resolution_date FROM resolutions WHERE id = ? LIMIT 1";
        if ($stmt = $conn->prepare($q)) {
            $stmt->bind_param("i", $docId);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($row = $r->fetch_assoc()) {
                $documentTitle = $row['title'] ?? 'Resolution';
                $details = 'Resolution #: ' . ($row['resolution_number'] ?? 'N/A') . ' | Date: ' . ($row['resolution_date'] ?? 'N/A');
            }
            $stmt->close();
        }
    } elseif ($documentType === 'minutes' || $documentType === 'minutes_of_meeting') {
        $q = "SELECT title, meeting_date, session_number FROM minutes_of_meeting WHERE id = ? LIMIT 1";
        if ($stmt = $conn->prepare($q)) {
            $stmt->bind_param("i", $docId);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($row = $r->fetch_assoc()) {
                $documentTitle = $row['title'] ?? 'Minutes of Meeting';
                $details = 'Meeting Date: ' . ($row['meeting_date'] ?? 'N/A') . (isset($row['session_number']) ? ' | Session: ' . $row['session_number'] : '');
            }
            $stmt->close();
        }
    }

    if (empty($documentTitle)) {
        $documentTitle = ucfirst($documentType ?? 'document');
    }

    $action = 'download';
    $description = ($userRole === 'admin' ? 'Admin' : 'User') . " successfully downloaded: " . $documentTitle;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    // Insert into activity_logs with all relevant columns
    $insertSql = "INSERT INTO activity_logs (user_id, user_name, user_role, action, description, document_type, document_id, details, file_path, ip_address)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    if ($insertStmt = $conn->prepare($insertSql)) {
        // types: i s s s s s i s s s
        $bindUserId = intval($userId);
        $bindDocumentId = $docId > 0 ? $docId : 0;
        $insertStmt->bind_param(
            "isssssisss",
            $bindUserId,
            $userName,
            $userRole,
            $action,
            $description,
            $documentType,
            $bindDocumentId,
            $details,
            $filePath,
            $ip
        );
        $insertStmt->execute();
        $insertStmt->close();
    }
}

// Modify the download handling section for each document type
// Add this in the download action handler sections of ordinances.php, resolutions.php, and minutes_of_meeting.php:

// For ordinances:
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    logDocumentDownload($id, 'ordinances', $filePath);
    // ... rest of download code ...
}

// For resolutions:
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    logDocumentDownload($id, 'resolutions', $filePath);
    // ... rest of download code ...
}

// For minutes of meeting:
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    logDocumentDownload($id, 'minutes', $filePath);
    // ... rest of download code ...
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - eFIND System</title>
    <link rel="icon" type="image/png" href="images/eFind_logo.png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-blue: #4361ee;
            --secondary-blue: #3a0ca3;
            --light-blue: #e8f0fe;
            --accent-orange: #ff6d00;
            --dark-gray: #2b2d42;
            --medium-gray: #8d99ae;
            --light-gray: #f8f9fa;
            --white: #ffffff;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa, #e4e8f0);
            color: var(--dark-gray);
            min-height: 100vh;
            padding-top: 70px;
        }
        .management-container {
            margin-left: 250px;
            padding: 20px;
            margin-top: 0;
            transition: all 0.3s;
            margin-bottom: 60px;
            position: relative;
        }
        @media (max-width: 992px) {
            .management-container {
                margin-left: 0;
                padding: 15px;
                margin-bottom: 60px;
            }
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: sticky;
            top: 70px;
            background: linear-gradient(135deg, #f5f7fa, #e4e8f0);
            padding: 15px 0;
            border-bottom: 2px solid var(--light-blue);
            flex-wrap: wrap;
            gap: 15px;
            z-index: 100;
        }
        .page-title {
            font-family: 'Montserrat', sans-serif;
            color: var(--secondary-blue);
            font-weight: 700;
            margin: 0;
            position: relative;
        }
        .page-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -17px;
            width: 60px;
            height: 4px;
            background: var(--accent-orange);
            border-radius: 2px;
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }
        .btn-secondary-custom {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 5px 10px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }
        .btn-secondary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }
        .btn-danger-custom {
            background: linear-gradient(135deg, #ff4d4d, #ff1a1a);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(255, 77, 77, 0.3);
        }
        .btn-danger-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(255, 77, 77, 0.4);
        }
        .filter-box {
            background-color: var(--white);
            border-radius: 12px;
            box-shadow: var(--box-shadow);
            padding: 20px;
            margin-bottom: 30px;
            position: sticky;
            top: 100px;
            z-index: 99;
        }.table-info {
            padding: 10px 20px;
            background-color: var(--light-blue);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            border-bottom: 1px solid var(--light-blue);
            font-weight: 600;
            color: var(--secondary-blue);
        }
        .table-container {
            background-color: var(--white);
            border-radius: 16px;
            box-shadow: var(--box-shadow);
            padding: 0;
            margin-bottom: 0;
            overflow: hidden;
            position: relative;
            z-index: 0;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            min-height: 0px;
            max-height: calc(100vh - 400px);
            overflow-y: auto;
            display: block;
        }
        .table {
            margin-bottom: 0;
            width: 100%;
            min-width: 1200px;
            table-layout: fixed;
        }
        .table th {
            background-color: var(--primary-blue);
            color: var(--white);
            font-weight: 600;
            border: none;
            padding: 12px 15px;
            position: sticky;
            top: 0px;
        }
        .table td {
            vertical-align: middle;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 5px 5px;
            height: 48px;
            overflow: hidden;
            word-break: break-word;
        }
        .filler-row td {
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            pointer-events: none;
            background-color: transparent !important;
        }
        .table tr:hover td {
            background-color: rgba(67, 97, 238, 0.05);
        }
        .action-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-login { background-color: #d4edda; color: #155724; }
        .badge-logout { background-color: #fff3cd; color: #856404; }
        .badge-download { background-color: #cce7ff; color: #004085; }
        .badge-upload { background-color: #d1ecf1; color: #0c5460; }
        .badge-view { background-color: #e2e3e5; color: #383d41; }
        .badge-update { background-color: #d1e7dd; color: #0f5132; }
        .badge-delete { background-color: #f8d7da; color: #721c24; }
        .badge-profile_update { background-color: #e7d1f8; color: #5a1f7c; }
        .badge-password_change { background-color: #ffeeba; color: #856404; }
        .badge-system { background-color: #e2e3e5; color: #383d41; }
        .badge-create { background-color: #cce7ff; color: #004085; }
        .badge-search { background-color: #f8f9fa; color: #212529; }
        .badge-chatbot { background-color: #e3d7ff; color: #5a1f7c; }
        .document-link {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
        }
        .document-link:hover {
            text-decoration: underline;
        }
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: var(--box-shadow);
        }
        .modal-header {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: var(--white);
            border-top-left-radius: 16px !important;
            border-top-right-radius: 16px !important;
            border-bottom: none;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid var(--light-blue);
            padding: 10px 15px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .floating-shapes {
            position: fixed;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }
        .shape {
            position: absolute;
            opacity: 0.1;
            transition: all 10s linear;
        }
        .shape-1 {
            width: 150px;
            height: 150px;
            background: var(--primary-blue);
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            top: 10%;
            left: 10%;
            animation: float 15s infinite ease-in-out;
        }
        .shape-2 {
            width: 100px;
            height: 100px;
            background: var(--accent-orange);
            border-radius: 50%;
            bottom: 15%;
            right: 10%;
            animation: float 12s infinite ease-in-out reverse;
        }
        .shape-3 {
            width: 180px;
            height: 180px;
            background: var(--secondary-blue);
            border-radius: 50% 20% 50% 30%;
            top: 50%;
            right: 20%;
            animation: float 18s infinite ease-in-out;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }
        .alert-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: var(--box-shadow);
            border-radius: 8px;
            overflow: hidden;
        }
        .alert-success {
            background-color: rgba(40, 167, 69, 0.9);
            border-left: 4px solid #28a745;
        }
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.9);
            border-left: 4px solid #dc3545;
        }
        .alert-danger .btn-close {
            color: white;
        }
        .alert-danger i {
            color: white;
        }
         .pagination-container {
            position: sticky;
            bottom: 0;
            background: var(--white);
            padding: 15px 20px;
            margin-top: 0;
            border-top: 2px solid var(--light-blue);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 100;
            border-radius: 0 0 16px 16px;
            margin-bottom: 50px;
        }
        .pagination-info {
            font-weight: 600;
            color: var(--secondary-blue);
            background-color: rgba(67, 97, 238, 0.1);
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid var(--light-blue);
        }
        /* Pagination Styles */
        .pagination {
            margin-bottom: 0;
            justify-content: center;
        }
        .page-link {
            border: 1px solid var(--light-blue);
            color: var(--primary-blue);
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 6px;
            margin: 0 3px;
            transition: all 0.3s;
        }
        .page-link:hover {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            transform: translateY(-2px);
        }
        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            border-color: var(--primary-blue);
            color: white;
        }
        .page-item.disabled .page-link {
            color: var(--medium-gray);
            background-color: var(--light-gray);
            border-color: var(--light-blue);
        }
        /* Ensure arrows are always visible and styled */
        .page-item:first-child .page-link,
        .page-item:last-child .page-link {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            font-weight: 600;
        }
        .page-item:first-child .page-link:hover,
        .page-item:last-child .page-link:hover {
            background-color: var(--secondary-blue);
            transform: translateY(-2px);
        }
        .page-item:first-child.disabled .page-link,
        .page-item:last-child.disabled .page-link {
            background-color: var(--medium-gray);
            border-color: var(--medium-gray);
            color: var(--light-gray);
        }
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .management-container {
                margin-top: 70px;
                padding: 15px;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                top: 60px;
            }
            .filter-box {
                top: 80px;
            }
            
            .d-flex.justify-content-between {
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
            .pagination-container {
                padding: 10px 15px;
            }
            .pagination-info {
                font-size: 0.9rem;
                padding: 6px 10px;
            }
            .page-link {
                padding: 6px 12px;
                font-size: 0.9rem;
            }
        }
        
        /* Sidebar Base */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 250px;
            background: linear-gradient(135deg, #1a3a8f, #1e40af);
            color: white;
            z-index: 1000;
            transition: all 0.3s;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }
        /* Sidebar Header */
        .sidebar-header {
            padding: 20px;
            position: relative;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        .logo-container {
            display: flex;
            justify-content: center;
        }
        .sidebar-logo {
            width: 150px;
            height: 150px;
            object-fit: contain;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.15);
            padding: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s;
        }
        .sidebar-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .sidebar-subtitle {
            font-size: 0.85rem;
            opacity: 0.8;
        }
        /* Sidebar Menu */
        .sidebar-menu {
            padding: 15px;
        }
        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu ul li {
            margin-bottom: 8px;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s;
        }
        .sidebar-menu ul li a {
            display: block;
            padding: 10px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
        }
        .sidebar-menu ul li a i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
            margin-right: 10px;
            transition: all 0.3s;
        }
        /* Hover and active states */
        .sidebar-menu ul li:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        .sidebar-menu ul li:hover a {
            color: #fff;
            font-weight: 600;
        }
        .sidebar-menu ul li:hover a i {
            transform: scale(1.1);
        }
        .sidebar-menu ul li.active {
            background-color: rgba(255, 255, 255, 0.9);
        }
        .sidebar-menu ul li.active a {
            color: #1a3a8f;
            font-weight: 700;
        }
        /* Toggle Button */
        #sidebarToggle {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(255, 255, 255, 0.2);
            border: none;
            color: #fff;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        #sidebarToggle:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
            color: #fff;
        }
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 0;
                overflow: hidden;
            }
            .sidebar.active {
                width: 250px;
            }
        }
        @media print {
            body * {
                visibility: hidden;
            }
            .print-container, .print-container * {
                visibility: visible;
            }
            .print-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            th {
                background-color: #4361ee !important;
                color: white !important;
            }
        }
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--white);
            padding: 15px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            margin-left: 250px;
        }
        @media (max-width: 992px) {
            footer {
                margin-left: 0;
            }
        }
        .truncated-text {
    cursor: help;
    border-bottom: 1px dotted #666;
    transition: all 0.2s ease;
}

.truncated-text:hover {
    color: var(--primary-blue);
    border-bottom-color: var(--primary-blue);
}

/* Ensure tooltips are styled nicely */
.tooltip {
    font-family: 'Poppins', sans-serif;
}

.tooltip .tooltip-inner {
    background-color: var(--dark-gray);
    color: var(--white);
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 0.85rem;
    max-width: 300px;
    word-wrap: break-word;
}
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>
    <?php include(__DIR__ . '/includes/sidebar.php'); ?>
    <?php include(__DIR__ . '/includes/navbar.php'); ?>
    <div class="management-container">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Activity Logs</h1>
                <div class="d-flex gap-2">
                    <button class="btn btn-secondary-custom" id="printButton">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                    <a href="?action=clear&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-danger-custom" onclick="return confirm('Are you sure you want to clear all activity logs? This action cannot be undone.');">
                        <i class="fas fa-trash me-1"></i> Clear Logs
                    </a>
                </div>
            </div>
            <!-- Filter Box -->
            <div class="filter-box">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="filter_action" class="form-label">Action Type</label>
                            <select class="form-select" id="filter_action" name="filter_action">
                                <option value="">All Actions</option>
                                <option value="login" <?php echo $filter_action === 'login' ? 'selected' : ''; ?>>Login</option>
                                <option value="logout" <?php echo $filter_action === 'logout' ? 'selected' : ''; ?>>Logout</option>
                                <option value="download" <?php echo $filter_action === 'download' ? 'selected' : ''; ?>>Download</option>
                                <option value="upload" <?php echo $filter_action === 'upload' ? 'selected' : ''; ?>>Upload</option>
                                <option value="view" <?php echo $filter_action === 'view' ? 'selected' : ''; ?>>View</option>
                                <option value="update" <?php echo $filter_action === 'update' ? 'selected' : ''; ?>>Update</option>
                                <option value="delete" <?php echo $filter_action === 'delete' ? 'selected' : ''; ?>>Delete</option>
                                <option value="profile_update" <?php echo $filter_action === 'profile_update' ? 'selected' : ''; ?>>Profile Update</option>
                                <option value="password_change" <?php echo $filter_action === 'password_change' ? 'selected' : ''; ?>>Password Change</option>
                                <option value="chatbot" <?php echo $filter_action === 'chatbot' ? 'selected' : ''; ?>>Chatbot</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filter_user" class="form-label">User</label>
                            <input type="text" class="form-control" id="filter_user" name="filter_user" value="<?php echo htmlspecialchars($filter_user); ?>" placeholder="User name">
                        </div>
                        <div class="col-md-2">
                            <label for="filter_date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="sort_by" class="form-label">Sort By</label>
                            <select name="sort_by" id="sort_by" class="form-select" onchange="updateSort()">
                                <option value="log_time_desc" <?php echo $sort_by === 'log_time_desc' ? 'selected' : ''; ?>>Date (Newest)</option>
                                <option value="log_time_asc" <?php echo $sort_by === 'log_time_asc' ? 'selected' : ''; ?>>Date (Oldest)</option>
                                <option value="user_name_asc" <?php echo $sort_by === 'user_name_asc' ? 'selected' : ''; ?>>User (A-Z)</option>
                                <option value="user_name_desc" <?php echo $sort_by === 'user_name_desc' ? 'selected' : ''; ?>>User (Z-A)</option>
                                <option value="action_asc" <?php echo $sort_by === 'action_asc' ? 'selected' : ''; ?>>Action (A-Z)</option>
                                <option value="action_desc" <?php echo $sort_by === 'action_desc' ? 'selected' : ''; ?>>Action (Z-A)</option>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end gap-3">
                            <button type="submit" class="btn btn-primary-custom w-100 h-75">
                                <i class="fas fa-filter me-1"></i> Filter
                            </button>
                        </div>
                        <div class="col-md-2 d-flex align-items-end gap-3">
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary w-100 h-75 d-flex align-items-center justify-content-center">
                                <i class="fas fa-refresh me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                    <!-- Hidden input for sort_by -->
                    <input type="hidden" name="sort_by" id="hiddenSortBy" value="<?php echo htmlspecialchars($sort_by); ?>">
                </form>
            </div>
            <!-- Table Info -->
            <div class="table-info d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-file-alt me-2"></i>
                    Showing <?php echo count($logs); ?> of <?php echo $total_logs; ?> logs
                    <?php if (!empty($search_query) || !empty($filter_action) || !empty($filter_user) || !empty($filter_date)): ?>
                        <span class="text-muted ms-2">(Filtered results)</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Logs Table -->
<div class="table-container">
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle text-center">
            <thead class="table-dark">
                <tr>
                    <th style="width:5%">ID</th>
                    <th style="width:12%">User</th>
                    <th style="width:10%">Action</th>
                    <th style="width:25%">Description</th>
                    <th style="width:13%">Document Type</th>
                    <th style="width:20%">Details</th>
                    <!-- <th>IP Address</th> -->
                    <th style="width:15%">Date/Time</th>
                </tr>
            </thead>
            <tbody id="logsTableBody">
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">No activity logs found</td>
                    </tr>
                <?php else: ?>
                    <?php $row_num = $offset + 1; ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        // Resolve correct username: use user_role if stored, else sniff 'Admin:' prefix in details for old records
                        $is_admin_log = ($log['user_role'] === 'admin') ||
                                        ($log['user_role'] === null && str_starts_with($log['details'] ?? '', 'Admin:'));
                        $resolved_user = $is_admin_log
                            ? ($log['admin_username'] ?? $log['user_name'] ?? 'System')
                            : ($log['user_username'] ?? $log['user_name'] ?? 'System');
                        ?>
                        <tr>
                            <td><?php echo $row_num++; ?></td>
                            <td>
                                <span class="badge bg-primary">
                                    <?php echo htmlspecialchars($resolved_user); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge
                                    <?php
                                        switch(strtolower($log['action'])) {
                                            case 'login': echo 'badge-login'; break;
                                            case 'logout': echo 'badge-logout'; break;
                                            case 'download': echo 'badge-download'; break;
                                            case 'upload': echo 'badge-upload'; break;
                                            case 'view': echo 'badge-view'; break;
                                            case 'update': echo 'badge-update'; break;
                                            case 'delete': echo 'badge-delete'; break;
                                            case 'profile_update': echo 'badge-profile_update'; break;
                                            case 'password_change': echo 'badge-password_change'; break;
                                            case 'create': echo 'badge-create'; break;
                                            case 'search': echo 'badge-search'; break;
                                            case 'chatbot': echo 'badge-chatbot'; break;
                                            default: echo 'badge-system';
                                        }
                                    ?>">
                                    <?php echo htmlspecialchars(ucfirst($log['action'])); ?>
                                </span>
                            </td>
                            <td class="text-start">
                                <?php
                                $description = htmlspecialchars($log['description'] ?? 'N/A');
                                if (strlen($description) > 50): ?>
                                    <span class="truncated-text" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo $description; ?>">
                                        <?php echo substr($description, 0, 50) . '...'; ?>
                                    </span>
                                <?php else: ?>
                                    <?php echo $description; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($log['document_type'])): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars(ucfirst($log['document_type'])); ?></span>
                                <?php elseif ($log['action'] === 'chatbot' || $log['action'] === 'logout' || $log['action'] === 'login'): ?>
                                    <span class="badge bg-secondary">System</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-start small">
                                <?php
                                $details = htmlspecialchars($log['details'] ?? 'N/A');
                                if (strlen($details) > 50): ?>
                                    <span class="truncated-text" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo $details; ?>">
                                        <?php echo substr($details, 0, 50) . '...'; ?>
                                    </span>
                                <?php else: ?>
                                    <?php echo $details; ?>
                                <?php endif; ?>
                            </td>
                            <!-- <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></span></td> -->
                            <td><?php echo formatPhilippineTime($log['log_time'] ?? $log['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php
                    $filled = count($logs);
                    for ($i = $filled; $i < $table_limit; $i++): ?>
                        <tr class="filler-row"><td colspan="7">&nbsp;</td></tr>
                    <?php endfor; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Sticky Pagination -->
            <?php if ($total_logs > 5): ?>
            <div class="pagination-container">
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <!-- Previous Page -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" aria-label="Previous">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </span>
                            </li>
                        <?php endif; ?>
                        <!-- Page Numbers -->
                        <?php
                        // Show limited page numbers for better UX
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        // Show first page if not in initial range
                        if ($start_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                            </li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <!-- Show last page if not in current range -->
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                            </li>
                        <?php endif; ?>
                        <!-- Next Page -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" aria-label="Next">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Print Date Range Modal -->
    <div class="modal fade" id="printDateRangeModal" tabindex="-1" aria-labelledby="printDateRangeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="printDateRangeModalLabel">Select Date Range for Print</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="modalPrintStartDate" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="modalPrintStartDate">
                    </div>
                    <div class="mb-3">
                        <label for="modalPrintEndDate" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="modalPrintEndDate">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmPrint">Print</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Alert Messages -->
    <?php if (!empty($success)): ?>
        <div class="alert-message alert-success alert-dismissible fade show">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle me-2"></i>
                <div><?php echo $success; ?></div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert-message alert-danger alert-dismissible fade show">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-2"></i>
                <div><?php echo $error; ?></div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to update sort parameter and submit form
        function updateSort() {
            const sortBySelect = document.getElementById('sort_by');
            const hiddenSortBy = document.getElementById('hiddenSortBy');
            hiddenSortBy.value = sortBySelect.value;
            sortBySelect.closest('form').submit();
        }

        // Function to update table limit
        function updateTableLimit(selectElement) {
            const limit = selectElement.value;
            const url = new URL(window.location.href);
            url.searchParams.set('table_limit', limit);
            url.searchParams.set('page', 1); // Reset to first page when changing limit
            window.location.href = url.toString();
        }

        document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl, {
            delay: { "show": 100, "hide": 100 }
        });
    });
    

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert-message');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });

            // Print button logic
            document.getElementById('printButton').addEventListener('click', function() {
                // Show modal for date range selection
                const printModal = new bootstrap.Modal(document.getElementById('printDateRangeModal'));
                printModal.show();
            });

            document.getElementById('confirmPrint').addEventListener('click', function() {
                const startDate = document.getElementById('modalPrintStartDate').value;
                const endDate = document.getElementById('modalPrintEndDate').value;

                // Validate date range
                if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                    alert('End date must be after start date.');
                    return;
                }

                // Build print URL with date range
                let printUrl = window.location.pathname + '?print=1';
                if (startDate) printUrl += '&print_start_date=' + startDate;
                if (endDate) printUrl += '&print_end_date=' + endDate;

                // Open print window
                window.open(printUrl, '_blank');

                // Close modal
                const printModal = bootstrap.Modal.getInstance(document.getElementById('printDateRangeModal'));
                printModal.hide();
            });

            // Add scroll event listener for sticky header
            window.addEventListener('scroll', function() {
                const pageHeader = document.querySelector('.page-header');
                if (pageHeader) {
                    if (window.scrollY > 100) {
                        pageHeader.style.background = 'var(--white)';
                        pageHeader.style.boxShadow = 'var(--box-shadow)';
                        pageHeader.style.padding = '15px 20px';
                        pageHeader.style.margin = '0 -20px 20px -20px';
                    } else {
                        pageHeader.style.background = 'linear-gradient(135deg, #f5f7fa, #e4e8f0)';
                        pageHeader.style.boxShadow = 'none';
                        pageHeader.style.padding = '15px 0';
                        pageHeader.style.margin = '0 0 20px 0';
                    }
                }
            });

            // Update table responsive height based on window size
            function updateTableHeight() {
                const tableResponsive = document.querySelector('.table-responsive');
                if (tableResponsive) {
                    const windowHeight = window.innerHeight;
                    tableResponsive.style.maxHeight = `calc(${windowHeight}px - 400px)`;
                }
            }
            window.addEventListener('resize', updateTableHeight);
            window.addEventListener('load', updateTableHeight);
        });
    </script>
    <footer class="bg-white p-3">
        <?php include(__DIR__ . '/includes/footer.php'); ?>
    </footer>
</body>
</html>

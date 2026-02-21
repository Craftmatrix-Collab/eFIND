<?php
include('includes/auth.php');
include('includes/config.php');
include(__DIR__ . '/includes/logger.php');

// Check if user is logged in - redirect to login if not
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Set default time zone
date_default_timezone_set('Asia/Manila');
// Fetch counts for statistics cards
$ordinances_count = $conn->query("SELECT COUNT(*) FROM ordinances")->fetch_row()[0];
$resolutions_count = $conn->query("SELECT COUNT(*) FROM resolutions")->fetch_row()[0];
$meeting_minutes_count = $conn->query("SELECT COUNT(*) FROM minutes_of_meeting")->fetch_row()[0];
$users_count = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
// Get table row limit from request or set default
$table_limit = isset($_GET['table_limit']) ? intval($_GET['table_limit']) : 5;
$valid_limits = [5, 10, 25, 50, 100];
if (!in_array($table_limit, $valid_limits)) {
    $table_limit = 5;
}
// Get current page for pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $table_limit;
// Get sort by parameter with validation
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'date_posted_desc';
$valid_sorts = [
    'date_posted_desc' => 'date_posted DESC',
    'date_posted_asc' => 'date_posted ASC',
    'title_asc' => 'title ASC',
    'title_desc' => 'title DESC',
    'type_asc' => 'doc_type ASC',
    'type_desc' => 'doc_type DESC'
];
$sort_clause = $valid_sorts[$sort_by] ?? 'date_posted DESC';
// Handle search functionality
$search_query = isset($_GET['search_query']) ? trim($_GET['search_query']) : '';
$document_type = isset($_GET['document_type']) ? $_GET['document_type'] : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';
// Validate year
if (!empty($year) && (!is_numeric($year) || $year < 1900 || $year > date('Y'))) {
    $year = '';
}
// Build base query
$base_query = "
    SELECT * FROM (
        SELECT
            'ordinance' as doc_type,
            o.id,
            o.title,
            o.date_posted as date,
            o.reference_number,
            o.content,
            COALESCE(u.full_name, au.full_name) as uploaded_by,
            o.date_posted as date_posted,
            o.image_path
        FROM ordinances o
        LEFT JOIN users u ON o.uploaded_by = u.username
        LEFT JOIN admin_users au ON o.uploaded_by = au.username
        UNION ALL
        SELECT
            'resolution' as doc_type,
            r.id,
            r.title,
            r.date_posted as date,
            r.reference_number,
            r.content,
            COALESCE(u.full_name, au.full_name) as uploaded_by,
            r.date_posted as date_posted,
            r.image_path
        FROM resolutions r
        LEFT JOIN users u ON r.uploaded_by = u.username
        LEFT JOIN admin_users au ON r.uploaded_by = au.username
        UNION ALL
        SELECT
            'meeting' as doc_type,
            m.id,
            m.title,
            m.date_posted as date,
            m.reference_number,
            m.content,
            COALESCE(u.full_name, au.full_name) as uploaded_by,
            m.date_posted as date_posted,
            m.image_path
        FROM minutes_of_meeting m
        LEFT JOIN users u ON m.uploaded_by = u.username
        LEFT JOIN admin_users au ON m.uploaded_by = au.username
    ) as combined
";
// Build WHERE conditions
$where_conditions = [];
$params = [];
$types = '';
if (!empty($search_query)) {
    $search_terms = "%" . $search_query . "%";
    $where_conditions[] = "(title LIKE ? OR reference_number LIKE ? OR content LIKE ?)";
    $types .= 'sss';
    $params = array_merge($params, [$search_terms, $search_terms, $search_terms]);
}
if (!empty($year)) {
    $where_conditions[] = "YEAR(date_posted) = ?";
    $types .= 's';
    $params = array_merge($params, [$year]);
}
if (!empty($document_type)) {
    $where_conditions[] = "doc_type = ?";
    $types .= 's';
    $params = array_merge($params, [$document_type]);
}
// Build final query
$where_clause = empty($where_conditions) ? "" : " WHERE " . implode(" AND ", $where_conditions);
$all_documents_query = $base_query . $where_clause . " ORDER BY " . $sort_clause . " LIMIT ? OFFSET ?";
// Add pagination parameters
$types .= 'ii';
$params = array_merge($params, [$table_limit, $offset]);
// Execute main query
$stmt = $conn->prepare($all_documents_query);
if ($stmt) {
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $all_documents = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $all_documents = [];
    error_log("Database query error: " . $conn->error);
}
// Fetch total count for pagination
$count_query = $base_query;
if (!empty($where_conditions)) {
    $count_query .= " WHERE " . implode(" AND ", $where_conditions);
}
// Remove ORDER BY and LIMIT for count query
$count_query = "SELECT COUNT(*) as total FROM (" . $count_query . ") as count_table";
// Prepare count query with parameters (without pagination params)
$count_params = [];
$count_types = '';
if (!empty($search_query)) {
    $search_terms = "%" . $search_query . "%";
    $count_types .= 'sss';
    $count_params = array_merge($count_params, [$search_terms, $search_terms, $search_terms]);
}
if (!empty($year)) {
    $count_types .= 's';
    $count_params = array_merge($count_params, [$year]);
}
if (!empty($document_type)) {
    $count_types .= 's';
    $count_params = array_merge($count_params, [$document_type]);
}
$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    if (!empty($count_types)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_row = $count_result->fetch_assoc();
    $total_documents = $total_row ? $total_row['total'] : 0;
    $count_stmt->close();
} else {
    $total_documents = 0;
    error_log("Count query error: " . $conn->error);
}
$total_pages = ceil($total_documents / $table_limit);
// Fetch distinct years from the database
$years_query = $conn->query("
    SELECT DISTINCT YEAR(date_posted) as year FROM ordinances
    UNION
    SELECT DISTINCT YEAR(date_posted) as year FROM resolutions
    UNION
    SELECT DISTINCT YEAR(date_posted) as year FROM minutes_of_meeting
    ORDER BY year DESC
");
$available_years = $years_query ? $years_query->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - eFIND System</title>
    <link rel="icon" type="image/png" href="images/eFind_logo.png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&display=swap" rel="stylesheet">
    <!-- jQuery (required for navbar functionality) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap Bundle JS (includes Popper) - loaded early for navbar dropdowns -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- n8n Chat Script -->
    <script src="https://cdn.jsdelivr.net/npm/@n8n/chat@latest/dist/n8n-chat.umd.js"></script>
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
            padding-top: 40px;
        }
        .dashboard-container {
            margin-left: 250px;
            padding: 20px;
            margin-top: 0;
            transition: all 0.3s;
            margin-bottom: 60px;
        }
        /* Responsive Layout */
        @media (max-width: 992px) {
            .dashboard-container {
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
            height: 3px;
            background: var(--accent-orange);
            border-radius: 2px;
        }
        /* Compact Stats Cards */
        .stat-card-link {
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            display: block;
        }

        .stat-card-link:hover .stat-card {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .stats-container {
            margin-bottom: 25px;
        }
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        @media (max-width: 992px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
        }
        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        .search-box input {
            padding-left: 40px;
            border-radius: 8px;
            border: 2px solid var(--light-blue);
            box-shadow: none;
        }
        .search-box i {
            position: absolute;
            left: 15px;
            top: 12px;
            color: var(--medium-gray);
        }
        .stat-card {
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--box-shadow);
            padding: clamp(12px, 2vw, 15px);
            transition: all 0.3s;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: auto;
            min-height: 90px;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .stat-card.resolutions { border-left-color: #4A90E2; }
        .stat-card.ordinances { border-left-color: #28a745; }
        .stat-card.meetings { border-left-color: #17a2b8; }
        .stat-card.users { border-left-color: #ffc107; }
        .stat-content {
            display: flex;
            flex-direction: column;
            justify-content: center;
            flex: 1;
        }
        .stat-number {
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            color: var(--medium-gray);
            font-weight: 500;
        }
        .stat-icon {
            width: clamp(40px, 8vw, 50px);
            height: clamp(40px, 8vw, 50px);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(1.2rem, 2.5vw, 1.5rem);
            color: white;
            flex-shrink: 0;
            margin-left: 10px;
        }
        .stat-icon.resolutions { background: linear-gradient(135deg, #4A90E2, #357ABD); }
        .stat-icon.ordinances { background: linear-gradient(135deg, #28a745, #218838); }
        .stat-icon.meetings { background: linear-gradient(135deg, #17a2b8, #138496); }
        .stat-icon.users { background: linear-gradient(135deg, #ffc107, #daa520); }
        /* Documents Table Section */
        .documents-table-section {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            overflow: hidden;
        }
        .documents-table-section .card-header {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: var(--white);
            padding: 15px 20px;
            border-bottom: none;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        @media (max-width: 768px) {
            .documents-table-section .card-header {
                padding: 12px 15px;
                flex-direction: column;
                align-items: flex-start;
            }
        }
        .table-controls {
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }
        @media (max-width: 576px) {
            .table-controls {
                gap: 5px;
            }
        }
        .table-limit-select {
            width: auto;
            min-width: 80px;
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
        @media (max-width: 768px) {
            .table-container {
                max-height: 300px;
            }
        }
        .documents-table {
            width: 100%;
            margin-bottom: 0;
            min-width: 600px;
        }
        .documents-table thead th {
             background-color: var(--primary-blue);
            color: var(--white);
            font-weight: 800;
            border: none;
            padding: 12px 15px;
            position: sticky;
            top: 0px;
        }
        .documents-table td {
            vertical-align: middle;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 5px 5px
        }
        @media (max-width: 768px) {
            .documents-table thead th,
            .documents-table td {
                padding: 8px 10px;
                font-size: 0.85rem;
            }
        }
        .documents-table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        /* Enhanced Content Preview Tooltip */
       .content-preview {
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: black;
        font-size: 0.9rem;
}

        /* Custom Tooltip Styling */
        .content-preview[title] {
            position: relative;
        }

        .content-preview[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.95);
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            line-height: 1.4;
            white-space: pre-wrap;
            max-width: 400px;
            width: max-content;
            z-index: 10000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-family: 'Poppins', sans-serif;
        }

        /* Tooltip arrow */
        .content-preview[title]:hover::before {
            content: '';
            position: absolute;
            bottom: calc(100% - 6px);
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: rgba(0, 0, 0, 0.95);
            z-index: 10001;
        }

        /* Ensure tooltip doesn't break table layout */
        .content-preview-container {
            position: relative;
            display: inline-block;
        }

        @media (max-width: 992px) {
            .content-preview {
                max-width: 150px;
            }
        }
        @media (max-width: 768px) {
            .content-preview {
                max-width: 120px;
                font-size: 0.8rem;
            }
            
            .content-preview[title]:hover::after {
                max-width: 300px;
                font-size: 0.8rem;
                padding: 10px 12px;
            }
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
        }
        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-action:hover {
            transform: translateY(-2px);
        }
        .btn-image {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        .btn-image:hover {
            background-color: rgba(40, 167, 69, 0.2);
        }
        .btn-ocr {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        .btn-ocr:hover {
            background-color: rgba(255, 193, 7, 0.2);
        }
        /* Floating Shapes */
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
        /* Chatbot Styles */
        .chatbot-btn {
            position: fixed;
            bottom: 95px;
            right: 35px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #8a2be2, #9370db, #9932cc);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s;
            border: none;
            outline: none;
        }
        @media (max-width: 768px) {
            .chatbot-btn {
                width: 50px;
                height: 50px;
                bottom: 20px;
                right: 20px;
            }
        }
        .chatbot-btn:hover {
            transform: scale(1.1);
            background: linear-gradient(135deg, #9932cc, #8a2be2, #9370db);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        .chatbot-btn i {
            font-size: clamp(18px, 4vw, 24px);
        }
        @media (max-width: 768px) {
            .chatbot-container {
                right: 5%;
                bottom: 80px;
                width: 90%;
            }
        }
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
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
        /* Footer */
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--white);
            padding: 12px 15px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            margin-left: 250px;
        }
        @media (max-width: 1200px) {
            footer {
                margin-left: 0;
            }
        }
        /* Utility Classes */
        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .badge-sm {
            font-size: 0.7rem;
            padding: 4px 8px;
        }
        /* Table Responsive Enhancements */
        @media (max-width: 768px) {
            .table-responsive-sm {
                font-size: 0.8rem;
            }
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            .action-buttons {
                gap: 5px;
            }
            .btn-action {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }
        }
        /* Card Footer */
        .card-footer {
            background-color: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: 12px 20px;
            font-size: 0.85rem;
        }
        @media (max-width: 768px) {
            .card-footer {
                padding: 10px 15px;
                font-size: 0.8rem;
            }
        }
        /* Image Modal */
        .image-modal .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: var(--box-shadow);
        }
        .image-modal .modal-header {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: var(--white);
            border-top-left-radius: 16px !important;
            border-top-right-radius: 16px !important;
            border-bottom: none;
        }
        .image-modal .modal-title {
            font-weight: 600;
        }
        /* OCR Modal */
        .ocr-modal .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: var(--box-shadow);
        }
        .ocr-modal .modal-header {
            background: var(--secondary-blue);
            color: white;
            border-top-left-radius: 16px !important;
            border-top-right-radius: 16px !important;
            border-bottom: none;
        }
        .ocr-modal .modal-title {
            font-weight: 600;
        }
        #ocrLoading {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px dashed #dee2e6;
        }
        #ocrResult {
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .ocr-view {
            max-height: 400px;
            overflow-y: auto;
        }
        #formattedView pre.ocr-paragraph {
            white-space: pre-wrap;
            word-break: break-word;
            font-family: "Courier New", monospace;
            line-height: 1.5;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            max-height: 400px;
            overflow-y: auto;
        }
        .ocr-heading {
            color: var(--secondary-blue);
            border-bottom: 1px solid var(--light-blue);
            padding-bottom: 5px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        .ocr-paragraph {
            line-height: 1.6;
            margin-bottom: 15px;
            text-align: justify;
        }
        #rawView pre {
            white-space: pre-wrap;
            word-break: break-word;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            font-family: "Courier New", monospace;
        }
        #ocrActions {
            display: none;
        }
        .ocr-view-toggle {
            margin-bottom: 10px;
        }
        #ocrTextEditor {
            font-family: "Courier New", monospace;
            line-height: 1.5;
            resize: vertical;
        }
        #editView {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        /* Alert Styles */
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
            color: white;
        }
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.9);
            border-left: 4px solid #dc3545;
            color: white;
        }
        /* Sticky Pagination Container */
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
        /* Table Info */
        .table-info {
            padding: 10px 20px;
            background-color: var(--light-blue);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            border-bottom: 1px solid var(--light-blue);
            font-weight: 600;
            color: var(--secondary-blue);
        }
        /* Enhanced Chatbot Styles */
.chatbot-container {
    position: fixed;
    bottom: 100px;
    right: 20px;
    width: min(90vw, 400px);
    height: min(80vh, 500px);
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    display: none;
    flex-direction: column;
    z-index: 1000;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
}

.chatbot-header {
    background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
    color: white;
    padding: 15px 20px;
    font-weight: 600;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.chatbot-body {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    background: #f8f9fa;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.chatbot-footer {
    padding: 15px 20px;
    border-top: 1px solid #dee2e6;
    background: white;
}

.chat-message {
    max-width: 85%;
    padding: 12px 16px;
    border-radius: 18px;
    position: relative;
    word-wrap: break-word;
    animation: fadeIn 0.3s ease-in;
}

.user-message {
    background: var(--primary-blue);
    color: white;
    margin-left: auto;
    border-bottom-right-radius: 5px;
}

.bot-message {
    background: white;
    color: var(--dark-gray);
    border: 1px solid #dee2e6;
    border-bottom-left-radius: 5px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.message-content {
    line-height: 1.4;
}

.message-content strong {
    font-weight: 600;
}

.typing-indicator .message-content {
    display: flex;
    align-items: center;
    gap: 8px;
}

.typing-dots {
    display: flex;
    gap: 4px;
}

.typing-dots span {
    width: 8px;
    height: 8px;
    background-color: var(--medium-gray);
    border-radius: 50%;
    animation: typing 1.4s infinite ease-in-out;
}

.typing-dots span:nth-child(1) { animation-delay: -0.32s; }
.typing-dots span:nth-child(2) { animation-delay: -0.16s; }
.typing-dots span:nth-child(3) { animation-delay: 0s; }

@keyframes typing {
    0%, 80%, 100% { 
        transform: scale(0.8);
        opacity: 0.5;
    }
    40% { 
        transform: scale(1);
        opacity: 1;
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .chatbot-container {
        right: 5%;
        bottom: 80px;
        width: 90%;
        height: 70vh;
    }
    
    .chat-message {
        max-width: 90%;
    }
}

/* Input group styling */
.input-group {
    gap: 10px;
}

.input-group .form-control {
    border-radius: 25px;
    border: 2px solid var(--light-blue);
    padding: 10px 20px;
}

.input-group .btn {
    border-radius: 50%;
    width: 45px;
    height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
}



    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>
    <!-- Mobile Sidebar Toggle -->
    <button class="mobile-sidebar-toggle" id="mobileSidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    <?php include(__DIR__ . '/includes/sidebar.php'); ?>
    <?php include(__DIR__ . '/includes/navbar.php'); ?>
    <div class="dashboard-container">
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Dashboard</h1>

            </div>
           <!-- Compact Stats Cards -->
<div class="stats-container">
    <div class="stats-row">
        <a href="resolutions.php" class="stat-card-link">
            <div class="stat-card resolutions">
                <div class="stat-content">
                    <div class="stat-number"><?php echo $resolutions_count; ?></div>
                    <div class="stat-label">Total Active Resolutions</div>
                </div>
                <div class="stat-icon resolutions">
                    <i class="fas fa-file-contract"></i>
                </div>
            </div>
        </a>
        <a href="ordinances.php" class="stat-card-link">
            <div class="stat-card ordinances">
                <div class="stat-content">
                    <div class="stat-number"><?php echo $ordinances_count; ?></div>
                    <div class="stat-label">Total Active Ordinances</div>
                </div>
                <div class="stat-icon ordinances">
                    <i class="fas fa-gavel"></i>
                </div>
            </div>
        </a>
        <a href="minutes_of_meeting.php" class="stat-card-link">
            <div class="stat-card meetings">
                <div class="stat-content">
                    <div class="stat-number"><?php echo $meeting_minutes_count; ?></div>
                    <div class="stat-label">Minutes of the Meeting</div>
                </div>
                <div class="stat-icon meetings">
                    <i class="fas fa-clipboard-list"></i>
                </div>
            </div>
        </a>
        <a href="users.php" class="stat-card-link">
            <div class="stat-card users">
                <div class="stat-content">
                    <div class="stat-number"><?php echo $users_count; ?></div>
                    <div class="stat-label">Users</div>
                </div>
                <div class="stat-icon users">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </a>
    </div>
</div>

            <!-- Search Form -->
            <form method="GET" action="dashboard.php" class="mb-2">
                <div class="row g-3">
                    <div class="col-md-9">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search_query" id="searchInput" class="form-control" placeholder="Search documents..." value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                    </div>
                    <div class="col-md-1">
                        <select name="document_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="ordinance" <?php echo $document_type === 'ordinance' ? 'selected' : ''; ?>>Ordinances</option>
                            <option value="resolution" <?php echo $document_type === 'resolution' ? 'selected' : ''; ?>>Resolutions</option>
                            <option value="meeting" <?php echo $document_type === 'meeting' ? 'selected' : ''; ?>>Meeting Minutes</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <?php foreach ($available_years as $y): ?>
                                <option value="<?php echo $y['year']; ?>" <?php echo $year == $y['year'] ? 'selected' : ''; ?>>
                                    <?php echo $y['year']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Search
                        </button>
                    </div>
                </div>
            </form>
            <!-- Table Info -->
            <div class="table-info d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-file-alt me-2"></i>
                    <span style="color: var(--secondary-blue); font-weight: 600;">
                        Showing <?php echo count($all_documents); ?> of <?php echo $total_documents; ?> documents
                    </span>
                    <?php if (!empty($search_query)): ?>
                        <span class="text-muted ms-2">(Filtered results)</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Documents Table Section -->
            <div class="documents-table-section card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-table me-2"></i>All Documents Overview</h5>
                    <div class="table-controls">
                        <form method="GET" action="dashboard.php" class="d-flex align-items-center">
                            <input type="hidden" name="search_query" value="<?php echo htmlspecialchars($search_query); ?>">
                            <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($document_type); ?>">
                            <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
                            <input type="hidden" name="page" value="1">
                            <label for="sort_by" class="form-label mb-0 ms-3 me-2 small">Sort by:</label>
                            <select name="sort_by" id="sort_by" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="date_posted_desc" <?php echo $sort_by === 'date_posted_desc' ? 'selected' : ''; ?>>Date (Newest)</option>
                                <option value="date_posted_asc" <?php echo $sort_by === 'date_posted_asc' ? 'selected' : ''; ?>>Date (Oldest)</option>
                                <option value="title_asc" <?php echo $sort_by === 'title_asc' ? 'selected' : ''; ?>>Title (A-Z)</option>
                                <option value="title_desc" <?php echo $sort_by === 'title_desc' ? 'selected' : ''; ?>>Title (Z-A)</option>
                                <option value="type_asc" <?php echo $sort_by === 'type_asc' ? 'selected' : ''; ?>>Type (A-Z)</option>
                                <option value="type_desc" <?php echo $sort_by === 'type_desc' ? 'selected' : ''; ?>>Type (Z-A)</option>
                            </select>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="documents-table table table-bordered table-hover align-middle text-center">
                            <thead class="table-dark">
                                <tr>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <!-- <th>Reference No.</th> -->
                                    <th>Date Posted</th>
                                    <th>Content Preview</th>
                                    <th>Image</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_documents)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="fas fa-file fa-2x mb-3"></i>
                                            <p>No documents found</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_documents as $document): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?php
                                                    switch($document['doc_type']) {
                                                        case 'ordinance': echo 'info'; break;
                                                        case 'resolution': echo 'primary'; break;
                                                        case 'meeting': echo 'warning'; break;
                                                        default: echo 'light text-dark';
                                                    }
                                                ?> badge-sm">
                                                    <?= ucfirst($document['doc_type']) ?>
                                                </span>
                                            </td>
                                            <td class="text-start">
                                                <div class="text-truncate-2" title="<?= htmlspecialchars($document['title']) ?>">
                                                    <strong><?= htmlspecialchars($document['title']) ?></strong>
                                                </div>
                                            </td>
                                            <!-- <td>
                                                <?= !empty($document['reference_number']) ?
                                                    '<span class="badge bg-light text-dark">' . htmlspecialchars($document['reference_number']) . '</span>' :
                                                    '<span class="badge bg-light text-dark">N/A</span>' ?>
                                            </td> -->
                                            <td>
                                                <small class="text-truncate-2">
                                                    <?= date('F j, Y', strtotime($document['date_posted'])) ?>
                                                </small>
                                            </td>
                                            <td class="text-start">
    <div class="content-preview">
        <?= htmlspecialchars(substr($document['content'], 0, 100)) ?>...
    </div>
</td>
                                            <td>
                                                <?php if (!empty($document['image_path'])): ?>
                                                    <div class="d-flex gap-1 justify-content-center">
                                                        <a href="#" class="btn-action btn-image image-link"
                                                           data-image-src="<?= htmlspecialchars($document['image_path']) ?>"
                                                           data-bs-toggle="tooltip"
                                                           data-bs-placement="top"
                                                           title="View Document Image">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <button type="button" class="btn-action btn-ocr ocr-btn"
                                                                data-image-src="<?= htmlspecialchars($document['image_path']) ?>"
                                                                data-document-type="<?= $document['doc_type'] ?>"
                                                                data-document-id="<?= $document['id'] ?>"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#ocrModal"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Run OCR on Document">
                                                            <i class="fas fa-magnifying-glass"></i>
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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
            </div>
        </div>
    </div>
    <!-- Chatbot Button -->
    <!-- <button class="chatbot-btn" id="chatbotToggle">
        <i class="fas fa-robot"></i>
    </button> -->

    <!-- Chatbot Container -->
    <div class="chatbot-container" id="chatbotContainer">
        <div class="chatbot-header">
            <span>eFIND AI Assistant</span>
            <button class="btn btn-sm btn-light" id="closeChatbot">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="chatbot-body" id="chatMessages">
            <!-- Chat messages will appear here -->
            <div class="chat-message bot-message">
                <div class="message-content">
                    <strong>eFIND Assistant:</strong> Hello! I'm your eFIND AI Assistant. How can I help you with documents, ordinances, resolutions, or meeting minutes today?
                </div>
            </div>
        </div>
        <div class="chatbot-footer">
            <div class="input-group">
                <input type="text" class="form-control" id="chatInput" placeholder="Type your message..." aria-label="Type your message">
                <button class="btn btn-primary" id="sendMessage" type="button">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
    <!-- OCR Modal -->
    <div class="modal fade ocr-modal" id="ocrModal" tabindex="-1" aria-labelledby="ocrModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ocrModalLabel"><i class="fas fa-file-search me-2"></i> Image Text Extraction (OCR)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="ocrDocumentId">
                    <input type="hidden" id="ocrDocumentType">
                    <input type="hidden" id="ocrImagePath">
                    <div id="ocrLoading" style="display:none;">
                        <div class="d-flex align-items-center gap-2">
                            <div class="spinner-border text-warning" role="status"></div>
                            <span>Extracting text from document...</span>
                        </div>
                    </div>
                    <div id="ocrActions" class="d-flex justify-content-between mb-2" style="display:none;">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary active" data-view="formatted" data-bs-toggle="tooltip" data-bs-placement="top" title="Formatted View">
                                <i class="fas fa-paragraph"></i>
                            </button>
                            <button type="button" class="btn btn-outline-primary" data-view="raw" data-bs-toggle="tooltip" data-bs-placement="top" title="Raw Text">
                                <i class="fas fa-code"></i>
                            </button>
                        </div>
                        <div>
                            <button id="editOcrText" class="btn btn-sm btn-outline-success me-1" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Text">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button id="copyOcrText" class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip" data-bs-placement="top" title="Copy Text">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div id="ocrResult" class="p-3 border rounded bg-light" style="display:none;">
                        <div id="formattedView" class="ocr-view">
                            <!-- Formatted content will be inserted here -->
                        </div>
                        <div id="rawView" class="ocr-view" style="display:none;">
                            <pre class="m-0 p-2 bg-white border rounded"></pre>
                        </div>
                        <div id="editView" class="ocr-view" style="display:none;">
                            <textarea id="ocrTextEditor" class="form-control" rows="10" style="width: 100%;"></textarea>
                            <div class="d-flex justify-content-end mt-2">
                                <button id="cancelEdit" class="btn btn-sm btn-secondary me-2">Cancel</button>
                                <button id="saveOcrText" class="btn btn-sm btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Image Modal -->
    <div class="modal fade image-modal" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageModalLabel"><i class="fas fa-image me-2"></i>Document Image Preview</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" class="img-fluid rounded" alt="Document Image" style="max-height: 70vh;">
                </div>
                <div class="modal-footer">
                    <a id="downloadImage" href="#" class="btn btn-primary" download>
                        <i class="fas fa-download me-2"></i>Download Image
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Chat Widget -->
<!-- <div class="chat-widget">
    <div class="chat-container" id="chatContainer">
        <div class="chat-header">
            <div class="chat-title">eFIND Assistant</div>
            <button class="chat-close" id="closeChat">×</button>
        </div>
        <div class="chat-messages" id="chatMessages">
            <div class="message bot">
                Hello! How can I help you today?
            </div>
        </div>
        <div class="typing-indicator" id="typingIndicator">
            <span class="typing-dots">
                Assistant is typing<span>.</span><span>.</span><span>.</span>
            </span>
        </div>
        <div class="chat-input-area">
            <input type="text" class="chat-input" id="chatInput" placeholder="Type your message...">
            <button class="send-button" id="sendButton">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22 2L11 13" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M22 2L15 22L11 13L2 9L22 2Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
    </div>
    <button class="chat-toggle" id="chatToggle">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2Z" fill="white"/>
        </svg>
    </button>
</div> -->
        <!-- Chat Widget Script -->
        <script src="https://cdn.jsdelivr.net/npm/@n8n/chat@latest/dist/n8n-chat.umd.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing chatbot...');
            
            // Chat elements
            const chatbotToggle = document.getElementById('chatbotToggle');
            const chatbotContainer = document.getElementById('chatbotContainer');
            const closeChatbot = document.getElementById('closeChatbot');
            const chatMessages = document.getElementById('chatMessages');
            const chatInput = document.getElementById('chatInput');
            const sendMessage = document.getElementById('sendMessage');
            
            // API Configuration - Use our PHP API middleware for security
            // The API will handle n8n webhook communication securely
            const API_URL = 'api.php/chat';  // Route through our API instead of direct n8n access
            
            // Session management
            let sessionId = `session_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
            let messageHistory = [];
            
            // Initialize chatbot
            function initializeChatbot() {
                console.log('Initializing chatbot...');
                
                // Toggle chatbot visibility
                chatbotToggle.addEventListener('click', function() {
                    console.log('Opening chatbot');
                    chatbotContainer.style.display = 'flex';
                    chatInput.focus();
                });
                
                closeChatbot.addEventListener('click', function() {
                    console.log('Closing chatbot');
                    chatbotContainer.style.display = 'none';
                });
                
                // Send message on button click
                sendMessage.addEventListener('click', sendUserMessage);
                
                // Send message on Enter key
                chatInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        sendUserMessage();
                    }
                });
                
                // Close chatbot when clicking outside
                document.addEventListener('click', function(e) {
                    if (!chatbotContainer.contains(e.target) && e.target !== chatbotToggle) {
                        chatbotContainer.style.display = 'none';
                    }
                });
                
                console.log('Chatbot initialized successfully');
            }
            
            // Send user message
            async function sendUserMessage() {
                const message = chatInput.value.trim();
                if (!message) return;
                
                console.log('Sending message:', message);
                
                // Add user message to chat
                addMessageToChat(message, 'user');
                
                // Clear input
                chatInput.value = '';
                
                // Show typing indicator
                showTypingIndicator();
                
                try {
                    // Prepare message data
                    const messageData = {
                        sessionId: sessionId,
                        message: message,
                        messageHistory: messageHistory,
                        userId: '<?php echo $_SESSION["user_id"] ?? "guest"; ?>',
                        userType: 'dashboard_user',
                        timestamp: new Date().toISOString(),
                        source: 'efind_dashboard'
                    };
                    
                    console.log('Sending to API:', API_URL);
                    
                    // Send to API (which will forward to n8n)
                    const response = await fetch(API_URL, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(messageData)
                    });
                    
                    if (!response.ok) {
                        const errorText = await response.text();
                        console.error('API Error Response:', errorText);
                        throw new Error(`API error! status: ${response.status}`);
                    }
                    
                    const data = await response.json();
                    console.log('Received response:', data);
                    
                    // Hide typing indicator
                    hideTypingIndicator();
                    
                    // Process response - API now returns standardized format
                    let botResponse = 'I apologize, but I encountered an issue processing your request. Please try again.';
                    
                    // Check for fallback mode
                    if (data && data.fallback) {
                        console.warn('Fallback response received:', data.error);
                    }
                    
                    // Standardized response handling (api.php returns 'output' field)
                    if (data && data.output) {
                        botResponse = data.output;
                    } else if (data && data.response) {
                        botResponse = data.response;
                    } else if (data && data.message) {
                        botResponse = data.message;
                    } else if (typeof data === 'string') {
                        botResponse = data;
                    }
                    
                    // Add bot response to chat
                    addMessageToChat(botResponse, 'bot');
                    
                    // Update message history (keep last 10 messages to avoid payload being too large)
                    messageHistory.push({ role: 'user', content: message });
                    messageHistory.push({ role: 'assistant', content: botResponse });
                    
                    if (messageHistory.length > 20) {
                        messageHistory = messageHistory.slice(-20);
                    }
                    
                } catch (error) {
                    console.error('Error sending message:', error);
                    hideTypingIndicator();
                    
                    // Log error details for debugging
                    if (error.message) {
                        console.error('Error details:', error.message);
                    }
                    
                    // Show error message
                    addMessageToChat(
                        'Sorry, I\'m having trouble connecting to the assistant right now. Please check your connection and try again.',
                        'bot'
                    );
                }
            }
            
            // Add message to chat
            function addMessageToChat(message, sender) {
                const messageDiv = document.createElement('div');
                messageDiv.className = `chat-message ${sender}-message`;
                
                const messageContent = document.createElement('div');
                messageContent.className = 'message-content';
                
                if (sender === 'bot') {
                    messageContent.innerHTML = `<strong>eFIND Assistant:</strong> ${message}`;
                } else {
                    messageContent.innerHTML = `<strong>You:</strong> ${message}`;
                }
                
                messageDiv.appendChild(messageContent);
                chatMessages.appendChild(messageDiv);
                
                // Scroll to bottom
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
            
            // Show typing indicator
            function showTypingIndicator() {
                const typingDiv = document.createElement('div');
                typingDiv.className = 'chat-message bot-message typing-indicator';
                typingDiv.id = 'typingIndicator';
                
                typingDiv.innerHTML = `
                    <div class="message-content">
                        <strong>eFIND Assistant:</strong> 
                        <div class="typing-dots">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                    </div>
                `;
                
                chatMessages.appendChild(typingDiv);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
            
            // Hide typing indicator
            function hideTypingIndicator() {
                const typingIndicator = document.getElementById('typingIndicator');
                if (typingIndicator) {
                    typingIndicator.remove();
                }
            }
            
            // Initialize the chatbot
            initializeChatbot();
        });
        
        // Handle image modal
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.image-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const imageSrc = this.getAttribute('data-image-src');
                    const modalImage = document.getElementById('modalImage');
                    const downloadLink = document.getElementById('downloadImage');
                    
                    modalImage.src = imageSrc;
                    downloadLink.href = imageSrc;
                    downloadLink.download = imageSrc.split('/').pop();
                    
                    const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
                    imageModal.show();
                });
            });
        });
    </script>
    
    <footer class="bg-white p-3">
        <?php include(__DIR__ . '/includes/footer.php'); ?>
    </footer>
    
    <!-- AI Chatbot Widget -->
    <?php include(__DIR__ . '/includes/chatbot_widget.php'); ?>

<!-- ============================================================
     Upload Documents Modal
     ============================================================ -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a3a8f,#1e40af);color:#fff;">
        <h5 class="modal-title" id="uploadDocumentModalLabel">
          <i class="fas fa-cloud-upload-alt me-2"></i>Upload Documents
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">

        <!-- Step indicator -->
        <div id="ud-step-indicator" class="d-flex gap-2 mb-4">
          <div class="flex-fill rounded" id="ud-dot-1" style="height:4px;background:#1a3a8f;transition:background .3s;"></div>
          <div class="flex-fill rounded" id="ud-dot-2" style="height:4px;background:#dee2e6;transition:background .3s;"></div>
          <div class="flex-fill rounded" id="ud-dot-3" style="height:4px;background:#dee2e6;transition:background .3s;"></div>
        </div>

        <!-- ─── STEP 1: Choose document type ─── -->
        <div id="ud-step-1">
          <p class="fw-semibold text-muted mb-3"><i class="fas fa-file-alt me-2"></i>Step 1 — Select Document Type</p>
          <div class="row g-3">
            <div class="col-md-4">
              <button class="ud-type-btn w-100 p-3 border rounded-3 text-start bg-white" onclick="udSelectType('resolutions', this)">
                <div class="fs-2">📋</div>
                <div class="fw-semibold mt-1">Resolution</div>
                <div class="text-muted small">Barangay council resolutions</div>
              </button>
            </div>
            <div class="col-md-4">
              <button class="ud-type-btn w-100 p-3 border rounded-3 text-start bg-white" onclick="udSelectType('minutes', this)">
                <div class="fs-2">📝</div>
                <div class="fw-semibold mt-1">Minutes of Meeting</div>
                <div class="text-muted small">Council session minutes</div>
              </button>
            </div>
            <div class="col-md-4">
              <button class="ud-type-btn w-100 p-3 border rounded-3 text-start bg-white" onclick="udSelectType('ordinances', this)">
                <div class="fs-2">⚖️</div>
                <div class="fw-semibold mt-1">Ordinance</div>
                <div class="text-muted small">Barangay ordinances</div>
              </button>
            </div>
          </div>
          <div class="mt-4 d-flex justify-content-end">
            <button class="btn btn-primary px-4" id="ud-btn-next-1" disabled onclick="udGoToStep(2)">
              Next <i class="fas fa-arrow-right ms-1"></i>
            </button>
          </div>
        </div>

        <!-- ─── STEP 2: Choose upload method ─── -->
        <div id="ud-step-2" class="d-none">
          <p class="fw-semibold text-muted mb-3"><i class="fas fa-upload me-2"></i>Step 2 — Choose Upload Method</p>
          <div class="row g-3">
            <div class="col-md-6">
              <button class="w-100 p-4 border rounded-3 text-center bg-white h-100 d-flex flex-column align-items-center justify-content-center gap-2"
                      style="border:2px solid #dee2e6;cursor:pointer;" onclick="udGoToStep(3)">
                <i class="fas fa-desktop" style="font-size:2.5rem;color:#1a3a8f;"></i>
                <div class="fw-semibold mt-1">Upload from this Device</div>
                <div class="text-muted small">Fill in details and upload files from your computer</div>
              </button>
            </div>
            <div class="col-md-6">
              <button class="w-100 p-4 border rounded-3 text-center bg-white h-100 d-flex flex-column align-items-center justify-content-center gap-2"
                      style="border:2px solid #dee2e6;cursor:pointer;" onclick="udShowQR()">
                <i class="fas fa-qrcode" style="font-size:2.5rem;color:#1a3a8f;"></i>
                <div class="fw-semibold mt-1">Upload from Mobile Device</div>
                <div class="text-muted small">Scan QR code with your phone to upload on the go</div>
              </button>
            </div>
          </div>
          <!-- QR Code Panel (hidden until selected) -->
          <div id="ud-qr-panel" class="d-none mt-4 text-center p-4 border rounded-3 bg-light">
            <p class="fw-semibold mb-1">Scan with your mobile device</p>
            <p class="text-muted small mb-3">Point your phone's camera at this QR code to open the mobile upload page.</p>
            <div id="ud-qrcode" class="d-inline-block p-2 bg-white rounded border"></div>
            <div class="mt-3">
              <a id="ud-qr-link" href="#" target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-external-link-alt me-1"></i>Open Mobile Upload Page
              </a>
            </div>
          </div>
          <div class="mt-4 d-flex justify-content-between">
            <button class="btn btn-outline-secondary" onclick="udGoToStep(1)">
              <i class="fas fa-arrow-left me-1"></i> Back
            </button>
          </div>
        </div>

        <!-- ─── STEP 3: Desktop upload form ─── -->
        <div id="ud-step-3" class="d-none">
          <p class="fw-semibold text-muted mb-3"><i class="fas fa-pencil-alt me-2"></i>Step 3 — Document Details &amp; Files</p>
          <div id="ud-meta-fields" class="mb-3"></div>

          <!-- Drop zone -->
          <div class="border border-2 border-dashed rounded-3 p-4 text-center mb-3"
               id="ud-drop-zone" style="cursor:pointer;background:#fafafa;"
               onclick="document.getElementById('ud-file-input').click()">
            <i class="fas fa-cloud-upload-alt" style="font-size:2.5rem;color:#adb5bd;"></i>
            <p class="mt-2 mb-1 fw-semibold">Click or drag files here</p>
            <p class="text-muted small mb-0">Images (JPG, PNG) or PDF · 10 MB max per file</p>
          </div>
          <input type="file" id="ud-file-input" class="d-none" accept="image/*,.pdf" multiple>
          <div id="ud-file-list" class="mb-3"></div>

          <div class="d-flex justify-content-between">
            <button class="btn btn-outline-secondary" onclick="udGoToStep(2)">
              <i class="fas fa-arrow-left me-1"></i> Back
            </button>
            <button class="btn btn-primary px-4" id="ud-btn-upload" disabled onclick="udStartUpload()">
              <i class="fas fa-upload me-2"></i>Upload
            </button>
          </div>
        </div>

        <!-- ─── STEP 4: Upload progress & result ─── -->
        <div id="ud-step-4" class="d-none">
          <div class="border rounded-3 p-3 mb-3">
            <div class="fw-semibold mb-3" id="ud-progress-header">
              <i class="fas fa-spinner fa-spin me-2"></i>Uploading…
            </div>
            <div id="ud-progress-list"></div>
          </div>
          <div id="ud-upload-result"></div>
        </div>

      </div><!-- /modal-body -->
    </div>
  </div>
</div>

<!-- QR Code library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
  'use strict';

  /* ── state ── */
  let udType      = '';
  let udFiles     = [];
  let udQrCreated = false;

  /* ── step dots ── */
  function udSetDots(active) {
    [1,2,3].forEach(i => {
      const d = document.getElementById(`ud-dot-${i}`);
      if (i < active)      d.style.background = '#198754';
      else if (i === active) d.style.background = '#1a3a8f';
      else                   d.style.background = '#dee2e6';
    });
  }

  /* ── navigate steps ── */
  window.udGoToStep = function (n) {
    [1,2,3,4].forEach(i =>
      document.getElementById(`ud-step-${i}`).classList.toggle('d-none', i !== n)
    );
    // dots only cover steps 1-3 visually
    udSetDots(Math.min(n, 3));
    if (n !== 2) document.getElementById('ud-qr-panel').classList.add('d-none');
  };

  /* ── type selection ── */
  window.udSelectType = function (type, btn) {
    udType = type;
    document.querySelectorAll('.ud-type-btn').forEach(b => {
      b.style.borderColor = '#dee2e6';
      b.style.background  = '#fff';
    });
    btn.style.borderColor = '#1a3a8f';
    btn.style.background  = '#e8f0fe';
    document.getElementById('ud-btn-next-1').disabled = false;
  };

  /* ── meta form templates ── */
  const udMetaTemplates = {
    resolutions: `
      <div class="row g-3">
        <div class="col-12"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
          <input class="form-control" name="title" placeholder="e.g. Resolution Approving Budget 2025" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Resolution Number <span class="text-danger">*</span></label>
          <input class="form-control" name="resolution_number" placeholder="e.g. 2025-001" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Resolution Date</label>
          <input class="form-control" type="date" name="resolution_date"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Date Issued</label>
          <input class="form-control" type="date" name="date_issued"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Reference Number</label>
          <input class="form-control" name="reference_number" placeholder="Optional"></div>
        <div class="col-12"><label class="form-label fw-semibold">Description</label>
          <textarea class="form-control" name="description" rows="2" placeholder="Brief description"></textarea></div>
      </div>`,
    minutes: `
      <div class="row g-3">
        <div class="col-12"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
          <input class="form-control" name="title" placeholder="e.g. Regular Session Minutes" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Session Number <span class="text-danger">*</span></label>
          <input class="form-control" name="session_number" placeholder="e.g. 1st Regular Session 2025" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Meeting Date</label>
          <input class="form-control" type="date" name="meeting_date"></div>
        <div class="col-12"><label class="form-label fw-semibold">Reference Number</label>
          <input class="form-control" name="reference_number" placeholder="Optional"></div>
      </div>`,
    ordinances: `
      <div class="row g-3">
        <div class="col-12"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
          <input class="form-control" name="title" placeholder="e.g. Ordinance on Noise Regulation" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Ordinance Number <span class="text-danger">*</span></label>
          <input class="form-control" name="ordinance_number" placeholder="e.g. ORD-2025-001" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Ordinance Date</label>
          <input class="form-control" type="date" name="ordinance_date"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Date Issued</label>
          <input class="form-control" type="date" name="date_issued"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
          <select class="form-select" name="status">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
            <option value="Repealed">Repealed</option>
          </select></div>
        <div class="col-12"><label class="form-label fw-semibold">Reference Number</label>
          <input class="form-control" name="reference_number" placeholder="Optional"></div>
        <div class="col-12"><label class="form-label fw-semibold">Description</label>
          <textarea class="form-control" name="description" rows="2" placeholder="Brief description"></textarea></div>
      </div>`
  };

  /* ── show QR panel ── */
  window.udShowQR = function () {
    const panel = document.getElementById('ud-qr-panel');
    panel.classList.remove('d-none');

    const url = `${location.protocol}//${location.host}${location.pathname.replace('dashboard.php','mobile_upload.php')}?type=${udType}`;
    document.getElementById('ud-qr-link').href = url;

    if (!udQrCreated) {
      document.getElementById('ud-qrcode').innerHTML = '';
      new QRCode(document.getElementById('ud-qrcode'), {
        text:   url,
        width:  200,
        height: 200,
        colorDark:  '#1a3a8f',
        colorLight: '#ffffff',
      });
      udQrCreated = true;
    }
  };

  /* ── reset QR when type changes ── */
  const origSelectType = window.udSelectType;
  window.udSelectType = function (type, btn) {
    udQrCreated = (type === udType); // reset QR only if type actually changed
    origSelectType(type, btn);
    // rebuild QR if panel already visible
    if (!document.getElementById('ud-qr-panel').classList.contains('d-none')) {
      udQrCreated = false;
      udShowQR();
    }
  };

  /* ── step 3 meta form ── */
  window.udGoToStep = function (n) {
    [1,2,3,4].forEach(i =>
      document.getElementById(`ud-step-${i}`).classList.toggle('d-none', i !== n)
    );
    udSetDots(Math.min(n, 3));
    if (n !== 2) document.getElementById('ud-qr-panel').classList.add('d-none');
    if (n === 3) {
      document.getElementById('ud-meta-fields').innerHTML = udMetaTemplates[udType] || '';
      document.getElementById('ud-meta-fields').addEventListener('input', udValidateStep3);
      udValidateStep3();
    }
  };

  function udValidateStep3() {
    const req = document.querySelectorAll('#ud-meta-fields [required]');
    const ok  = [...req].every(el => el.value.trim() !== '') && udFiles.length > 0;
    document.getElementById('ud-btn-upload').disabled = !ok;
  }

  /* ── file handling ── */
  const udFileInput = document.getElementById('ud-file-input');
  const udDropZone  = document.getElementById('ud-drop-zone');

  udFileInput.addEventListener('change', e => udAddFiles([...e.target.files]));
  udDropZone.addEventListener('dragover',  e => { e.preventDefault(); udDropZone.style.borderColor='#1a3a8f'; });
  udDropZone.addEventListener('dragleave', () => { udDropZone.style.borderColor=''; });
  udDropZone.addEventListener('drop',      e => {
    e.preventDefault(); udDropZone.style.borderColor='';
    udAddFiles([...e.dataTransfer.files]);
  });

  function udAddFiles(files) {
    const MAX = 10 * 1024 * 1024;
    files.forEach(f => {
      if (f.size > MAX) { alert(`${f.name} exceeds 10 MB limit.`); return; }
      if (udFiles.find(x => x.name === f.name && x.size === f.size)) return;
      udFiles.push(f);
    });
    udRenderFileList();
    udFileInput.value = '';
    udValidateStep3();
  }

  window.udRemoveFile = function (idx) {
    udFiles.splice(idx, 1);
    udRenderFileList();
    udValidateStep3();
  };

  function udRenderFileList() {
    const list = document.getElementById('ud-file-list');
    list.innerHTML = udFiles.map((f, i) => {
      const isImg = f.type.startsWith('image/');
      const url   = isImg ? URL.createObjectURL(f) : null;
      const size  = f.size < 1024*1024 ? (f.size/1024).toFixed(1)+' KB' : (f.size/1024/1024).toFixed(1)+' MB';
      const thumb = isImg
        ? `<img src="${url}" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px;">`
        : `<div style="width:44px;height:44px;display:flex;align-items:center;justify-content:center;background:#fee2e2;border-radius:6px;font-size:22px;color:#dc2626;"><i class="fas fa-file-pdf"></i></div>`;
      return `<div class="d-flex align-items-center gap-2 p-2 border rounded mb-2 bg-white">
        ${thumb}
        <div class="flex-grow-1 overflow-hidden">
          <div class="small fw-semibold text-truncate">${udEsc(f.name)}</div>
          <div class="small text-muted">${size}</div>
        </div>
        <span style="color:#dc2626;cursor:pointer;font-size:18px;" onclick="udRemoveFile(${i})"><i class="fas fa-times-circle"></i></span>
      </div>`;
    }).join('');
  }

  function udEsc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── upload ── */
  window.udStartUpload = async function () {
    udGoToStep(4);
    const progList = document.getElementById('ud-progress-list');
    progList.innerHTML = '';
    const objectKeys = [];
    let allOk = true;

    for (let i = 0; i < udFiles.length; i++) {
      const file  = udFiles[i];
      const rowId = `ud-prog-${i}`;
      progList.insertAdjacentHTML('beforeend', `
        <div id="${rowId}" class="mb-3">
          <div class="d-flex justify-content-between mb-1">
            <span class="small fw-semibold text-truncate" style="max-width:75%">${udEsc(file.name)}</span>
            <span class="small text-muted" id="${rowId}-pct">0%</span>
          </div>
          <div class="progress" style="height:6px;border-radius:3px;">
            <div class="progress-bar" id="${rowId}-bar" style="width:0%;"></div>
          </div>
          <div class="small text-muted mt-1" id="${rowId}-status">Getting upload URL…</div>
        </div>`);

      let presignedUrl, objectKey;
      try {
        const res = await fetch('generate_presigned_url.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ doc_type: udType, file_name: file.name, content_type: file.type || 'application/octet-stream' }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to get presigned URL');
        presignedUrl = data.presigned_url;
        objectKey    = data.object_key;
      } catch (err) {
        udSetFileStatus(rowId, 'danger', `Error: ${err.message}`);
        allOk = false; continue;
      }

      try {
        await udUploadToMinio(file, presignedUrl, rowId);
        objectKeys.push(objectKey);
        udSetFileStatus(rowId, 'success', 'Uploaded ✓');
      } catch (err) {
        udSetFileStatus(rowId, 'danger', `Upload failed: ${err.message}`);
        allOk = false;
      }
    }

    if (!allOk || objectKeys.length === 0) {
      udShowResult(false, 'Some files failed to upload. Please try again.');
      return;
    }

    const meta = {};
    document.querySelectorAll('#ud-meta-fields input,#ud-meta-fields textarea,#ud-meta-fields select')
      .forEach(el => { if (el.name) meta[el.name] = el.value; });

    try {
      const res = await fetch('confirm_upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ doc_type: udType, object_keys: objectKeys, ...meta }),
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'DB save failed');
      udShowResult(true, data);
    } catch (err) {
      udShowResult(false, err.message);
    }
  };

  function udUploadToMinio(file, presignedUrl, rowId) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('PUT', presignedUrl);
      xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
      xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
          const pct = Math.round((e.loaded / e.total) * 100);
          document.getElementById(`${rowId}-bar`).style.width = pct + '%';
          document.getElementById(`${rowId}-pct`).textContent  = pct + '%';
        }
      };
      xhr.onload  = () => xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new Error(`HTTP ${xhr.status}`));
      xhr.onerror = () => reject(new Error('Network error'));
      xhr.send(file);
    });
  }

  function udSetFileStatus(rowId, type, msg) {
    const bar = document.getElementById(`${rowId}-bar`);
    if (bar) { bar.classList.add(type === 'success' ? 'bg-success' : 'bg-danger'); bar.style.width = '100%'; }
    const st = document.getElementById(`${rowId}-status`);
    if (st) { st.textContent = msg; st.className = `small mt-1 text-${type === 'success' ? 'success' : 'danger'}`; }
  }

  function udShowResult(success, data) {
    const header = document.getElementById('ud-progress-header');
    header.innerHTML = success
      ? '<i class="fas fa-check-circle text-success me-2"></i>Upload Complete'
      : '<i class="fas fa-exclamation-circle text-danger me-2"></i>Upload Failed';

    const docPages = { resolutions: 'resolutions.php', minutes: 'minutes_of_meeting.php', ordinances: 'ordinances.php' };
    const resultEl = document.getElementById('ud-upload-result');
    if (success) {
      resultEl.innerHTML = `
        <div class="text-center p-4">
          <div style="font-size:4rem;color:#198754;"><i class="fas fa-check-circle"></i></div>
          <h5 class="fw-bold mt-2">Upload Successful!</h5>
          <p class="text-muted">Your document has been saved and is now available in the system.</p>
          <a href="${docPages[udType]}" class="btn btn-primary me-2"><i class="fas fa-eye me-2"></i>View Document</a>
          <button class="btn btn-outline-secondary" onclick="udResetModal()"><i class="fas fa-plus me-2"></i>Upload Another</button>
        </div>`;
    } else {
      resultEl.innerHTML = `
        <div class="alert alert-danger"><strong>Error:</strong> ${udEsc(typeof data === 'string' ? data : JSON.stringify(data))}</div>
        <button class="btn btn-outline-secondary w-100" onclick="udGoToStep(3)"><i class="fas fa-arrow-left me-1"></i>Go Back</button>`;
    }
  }

  /* ── reset modal when closed ── */
  window.udResetModal = function () {
    udType      = '';
    udFiles     = [];
    udQrCreated = false;
    document.querySelectorAll('.ud-type-btn').forEach(b => { b.style.borderColor=''; b.style.background='#fff'; });
    document.getElementById('ud-btn-next-1').disabled = true;
    document.getElementById('ud-file-list').innerHTML = '';
    document.getElementById('ud-meta-fields').innerHTML = '';
    document.getElementById('ud-progress-list').innerHTML = '';
    document.getElementById('ud-upload-result').innerHTML = '';
    document.getElementById('ud-qr-panel').classList.add('d-none');
    document.getElementById('ud-qrcode').innerHTML = '';
    udGoToStep(1);
  };

  document.getElementById('uploadDocumentModal').addEventListener('hidden.bs.modal', window.udResetModal);
})();
</script>
</body>
</html>

<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

// Initialize variables
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "User deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting user: " . $stmt->error;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed.");
    }
    if (isset($_POST['add_user'])) {
        $full_name = trim($_POST['full_name']);
        $contact_number = trim($_POST['contact_number']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role']);
        $profile_picture = '';

        // Handle file upload if present
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png'];
            $file_type = $_FILES['profile_picture']['type'];
            if (!in_array($file_type, $allowed_types)) {
                $_SESSION['error'] = "Only JPG and PNG files are allowed.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $upload_dir = __DIR__ . '/uploads/profiles/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_name = uniqid() . '_' . basename($_FILES['profile_picture']['name']);
                $target_path = $upload_dir . $file_name;
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
                    $profile_picture = 'uploads/profiles/' . $file_name;
                } else {
                    $_SESSION['error'] = "Failed to upload profile picture.";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            }
        }

        // Validate inputs
        if (empty($full_name) || empty($email) || empty($username) || empty($password) || empty($role)) {
            $_SESSION['error'] = "Full Name, Email, Username, Password, and Role are required fields.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // Insert new user
            $created_at = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO users (full_name, contact_number, email, username, password, role, profile_picture, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $full_name, $contact_number, $email, $username, $hashed_password, $role, $profile_picture, $created_at);
            if ($stmt->execute()) {
                $_SESSION['success'] = "User added successfully!";
            } else {
                $_SESSION['error'] = "Error adding user: " . $stmt->error;
            }
            $stmt->close();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } elseif (isset($_POST['update_user'])) {
        $id = (int)$_POST['user_id'];
        $full_name = trim($_POST['full_name']);
        $contact_number = trim($_POST['contact_number']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $password = !empty(trim($_POST['password'])) ? password_hash(trim($_POST['password']), PASSWORD_DEFAULT) : null;
        $role = trim($_POST['role']);
        $existing_profile_picture = $_POST['existing_profile_picture'] ?? '';
        $profile_picture = $existing_profile_picture;

        // Handle file upload if present
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png'];
            $file_type = $_FILES['profile_picture']['type'];
            if (!in_array($file_type, $allowed_types)) {
                $_SESSION['error'] = "Only JPG and PNG files are allowed.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                // Delete old file if exists
                if (!empty($existing_profile_picture)) {
                    @unlink(__DIR__ . '/' . $existing_profile_picture);
                }
                $upload_dir = __DIR__ . '/uploads/profiles/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_name = uniqid() . '_' . basename($_FILES['profile_picture']['name']);
                $target_path = $upload_dir . $file_name;
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
                    $profile_picture = 'uploads/profiles/' . $file_name;
                } else {
                    $_SESSION['error'] = "Failed to upload profile picture.";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            }
        }

        // Validate inputs
        if (empty($full_name) || empty($email) || empty($username) || empty($role)) {
            $_SESSION['error'] = "Full Name, Email, Username, and Role are required fields.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            // Update existing user
            if ($password) {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, contact_number = ?, email = ?, username = ?, password = ?, role = ?, profile_picture = ? WHERE id = ?");
                $stmt->bind_param("sisssssi", $full_name, $contact_number, $email, $username, $password, $role, $profile_picture, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, contact_number = ?, email = ?, username = ?, role = ?, profile_picture = ? WHERE id = ?");
                $stmt->bind_param("sissssi", $full_name, $contact_number, $email, $username, $role, $profile_picture, $id);
            }
            if ($stmt->execute()) {
                $_SESSION['success'] = "User updated successfully!";
            } else {
                $_SESSION['error'] = "Error updating user: " . $stmt->error;
            }
            $stmt->close();
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Handle GET request for fetching user data
if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['id'])) {
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF token validation failed.']);
        exit();
    }
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    if ($user) {
        header('Content-Type: application/json');
        echo json_encode($user);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'User not found']);
        exit();
    }
}

// Handle search, pagination, and sort functionality
$search_query = isset($_GET['search_query']) ? trim($_GET['search_query']) : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'full_name_asc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$table_limit = isset($_GET['table_limit']) ? intval($_GET['table_limit']) : 10;
$valid_limits = [5, 10, 25, 50, 100];
if (!in_array($table_limit, $valid_limits)) {
    $table_limit = 10;
}
$offset = ($page - 1) * $table_limit;

// Initialize parameters and types for search
$params = [];
$types = '';
$where_clauses = [];

// Add search condition if search query is provided
if (!empty($search_query)) {
    $search_like = "%" . $search_query . "%";
    $where_clauses[] = "(full_name LIKE ? OR email LIKE ? OR username LIKE ? OR contact_number LIKE ?)";
    $params = array_merge($params, [$search_like, $search_like, $search_like, $search_like]);
    $types .= 'ssss';
}

// Build the query
$query = "SELECT * FROM users";
if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

// Validate and set sort parameter
$valid_sorts = [
    'full_name_asc' => 'full_name ASC',
    'full_name_desc' => 'full_name DESC',
    'email_asc' => 'email ASC',
    'email_desc' => 'email DESC',
    'username_asc' => 'username ASC',
    'username_desc' => 'username DESC',
    'role_asc' => 'role ASC',
    'role_desc' => 'role DESC'
];

// Use validated sort or default
$sort_clause = $valid_sorts[$sort_by] ?? 'full_name ASC';

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
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Fetch total count for pagination
$count_query = "SELECT COUNT(*) as total FROM users";
if (!empty($where_clauses)) {
    $count_query .= " WHERE " . implode(" AND ", $where_clauses);
}
$count_stmt = $conn->prepare($count_query);
if (!empty($params) && !empty($types)) {
    // For count query, we need to remove the LIMIT parameters but keep search parameters
    $countParams = [];
    $countTypes = '';
    // Only include search parameters (not pagination parameters)
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
$total_users = $total_row ? $total_row['total'] : 0;
$total_pages = ceil($total_users / $table_limit);
$count_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - eFIND System</title>
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
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }
        .btn-secondary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }
        .search-box {
            position: relative;
            margin-bottom: 20px;
            width: 100%;
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
            padding: 5px 5px
        }
        .table tr:hover td {
            background-color: rgba(67, 97, 238, 0.05);
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            min-width: 35px;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .action-btn i {
            margin-right: 0;
            font-size: 1rem;
        }
        .btn-view {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        .btn-view:hover {
            background-color: rgba(40, 167, 69, 0.2);
        }
        .btn-edit {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            border: 1px solid rgba(13, 110, 253, 0.3);
        }
        .btn-edit:hover {
            background-color: rgba(13, 110, 253, 0.2);
        }
        .btn-delete {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        .btn-delete:hover {
            background-color: rgba(220, 53, 69, 0.2);
        }
        .tooltip-inner {
            font-size: 0.8rem;
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
        .modal-title {
            font-weight: 600;
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
        .file-upload {
            border: 2px dashed var(--light-blue);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background-color: rgba(67, 97, 238, 0.05);
        }
        .file-upload:hover {
            background-color: rgba(67, 97, 238, 0.1);
        }
        .current-file {
            margin-top: 10px;
            font-size: 0.9rem;
            color: var(--medium-gray);
        }
        .current-file a {
            color: var(--primary-blue);
            text-decoration: none;
        }
        .current-file a:hover {
            text-decoration: underline;
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
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                max-height: calc(100vh - 350px);
            }
            .action-buttons {
                flex-direction: row;
                gap: 5px;
            }
            .action-btn {
                min-width: 35px;
                font-size: 0.8rem;
                padding: 5px 8px;
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
            .d-flex.justify-content-between {
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
        }
        .table-info {
            padding: 10px 20px;
            background-color: var(--light-blue);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            border-bottom: 1px solid var(--light-blue);
            font-weight: 600;
            color: var(--secondary-blue);
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
                <h1 class="page-title">Users Management</h1>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-plus me-1"></i> Add User
                    </button>
                </div>
            </div>
            <!-- Search Form -->
            <form method="GET" action="users.php" class="mb-0">
                <div class="row">
                    <div class="col-md-9">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search_query" id="searchInput" class="form-control" placeholder="Search users..." value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <select name="sort_by" id="sort_by" class="form-select" onchange="updateSort()">
                            <option value="full_name_asc" <?php echo $sort_by === 'full_name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            <option value="full_name_desc" <?php echo $sort_by === 'full_name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                            <option value="email_asc" <?php echo $sort_by === 'email_asc' ? 'selected' : ''; ?>>Email (A-Z)</option>
                            <option value="email_desc" <?php echo $sort_by === 'email_desc' ? 'selected' : ''; ?>>Email (Z-A)</option>
                            <option value="username_asc" <?php echo $sort_by === 'username_asc' ? 'selected' : ''; ?>>Username (A-Z)</option>
                            <option value="username_desc" <?php echo $sort_by === 'username_desc' ? 'selected' : ''; ?>>Username (Z-A)</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary-custom w-100">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                    </div>
                </div>
                <!-- Hidden input for sort_by -->
                <input type="hidden" name="sort_by" id="hiddenSortBy" value="<?php echo htmlspecialchars($sort_by); ?>">
            </form>
            <!-- Table Info -->
            <div class="table-info d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-users me-2"></i>
                    Showing <?php echo count($users); ?> of <?php echo $total_users; ?> users
                    <?php if (!empty($search_query)): ?>
                        <span class="text-muted ms-2">(Filtered results)</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Users Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle text-center">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Contact Number</th>
                                <th>Email</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Profile Picture</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No users found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr data-id="<?php echo $user['id']; ?>">
                                        <td><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td class="full-name text-start"><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td class="contact-number"><?php echo htmlspecialchars($user['contact_number']); ?></td>
                                        <td class="email"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="username"><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td class="role">
                                            <span class="badge bg-<?php
                                                switch($user['role']) {
                                                    case 'admin': echo 'danger'; break;
                                                    case 'staff': echo 'primary'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['profile_picture'])): ?>
                                                <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>?t=<?php echo time(); ?>"
                                                     alt="Profile Picture"
                                                     class="rounded-circle"
                                                     width="40"
                                                     height="40"
                                                     onerror="this.onerror=null; this.src='assets/img/default-profile.png';">
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <button class="btn btn-sm btn-outline-primary p-1 edit-btn"
                                                        data-id="<?php echo $user['id']; ?>"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="top"
                                                        title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?action=delete&id=<?php echo $user['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>"
                                                   class="btn btn-sm btn-outline-danger p-1"
                                                   onclick="return confirm('Are you sure you want to delete this user?');"
                                                   data-bs-toggle="tooltip"
                                                   data-bs-placement="top"
                                                   title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Sticky Pagination -->
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
    <!-- Modal for Add New User -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Add New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="contact_number" class="form-label">Contact Number</label>
                                <input type="text" class="form-control" id="contact_number" name="contact_number">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="admin">Admin</option>
                                    <option value="staff">Staff</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Profile Picture (JPG, PNG)</label>
                            <div class="file-upload">
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png">
                                <small class="text-muted">Max file size: 5MB</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_user" class="btn btn-primary-custom">Add User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal for Edit User -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editUserForm" method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="user_id" id="editUserId">
                        <input type="hidden" name="existing_profile_picture" id="editExistingProfilePicture">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editFullName" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editFullName" name="full_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editContactNumber" class="form-label">Contact Number</label>
                                <input type="text" class="form-control" id="editContactNumber" name="contact_number">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editEmail" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="editEmail" name="email" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editUsername" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editUsername" name="username" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editPassword" class="form-label">Password (Leave blank to keep current)</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="editPassword" name="password">
                                    <button class="btn btn-outline-secondary toggle-password" type="button">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editRole" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" id="editRole" name="role" required>
                                    <option value="admin">Admin</option>
                                    <option value="staff">Staff</option>
                                    <option value="viewer">Viewer</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Profile Picture (JPG, PNG)</label>
                            <div class="file-upload">
                                <input type="file" class="form-control" id="editProfilePicture" name="profile_picture" accept=".jpg,.jpeg,.png">
                                <small class="text-muted">Max file size: 5MB</small>
                            </div>
                            <div id="currentProfilePictureInfo" class="current-file"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update_user" class="btn btn-primary-custom">Update User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
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
            // Toggle password visibility
            document.querySelectorAll('.toggle-password').forEach(button => {
                button.addEventListener('click', function() {
                    const input = this.parentElement.querySelector('input');
                    const icon = this.querySelector('i');
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.replace('fa-eye', 'fa-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    }
                });
            });
            // Edit button functionality
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    fetch(`?action=get_user&id=${id}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`)
                        .then(response => response.json())
                        .then(user => {
                            document.getElementById('editUserId').value = user.id;
                            document.getElementById('editFullName').value = user.full_name;
                            document.getElementById('editContactNumber').value = user.contact_number || '';
                            document.getElementById('editEmail').value = user.email;
                            document.getElementById('editUsername').value = user.username;
                            document.getElementById('editRole').value = user.role;
                            document.getElementById('editExistingProfilePicture').value = user.profile_picture || '';
                            const currentProfilePictureInfo = document.getElementById('currentProfilePictureInfo');
                            if (user.profile_picture) {
                                currentProfilePictureInfo.innerHTML = `
                                    <strong>Current Profile Picture:</strong><br>
                                    <img src="uploads/profiles/${user.profile_picture}?t=${new Date().getTime()}"
                                         alt="Profile Picture"
                                         class="rounded-circle mt-2"
                                         width="60"
                                         height="60">
                                `;
                            } else {
                                currentProfilePictureInfo.innerHTML = '<strong>No profile picture uploaded</strong>';
                            }
                            const editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
                            editModal.show();
                        })
                        .catch(error => {
                            console.error('Error fetching user:', error);
                            alert('Error loading user data.');
                        });
                });
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

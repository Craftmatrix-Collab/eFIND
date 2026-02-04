<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Include configuration files
include(__DIR__ . '/includes/auth.php');
include(__DIR__ . '/includes/config.php');
include(__DIR__ . '/includes/logger.php');
include(__DIR__ . '/includes/minio_helper.php');

// Check if user is logged in - redirect to login if not
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Function to check if a file already exists in the uploads directory
function isFileDuplicate($uploadDir, $fileName) {
    $targetPath = $uploadDir . basename($fileName);
    return file_exists($targetPath);
}

// Function to validate if the file is an ordinance document (image, PDF, or DOCX)
function isValidOrdinanceDocument($file) {
    $allowedTypes = [
        'image/jpeg', 
        'image/png', 
        'image/gif', 
        'image/bmp', 
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // DOCX
        'application/msword' // DOC
    ];
    return in_array($file['type'], $allowedTypes);
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

// Check if OCR content table exists, create if not
$checkTableQuery = "SHOW TABLES LIKE 'document_ocr_content'";
$tableResult = $conn->query($checkTableQuery);
if ($tableResult->num_rows == 0) {
    $createTableQuery = "CREATE TABLE document_ocr_content (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        document_type VARCHAR(50) NOT NULL,
        ocr_content LONGTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_document (document_id, document_type)
    )";
    if (!$conn->query($createTableQuery)) {
        error_log("Failed to create document_ocr_content table: " . $conn->error);
    }
}

// Function to generate reference number for ordinances
function generateReferenceNumber($conn, $ordinance_date = null) {
    if ($ordinance_date) {
        $year = date('Y', strtotime($ordinance_date));
        $month = date('m', strtotime($ordinance_date));
    } else {
        $year = date('Y');
        $month = date('m');
    }
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ordinances WHERE YEAR(ordinance_date) = ? AND MONTH(ordinance_date) = ?");
    $stmt->bind_param("ii", $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'] + 1;
    $stmt->close();
    return sprintf("ORD%04d%02d%04d", $year, $month, $count);
}

// Handle print action
if (isset($_GET['print']) && $_GET['print'] === '1') {
    $printStartDate = $_GET['print_start_date'] ?? '';
    $printEndDate = $_GET['print_end_date'] ?? '';
    
    // Validate date range
    if (!empty($printStartDate) && !empty($printEndDate) && strtotime($printStartDate) > strtotime($printEndDate)) {
        die("End date must be after start date.");
    }

    // Build query with filters for print
    $printQuery = "SELECT id, title, ordinance_number, date_posted, ordinance_date, content, status FROM ordinances WHERE 1=1";
    $printConditions = [];
    $printParams = [];
    $printTypes = '';

    if (!empty($printStartDate) && !empty($printEndDate)) {
        $printConditions[] = "DATE(ordinance_date) BETWEEN ? AND ?";
        $printParams[] = $printStartDate;
        $printParams[] = $printEndDate;
        $printTypes .= 'ss';
    } elseif (!empty($printStartDate)) {
        $printConditions[] = "DATE(ordinance_date) >= ?";
        $printParams[] = $printStartDate;
        $printTypes .= 's';
    } elseif (!empty($printEndDate)) {
        $printConditions[] = "DATE(ordinance_date) <= ?";
        $printParams[] = $printEndDate;
        $printTypes .= 's';
    }

    if (!empty($printConditions)) {
        $printQuery .= " AND " . implode(" AND ", $printConditions);
    }

    $printQuery .= " ORDER BY ordinance_date DESC";

    $printStmt = $conn->prepare($printQuery);
    if (!empty($printParams)) {
        $printStmt->bind_param($printTypes, ...$printParams);
    }
    $printStmt->execute();
    $printResult = $printStmt->get_result();
    $printOrdinances = $printResult->fetch_all(MYSQLI_ASSOC);
    $printStmt->close();

    // Render printable HTML
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ordinances Report - eFIND System</title>
        <link rel="icon" type="image/png" href="images/eFind_logo.png">
        <style>
            @page {
                size: A4;
                margin: 20mm;
            }
            body { 
                font-family: "DejaVu Sans", Arial, sans-serif; 
                font-size: 12px; 
                color: #333; 
                line-height: 1.4; 
                margin: 0; 
                padding: 0; 
                background: white;
            }
            .container { 
                width: 100%; 
                max-width: 100%;
                margin: 0 auto; 
                padding: 0;
            }
            .header { 
                text-align: center; 
                margin-bottom: 20px; 
                border-bottom: 2px solid #0056b3; 
                padding-bottom: 10px; 
            }
            .header h1 { 
                color: #0056b3; 
                font-size: 20px; 
                margin: 0; 
            }
            .header p { 
                color: #666; 
                margin: 5px 0 0; 
                font-size: 12px; 
            }
            .date-range { 
                text-align: center; 
                margin-bottom: 20px; 
                font-style: italic; 
                color: #555; 
            }
            .table-container {
                width: 100%;
                margin: 0 auto;
                overflow: visible;
            }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                margin: 0 auto 20px; 
                font-size: 10px;
                table-layout: fixed;
                word-wrap: break-word;
            }
            th, td { 
                border: 1px solid #ddd; 
                padding: 6px; 
                text-align: left; 
                vertical-align: top; 
                word-break: break-word;
            }
            th { 
                background-color: #0056b3; 
                color: white; 
                font-weight: bold; 
                font-size: 10px;
            }
            tr:nth-child(even) { 
                background-color: #f9f9f9; 
            }
            .footer { 
                text-align: center; 
                margin-top: 20px; 
                padding-top: 10px; 
                border-top: 1px solid #ddd; 
                font-size: 11px; 
                color: #666; 
            }
            .logo { 
                text-align: center; 
                margin-bottom: 10px; 
            }
            .logo img { 
                max-height: 60px; 
            }
            .badge {
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 9px;
                font-weight: 600;
            }
            .badge-success { background-color: #d4edda; color: #155724; }
            .badge-secondary { background-color: #e2e3e5; color: #383d41; }
            .badge-warning { background-color: #fff3cd; color: #856404; }
            .badge-primary { background-color: #cce7ff; color: #004085; }
            .badge-danger { background-color: #f8d7da; color: #721c24; }
            .reference-number {
                font-weight: 600;
                color: #0056b3;
                background-color: rgba(0, 86, 179, 0.1);
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 9px;
            }
            .content-preview {
                max-width: 150px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .col-id { width: 5%; }
            .col-ref { width: 12%; }
            .col-title { width: 20%; }
            .col-date-posted { width: 10%; }
            .col-ordinance-date { width: 10%; }
            .col-number { width: 12%; }
            .col-content { width: 20%; }
            .col-status { width: 8%; }
            
            @media print {
                body { 
                    margin: 0;
                    padding: 0;
                }
                .container { 
                    width: 100%;
                    padding: 0;
                    margin: 0;
                }
                table { 
                    page-break-inside: auto;
                    width: 100% !important;
                }
                tr { 
                    page-break-inside: avoid; 
                    page-break-after: auto; 
                }
                thead { 
                    display: table-header-group; 
                }
                tfoot { 
                    display: table-footer-group; 
                }
                .header {
                    margin-top: 0;
                }
                .no-print {
                    display: none !important;
                }
            }
            
            @media all {
                .page-break { display: none; }
            }
            
            @media print {
                .page-break { 
                    display: block; 
                    page-break-before: always; 
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="logo">
                    <img src="images/logo_pbsth.png" alt="Company Logo" style="max-height: 60px;">
                </div>
                <h1>Ordinances Report</h1>
            </div>';

    if (!empty($printStartDate) || !empty($printEndDate)) {
        echo '<div class="date-range">';
        if (!empty($printStartDate) && !empty($printEndDate)) {
            echo '<p>Date Range: ' . date('F j, Y', strtotime($printStartDate)) . ' to ' . date('F j, Y', strtotime($printEndDate)) . '</p>';
        } elseif (!empty($printStartDate)) {
            echo '<p>From: ' . date('F j, Y', strtotime($printStartDate)) . '</p>';
        } elseif (!empty($printEndDate)) {
            echo '<p>To: ' . date('F j, Y', strtotime($printEndDate)) . '</p>';
        }
        echo '</div>';
    }

    echo '<div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th class="col-id">ID</th>
                        <!-- <th class="col-ref">Reference No.</th> -->
                        <th class="col-title">Title</th>
                        <th class="col-date-posted">Date Posted</th>
                        <th class="col-ordinance-date">Ordinance Date</th>
                        <th class="col-number">Ordinance Number</th>
                        <th class="col-content">Content Preview</th>
                        <th class="col-status">Status</th>
                    </tr>
                </thead>
                <tbody>';

    if (empty($printOrdinances)) {
        echo '<tr><td colspan="8" style="text-align: center;">No ordinances found for the selected criteria.</td></tr>';
    } else {
        $count = 0;
        foreach ($printOrdinances as $ordinance) {
            $count++;
            // Add page break every 25 rows to prevent table cutting
            if ($count % 25 === 0) {
                echo '</tbody></table></div><div class="page-break"></div><div class="table-container"><table><thead><tr>
                    <th class="col-id">ID</th>
                    <!-- <th class="col-ref">Reference No.</th> -->
                    <th class="col-title">Title</th>
                    <th class="col-date-posted">Date Posted</th>
                    <th class="col-ordinance-date">Ordinance Date</th>
                    <th class="col-number">Ordinance Number</th>
                    <th class="col-content">Content Preview</th>
                    <th class="col-status">Status</th>
                </tr></thead><tbody>';
            }
            
            echo '<tr>
                <td>' . htmlspecialchars($ordinance['id']) . '</td>
                <!-- <td><span class="reference-number">' . htmlspecialchars($ordinance['reference_number'] ?? 'N/A') . '</span></td> -->
                <td>' . htmlspecialchars($ordinance['title']) . '</td>
                <td>' . date('M d, Y', strtotime($ordinance['date_posted'])) . '</td>
                <td>' . date('M d, Y', strtotime($ordinance['ordinance_date'])) . '</td>
                <td>' . htmlspecialchars($ordinance['ordinance_number']) . '</td>
                <td class="content-preview" title="' . htmlspecialchars($ordinance['content'] ?? 'N/A') . '">';
                
            $content = htmlspecialchars($ordinance['content'] ?? 'N/A');
            if (strlen($content) > 50) {
                echo substr($content, 0, 50) . '...';
            } else {
                echo $content;
            }
            
            echo '</td>
                <td>
                    <span class="badge badge-';
                    switch($ordinance['status']) {
                        case 'Active': echo 'success'; break;
                        case 'Inactive': echo 'secondary'; break;
                        case 'Pending': echo 'warning'; break;
                        case 'Approved': echo 'primary'; break;
                        case 'Rejected': echo 'danger'; break;
                        default: echo 'secondary';
                    }
                    echo '">' . htmlspecialchars($ordinance['status']) . '</span>
                </td>
            </tr>';
        }
    }

    echo '</tbody>
            </table>
        </div>
        <div class="footer">
            <p>© ' . date('Y') . ' eFIND System. All rights reserved.</p>
            <p>This document was generated automatically and is for internal use only.</p>
            <p>Page 1 of 1</p>
        </div>
    </div>
    <script>
        window.onload = function() {
            window.print();
            setTimeout(function() {
                window.close();
            }, 500);
        };
    </script>
    </body>
    </html>';
    exit;
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    // Log the deletion before executing
    $stmt = $conn->prepare("SELECT title FROM ordinances WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $ordinance = $result->fetch_assoc();
    $stmt->close();

    if ($ordinance) {
        logDocumentDelete('ordinance', $ordinance['title'], $id);
    }

    $stmt = $conn->prepare("DELETE FROM ordinances WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Ordinance deleted successfully!";
    } else {
        $_SESSION['error'] = "Failed to delete ordinance: " . $conn->error;
    }
    $stmt->close();
    header("Location: ordinances.php");
    exit();
}

// Initialize variables
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_ordinance'])) {
        // Add new ordinance
        $title = trim($_POST['title']);
        $ordinance_number = trim($_POST['ordinance_number']);
        $date_posted = $_POST['date_posted'];
        $ordinance_date = $_POST['ordinance_date'];
        $status = $_POST['status'];
        $content = trim($_POST['content']);
        $reference_number = generateReferenceNumber($conn, $ordinance_date);
        // Handle multiple file uploads to MinIO
        $image_path = null;
        if (isset($_FILES['image_file']) && is_array($_FILES['image_file']['tmp_name'])) {
            $minioClient = new MinioS3Client();
            $image_paths = [];
            
            foreach ($_FILES['image_file']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['image_file']['error'][$key] !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (!isValidOrdinanceDocument(['type' => $_FILES['image_file']['type'][$key]])) {
                    $_SESSION['error'] = "Invalid file type. Only JPG, PNG, GIF, BMP, PDF, or DOCX files are allowed.";
                    header("Location: ordinances.php");
                    exit();
                }
                
                $fileName = basename($_FILES['image_file']['name'][$key]);
                $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                $uniqueFileName = uniqid() . '_' . time() . '_' . $key . '.' . $fileExt;
                $objectName = 'ordinances/' . date('Y/m/') . $uniqueFileName;
                
                // Upload to MinIO
                $contentType = MinioS3Client::getMimeType($fileName);
                $uploadResult = $minioClient->uploadFile($tmpName, $objectName, $contentType);
                
                if ($uploadResult['success']) {
                    $image_paths[] = $uploadResult['url'];
                    logDocumentUpload('ordinance', $fileName, $uniqueFileName);
                } else {
                    $_SESSION['error'] = "Failed to upload file: $fileName. " . $uploadResult['error'];
                    header("Location: ordinances.php");
                    exit();
                }
            }
            if (!empty($image_paths)) {
                $image_path = implode('|', $image_paths);
            }
        }
        // Set description as title or content preview if not provided
        $description = !empty($content) ? substr($content, 0, 500) : $title;
        
        // Set required fields with defaults if not provided
        $date_issued = $ordinance_date; // Use ordinance_date as date_issued
        $file_path = $image_path ? $image_path : ''; // Use image_path or empty string
        $uploaded_by = isset($_SESSION['username']) ? $_SESSION['username'] : 'admin';
        
        $stmt = $conn->prepare("INSERT INTO ordinances (title, description, ordinance_number, date_posted, ordinance_date, status, content, image_path, reference_number, date_issued, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssssss", $title, $description, $ordinance_number, $date_posted, $ordinance_date, $status, $content, $image_path, $reference_number, $date_issued, $file_path, $uploaded_by);
        if ($stmt->execute()) {
            $new_ordinance_id = $conn->insert_id;
            logDocumentAction('create', 'ordinance', $title, $new_ordinance_id, "New ordinance created with reference number: $reference_number");
            $_SESSION['success'] = "Ordinance added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add ordinance: " . $conn->error;
        }
        $stmt->close();
        header("Location: ordinances.php");
        exit();
    }
    if (isset($_POST['update_ordinance'])) {
        // Update existing ordinance
        $id = intval($_POST['ordinance_id']);
        $title = trim($_POST['title']);
        $ordinance_number = trim($_POST['ordinance_number']);
        $date_posted = $_POST['date_posted'];
        $ordinance_date = $_POST['ordinance_date'];
        $status = $_POST['status'];
        $content = trim($_POST['content']);
        $existing_image_path = $_POST['existing_image_path'];
        // Handle multiple file uploads for update to MinIO
        $image_path = $existing_image_path;
        if (isset($_FILES['image_file']) && is_array($_FILES['image_file']['tmp_name'])) {
            $minioClient = new MinioS3Client();
            $image_paths = [];
            
            foreach ($_FILES['image_file']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['image_file']['error'][$key] !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (!isValidOrdinanceDocument(['type' => $_FILES['image_file']['type'][$key]])) {
                    $_SESSION['error'] = "Invalid file type. Only JPG, PNG, GIF, BMP, PDF, or DOCX files are allowed.";
                    header("Location: ordinances.php");
                    exit();
                }
                
                $fileName = basename($_FILES['image_file']['name'][$key]);
                $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                $uniqueFileName = uniqid() . '_' . time() . '_' . $key . '.' . $fileExt;
                $objectName = 'ordinances/' . date('Y/m/') . $uniqueFileName;
                
                // Upload to MinIO
                $contentType = MinioS3Client::getMimeType($fileName);
                $uploadResult = $minioClient->uploadFile($tmpName, $objectName, $contentType);
                
                if ($uploadResult['success']) {
                    $image_paths[] = $uploadResult['url'];
                    logDocumentUpload('ordinance', $fileName, $uniqueFileName);
                } else {
                    $_SESSION['error'] = "Failed to upload file: $fileName. " . $uploadResult['error'];
                    header("Location: ordinances.php");
                    exit();
                }
            }
            if (!empty($image_paths)) {
                $image_path = implode('|', $image_paths);
            }
        }
        
        // Set description as title or content preview if not provided
        $description = !empty($content) ? substr($content, 0, 500) : $title;
        
        $stmt = $conn->prepare("UPDATE ordinances SET title = ?, description = ?, ordinance_number = ?, date_posted = ?, ordinance_date = ?, status = ?, content = ?, image_path = ? WHERE id = ?");
        $stmt->bind_param("ssssssssi", $title, $description, $ordinance_number, $date_posted, $ordinance_date, $status, $content, $image_path, $id);
        if ($stmt->execute()) {
            logDocumentUpdate('ordinance', $title, $id, "Ordinance updated: $title");
            $_SESSION['success'] = "Ordinance updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update ordinance: " . $conn->error;
        }
        $stmt->close();
        header("Location: ordinances.php");
        exit();
    }
}

// Handle GET request for fetching ordinance data
if (isset($_GET['action']) && $_GET['action'] === 'get_ordinance' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM ordinances WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $ordinance = $result->fetch_assoc();
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($ordinance);
    exit();
}

// Handle search, pagination, and sort functionality
$search_query = isset($_GET['search_query']) ? trim($_GET['search_query']) : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'ordinance_date_desc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$table_limit = isset($_GET['table_limit']) ? intval($_GET['table_limit']) : 10;
$valid_limits = [5, 10, 25, 50, 100];
if (!in_array($table_limit, $valid_limits)) {
    $table_limit = 10;
}
$offset = ($page - 1) * $table_limit;

// Fetch distinct years from the database for filtering
$years_query = $conn->query("
    SELECT DISTINCT YEAR(ordinance_date) as year FROM ordinances
    ORDER BY year DESC
");
$available_years = $years_query ? $years_query->fetch_all(MYSQLI_ASSOC) : [];

// Initialize parameters and types for search
$params = [];
$types = '';
$where_clauses = [];

// Add search condition if search query is provided
if (!empty($search_query)) {
    $search_like = "%" . $search_query . "%";
    $where_clauses[] = "(title LIKE ? OR reference_number LIKE ? OR ordinance_number LIKE ? OR content LIKE ?)";
    $params = array_merge($params, [$search_like, $search_like, $search_like, $search_like]);
    $types .= 'ssss';
}

// Add year condition if year is provided
if (!empty($year)) {
    $where_clauses[] = "YEAR(ordinance_date) = ?";
    $params[] = $year;
    $types .= 's';
}

// Build the query
$query = "SELECT id, title, description, ordinance_number, date_posted, ordinance_date, status, content, image_path, date_issued, file_path, uploaded_by FROM ordinances";
if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

// Validate and set sort parameter
$valid_sorts = [
    'ordinance_date_desc' => 'ordinance_date DESC',
    'ordinance_date_asc'  => 'ordinance_date ASC',
    'title_asc'            => 'title ASC',
    'title_desc'           => 'title DESC',
    'date_posted_asc'      => 'date_posted ASC',
    'date_posted_desc'     => 'date_posted DESC'
];

// Use validated sort or default
$sort_clause = $valid_sorts[$sort_by] ?? 'ordinance_date DESC';

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
$ordinances = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Fetch total count for pagination
$count_query = "SELECT COUNT(*) as total FROM ordinances";
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
$total_ordinances = $total_row ? $total_row['total'] : 0;
$total_pages = ceil($total_ordinances / $table_limit);
$count_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ordinances Management - eFIND System</title>
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
            min-height: calc(100vh - 130px);
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
        .btn-ocr {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        .btn-ocr:hover {
            background-color: rgba(255, 193, 7, 0.2);
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
        .ocr-view {
            max-height: 400px;
            overflow-y: auto;
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
        .auto-fill-alert {
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 0.9rem;
        }
        #ocrProcessing, #editOcrProcessing {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            border: 1px dashed #dee2e6;
        }
        .reference-number {
            font-weight: 600;
            color: var(--secondary-blue);
            background-color: rgba(67, 97, 238, 0.1);
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
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
        .table-info {
            padding: 10px 20px;
            background-color: var(--light-blue);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            border-bottom: 1px solid var(--light-blue);
            font-weight: 600;
            color: var(--secondary-blue);
        }
        /* Auto-fill detection styles */
        .auto-fill-section {
            border: 2px dashed var(--primary-blue);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            background-color: rgba(67, 97, 238, 0.05);
        }
        .auto-fill-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .auto-fill-icon {
            font-size: 1.2rem;
            color: var(--primary-blue);
            margin-right: 10px;
        }
        .auto-fill-status {
            font-weight: 600;
            color: var(--secondary-blue);
        }
        .auto-fill-details {
            font-size: 0.9rem;
            color: var(--medium-gray);
        }
        .field-highlight {
            background-color: rgba(255, 193, 7, 0.2);
            border: 1px solid rgba(255, 193, 7, 0.5);
            transition: all 0.3s ease;
        }
        .detected-field {
            position: relative;
        }
        .detected-field::after {
            content: "✓ Auto-detected";
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--primary-blue);
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
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
                <h1 class="page-title">Ordinances Management</h1>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addOrdinanceModal">
                        <i class="fas fa-plus me-1"></i> Add Ordinance
                    </button>
                    <button class="btn btn-secondary-custom" id="printButton">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                </div>
            </div>
            <!-- Search Form -->
            <form method="GET" action="ordinances.php" class="mb-9">
                <div class="row">
                    <div class="col-md-8">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search_query" id="searchInput" class="form-control" placeholder="Search documents..." value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
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
                    <div class="col-md-2">
                        <select name="sort_by" id="sort_by" class="form-select" onchange="updateSort()">
                            <option value="ordinance_date_desc" <?php echo $sort_by === 'ordinance_date_desc' ? 'selected' : ''; ?>>Date (Newest)</option>
                            <option value="ordinance_date_asc" <?php echo $sort_by === 'ordinance_date_asc' ? 'selected' : ''; ?>>Date (Oldest)</option>
                            <option value="title_asc" <?php echo $sort_by === 'title_asc' ? 'selected' : ''; ?>>Title (A-Z)</option>
                            <option value="title_desc" <?php echo $sort_by === 'title_desc' ? 'selected' : ''; ?>>Title (Z-A)</option>
                            <option value="date_posted_asc" <?php echo $sort_by === 'date_posted_asc' ? 'selected' : ''; ?>>Date Posted (Oldest)</option>
                            <option value="date_posted_desc" <?php echo $sort_by === 'date_posted_desc' ? 'selected' : ''; ?>>Date Posted (Newest)</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary-custom w-100" style="height: 45px; min-width: 100px;">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                <!-- Hidden input for sort_by -->
                <input type="hidden" name="sort_by" id="hiddenSortBy" value="<?php echo htmlspecialchars($sort_by); ?>">
            </form>
            <!-- Table Info -->
            <div class="table-info d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-file-alt me-2"></i>
                    Showing <?php echo count($ordinances); ?> of <?php echo $total_ordinances; ?> ordinances
                    <?php if (!empty($search_query)): ?>
                        <span class="text-muted ms-2">(Filtered results)</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Ordinances Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle text-center">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <!-- <th>Reference No.</th> -->
                                <th>Title</th>
                                <th>Date Posted</th>
                                <th>Ordinance Date</th>
                                <th>Ordinance Number</th>
                                <th>Content Preview</th>
                                <th>Status</th>
                                <th>Image</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ordinancesTableBody">
                            <?php if (empty($ordinances)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">No ordinances found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ordinances as $ordinance): ?>
                                    <tr data-id="<?php echo $ordinance['id']; ?>">
                                        <td><?php echo htmlspecialchars($ordinance['id']); ?></td>
                                        <!-- <td>
                                            <span class="reference-number">
                                                <?php echo !empty($ordinance['reference_number']) ? htmlspecialchars($ordinance['reference_number']) : 'N/A'; ?>
                                            </span>
                                        </td> -->
                                        <td class="title text-start"><?php echo htmlspecialchars($ordinance['title']); ?></td>
                                        <td class="date-posted" data-date="<?php echo $ordinance['date_posted']; ?>">
                                            <?php echo date('M d, Y', strtotime($ordinance['date_posted'])); ?>
                                        </td>
                                        <td class="ordinance-date" data-date="<?php echo $ordinance['ordinance_date']; ?>">
                                            <?php echo date('M d, Y', strtotime($ordinance['ordinance_date'])); ?>
                                        </td>
                                        <td class="ordinance-number"><?php echo htmlspecialchars($ordinance['ordinance_number']); ?></td>
                                        <td class="content-preview text-start">
                                            <?php
                                            $content = htmlspecialchars($ordinance['content'] ?? 'N/A');
                                            if (strlen($content) > 50): ?>
                                                <span class="truncated-text" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo $content; ?>">
                                                    <?php echo substr($content, 0, 50) . '...'; ?>
                                                </span>
                                            <?php else: ?>
                                                <?php echo $content; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="status">
                                            <span class="badge bg-<?php
                                                switch($ordinance['status']) {
                                                    case 'Active': echo 'success'; break;
                                                    case 'Inactive': echo 'secondary'; break;
                                                    case 'Pending': echo 'warning'; break;
                                                    case 'Approved': echo 'primary'; break;
                                                    case 'Rejected': echo 'danger'; break;
                                                    default: echo 'dark';
                                                }
                                            ?>">
                                                <?php echo htmlspecialchars($ordinance['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($ordinance['image_path'])): ?>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <a href="#" class="btn btn-sm btn-outline-success p-1 image-link" data-image-src="<?php echo htmlspecialchars($ordinance['image_path']); ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="View Image">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-warning p-1 ocr-btn" data-image-src="<?php echo htmlspecialchars($ordinance['image_path']); ?>" data-ordinance-id="<?php echo $ordinance['id']; ?>" data-bs-toggle="modal" data-bs-target="#ocrModal" data-bs-toggle="tooltip" data-bs-placement="top" title="OCR">
                                                        <i class="fas fa-magnifying-glass"></i>
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <button class="btn btn-sm btn-outline-primary p-1 edit-btn" data-id="<?php echo $ordinance['id']; ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?action=delete&id=<?php echo $ordinance['id']; ?>" class="btn btn-sm btn-outline-danger p-1" onclick="return confirm('Are you sure you want to delete this ordinance?');" data-bs-toggle="tooltip" data-bs-placement="top" title="Delete">
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
                        <label for="printStartDate" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="printStartDate">
                    </div>
                    <div class="mb-3">
                        <label for="printEndDate" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="printEndDate">
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
    <!-- Modal for Add New Ordinance -->
    <div class="modal fade" id="addOrdinanceModal" tabindex="-1" aria-labelledby="addOrdinanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Add New Ordinance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Auto-fill Detection Section -->
                    <div id="autoFillSection" class="auto-fill-section" style="display: none;">
                        <div class="auto-fill-header">
                            <i class="fas fa-robot auto-fill-icon"></i>
                            <div>
                                <div class="auto-fill-status">Smart Detection Active</div>
                                <div class="auto-fill-details">Fields will be automatically filled from the uploaded document</div>
                            </div>
                        </div>
                        <div id="autoFillProgress" class="progress mb-2" style="height: 6px;">
                            <div id="autoFillProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div id="autoFillResults" class="row"></div>
                    </div>
                    <form method="POST" action="" enctype="multipart/form-data" id="addOrdinanceForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="ordinance_number" class="form-label">Ordinance Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ordinance_number" name="ordinance_number" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="date_posted" class="form-label">Date Posted <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date_posted" name="date_posted" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="ordinance_date" class="form-label">Ordinance Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="ordinance_date" name="ordinance_date" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Rejected">Rejected</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="content" class="form-label">Content</label>
                            <textarea class="form-control" id="content" name="content" rows="4"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Image File (JPG, PNG, PDF)</label>
                            <div class="file-upload">
                                <input type="file" class="form-control" id="image_file" name="image_file[]" accept=".jpg,.jpeg,.png,.pdf" multiple onchange="processFilesWithAutoFill(this)">
                                <small class="text-muted">Max file size: 5MB per file. You can upload multiple images (e.g., page 1, page 2). The system will automatically detect and fill fields from all documents.</small>
                            </div>
                            <div id="ocrProcessing" class="mt-2" style="display: none;">
                                <div class="d-flex align-items-center">
                                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                    <span>Processing file and detecting content...</span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_ordinance" class="btn btn-primary-custom">Add Ordinance</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal for Edit Ordinance -->
    <div class="modal fade" id="editOrdinanceModal" tabindex="-1" aria-labelledby="editOrdinanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit Ordinance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editOrdinanceForm" method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="ordinance_id" id="editOrdinanceId">
                        <input type="hidden" name="existing_image_path" id="editExistingImagePath">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editTitle" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editTitle" name="title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editOrdinanceNumber" class="form-label">Ordinance Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editOrdinanceNumber" name="ordinance_number" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="editDatePosted" class="form-label">Date Posted <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="editDatePosted" name="date_posted" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="editOrdinanceDate" class="form-label">Ordinance Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="editOrdinanceDate" name="ordinance_date" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="editStatus" class="form-label">Status</label>
                                <select class="form-select" id="editStatus" name="status">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Rejected">Rejected</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editContent" class="form-label">Content</label>
                            <textarea class="form-control" id="editContent" name="content" rows="4"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Image File (JPG, PNG, PDF)</label>
                            <div class="file-upload">
                                <input type="file" class="form-control" id="editImageFile" name="image_file[]" accept=".jpg,.jpeg,.png,.pdf" multiple onchange="processFiles(this, 'edit')">
                                <small class="text-muted">Max file size: 5MB per file. You can upload multiple images (e.g., page 1, page 2).</small>
                            </div>
                            <div id="currentImageInfo" class="current-file"></div>
                            <div id="editOcrProcessing" class="mt-2" style="display: none;">
                                <div class="d-flex align-items-center">
                                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                    <span>Processing file...</span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update_ordinance" class="btn btn-primary-custom">Update Ordinance</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-image me-2"></i> Image Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" class="img-fluid rounded" alt="Image Preview" style="max-height: 70vh;">
                </div>
                <div class="modal-footer">
                    <a id="downloadImage" href="#" class="btn btn-primary-custom" download>
                        <i class="fas fa-download me-2"></i> Download
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
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
                    <input type="hidden" id="ocrOrdinanceId">
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
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jsPDF & html2canvas for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    <!-- Tesseract.js for OCR -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js"></script>
    <script>
        // Function to format OCR text into HTML (moved to global scope)
        function formatOcrText(text) {
            const formattedView = document.getElementById('formattedView');
            const paragraphs = text.split(/\n\s*\n/);
            let htmlContent = '';
            paragraphs.forEach(paragraph => {
                if (paragraph.trim()) {
                    if (paragraph.length < 100 && /[A-Z]/.test(paragraph[0]) &&
                        (paragraph.endsWith(':') || !paragraph.includes('.') || paragraph.split(' ').length < 10)) {
                        htmlContent += `<h5 class="ocr-heading">${paragraph.trim()}</h5>`;
                    } else {
                        htmlContent += `<p class="ocr-paragraph">${paragraph.trim().replace(/\n/g, '<br>')}</p>`;
                    }
                }
            });
            formattedView.innerHTML = htmlContent || '<p class="text-muted">No text content could be extracted.</p>';
        }
        // Function to update sort parameter and submit form
        function updateSort() {
            const sortBySelect = document.getElementById('sort_by');
            const hiddenSortBy = document.getElementById('hiddenSortBy');
            hiddenSortBy.value = sortBySelect.value;
            sortBySelect.closest('form').submit();
        }
        // Ensure pagination stays at bottom and is always visible
        function updatePaginationPosition() {
            const paginationContainer = document.querySelector('.pagination-container');
            const managementContainer = document.querySelector('.management-container');
            if (paginationContainer && managementContainer) {
                const containerRect = managementContainer.getBoundingClientRect();
                const windowHeight = window.innerHeight;
                // If content is shorter than viewport, stick to bottom of container
                if (containerRect.height < windowHeight - 200) {
                    paginationContainer.style.position = 'absolute';
                    paginationContainer.style.bottom = '0';
                    paginationContainer.style.left = '0';
                    paginationContainer.style.right = '0';
                } else {
                    paginationContainer.style.position = 'sticky';
                    paginationContainer.style.bottom = '0';
                }
            }
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
            // Initialize pagination position
            updatePaginationPosition();
            window.addEventListener('resize', updatePaginationPosition);
            // Also update when table content changes
            const observer = new MutationObserver(updatePaginationPosition);
            const tableBody = document.querySelector('#ordinancesTableBody');
            if (tableBody) {
                observer.observe(tableBody, { childList: true, subtree: true });
            }
            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert-message');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
            // Handle image link clicks
            document.querySelectorAll('.image-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const imageSrc = this.getAttribute('data-image-src');
                    const modalImage = document.getElementById('modalImage');
                    const downloadLink = document.getElementById('downloadImage');
                    modalImage.src = imageSrc;
                    
                    // Set up download button to force download instead of opening
                    downloadLink.replaceWith(downloadLink.cloneNode(true));
                    const newDownloadLink = document.getElementById('downloadImage');
                    
                    newDownloadLink.addEventListener('click', async function(e) {
                        e.preventDefault();
                        
                        try {
                            // Fetch the image as blob
                            const response = await fetch(imageSrc);
                            const blob = await response.blob();
                            
                            // Create download link
                            const url = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.style.display = 'none';
                            a.href = url;
                            a.download = imageSrc.split('/').pop();
                            
                            document.body.appendChild(a);
                            a.click();
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);
                        } catch (error) {
                            console.error('Error downloading image:', error);
                            alert('Failed to download image. Please try again.');
                        }
                    });
                    
                    const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
                    imageModal.show();
                });
            });
            // Edit button functionality
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    fetch(`?action=get_ordinance&id=${id}`)
                        .then(response => response.json())
                        .then(ordinance => {
                            document.getElementById('editOrdinanceId').value = ordinance.id;
                            document.getElementById('editTitle').value = ordinance.title;
                            document.getElementById('editDatePosted').value = ordinance.date_posted;
                            document.getElementById('editOrdinanceDate').value = ordinance.ordinance_date;
                            document.getElementById('editOrdinanceNumber').value = ordinance.ordinance_number;
                            document.getElementById('editStatus').value = ordinance.status;
                            document.getElementById('editContent').value = ordinance.content || '';
                            document.getElementById('editExistingImagePath').value = ordinance.image_path || '';
                            const currentFileInfo = document.getElementById('currentImageInfo');
                            if (ordinance.image_path) {
                                currentFileInfo.innerHTML = `
                                    <strong>Current Image:</strong>
                                    <a href="${ordinance.image_path}" target="_blank">View Image</a>
                                `;
                            } else {
                                currentFileInfo.innerHTML = '<strong>No image uploaded</strong>';
                            }
                            const editModal = new bootstrap.Modal(document.getElementById('editOrdinanceModal'));
                            editModal.show();
                        })
                        .catch(error => {
                            console.error('Error fetching ordinance:', error);
                            alert('Error loading ordinance data.');
                        });
                });
            });
            // Print button logic - FIXED
            document.getElementById('printButton').addEventListener('click', function() {
                const printModal = new bootstrap.Modal(document.getElementById('printDateRangeModal'));
                printModal.show();
            });
            document.getElementById('confirmPrint').addEventListener('click', function() {
                const startDate = document.getElementById('printStartDate').value;
                const endDate = document.getElementById('printEndDate').value;
                
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
            // OCR button functionality
            document.querySelectorAll('.ocr-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var imageSrc = btn.getAttribute('data-image-src');
                    var ordinanceId = btn.closest('tr').getAttribute('data-id');
                    var ocrLoading = document.getElementById('ocrLoading');
                    var ocrResult = document.getElementById('ocrResult');
                    var ocrActions = document.getElementById('ocrActions');
                    document.getElementById('ocrOrdinanceId').value = ordinanceId;
                    document.getElementById('ocrImagePath').value = imageSrc;
                    document.getElementById('formattedView').innerHTML = '';
                    document.querySelector('#rawView pre').textContent = '';
                    document.getElementById('ocrTextEditor').value = '';
                    document.getElementById('editView').style.display = 'none';
                    document.getElementById('formattedView').style.display = 'block';
                    ocrLoading.style.display = 'block';
                    ocrResult.style.display = 'none';
                    ocrActions.style.display = 'none';
                    // First try to get existing content from the ordinance table
                    fetch(`?action=get_ordinance&id=${ordinanceId}`)
                        .then(response => response.json())
                        .then(ordinance => {
                            if (ordinance && ordinance.content) {
                                // Use existing content from the ordinance table
                                ocrLoading.style.display = 'none';
                                ocrResult.style.display = 'block';
                                ocrActions.style.display = 'flex';
                                document.getElementById('ocrTextEditor').value = ordinance.content;
                                formatOcrText(ordinance.content);
                                document.querySelector('#rawView pre').textContent = ordinance.content;
                            } else {
                                // If no content in ordinance table, check OCR table
                                fetch(`get_ocr_content.php?id=${ordinanceId}&type=ordinance`)
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success && data.content) {
                                            // Use existing OCR content
                                            ocrLoading.style.display = 'none';
                                            ocrResult.style.display = 'block';
                                            ocrActions.style.display = 'flex';
                                            document.getElementById('ocrTextEditor').value = data.content;
                                            formatOcrText(data.content);
                                            document.querySelector('#rawView pre').textContent = data.content;
                                        } else {
                                            // Process new OCR with Tesseract.js
                                            performOCR(imageSrc, ordinanceId);
                                        }
                                    })
                                    .catch(() => {
                                        // If database check fails, proceed with OCR
                                        performOCR(imageSrc, ordinanceId);
                                    });
                            }
                        })
                        .catch(() => {
                            // If fetching ordinance fails, try OCR table
                            fetch(`get_ocr_content.php?id=${ordinanceId}&type=ordinance`)
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success && data.content) {
                                        // Use existing OCR content
                                        ocrLoading.style.display = 'none';
                                        ocrResult.style.display = 'block';
                                        ocrActions.style.display = 'flex';
                                        document.getElementById('ocrTextEditor').value = data.content;
                                        formatOcrText(data.content);
                                        document.querySelector('#rawView pre').textContent = data.content;
                                    } else {
                                        // Process new OCR with Tesseract.js
                                        performOCR(imageSrc, ordinanceId);
                                    }
                                })
                                .catch(() => {
                                    // If database check fails, proceed with OCR
                                    performOCR(imageSrc, ordinanceId);
                                });
                        });
                });
            });
            // Edit OCR text
            document.getElementById('editOcrText').addEventListener('click', function() {
                const rawText = document.querySelector('#rawView pre').textContent;
                document.getElementById('ocrTextEditor').value = rawText;
                document.getElementById('formattedView').style.display = 'none';
                document.getElementById('rawView').style.display = 'none';
                document.getElementById('editView').style.display = 'block';
            });
            // Cancel editing
            document.getElementById('cancelEdit').addEventListener('click', function() {
                document.getElementById('editView').style.display = 'none';
                document.getElementById('formattedView').style.display = 'block';
            });
            // Save edited OCR text
            document.getElementById('saveOcrText').addEventListener('click', function() {
                const editedText = document.getElementById('ocrTextEditor').value;
                const ordinanceId = document.getElementById('ocrOrdinanceId').value;
                const saveButton = this;
                const originalText = saveButton.innerHTML;
                // Validate input
                if (!ordinanceId || ordinanceId <= 0) {
                    alert('Error: Invalid ordinance ID');
                    return;
                }
                if (!editedText.trim()) {
                    alert('Error: OCR text cannot be empty');
                    return;
                }
                saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                saveButton.disabled = true;
                // Use FormData for proper form submission
                const formData = new FormData();
                formData.append('id', ordinanceId);
                formData.append('document_type', 'ordinance');
                formData.append('content', editedText);
                fetch('update_document_content.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Update the displayed text
                        formatOcrText(editedText);
                        document.querySelector('#rawView pre').textContent = editedText;
                        // Switch back to formatted view
                        document.getElementById('editView').style.display = 'none';
                        document.getElementById('formattedView').style.display = 'block';
                        document.getElementById('rawView').style.display = 'none';
                        // Reset view buttons
                        document.querySelectorAll('[data-view]').forEach(btn => {
                            btn.classList.toggle('active', btn.getAttribute('data-view') === 'formatted');
                        });
                        // Show success message
                        const successAlert = document.createElement('div');
                        successAlert.className = 'alert alert-success alert-dismissible fade show mb-3';
                        successAlert.innerHTML = `
                            <i class="fas fa-check-circle me-2"></i>
                            ${data.message || 'OCR content updated successfully!'}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        // Remove any existing alerts
                        const existingAlerts = document.querySelectorAll('#ocrResult .alert');
                        existingAlerts.forEach(alert => alert.remove());
                        document.querySelector('#ocrResult').prepend(successAlert);
                        // Auto-remove alert after 5 seconds
                        setTimeout(() => {
                            if (successAlert.parentNode) {
                                successAlert.remove();
                            }
                        }, 5000);
                    } else {
                        throw new Error(data.error || 'Unknown error occurred');
                    }
                })
                .catch(error => {
                    console.error('Error updating OCR content:', error);
                    // Show error message
                    const errorAlert = document.createElement('div');
                    errorAlert.className = 'alert alert-danger alert-dismissible fade show mb-3';
                    errorAlert.innerHTML = `
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error updating OCR content: ${error.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    // Remove any existing alerts
                    const existingAlerts = document.querySelectorAll('#ocrResult .alert');
                    existingAlerts.forEach(alert => alert.remove());
                    document.querySelector('#ocrResult').prepend(errorAlert);
                })
                .finally(() => {
                    saveButton.innerHTML = originalText;
                    saveButton.disabled = false;
                });
            });
            // Toggle between formatted and raw views
            document.querySelectorAll('[data-view]').forEach(btn => {
                btn.addEventListener('click', function() {
                    const viewType = this.getAttribute('data-view');
                    document.querySelectorAll('[data-view]').forEach(b => {
                        b.classList.toggle('active', b === this);
                    });
                    document.getElementById('formattedView').style.display =
                        viewType === 'formatted' ? 'block' : 'none';
                    document.getElementById('rawView').style.display =
                        viewType === 'raw' ? 'block' : 'none';
                    document.getElementById('editView').style.display = 'none';
                });
            });
            // Copy to clipboard functionality
            document.getElementById('copyOcrText').addEventListener('click', function() {
                const rawText = document.querySelector('#rawView pre').textContent;
                navigator.clipboard.writeText(rawText).then(() => {
                    const originalHtml = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check"></i>';
                    setTimeout(() => {
                        this.innerHTML = originalHtml;
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy text: ', err);
                    alert('Failed to copy text to clipboard');
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
            // Client-side validation for file type
            document.getElementById('addOrdinanceForm').addEventListener('submit', function(e) {
                const fileInput = document.getElementById('image_file');
                if (fileInput.files.length > 0) {
                    const file = fileInput.files[0];
                    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'];
                    if (!allowedTypes.includes(file.type)) {
                        e.preventDefault();
                        alert('Invalid file type. Only JPG, PNG, GIF, BMP, PDF, or DOCX files are allowed.');
                    }
                }
            });
            // Client-side validation for edit form
            document.getElementById('editOrdinanceForm').addEventListener('submit', function(e) {
                const fileInput = document.getElementById('editImageFile');
                if (fileInput.files.length > 0) {
                    const file = fileInput.files[0];
                    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'];
                    if (!allowedTypes.includes(file.type)) {
                        e.preventDefault();
                        alert('Invalid file type. Only JPG, PNG, GIF, BMP, PDF, or DOCX files are allowed.');
                    }
                }
            });
        });
        // Function to perform actual OCR using Tesseract.js
        function performOCR(imagePath, ordinanceId) {
            const ocrLoading = document.getElementById('ocrLoading');
            const ocrResult = document.getElementById('ocrResult');
            const ocrActions = document.getElementById('ocrActions');
            // Update loading message
            ocrLoading.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <div class="spinner-border text-warning" role="status"></div>
                    <div>
                        <div>Extracting text from image using OCR...</div>
                        <small class="text-muted" id="ocrProgress">Initializing...</small>
                    </div>
                </div>
            `;
            // Use Tesseract.js for OCR
            Tesseract.recognize(
                imagePath,
                'eng', // English language
                {
                    logger: progress => {
                        const progressElement = document.getElementById('ocrProgress');
                        if (progressElement) {
                            if (progress.status === 'recognizing text') {
                                const percent = Math.round(progress.progress * 100);
                                progressElement.textContent = `Processing: ${percent}%`;
                            } else {
                                progressElement.textContent = `Status: ${progress.status}`;
                            }
                        }
                    }
                }
            ).then(({ data: { text, confidence } }) => {
                ocrLoading.style.display = 'none';
                if (text && text.trim().length > 0) {
                    ocrResult.style.display = 'block';
                    ocrActions.style.display = 'flex';
                    // Clean up the extracted text
                    const cleanedText = cleanOcrText(text);
                    document.getElementById('ocrTextEditor').value = cleanedText;
                    formatOcrText(cleanedText);
                    document.querySelector('#rawView pre').textContent = cleanedText;
                    // Show confidence score
                    // const confidenceAlert = document.createElement('div');
                    // confidenceAlert.className = 'alert alert-info alert-dismissible fade show mb-2';
                    // confidenceAlert.innerHTML = `
                    //     <i class="fas fa-info-circle me-2"></i>
                    //     OCR Confidence: ${Math.round(confidence * 100)}% - Review and edit the extracted text as needed.
                    //     <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    // `;
                    // document.querySelector('#ocrResult').prepend(confidenceAlert);
                    // Auto-save the OCR result to database
                    saveOcrToDatabase(ordinanceId, cleanedText, confidence);
                } else {
                    ocrResult.style.display = 'block';
                    document.getElementById('formattedView').innerHTML = `
                        <div class="alert alert-warning">
                            No text could be extracted from this image. The image might be too blurry, low quality, or contain no readable text.
                        </div>
                    `;
                }
            }).catch(error => {
                console.error('OCR Error:', error);
                ocrLoading.style.display = 'none';
                ocrResult.style.display = 'block';
                document.getElementById('formattedView').innerHTML = `
                    <div class="alert alert-danger">
                        OCR processing failed: ${error.message}
                    </div>
                `;
            });
        }
        // Function to clean OCR text
        function cleanOcrText(text) {
            return text
                // Remove excessive line breaks
                .replace(/(\r\n|\r|\n){3,}/g, '\n\n')
                // Fix common OCR errors
                .replace(/[|]/g, 'I')
                .replace(/[0]/g, 'O')
                .replace(/[1]/g, 'I')
                // Remove extra spaces
                .replace(/ +/g, ' ')
                .replace(/\n /g, '\n')
                .replace(/ \n/g, '\n')
                // Trim each line
                .split('\n')
                .map(line => line.trim())
                .join('\n')
                // Final trim
                .trim();
        }
        // Function to auto-save OCR result to database
        function saveOcrToDatabase(ordinanceId, text, confidence) {
            if (!ordinanceId || !text) return;
            const formData = new FormData();
            formData.append('id', ordinanceId);
            formData.append('content', text);
            formData.append('confidence', confidence);
            formData.append('type', 'ordinance');
            fetch('update_document_content.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.warn('Failed to auto-save OCR content:', data.error);
                }
            })
            .catch(error => {
                console.error('Error auto-saving OCR content:', error);
            });
        }
        
        // NEW FUNCTION: Process multiple files with auto-fill feature
        async function processFilesWithAutoFill(input) {
            const files = input.files;
            if (!files || files.length === 0) return;
            const autoFillSection = document.getElementById('autoFillSection');
            const autoFillProgressBar = document.getElementById('autoFillProgressBar');
            const autoFillResults = document.getElementById('autoFillResults');
            const processingElement = document.getElementById('ocrProcessing');
            autoFillSection.style.display = 'block';
            processingElement.style.display = 'block';
            autoFillProgressBar.style.width = '0%';
            autoFillResults.innerHTML = '';
            processingElement.innerHTML = `
                <div class="d-flex align-items-center">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    <span>Uploading and analyzing ${files.length} document(s)...</span>
                </div>
            `;
            try {
                let combinedText = '';
                let processedFiles = 0;
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const fileExtension = file.name.split('.').pop().toLowerCase();
                    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif'];
                    if (imageExtensions.includes(fileExtension)) {
                        processingElement.innerHTML = `
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                <div>
                                    <div>Performing OCR on file ${i + 1} of ${files.length}...</div>
                                    <small class="text-muted" id="fileOcrProgress">Initializing...</small>
                                </div>
                            </div>
                        `;
                        const { data: { text } } = await Tesseract.recognize(
                            URL.createObjectURL(file),
                            'eng',
                            {
                                logger: progress => {
                                    const progressElement = document.getElementById('fileOcrProgress');
                                    if (progressElement) {
                                        if (progress.status === 'recognizing text') {
                                            const percent = Math.round(progress.progress * 100);
                                            progressElement.textContent = `Processing: ${percent}%`;
                                            autoFillProgressBar.style.width = `${50 + (percent * 0.4)}%`;
                                        } else {
                                            progressElement.textContent = `Status: ${progress.status}`;
                                        }
                                    }
                                }
                            }
                        );
                        if (text && text.trim().length > 0) {
                            combinedText += cleanOcrText(text) + '\n\n---\n\n';
                        }
                    } else if (fileExtension === 'pdf' || fileExtension === 'docx' || fileExtension === 'doc') {
                        // Use server-side extraction for PDF and DOCX files
                        processingElement.innerHTML = `
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                <div>
                                    <div>Extracting text from ${fileExtension.toUpperCase()} file ${i + 1} of ${files.length}...</div>
                                    <small class="text-muted">Using server-side extraction</small>
                                </div>
                            </div>
                        `;
                        
                        try {
                            // Upload file and extract text using server-side PHP
                            const formData = new FormData();
                            formData.append('file', file);
                            formData.append('extract_text', '1');
                            formData.append('use_ocr', '1');
                            formData.append('force_upload', '1');
                            
                            const response = await fetch('../upload_handler.php?action=upload', {
                                method: 'POST',
                                body: formData
                            });
                            
                            const result = await response.json();
                            
                            if (result.success && result.extraction && result.extraction.success) {
                                const extractedText = result.extraction.text;
                                if (extractedText && extractedText.trim().length > 0) {
                                    combinedText += cleanOcrText(extractedText) + '\n\n---\n\n';
                                    processingElement.innerHTML = `
                                        <div class="alert alert-success">
                                            <i class="fas fa-check-circle me-2"></i>
                                            Successfully extracted ${result.extraction.word_count} words from ${file.name}
                                        </div>
                                    `;
                                } else {
                                    processingElement.innerHTML = `
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No text found in ${file.name}. File might be empty or encrypted.
                                        </div>
                                    `;
                                }
                            } else {
                                const errorMsg = result.extraction ? result.extraction.message : result.message;
                                processingElement.innerHTML = `
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Could not extract text from ${file.name}: ${errorMsg}
                                        <br><small>Please fill the form manually.</small>
                                    </div>
                                `;
                            }
                        } catch (error) {
                            console.error('Server extraction error:', error);
                            processingElement.innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    Error extracting text from ${file.name}: ${error.message}
                                </div>
                            `;
                        }
                    } else {
                        processingElement.innerHTML = `
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                File uploaded: ${file.name}. Please fill the form manually.
                            </div>
                        `;
                    }
                    processedFiles++;
                    autoFillProgressBar.style.width = `${(processedFiles / files.length) * 100}%`;
                }
                if (combinedText.trim().length > 0) {
                    const detectedFields = analyzeDocumentContent(combinedText);
                    updateFormWithDetectedData(detectedFields);
                    showAutoFillResults(detectedFields);
                } else {
                    processingElement.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No text could be extracted from the files. Please fill the form manually.
                        </div>
                    `;
                }
                autoFillProgressBar.style.width = '100%';
            } catch (error) {
                console.error('Error:', error);
                processingElement.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error processing files: ${error.message}
                    </div>
                `;
                autoFillProgressBar.style.width = '100%';
            }
        }
        
        // NEW FUNCTION: Process file with auto-fill feature
        async function processFileWithAutoFill(input) {
            const file = input.files[0];
            if (!file) return;
            // Show auto-fill section
            const autoFillSection = document.getElementById('autoFillSection');
            const autoFillProgressBar = document.getElementById('autoFillProgressBar');
            const autoFillResults = document.getElementById('autoFillResults');
            const processingElement = document.getElementById('ocrProcessing');
            autoFillSection.style.display = 'block';
            processingElement.style.display = 'block';
            autoFillProgressBar.style.width = '0%';
            autoFillResults.innerHTML = '';
            processingElement.innerHTML = `
                <div class="d-flex align-items-center">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    <span>Uploading and analyzing document...</span>
                </div>
            `;
            try {
                // Update progress
                autoFillProgressBar.style.width = '25%';
                // Process with OCR if it's an image
                const fileExtension = file.name.split('.').pop().toLowerCase();
                const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif'];
                if (imageExtensions.includes(fileExtension)) {
                    processingElement.innerHTML = `
                        <div class="d-flex align-items-center">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            <div>
                                <div>Performing OCR and analyzing document structure...</div>
                                <small class="text-muted" id="fileOcrProgress">Initializing...</small>
                            </div>
                        </div>
                    `;
                    // Update progress
                    autoFillProgressBar.style.width = '50%';
                    // Use Tesseract.js for OCR on the uploaded file
                    const { data: { text, confidence } } = await Tesseract.recognize(
                        URL.createObjectURL(file),
                        'eng',
                        {
                            logger: progress => {
                                const progressElement = document.getElementById('fileOcrProgress');
                                if (progressElement) {
                                    if (progress.status === 'recognizing text') {
                                        const percent = Math.round(progress.progress * 100);
                                        progressElement.textContent = `Processing: ${percent}%`;
                                        // Update overall progress (50% to 90% during OCR)
                                        autoFillProgressBar.style.width = `${50 + (percent * 0.4)}%`;
                                    } else {
                                        progressElement.textContent = `Status: ${progress.status}`;
                                    }
                                }
                            }
                        }
                    );
                    // Update progress
                    autoFillProgressBar.style.width = '90%';
                    if (text && text.trim().length > 0) {
                        const cleanedText = cleanOcrText(text);
                        // Analyze and auto-fill form fields
                        const detectedFields = analyzeDocumentContent(cleanedText);
                        // Update form fields with detected data
                        updateFormWithDetectedData(detectedFields);
                        // Show detection results
                        showAutoFillResults(detectedFields, confidence);
                        // Update progress to complete
                        autoFillProgressBar.style.width = '100%';
                        processingElement.style.display = 'none';
                    } else {
                        processingElement.innerHTML = `
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No text could be extracted from the image. Please fill the form manually.
                            </div>
                        `;
                        autoFillProgressBar.style.width = '100%';
                    }
                } else if (fileExtension === 'pdf' || fileExtension === 'docx' || fileExtension === 'doc') {
                    // Use server-side extraction for PDF and DOCX files
                    processingElement.innerHTML = `
                        <div class="d-flex align-items-center">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            <div>
                                <div>Extracting text from ${fileExtension.toUpperCase()} file...</div>
                                <small class="text-muted">Using server-side extraction</small>
                            </div>
                        </div>
                    `;
                    autoFillProgressBar.style.width = '50%';
                    
                    try {
                        // Upload file and extract text using server-side PHP
                        const formData = new FormData();
                        formData.append('file', file);
                        formData.append('extract_text', '1');
                        formData.append('use_ocr', '1');
                        formData.append('force_upload', '1');
                        
                        const response = await fetch('../upload_handler.php?action=upload', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        autoFillProgressBar.style.width = '90%';
                        
                        if (result.success && result.extraction && result.extraction.success) {
                            const extractedText = result.extraction.text;
                            if (extractedText && extractedText.trim().length > 0) {
                                const cleanedText = cleanOcrText(extractedText);
                                // Analyze and auto-fill form fields
                                const detectedFields = analyzeDocumentContent(cleanedText);
                                // Update form fields with detected data
                                updateFormWithDetectedData(detectedFields);
                                // Show detection results
                                showAutoFillResults(detectedFields, 100);
                                // Update progress to complete
                                autoFillProgressBar.style.width = '100%';
                                processingElement.style.display = 'none';
                            } else {
                                processingElement.innerHTML = `
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        No text found in ${file.name}. File might be empty or encrypted.
                                    </div>
                                `;
                                autoFillProgressBar.style.width = '100%';
                            }
                        } else {
                            const errorMsg = result.extraction ? result.extraction.message : result.message;
                            processingElement.innerHTML = `
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Could not extract text from ${file.name}: ${errorMsg}
                                    <br><small>Please fill the form manually.</small>
                                </div>
                            `;
                            autoFillProgressBar.style.width = '100%';
                        }
                    } catch (error) {
                        console.error('Server extraction error:', error);
                        processingElement.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Error extracting text from ${file.name}: ${error.message}
                            </div>
                        `;
                        autoFillProgressBar.style.width = '100%';
                    }
                } else {
                    processingElement.innerHTML = `
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            File uploaded successfully. Please fill the form manually.
                        </div>
                    `;
                    autoFillProgressBar.style.width = '100%';
                }
            } catch (error) {
                console.error('Error:', error);
                processingElement.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error processing file: ${error.message}
                    </div>
                `;
            }
        }
        // NEW FUNCTION: Analyze document content and extract structured data
        function analyzeDocumentContent(text) {
            const detectedFields = {
                title: '',
                ordinanceNumber: '',
                dates: [],
                content: text
            };
            // Extract title (usually the first meaningful line)
            const lines = text.split('\n').filter(line => line.trim().length > 10);
            if (lines.length > 0) {
                detectedFields.title = lines[0].substring(0, 200);
            }
            // Extract ordinance number using various patterns
            const ordinancePatterns = [
                /Ordinance\s+No\.?\s*([A-Z0-9\-]+)/i,
                /Ordinance\s+([A-Z0-9\-]+)/i,
                /Ordinance\s+Number\s+([A-Z0-9\-]+)/i,
                /ORDINANCE\s+NO\.?\s*([A-Z0-9\-]+)/i,
                /Resolution\s+No\.?\s*([A-Z0-9\-]+)/i,
                /RESOLUTION\s+NO\.?\s*([A-Z0-9\-]+)/i
            ];
            for (const pattern of ordinancePatterns) {
                const match = text.match(pattern);
                if (match && match[1]) {
                    detectedFields.ordinanceNumber = match[1];
                    break;
                }
            }
            // Extract dates using various patterns
            const datePatterns = [
                /\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/g,
                /\b(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})\b/g,
                /\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}\b/gi,
                /\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},?\s+\d{4}\b/gi
            ];
            datePatterns.forEach(pattern => {
                let match;
                while ((match = pattern.exec(text)) !== null) {
                    detectedFields.dates.push(match[0]);
                }
            });
            // Remove duplicate dates
            detectedFields.dates = [...new Set(detectedFields.dates)];
            return detectedFields;
        }
        // NEW FUNCTION: Update form fields with detected data
        function updateFormWithDetectedData(detectedFields) {
            // Update title
            if (detectedFields.title && !document.getElementById('title').value) {
                document.getElementById('title').value = detectedFields.title;
                document.getElementById('title').classList.add('field-highlight', 'detected-field');
            }
            // Update ordinance number
            if (detectedFields.ordinanceNumber && !document.getElementById('ordinance_number').value) {
                document.getElementById('ordinance_number').value = detectedFields.ordinanceNumber;
                document.getElementById('ordinance_number').classList.add('field-highlight', 'detected-field');
            }
            // Update dates
            if (detectedFields.dates.length > 0) {
                const today = new Date().toISOString().split('T')[0];
                // Use first date for ordinance date if not set
                if (!document.getElementById('ordinance_date').value) {
                    const ordinanceDate = parseDate(detectedFields.dates[0]);
                    if (ordinanceDate) {
                        document.getElementById('ordinance_date').value = ordinanceDate;
                        document.getElementById('ordinance_date').classList.add('field-highlight', 'detected-field');
                    }
                }
                // Use second date for date posted if not set, or use first date
                if (!document.getElementById('date_posted').value || document.getElementById('date_posted').value === today) {
                    const datePosted = detectedFields.dates.length > 1 ?
                        parseDate(detectedFields.dates[1]) : parseDate(detectedFields.dates[0]);
                    if (datePosted) {
                        document.getElementById('date_posted').value = datePosted;
                        document.getElementById('date_posted').classList.add('field-highlight', 'detected-field');
                    }
                }
            }
            // Update content
            if (detectedFields.content && !document.getElementById('content').value) {
                document.getElementById('content').value = detectedFields.content;
                document.getElementById('content').classList.add('field-highlight', 'detected-field');
            }
        }
        // NEW FUNCTION: Show auto-fill results in the UI
        function showAutoFillResults(detectedFields, confidence) {
            const autoFillResults = document.getElementById('autoFillResults');
            let resultsHTML = '';
            resultsHTML += `
            `;
            if (detectedFields.title) {
                resultsHTML += `
                    <div class="col-md-6 mb-2">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            <small>Title detected</small>
                        </div>
                    </div>
                `;
            }
            if (detectedFields.ordinanceNumber) {
                resultsHTML += `
                    <div class="col-md-6 mb-2">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            <small>Ordinance number detected</small>
                        </div>
                    </div>
                `;
            }
            if (detectedFields.dates.length > 0) {
                resultsHTML += `
                    <div class="col-md-6 mb-2">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            <small>${detectedFields.dates.length} date(s) found</small>
                        </div>
                    </div>
                `;
            }
            if (detectedFields.content) {
                const wordCount = detectedFields.content.split(/\s+/).length;
                resultsHTML += `
                    <div class="col-md-6 mb-2">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check text-success me-2"></i>
                            <small>${wordCount} words extracted</small>
                        </div>
                    </div>
                `;
            }
            autoFillResults.innerHTML = resultsHTML;
            // Remove highlights after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.field-highlight').forEach(field => {
                    field.classList.remove('field-highlight');
                });
            }, 5000);
        }
        // NEW FUNCTION: Parse various date formats to YYYY-MM-DD
        function parseDate(dateString) {
            try {
                // Try direct parsing first
                const date = new Date(dateString);
                if (!isNaN(date.getTime())) {
                    return date.toISOString().split('T')[0];
                }
                // Handle common date formats
                const formats = [
                    /(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/, // MM/DD/YYYY or MM-DD-YYYY
                    /(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/, // YYYY/MM/DD or YYYY-MM-DD
                    /(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2}),?\s+(\d{4})/i,
                    /(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2}),?\s+(\d{4})/i
                ];
                for (const format of formats) {
                    const match = dateString.match(format);
                    if (match) {
                        let year, month, day;
                        if (match[1].length === 4) {
                            // YYYY-MM-DD format
                            year = match[1];
                            month = match[2].padStart(2, '0');
                            day = match[3].padStart(2, '0');
                        } else if (isNaN(match[1])) {
                            // Month name format
                            const monthNames = {
                                'january': '01', 'february': '02', 'march': '03', 'april': '04',
                                'may': '05', 'june': '06', 'july': '07', 'august': '08',
                                'september': '09', 'october': '10', 'november': '11', 'december': '12',
                                'jan': '01', 'feb': '02', 'mar': '03', 'apr': '04', 'may': '05', 'jun': '06',
                                'jul': '07', 'aug': '08', 'sep': '09', 'oct': '10', 'nov': '11', 'dec': '12'
                            };
                            const monthName = match[1].toLowerCase();
                            if (monthNames[monthName]) {
                                year = match[3];
                                month = monthNames[monthName];
                                day = match[2].padStart(2, '0');
                            }
                        } else {
                            // MM/DD/YYYY format
                            year = match[3];
                            month = match[1].padStart(2, '0');
                            day = match[2].padStart(2, '0');
                        }
                        if (year && month && day) {
                            return `${year}-${month}-${day}`;
                        }
                    }
                }
            } catch (error) {
                console.error('Error parsing date:', error);
            }
            return null;
        }
        // Function to process multiple uploaded files with OCR (for edit form)
        async function processFiles(input, formType = 'edit') {
            const files = input.files;
            if (!files || files.length === 0) return;
            const processingId = formType === 'add' ? 'ocrProcessing' : 'editOcrProcessing';
            const processingElement = document.getElementById(processingId);
            processingElement.style.display = 'block';
            processingElement.innerHTML = `
                <div class="d-flex align-items-center">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    <span>Uploading and processing ${files.length} file(s)...</span>
                </div>
            `;
            try {
                let combinedText = '';
                let processedFiles = 0;
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const fileExtension = file.name.split('.').pop().toLowerCase();
                    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif'];
                    if (imageExtensions.includes(fileExtension)) {
                        processingElement.innerHTML = `
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                <div>
                                    <div>Performing OCR on file ${i + 1} of ${files.length}...</div>
                                    <small class="text-muted" id="fileOcrProgress">Initializing...</small>
                                </div>
                            </div>
                        `;
                        const { data: { text } } = await Tesseract.recognize(
                            URL.createObjectURL(file),
                            'eng',
                            {
                                logger: progress => {
                                    const progressElement = document.getElementById('fileOcrProgress');
                                    if (progressElement) {
                                        if (progress.status === 'recognizing text') {
                                            const percent = Math.round(progress.progress * 100);
                                            progressElement.textContent = `Processing: ${percent}%`;
                                        } else {
                                            progressElement.textContent = `Status: ${progress.status}`;
                                        }
                                    }
                                }
                            }
                        );
                        if (text && text.trim().length > 0) {
                            combinedText += cleanOcrText(text) + '\n\n---\n\n';
                        }
                    } else if (fileExtension === 'pdf' || fileExtension === 'docx' || fileExtension === 'doc') {
                        // Use server-side extraction for PDF and DOCX files
                        processingElement.innerHTML = `
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                <div>
                                    <div>Extracting text from ${fileExtension.toUpperCase()} file ${i + 1} of ${files.length}...</div>
                                    <small class="text-muted">Using server-side extraction</small>
                                </div>
                            </div>
                        `;
                        
                        try {
                            // Upload file and extract text using server-side PHP
                            const formData = new FormData();
                            formData.append('file', file);
                            formData.append('extract_text', '1');
                            formData.append('use_ocr', '1');
                            formData.append('force_upload', '1');
                            
                            const response = await fetch('../upload_handler.php?action=upload', {
                                method: 'POST',
                                body: formData
                            });
                            
                            const result = await response.json();
                            
                            if (result.success && result.extraction && result.extraction.success) {
                                const extractedText = result.extraction.text;
                                if (extractedText && extractedText.trim().length > 0) {
                                    combinedText += cleanOcrText(extractedText) + '\n\n---\n\n';
                                    processingElement.innerHTML = `
                                        <div class="alert alert-success">
                                            <i class="fas fa-check-circle me-2"></i>
                                            Successfully extracted ${result.extraction.word_count} words from ${file.name}
                                        </div>
                                    `;
                                } else {
                                    processingElement.innerHTML = `
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No text found in ${file.name}. File might be empty or encrypted.
                                        </div>
                                    `;
                                }
                            } else {
                                const errorMsg = result.extraction ? result.extraction.message : result.message;
                                processingElement.innerHTML = `
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Could not extract text from ${file.name}: ${errorMsg}
                                        <br><small>Please fill the form manually.</small>
                                    </div>
                                `;
                            }
                        } catch (error) {
                            console.error('Server extraction error:', error);
                            processingElement.innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    Error extracting text from ${file.name}: ${error.message}
                                </div>
                            `;
                        }
                    } else {
                        processingElement.innerHTML = `
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                File uploaded: ${file.name}. Please fill the form manually.
                            </div>
                        `;
                    }
                    processedFiles++;
                }
                if (combinedText.trim().length > 0) {
                    autoFillFormFields(combinedText, formType);
                    processingElement.innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            OCR completed successfully!
                            <br><small>Text has been extracted and form fields auto-filled.</small>
                        </div>
                    `;
                } else {
                    processingElement.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No text could be extracted from the files. Please fill the form manually.
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error:', error);
                processingElement.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error processing files: ${error.message}
                    </div>
                `;
            }
        }
        // Function to auto-fill form fields based on extracted text (original function)
        function autoFillFormFields(text, formType = 'add') {
            if (!text) return;
            const prefix = formType === 'add' ? '' : 'edit';
            const titleField = document.getElementById(prefix + 'Title');
            const ordinanceNumberField = document.getElementById(prefix + 'OrdinanceNumber');
            const datePostedField = document.getElementById(prefix + 'DatePosted');
            const ordinanceDateField = document.getElementById(prefix + 'OrdinanceDate');
            const contentField = document.getElementById(prefix + 'Content');
            if (titleField) {
                const lines = text.split('\n').filter(line => line.trim().length > 0);
                if (lines.length > 0) {
                    titleField.value = lines[0].substring(0, 200);
                }
            }
            if (contentField) {
                contentField.value = text;
            }
            const datePatterns = [
                /\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/g,
                /\b(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})\b/g,
                /\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}\b/gi
            ];
            const foundDates = [];
            datePatterns.forEach(pattern => {
                let match;
                while ((match = pattern.exec(text)) !== null) {
                    foundDates.push(match[0]);
                }
            });
            if (foundDates.length >= 2) {
                if (datePostedField) datePostedField.value = foundDates[0];
                if (ordinanceDateField) ordinanceDateField.value = foundDates[1];
            } else if (foundDates.length === 1) {
                if (datePostedField) datePostedField.value = foundDates[0];
                if (ordinanceDateField) ordinanceDateField.value = foundDates[0];
            }
            const ordinancePatterns = [
                /Ordinance\s+No\.?\s*([A-Z0-9\-]+)/i,
                /Ordinance\s+([A-Z0-9\-]+)/i,
                /Ordinance\s+Number\s+([A-Z0-9\-]+)/i
            ];
            for (const pattern of ordinancePatterns) {
                const match = text.match(pattern);
                if (match && match[1] && ordinanceNumberField) {
                    ordinanceNumberField.value = match[1];
                    break;
                }
            }
        }
    </script>
    <footer class="bg-white p-3">
        <?php include(__DIR__ . '/includes/footer.php'); ?>
    </footer>
    
    <!-- AI Chatbot Widget -->
    <?php include(__DIR__ . '/includes/chatbot_widget.php'); ?>
</body>
</html>
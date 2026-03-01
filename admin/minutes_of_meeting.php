<?php
// Enable detailed error logging for debugging
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Include configuration files
try {
    include(__DIR__ . '/includes/auth.php');
    include(__DIR__ . '/includes/config.php');
    include(__DIR__ . '/includes/logger.php');
    include(__DIR__ . '/includes/minio_helper.php');
    include(__DIR__ . '/includes/image_hash_helper.php');
    include(__DIR__ . '/includes/text_duplicate_helper.php');
} catch (Exception $e) {
    error_log("Failed to include required files: " . $e->getMessage());
    die("System initialization error. Please contact the administrator.");
}

// Check if user is logged in - redirect to login if not
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Function to check if a file already exists in the uploads directory
function isFileDuplicate($uploadDir, $fileName) {
    $targetPath = $uploadDir . basename($fileName);
    return file_exists($targetPath);
}

// Function to validate if the file is a minutes document (image only)
function isValidMinutesDocument($file) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp'];
    $mimeType = !empty($file['tmp_name']) && is_readable($file['tmp_name'])
        ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name'])
        : $file['type'];
    return in_array($mimeType, $allowedTypes);
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

if (!ensureDocumentImageHashTable($conn)) {
    error_log("Failed to ensure document_image_hashes table: " . $conn->error);
}

// Check if document_downloads table exists, create if not
$checkDownloadsTableQuery = "SHOW TABLES LIKE 'document_downloads'";
$downloadsTableResult = $conn->query($checkDownloadsTableQuery);
if ($downloadsTableResult->num_rows == 0) {
    $createDownloadsTableQuery = "CREATE TABLE document_downloads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        document_title VARCHAR(255) NOT NULL,
        document_type VARCHAR(50) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        ip_address VARCHAR(45),
        downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id)
    )";
    if (!$conn->query($createDownloadsTableQuery)) {
        error_log("Failed to create document_downloads table: " . $conn->error);
    }
}

// Function to generate reference number for minutes
function generateReferenceNumber($conn, $meeting_date = null) {
    if ($meeting_date) {
        $year = date('Y', strtotime($meeting_date));
        $month = date('m', strtotime($meeting_date));
    } else {
        $year = date('Y');
        $month = date('m');
    }
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM minutes_of_meeting WHERE YEAR(meeting_date) = ? AND MONTH(meeting_date) = ?");
    $stmt->bind_param("ii", $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'] + 1;
    $stmt->close();
    return sprintf("MOM%04d%02d%04d", $year, $month, $count);
}

// Handle download action
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM minutes_of_meeting WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $minute = $result->fetch_assoc();
    $stmt->close();

    // Determine file path from common fields
    $possible = ['file_path','pdf_path','image_path','document_path','attachment'];
    $filePath = '';
    foreach ($possible as $p) {
        if (!empty($minute[$p])) { $filePath = $minute[$p]; break; }
    }

    // Resolve absolute path if stored relative to project
    if ($filePath && !preg_match('#^(?:/|[A-Za-z]:\\\\)#', $filePath)) {
        $absPath = __DIR__ . '/uploads/minutes/' . ltrim($filePath, '/\\');
    } else {
        $absPath = $filePath;
    }

    // Robust session fallback for user info
    $userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? ($_SESSION['user']['id'] ?? null);
    $userName = $_SESSION['username'] ?? $_SESSION['user']['username'] ?? ($_SESSION['user']['name'] ?? 'System');
    $userRole = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? 'user';

    $documentTitle = $minute['title'] ?? 'Minutes of Meeting';
    $details = 'Meeting Date: ' . ($minute['meeting_date'] ?? 'N/A') . (isset($minute['session_number']) ? ' | Session: '.$minute['session_number'] : '');

    // Insert into activity_logs
    $action = 'download';
    $description = ($userRole === 'admin' ? 'Admin' : 'User') . " successfully downloaded: " . $documentTitle;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    $docType = 'minutes';
    $docId = $id;

    $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_name, user_role, action, description, document_type, document_id, details, file_path, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $logStmt->bind_param("isssssisss", $userId, $userName, $userRole, $action, $description, $docType, $docId, $details, $filePath, $ip);
    $logStmt->execute();
    $logStmt->close();

    // Serve file if exists, otherwise redirect back with error
    if (!empty($absPath) && file_exists($absPath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($absPath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($absPath));
        readfile($absPath);
        exit();
    } else {
        $_SESSION['error'] = "File not found.";
        header("Location: minutes_of_meeting.php");
        exit();
    }
}

// Handle print action
if (isset($_GET['print']) && $_GET['print'] === '1') {
    $printStartDate = $_GET['print_start_date'] ?? '';
    $printEndDate = $_GET['print_end_date'] ?? '';

    if (!empty($printStartDate)) {
        $startDateObj = DateTime::createFromFormat('Y-m-d', $printStartDate);
        if (!$startDateObj || $startDateObj->format('Y-m-d') !== $printStartDate) {
            die("Invalid start date format. Please use YYYY-MM-DD.");
        }
    }

    if (!empty($printEndDate)) {
        $endDateObj = DateTime::createFromFormat('Y-m-d', $printEndDate);
        if (!$endDateObj || $endDateObj->format('Y-m-d') !== $printEndDate) {
            die("Invalid end date format. Please use YYYY-MM-DD.");
        }
    }

    if (!empty($printStartDate) && !empty($printEndDate) && strtotime($printStartDate) > strtotime($printEndDate)) {
        die("End date must be after start date.");
    }
    $printQuery = "SELECT id, title, session_number, date_posted, meeting_date, content FROM minutes_of_meeting WHERE 1=1";
    $printConditions = [];
    $printParams = [];
    $printTypes = '';
    if (!empty($printStartDate) && !empty($printEndDate)) {
        $printConditions[] = "DATE(meeting_date) BETWEEN ? AND ?";
        $printParams[] = $printStartDate;
        $printParams[] = $printEndDate;
        $printTypes .= 'ss';
    } elseif (!empty($printStartDate)) {
        $printConditions[] = "DATE(meeting_date) >= ?";
        $printParams[] = $printStartDate;
        $printTypes .= 's';
    } elseif (!empty($printEndDate)) {
        $printConditions[] = "DATE(meeting_date) <= ?";
        $printParams[] = $printEndDate;
        $printTypes .= 's';
    }
    if (!empty($printConditions)) {
        $printQuery .= " AND " . implode(" AND ", $printConditions);
    }
    $printQuery .= " ORDER BY meeting_date DESC";
    $printStmt = $conn->prepare($printQuery);
    if (!empty($printParams)) {
        $printStmt->bind_param($printTypes, ...$printParams);
    }
    $printStmt->execute();
    $printResult = $printStmt->get_result();
    $printMinutes = $printResult->fetch_all(MYSQLI_ASSOC);
    $printStmt->close();
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Minutes of Meeting Report - eFIND System</title>
        <link rel="icon" type="image/png" href="images/eFind_logo.png">
        <style>
            @page { size: A4; margin: 20mm; }
            body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 12px; color: #333; line-height: 1.4; margin: 0; padding: 0; background: white; }
            .container { width: 100%; max-width: 100%; margin: 0 auto; padding: 0; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0056b3; padding-bottom: 10px; }
            .header h1 { color: #0056b3; font-size: 20px; margin: 0; }
            .header p { color: #666; margin: 5px 0 0; font-size: 12px; }
            .date-range { text-align: center; margin-bottom: 20px; font-style: italic; color: #555; }
            .table-container { width: 100%; margin: 0 auto; overflow: visible; }
            table { width: 100%; border-collapse: collapse; margin: 0 auto 20px; font-size: 10px; table-layout: fixed; word-wrap: break-word; }
            th, td { border: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; word-break: break-word; }
            th { background-color: #0056b3; color: white; font-weight: bold; font-size: 10px; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 10px; color: #666; }
            .logo { text-align: center; margin-bottom: 10px; }
            .logo img { max-height: 60px; }
            .badge { padding: 3px 8px; border-radius: 4px; font-size: 9px; font-weight: 600; }
            .badge-success { background-color: #d4edda; color: #155724; }
            .badge-secondary { background-color: #e2e3e5; color: #383d41; }
            .badge-warning { background-color: #fff3cd; color: #856404; }
            .badge-primary { background-color: #cce7ff; color: #004085; }
            .badge-danger { background-color: #f8d7da; color: #721c24; }
            .reference-number { font-weight: 600; color: #0056b3; background-color: rgba(0, 86, 179, 0.1); padding: 2px 6px; border-radius: 3px; font-size: 9px; }
            .content-preview { max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .col-id { width: 5%; }
            .col-ref { width: 12%; }
            .col-title { width: 20%; }
            .col-date-posted { width: 10%; }
            .col-meeting-date { width: 10%; }
            .col-number { width: 12%; }
            .col-content { width: 20%; }
            .col-image { width: 10%; }
            .col-actions { width: 11%; }
            @media print {
                body { margin: 0; padding: 0; }
                .container { width: 100%; padding: 0; margin: 0; }
                table { page-break-inside: auto; width: 100% !important; }
                tr { page-break-inside: avoid; page-break-after: auto; }
                thead { display: table-header-group; }
                tfoot { display: table-footer-group; }
                .header { margin-top: 0; }
                .no-print { display: none !important; }
            }
            @media all { .page-break { display: none; } }
            @media print { .page-break { display: block; page-break-before: always; } }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="logo">
                    <img src="images/logo_pbsth.png" alt="Company Logo" style="max-height: 60px;">
                </div>
                <h1>Minutes of Meeting Report</h1>
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
                        <th class="col-meeting-date">Meeting Date</th>
                        <th class="col-number">Session Number</th>
                        <th class="col-content">Content Preview</th>
                        <th class="col-status">Status</th>
                    </tr>
                </thead>
                <tbody>';
    if (empty($printMinutes)) {
        echo '<tr><td colspan="8" style="text-align: center;">No minutes found for the selected criteria.</td></tr>';
    } else {
        $count = 0;
        foreach ($printMinutes as $minute) {
            $count++;
            if ($count % 25 === 0) {
                echo '</tbody></table></div><div class="page-break"></div><div class="table-container"><table><thead><tr>
                    <th class="col-id">ID</th>
                    <!-- <th class="col-ref">Reference No.</th> -->
                    <th class="col-title">Title</th>
                    <th class="col-date-posted">Date Posted</th>
                    <th class="col-meeting-date">Meeting Date</th>
                    <th class="col-number">Session Number</th>
                    <th class="col-content">Content Preview</th>
                    <th class="col-status">Status</th>
                </tr></thead><tbody>';
            }
            echo '<tr>
                <td>' . $count . '</td>
                <!-- <td><span class="reference-number">' . htmlspecialchars($minute['reference_number'] ?? 'N/A') . '</span></td> -->
                <td>' . htmlspecialchars($minute['title']) . '</td>
                <td>' . date('M d, Y', strtotime($minute['date_posted'])) . '</td>
                <td>' . date('M d, Y', strtotime($minute['meeting_date'])) . '</td>
                <td>' . htmlspecialchars($minute['session_number']) . '</td>
                <td class="content-preview" title="' . htmlspecialchars($minute['content'] ?? 'N/A') . '">';
            $content = htmlspecialchars($minute['content'] ?? 'N/A');
            if (strlen($content) > 20) {
                echo substr($content, 0, 20) . '...';
            } else {
                echo $content;
            }
            echo '</td>
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

// Handle delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['delete', 'bulk_delete'], true)) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['error'] = "CSRF token validation failed.";
        header("Location: minutes_of_meeting.php");
        exit();
    }

    $isBulkDelete = $_POST['action'] === 'bulk_delete';
    $ids = [];

    if ($isBulkDelete) {
        $submittedIds = $_POST['ids'] ?? [];
        if (!is_array($submittedIds)) {
            $_SESSION['error'] = "Invalid bulk delete request.";
            header("Location: minutes_of_meeting.php");
            exit();
        }

        $ids = array_filter(array_map('intval', $submittedIds), function ($value) {
            return $value > 0;
        });
    } elseif (isset($_POST['id'])) {
        $id = intval($_POST['id']);
        if ($id > 0) {
            $ids = [$id];
        }
    }

    $ids = array_values(array_unique($ids));

    if (empty($ids)) {
        $_SESSION['error'] = "No minutes selected for deletion.";
        header("Location: minutes_of_meeting.php");
        exit();
    }

    try {
        $conn->begin_transaction();
        $deletedCount = 0;

        foreach ($ids as $id) {
            $stmt = $conn->prepare("SELECT * FROM minutes_of_meeting WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare minute lookup.");
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $minute = $result->fetch_assoc();
            $stmt->close();

            if (!$minute) {
                throw new Exception("Minute not found.");
            }

            if (function_exists('logDocumentDelete')) {
                try {
                    logDocumentDelete('minute', $minute['title'], $id);
                } catch (Exception $e) {
                    error_log("Logger error: " . $e->getMessage());
                }
            }

            if (!archiveToRecycleBin('minutes_of_meeting', $id, $minute)) {
                throw new Exception("Failed to archive minute to recycle bin.");
            }

            $stmt = $conn->prepare("DELETE FROM minutes_of_meeting WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare delete query.");
            }
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new Exception("Failed to delete minute: " . $error);
            }
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new Exception("Minute was not deleted.");
            }
            $stmt->close();
            $deletedCount++;
        }

        $conn->commit();
        $_SESSION['success'] = $deletedCount === 1
            ? "Minute deleted successfully!"
            : $deletedCount . " Minutes deleted successfully!";
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Minute delete failed: " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
    }

    header("Location: minutes_of_meeting.php");
    exit();
}

// Initialize variables
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_minute'])) {
        try {
            error_log("=== ADD MINUTE START ===");
            $title = trim($_POST['title']);
            $session_number = trim($_POST['session_number']);
            $date_posted = $_POST['date_posted'];
            $meeting_date = $_POST['meeting_date'];
            $content = trim($_POST['content']);
            $uploadedImageHashEntries = [];
            $textDuplicateMatches = findMatchingDocumentTextDuplicates($conn, 'minutes', $content);
            if (!empty($textDuplicateMatches)) {
                $_SESSION['error'] = "This file has already been uploaded. Please upload a different file.";
                header("Location: minutes_of_meeting.php");
                exit();
            }
            error_log("Form data received - Title: $title, Session: $session_number, Date: $meeting_date");
            
            $reference_number = generateReferenceNumber($conn, $meeting_date);
            error_log("Reference number generated: $reference_number");
            $image_path = null;

            // Handle multiple file uploads to MinIO
            if (isset($_FILES['image_file']) && is_array($_FILES['image_file']['tmp_name'])) {
                $hasFiles = false;
                // Check if any actual files were uploaded
                foreach ($_FILES['image_file']['tmp_name'] as $key => $tmpName) {
                    if ($_FILES['image_file']['error'][$key] === UPLOAD_ERR_OK && !empty($tmpName)) {
                        $hasFiles = true;
                        break;
                    }
                }
                
                if ($hasFiles) {
                    error_log("Processing file uploads...");
                    $minioClient = new MinioS3Client();
                    $image_paths = [];
                    backfillDocumentImageHashes($conn, 'minutes', 200);
                     
                    foreach ($_FILES['image_file']['tmp_name'] as $key => $tmpName) {
                        if ($_FILES['image_file']['error'][$key] !== UPLOAD_ERR_OK) {
                            error_log("File upload error for file $key: " . $_FILES['image_file']['error'][$key]);
                            continue;
                        }
                        if (empty($tmpName)) {
                            continue; // Skip empty uploads
                        }
                        if (!isValidMinutesDocument(['type' => $_FILES['image_file']['type'][$key], 'tmp_name' => $_FILES['image_file']['tmp_name'][$key]])) {
                            error_log("Invalid file type: " . $_FILES['image_file']['type'][$key]);
                            $_SESSION['error'] = "Invalid file type. Only JPG, PNG, GIF, and BMP files are allowed.";
                            header("Location: minutes_of_meeting.php");
                            exit();
                        }

                        $imageHash = computeAverageImageHashFromFile($tmpName);
                        if ($imageHash !== null && $imageHash !== '') {
                            $duplicateMatches = findMatchingImageHashes($conn, 'minutes', $imageHash);
                            if (!empty($duplicateMatches)) {
                                $_SESSION['error'] = "This image has already been uploaded. Please upload a different file.";
                                header("Location: minutes_of_meeting.php");
                                exit();
                            }
                        }
                         
                        $fileName = basename($_FILES['image_file']['name'][$key]);
                        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                        $uniqueFileName = uniqid() . '_' . time() . '_' . $key . '.' . $fileExt;
                        $objectName = 'minutes/' . date('Y/m/') . $uniqueFileName;
                        error_log("Uploading file: $fileName as $objectName");
                        
                        // Upload to MinIO
                        $contentType = MinioS3Client::getMimeType($fileName);
                        $uploadResult = $minioClient->uploadFile($tmpName, $objectName, $contentType);
                        
                        if ($uploadResult['success']) {
                            $image_paths[] = $uploadResult['url'];
                            $uploadedImageHashEntries[] = [
                                'hash' => $imageHash,
                                'path' => $uploadResult['url'],
                            ];
                            error_log("File uploaded successfully: " . $uploadResult['url']);
                            logDocumentUpload('minute', $fileName, $uniqueFileName);
                        } else {
                            error_log("MinIO upload failed: " . $uploadResult['error']);
                            $_SESSION['error'] = "Failed to upload file: $fileName. " . $uploadResult['error'];
                            header("Location: minutes_of_meeting.php");
                            exit();
                        }
                    }
                    if (!empty($image_paths)) {
                        $image_path = implode('|', $image_paths);
                        error_log("All files uploaded. Combined path: $image_path");
                    }
                } else {
                    error_log("No files uploaded (empty upload)");
                }
            }

            // Get the logged-in user's username
            $uploaded_by = $_SESSION['username'] ?? $_SESSION['staff_username'] ?? 'admin';
            $stmt = $conn->prepare("INSERT INTO minutes_of_meeting (title, session_number, date_posted, meeting_date, content, image_path, reference_number, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                error_log("Failed to prepare statement: " . $conn->error);
                throw new Exception("Database prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ssssssss", $title, $session_number, $date_posted, $meeting_date, $content, $image_path, $reference_number, $uploaded_by);
            if ($stmt->execute()) {
                $new_minute_id = $conn->insert_id;
                if (!empty($uploadedImageHashEntries)) {
                    saveDocumentImageHashes($conn, 'minutes', $new_minute_id, $uploadedImageHashEntries);
                }
                error_log("Minute inserted successfully with ID: $new_minute_id");
                logDocumentAction('create', 'minute', $title, $new_minute_id, "New minute created with reference number: $reference_number");
                $_SESSION['success'] = "Minute added successfully!";
            } else {
                error_log("Failed to execute statement: " . $stmt->error);
                $_SESSION['error'] = "Failed to add minute: " . $conn->error;
            }
            $stmt->close();
            error_log("=== ADD MINUTE END ===");
            header("Location: minutes_of_meeting.php");
            exit();
        } catch (Exception $e) {
            error_log("EXCEPTION in add_minute: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            $_SESSION['error'] = "An error occurred: " . $e->getMessage();
            header("Location: minutes_of_meeting.php");
            exit();
        }
    }

    if (isset($_POST['update_minute'])) {
        $id = intval($_POST['minute_id']);
        $title = trim($_POST['title']);
        $session_number = trim($_POST['session_number']);
        $date_posted = $_POST['date_posted'];
        $meeting_date = $_POST['meeting_date'];
        $content = trim($_POST['content']);
        $existing_image_path = $_POST['existing_image_path'];
        $allowDuplicateImages = isset($_POST['allow_duplicate_images']) && $_POST['allow_duplicate_images'] === '1';
        $uploadedImageHashEntries = [];
        $image_path = $existing_image_path;

        // Handle multiple file uploads for update to MinIO
        if (isset($_FILES['image_file']) && is_array($_FILES['image_file']['tmp_name'])) {
            $minioClient = new MinioS3Client();
            $image_paths = [];
            backfillDocumentImageHashes($conn, 'minutes', 200);
             
            foreach ($_FILES['image_file']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['image_file']['error'][$key] !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (!isValidMinutesDocument(['type' => $_FILES['image_file']['type'][$key], 'tmp_name' => $_FILES['image_file']['tmp_name'][$key]])) {
                    $_SESSION['error'] = "Invalid file type. Only JPG, PNG, GIF, and BMP files are allowed.";
                    header("Location: minutes_of_meeting.php");
                    exit();
                }

                $imageHash = computeAverageImageHashFromFile($tmpName);
                if ($imageHash !== null && $imageHash !== '') {
                    $duplicateMatches = findMatchingImageHashes($conn, 'minutes', $imageHash, $id);
                    if (!$allowDuplicateImages && !empty($duplicateMatches)) {
                        $_SESSION['error'] = "This image is already upploaded. Please remove it or click Proceed anyway.";
                        header("Location: minutes_of_meeting.php");
                        exit();
                    }
                }
                 
                $fileName = basename($_FILES['image_file']['name'][$key]);
                $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                $uniqueFileName = uniqid() . '_' . time() . '_' . $key . '.' . $fileExt;
                $objectName = 'minutes/' . date('Y/m/') . $uniqueFileName;
                
                // Upload to MinIO
                $contentType = MinioS3Client::getMimeType($fileName);
                $uploadResult = $minioClient->uploadFile($tmpName, $objectName, $contentType);
                 
                if ($uploadResult['success']) {
                    $image_paths[] = $uploadResult['url'];
                    $uploadedImageHashEntries[] = [
                        'hash' => $imageHash,
                        'path' => $uploadResult['url'],
                    ];
                    logDocumentUpload('minute', $fileName, $uniqueFileName);
                } else {
                    $_SESSION['error'] = "Failed to upload file: $fileName. " . $uploadResult['error'];
                    header("Location: minutes_of_meeting.php");
                    exit();
                }
            }
            if (!empty($image_paths)) {
                $image_path = implode('|', $image_paths);
            }
        }

        $stmt = $conn->prepare("UPDATE minutes_of_meeting SET title = ?, session_number = ?, date_posted = ?, meeting_date = ?, content = ?, image_path = ? WHERE id = ?");
        $stmt->bind_param("ssssssi", $title, $session_number, $date_posted, $meeting_date, $content, $image_path, $id);
        if ($stmt->execute()) {
            if (!empty($uploadedImageHashEntries)) {
                saveDocumentImageHashes($conn, 'minutes', $id, $uploadedImageHashEntries);
            }
            if (function_exists('logDocumentUpdate')) {
                try {
                    logDocumentUpdate('minute', $title, $id, "Minute updated: $title");
                } catch (Exception $e) {
                    error_log("Logger error: " . $e->getMessage());
                }
            }
            $_SESSION['success'] = "Minute updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update minute: " . $conn->error;
        }
        $stmt->close();
        header("Location: minutes_of_meeting.php");
        exit();
    }
}

// Handle GET request for fetching minute data
if (isset($_GET['action']) && $_GET['action'] === 'get_minute' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM minutes_of_meeting WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $minute = $result->fetch_assoc();
    $stmt->close();
    if ($minute) {
        logDocumentView('minutes', $minute['title'] ?? 'Minutes of Meeting', $id);
    }
    header('Content-Type: application/json');
    echo json_encode($minute);
    exit();
}

// Handle search, pagination, and sort functionality
$search_query = isset($_GET['search_query']) ? trim($_GET['search_query']) : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'meeting_date_desc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$table_limit = isset($_GET['table_limit']) ? intval($_GET['table_limit']) : 5;
$valid_limits = [5, 10, 25, 50, 100];
if (!in_array($table_limit, $valid_limits)) {
    $table_limit = 5;
}
$offset = ($page - 1) * $table_limit;

// Fetch distinct years from the database for filtering
$years_query = $conn->query("
    SELECT DISTINCT YEAR(meeting_date) as year FROM minutes_of_meeting
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
    $searchFields = [
        "CAST(id AS CHAR) LIKE ?",
        "title LIKE ?",
        "COALESCE(reference_number, '') LIKE ?",
        "COALESCE(session_number, '') LIKE ?",
        "COALESCE(date_posted, '') LIKE ?",
        "COALESCE(meeting_date, '') LIKE ?",
        "COALESCE(content, '') LIKE ?",
        "COALESCE(image_path, '') LIKE ?",
        "COALESCE(uploaded_by, '') LIKE ?"
    ];
    $where_clauses[] = "(" . implode(" OR ", $searchFields) . ")";
    $searchParams = array_fill(0, count($searchFields), $search_like);
    $params = array_merge($params, $searchParams);
    $types .= str_repeat('s', count($searchFields));
}

// Add year condition if year is provided
if (!empty($year)) {
    $where_clauses[] = "YEAR(meeting_date) = ?";
    $params[] = $year;
    $types .= 's';
}

// Build the query
$query = "SELECT id, title, session_number, date_posted, meeting_date, content, image_path,
    CASE WHEN EXISTS (
        SELECT 1
        FROM document_image_hashes dih
        WHERE dih.document_type = 'minutes'
          AND dih.document_id = minutes_of_meeting.id
          AND dih.image_hash IN (
              SELECT image_hash
              FROM document_image_hashes
              WHERE document_type = 'minutes'
              GROUP BY image_hash
              HAVING COUNT(DISTINCT document_id) > 1
          )
    ) THEN 1 ELSE 0 END AS has_duplicate_image
    FROM minutes_of_meeting";
if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

// Validate and set sort parameter
$valid_sorts = [
    'meeting_date_desc' => 'meeting_date DESC',
    'meeting_date_asc'  => 'meeting_date ASC',
    'title_asc'         => 'title ASC',
    'title_desc'        => 'title DESC',
    'date_posted_asc'   => 'date_posted ASC',
    'date_posted_desc'  => 'date_posted DESC'
];

// Use validated sort or default
$sort_clause = $valid_sorts[$sort_by] ?? 'meeting_date DESC';

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
$minutes = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Fetch total count for pagination
$count_query = "SELECT COUNT(*) as total FROM minutes_of_meeting";
if (!empty($where_clauses)) {
    $count_query .= " WHERE " . implode(" AND ", $where_clauses);
}
$count_stmt = $conn->prepare($count_query);
if (!empty($params) && !empty($types)) {
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
$total_minutes = $total_row ? $total_row['total'] : 0;
$total_pages = ceil($total_minutes / $table_limit);
$count_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minutes of Meeting Management - eFIND System</title>
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
        .btn-download-custom {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
        }
        .btn-download-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(23, 162, 184, 0.4);
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
        .table tbody tr[data-id] {
            cursor: pointer;
        }
        .table tbody tr.selected-for-delete td {
            background-color: rgba(220, 53, 69, 0.18) !important;
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
        .btn-download {
            background-color: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.3);
        }
        .btn-download:hover {
            background-color: rgba(23, 162, 184, 0.2);
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
            font-size: 0.9rem;
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
        .carousel-control-prev-icon,
        .carousel-control-next-icon {
            background-size: 24px 24px !important;
            width: 24px !important;
            height: 24px !important;
            filter: invert(1) !important;
        }
        .carousel-indicators [data-bs-target] {
            background-color: rgba(17, 16, 16, 0.5) !important;
            width: 10px !important;
            height: 10px !important;
            border-radius: 50% !important;
            margin: 0 4px !important;
        }
        .carousel-indicators .active {
            background-color: #fff !important;
        }
        .alert-container {
            min-width: 400px;
            max-width: 600px;
            animation: slideInDown 0.5s ease-out;
        }
        .alert {
            border: none;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border-left: 4px solid;
        }
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border-left-color: #28a745;
        }
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left-color: #dc3545;
        }
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border-left-color: #ffc107;
        }
        @keyframes slideInDown {
            from {
                transform: translate(-50%, -100%);
                opacity: 0;
            }
            to {
                transform: translate(-50%, 0);
                opacity: 1;
            }
        }
        #deleteConfirmModal .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        #deleteConfirmModal .modal-header {
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .content-preview text-start {
            position: absolute;
            z-index: 10000;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(251, 242, 242, 0.15);
            padding: 15px;
            max-width: 500px;
            max-height: 300px;
            overflow-y: auto;
            display: none;
            font-size: 11px;
            line-height: 1.5;
            left: 50%;
            transform: translateX(-50%);
            top: -10px;
        }
        .content-preview:hover .content-tooltip {
            display: block;
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
                <h1 class="page-title">Minutes of Meeting Management</h1>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addMinuteModal">
                        <i class="fas fa-plus me-1"></i> Add Minute
                    </button>
                    <button class="btn btn-secondary-custom" id="printButton">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                </div>
            </div>
            <!-- Search Form -->
            <form method="GET" action="minutes_of_meeting.php" class="mb-9">
                <div class="row">
                    <div class="col-md-8">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search_query" id="searchInput" class="form-control" placeholder="Search any minutes field (title, session no., reference, content, uploader, etc.)..." value="<?php echo htmlspecialchars($search_query); ?>">
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
                            <option value="meeting_date_desc" <?php echo $sort_by === 'meeting_date_desc' ? 'selected' : ''; ?>>Date (Newest)</option>
                            <option value="meeting_date_asc" <?php echo $sort_by === 'meeting_date_asc' ? 'selected' : ''; ?>>Date (Oldest)</option>
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
                    Showing <?php echo count($minutes); ?> of <?php echo $total_minutes; ?> minutes
                    <?php if (!empty($search_query)): ?>
                        <span class="text-muted ms-2">(Filtered results)</span>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-column align-items-end gap-1">
                    <small class="text-muted">Double-click/double-tap the first row, then single click/tap to add more.</small>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary" id="selectedRowsCount">0 selected</span>
                        <button type="button" class="btn btn-sm btn-danger disabled" id="bulkDeleteBtn" aria-disabled="true" disabled>
                            <i class="fas fa-trash me-1"></i>Delete Selected
                        </button>
                    </div>
                </div>
            </div>
            <!-- Minutes Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle text-center">
                        <thead class="table-dark">
                            <tr>
                                <th style="width:5%">ID</th>
                                <!-- <th>Reference No.</th> -->
                                <th style="width:20%">Title</th>
                                <th style="width:9%">Date Posted</th>
                                <th style="width:9%">Meeting Date</th>
                                <th style="width:12%">Session Number</th>
                                <th style="width:20%">Content Preview</th>
                                <th style="width:8%">Image</th>
                                <th style="width:17%">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="minutesTableBody">
                            <?php if (empty($minutes)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No minutes found</td>
                                </tr>
                            <?php else: ?>
                                <?php $row_num = $offset + 1; ?>
                                <?php foreach ($minutes as $minute): ?>
                                    <tr data-id="<?php echo $minute['id']; ?>"<?php echo !empty($minute['has_duplicate_image']) ? ' style="background-color: #ffd8a8;"' : ''; ?>>
                                        <td><?php echo $row_num++; ?></td>
                                        <!-- <td>
                                            <span class="reference-number">
                                                <?php echo !empty($minute['reference_number']) ? htmlspecialchars($minute['reference_number']) : 'N/A'; ?>
                                            </span>
                                        </td> -->
                                        <td class="title text-start"><?php echo htmlspecialchars($minute['title']); ?></td>
                                        <td class="date-posted" data-date="<?php echo $minute['date_posted']; ?>">
                                            <?php echo date('M d, Y', strtotime($minute['date_posted'])); ?>
                                        </td>
                                        <td class="meeting-date" data-date="<?php echo $minute['meeting_date']; ?>">
                                            <?php echo date('M d, Y', strtotime($minute['meeting_date'])); ?>
                                        </td>
                                        <td class="meeting-number"><?php echo htmlspecialchars($minute['session_number']); ?></td>
                                        <td class="content-preview text-start" title="<?php echo htmlspecialchars($minute['content'] ?? 'N/A'); ?>">
    <?php
    $content = htmlspecialchars($minute['content'] ?? 'N/A');
    if (strlen($content) > 20) {
        echo substr($content, 0, 20) . '...';
    } else {
        echo $content;
    }
    ?>
</td>

                                        <td>
                                            <?php if (!empty($minute['image_path'])): ?>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <a href="#" class="btn btn-sm btn-outline-success p-1 image-link"
                                                       data-image-src="<?php echo htmlspecialchars($minute['image_path']); ?>"
                                                      >
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-warning p-1 ocr-btn"
                                                            data-image-src="<?php echo htmlspecialchars($minute['image_path']); ?>"
                                                            data-minute-id="<?php echo $minute['id']; ?>"
                                                            data-bs-toggle="modal" data-bs-target="#ocrModal"
                                                           >
                                                        <i class="fas fa-magnifying-glass"></i>
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <button class="btn btn-sm btn-outline-primary p-1 edit-btn" data-id="<?php echo $minute['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger p-1 delete-btn"
        data-id="<?php echo $minute['id']; ?>"
        data-title="<?php echo htmlspecialchars($minute['title']); ?>"
       >
    <i class="fas fa-trash"></i>
</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php
                                $filled = count($minutes);
                                for ($i = $filled; $i < $table_limit; $i++): ?>
                                    <tr class="filler-row"><td colspan="8">&nbsp;</td></tr>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Custom Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteConfirmModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-3">
                    <i class="fas fa-trash-alt text-danger" style="font-size: 3rem;"></i>
                </div>
                <h6 class="fw-bold mb-2" id="deleteConfirmMessage">Are you sure you want to delete this minute?</h6>
                <p class="text-muted mb-3" id="deleteItemTitle">Item Title</p>
                <div class="alert alert-warning mb-3">
                    <small><i class="fas fa-info-circle me-1"></i>This action cannot be undone.</small>
                </div>
                <div class="text-start">
                    <label for="deleteConfirmInput" class="form-label text-muted small" id="deleteConfirmLabel">Type <strong class="text-danger">MINUTES</strong> to confirm:</label>
                    <input type="text" class="form-control" id="deleteConfirmInput" placeholder="Type MINUTES" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <form method="POST" action="" class="d-inline">
                    <input type="hidden" name="action" id="deleteActionInput" value="delete">
                    <input type="hidden" name="id" id="deleteItemId">
                    <div id="bulkDeleteIdsContainer"></div>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" class="btn btn-danger disabled" id="confirmDeleteBtn" aria-disabled="true" disabled>
                        <i class="fas fa-trash me-1"></i>Delete Minute
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
            <!-- Sticky Pagination -->
            <?php if ($total_minutes > 5): ?>
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
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
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
<!-- Alert Messages - Top Center Position -->
<?php if (!empty($success)): ?>
    <div class="alert-container position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">
        <div class="alert alert-success alert-dismissible fade show shadow-lg" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle me-2 fs-5"></i>
                <div class="fw-semibold"><?php echo $success; ?></div>
                <button type="button" class="btn-close ms-3" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert-container position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">
        <div class="alert alert-danger alert-dismissible fade show shadow-lg" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-circle me-2 fs-5"></i>
                <div class="fw-semibold"><?php echo $error; ?></div>
                <button type="button" class="btn-close ms-3" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    </div>
<?php endif; ?>
    <!-- Modal for Add New Minute -->
    <div class="modal fade" id="addMinuteModal" tabindex="-1" aria-labelledby="addMinuteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Add New Minute</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Auto-fill Detection Section -->
                    <form method="POST" action="" enctype="multipart/form-data" id="addMinuteForm">
                        <input type="hidden" name="allow_duplicate_images" id="allowDuplicateMinuteImages" value="0">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="session_number" class="form-label">Session Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="session_number" name="session_number" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="date_posted" class="form-label">Date Posted <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date_posted" name="date_posted" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="meeting_date" class="form-label">Meeting Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="meeting_date" name="meeting_date" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="content" class="form-label">Content</label>
                            <textarea class="form-control" id="content" name="content" rows="4"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Image Files (JPG, PNG)</label>
                            <div class="d-flex gap-2 mb-2">
                                <button type="button" class="btn btn-sm btn-primary active" id="mom-method-desktop" onclick="momSwitchMethod('desktop')">
                                    <i class="fas fa-desktop me-1"></i> This Device
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="mom-method-mobile" onclick="momSwitchMethod('mobile')">
                                    <i class="fas fa-qrcode me-1"></i> Mobile Camera
                                </button>
                            </div>

                            <div id="mom-desktop-upload">
                                <div class="file-upload">
                                    <input type="file" class="form-control" id="image_file" name="image_file[]" accept=".jpg,.jpeg,.png" multiple onchange="processFilesWithAutoFill(this)">
                                    <small class="text-muted">Max file size: 5MB per file. You can upload multiple images (e.g., page 1, page 2). The system will automatically detect and fill fields from all documents.</small>
                                </div>
                                <div id="ocrProcessing" class="mt-2" style="display: none;">
                                    <div class="d-flex align-items-center">
                                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                        <span>Processing files and detecting content...</span>
                                    </div>
                                </div>
                            </div>

                            <div id="mom-mobile-upload" class="d-none">
                                <div class="text-center p-4 border rounded-3 bg-light">
                                    <p class="fw-semibold mb-1"><i class="fas fa-mobile-alt me-2 text-primary"></i>Scan to Upload from Mobile</p>
                                    <p class="text-muted small mb-3">Point your phone's camera at this QR code to open the mobile upload page for meeting minutes.</p>
                                    <div id="mom-qrcode" class="d-inline-block p-2 bg-white rounded border"></div>
                                    <div class="mt-3">
                                        <a id="mom-qr-link" href="#" target="_blank" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-external-link-alt me-1"></i>Open on Mobile
                                        </a>
                                    </div>
                                    <div id="mom-mobile-status" class="mt-3 d-none">
                                        <div class="d-flex align-items-center justify-content-center gap-2 text-success">
                                            <i class="fas fa-check-circle"></i>
                                            <span id="mom-mobile-status-text">Upload detected! Preparing OCR…</span>
                                        </div>
                                    </div>
                                    <div id="mom-mobile-live" class="mt-3 d-none">
                                        <div id="mom-mobile-live-text" class="small text-muted mb-2">Waiting for live camera preview…</div>
                                        <img id="mom-mobile-live-image" src="" alt="Live mobile camera preview"
                                             class="img-fluid rounded border" style="max-height:240px;object-fit:cover;">
                                    </div>
                                    <div class="mt-2">
                                        <span class="badge bg-secondary"><i class="fas fa-circle-notch fa-spin me-1"></i>Waiting for mobile upload…</span>
                                        <div class="text-muted small mt-1">After mobile upload, images will be loaded here automatically and OCR will fill this form.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_minute" class="btn btn-primary-custom">Add Minute</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal for Edit Minute -->
    <div class="modal fade" id="editMinuteModal" tabindex="-1" aria-labelledby="editMinuteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit Minute</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editMinuteForm" method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="minute_id" id="editMinuteId">
                        <input type="hidden" name="existing_image_path" id="editExistingImagePath">
                        <input type="hidden" name="allow_duplicate_images" id="allowDuplicateMinuteEditImages" value="0">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editTitle" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editTitle" name="title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editMeetingNumber" class="form-label">Session Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editMeetingNumber" name="session_number" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="editDatePosted" class="form-label">Date Posted <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="editDatePosted" name="date_posted" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="editMeetingDate" class="form-label">Meeting Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="editMeetingDate" name="meeting_date" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editContent" class="form-label">Content</label>
                            <textarea class="form-control" id="editContent" name="content" rows="4"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Image Files (JPG, PNG)</label>
                            <div class="file-upload">
                                <input type="file" class="form-control" id="editImageFile" name="image_file[]" accept=".jpg,.jpeg,.png" multiple onchange="processFiles(this, 'edit')">
                                <small class="text-muted">Max file size: 5MB per file. You can upload multiple images (e.g., page 1, page 2).</small>
                            </div>
                            <div id="currentImageInfo" class="current-file"></div>
                            <div id="editOcrProcessing" class="mt-2" style="display: none;">
                                <div class="d-flex align-items-center">
                                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                    <span>Processing files...</span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update_minute" class="btn btn-primary-custom">Update Minute</button>
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
                <!-- Carousel for multiple images -->
                <div id="imageCarousel" class="carousel slide" data-bs-ride="carousel">
                    <!-- Carousel indicators -->
                    <div class="carousel-indicators" id="carouselIndicators">
                        <!-- Indicators will be dynamically inserted here -->
                    </div>
                    <div class="carousel-inner" id="carouselInner">
                        <!-- Images will be dynamically inserted here -->
                    </div>
                    <button class="carousel-control-prev" type="button" data-bs-target="#imageCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#imageCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <a id="downloadImage" href="#" class="btn btn-primary-custom" download>
                    <i class="fas fa-download me-2"></i> Download
                </a>
                <button type="button" id="printImage" class="btn btn-outline-primary">
                    <i class="fas fa-print me-2"></i> Print
                </button>
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
                    <input type="hidden" id="ocrMinuteId">
                    <input type="hidden" id="ocrImagePath">
                    <div id="ocrLoading" style="display:none;">
                        <div class="d-flex align-items-center gap-2">
                            <div class="spinner-border text-warning" role="status"></div>
                            <span>Extracting text from document...</span>
                        </div>
                    </div>
                    <div id="ocrActions" class="d-flex justify-content-between mb-2" style="display:none;">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary active" data-view="formatted">
                                <i class="fas fa-paragraph"></i>
                            </button>
                            <button type="button" class="btn btn-outline-primary" data-view="raw">
                                <i class="fas fa-code"></i>
                            </button>
                        </div>
                        <div>
                            <button id="editOcrText" class="btn btn-sm btn-outline-success me-1">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button id="copyOcrText" class="btn btn-sm btn-outline-secondary">
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
    <!-- QRCode.js for mobile upload QR generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
    /* ── Minutes Mobile Upload QR ── */
    (function () {
        let momQrCreated = false;
        let momMobileSession = null;
        let momWs = null;
        let momPollTimer = null;
        let momWsReconnectTimer = null;
        let momHandledComplete = false;

        function momResolveWsUrl() {
            if (window.EFIND_MOBILE_WS_URL) {
                return window.EFIND_MOBILE_WS_URL;
            }
            const scheme = location.protocol === 'https:' ? 'wss' : 'ws';
            const host = location.hostname;
            const port = window.EFIND_MOBILE_WS_PORT || '8090';
            return `${scheme}://${host}:${port}/mobile-upload`;
        }

        async function momEnsureSession() {
            if (momMobileSession) return momMobileSession;
            const res = await fetch('mobile_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ doc_type: 'minutes' }),
            });
            const data = await res.json();
            if (!data.success || !data.session_id) {
                throw new Error(data.error || 'Failed to create mobile session');
            }
            momMobileSession = data.session_id;
            return momMobileSession;
        }

        window.momSwitchMethod = async function (method) {
            const desktopBtn  = document.getElementById('mom-method-desktop');
            const mobileBtn   = document.getElementById('mom-method-mobile');
            const desktopPane = document.getElementById('mom-desktop-upload');
            const mobilePane  = document.getElementById('mom-mobile-upload');

            if (!desktopBtn || !mobileBtn || !desktopPane || !mobilePane) return;

            if (method === 'mobile') {
                desktopBtn.classList.replace('btn-primary', 'btn-outline-primary');
                desktopBtn.classList.remove('active');
                mobileBtn.classList.replace('btn-outline-primary', 'btn-primary');
                mobileBtn.classList.add('active');
                desktopPane.classList.add('d-none');
                mobilePane.classList.remove('d-none');
                momHandledComplete = false;
                momResetLivePreview();
                try {
                    await momGenerateQR();
                    momStartRealtime();
                } catch (error) {
                    console.error('Unable to initialize mobile upload session:', error);
                    alert('Unable to create mobile upload session. Please try again.');
                    momSwitchMethod('desktop');
                }
            } else {
                mobileBtn.classList.replace('btn-primary', 'btn-outline-primary');
                mobileBtn.classList.remove('active');
                desktopBtn.classList.replace('btn-outline-primary', 'btn-primary');
                desktopBtn.classList.add('active');
                mobilePane.classList.add('d-none');
                desktopPane.classList.remove('d-none');
                momStopRealtime();
                momResetLivePreview();
            }
        };

        async function momGenerateQR() {
            if (momQrCreated && momMobileSession) return;
            const sessionId = await momEnsureSession();
            const mobileUploadUrl = new URL('mobile_upload', window.location.href);
            mobileUploadUrl.searchParams.set('type', 'minutes');
            mobileUploadUrl.searchParams.set('camera', '1');
            mobileUploadUrl.searchParams.set('flow', 'modal_ocr');
            mobileUploadUrl.searchParams.set('session', sessionId);
            const url = mobileUploadUrl.toString();
            const qrLink = document.getElementById('mom-qr-link');
            const qrWrap = document.getElementById('mom-qrcode');
            if (!qrLink || !qrWrap) return;
            qrLink.href = url;
            qrWrap.innerHTML = '';
            new QRCode(qrWrap, {
                text: url,
                width: 200,
                height: 200,
                colorDark: '#002147',
                colorLight: '#ffffff',
            });
            momQrCreated = true;
        }

        function momResetLivePreview() {
            const wrap = document.getElementById('mom-mobile-live');
            const img = document.getElementById('mom-mobile-live-image');
            const text = document.getElementById('mom-mobile-live-text');
            if (img) img.removeAttribute('src');
            if (text) text.textContent = 'Waiting for live camera preview…';
            if (wrap) wrap.classList.add('d-none');
        }

        function momRenderLivePreview(frameData) {
            const wrap = document.getElementById('mom-mobile-live');
            const img = document.getElementById('mom-mobile-live-image');
            const text = document.getElementById('mom-mobile-live-text');
            if (!wrap || !img) return;
            img.src = frameData;
            if (text) text.textContent = 'Live camera preview from mobile.';
            wrap.classList.remove('d-none');
        }

        function momUpdateLiveStatus(status) {
            const text = document.getElementById('mom-mobile-live-text');
            if (!text) return;
            if (status === 'live') {
                text.textContent = 'Live camera preview from mobile.';
            } else if (status === 'stopped') {
                text.textContent = 'Camera paused on mobile.';
            }
        }

        function momStartRealtime() {
            momStopRealtime();
            momStartPollingFallback();
            momStartWebSocket();
        }

        function momStartWebSocket() {
            if (!momMobileSession || !window.WebSocket) return;
            try {
                momWs = new WebSocket(momResolveWsUrl());
            } catch (error) {
                console.error('WebSocket connection failed:', error);
                momWs = null;
                return;
            }

            momWs.onopen = function () {
                if (!momWs || !momMobileSession) return;
                momWs.send(JSON.stringify({
                    action: 'subscribe',
                    session_id: momMobileSession,
                    doc_type: 'minutes',
                }));
            };

            momWs.onmessage = function (event) {
                let data;
                try {
                    data = JSON.parse(event.data);
                } catch (error) {
                    return;
                }
                if (data.type === 'camera_frame' && data.session_id === momMobileSession && data.frame_data) {
                    momRenderLivePreview(data.frame_data);
                    return;
                }
                if (data.type === 'camera_status' && data.session_id === momMobileSession) {
                    momUpdateLiveStatus(data.status);
                    return;
                }
                if (data.type === 'upload_complete' && data.session_id === momMobileSession) {
                    momHandleComplete(data);
                }
            };

            momWs.onclose = function () {
                momWs = null;
                const mobilePane = document.getElementById('mom-mobile-upload');
                if (mobilePane && !mobilePane.classList.contains('d-none') && momMobileSession && !momHandledComplete) {
                    clearTimeout(momWsReconnectTimer);
                    momWsReconnectTimer = setTimeout(momStartWebSocket, 3000);
                }
            };

            momWs.onerror = function () {
                // Poll fallback remains active.
            };
        }

        function momStartPollingFallback() {
            if (!momMobileSession) return;
            momPollTimer = setInterval(async function () {
                try {
                    const response = await fetch(`mobile_session?action=check&session=${encodeURIComponent(momMobileSession)}&_=${Date.now()}`, {
                        cache: 'no-store',
                        headers: {
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache',
                        },
                    });
                    const data = await response.json();
                    const status = String(data.status || '').trim().toLowerCase();
                    if (status === 'complete') {
                        momHandleComplete({
                            title: 'Minutes of Meeting',
                            uploaded_by: 'mobile',
                            result_id: data.result_id || null,
                            object_keys: Array.isArray(data.object_keys) ? data.object_keys : [],
                            image_urls: Array.isArray(data.image_urls) ? data.image_urls : [],
                            deferred_to_desktop: true,
                        });
                    }
                } catch (error) {
                    // Keep polling on transient errors.
                }
            }, 3000);
        }

        function momSetMobileStatus(message, type = 'info') {
            const statusEl = document.getElementById('mom-mobile-status');
            const textEl = document.getElementById('mom-mobile-status-text');
            if (!statusEl || !textEl) return;

            const row = statusEl.querySelector('.d-flex');
            const icon = statusEl.querySelector('i');
            if (row) {
                row.classList.remove('text-success', 'text-danger', 'text-primary');
                row.classList.add(type === 'error' ? 'text-danger' : (type === 'success' ? 'text-success' : 'text-primary'));
            }
            if (icon) {
                icon.className = type === 'error'
                    ? 'fas fa-exclamation-circle'
                    : (type === 'success' ? 'fas fa-check-circle' : 'fas fa-circle-notch fa-spin');
            }

            textEl.textContent = message;
            statusEl.classList.remove('d-none');
        }

        function momGuessFileExtension(mimeType, fallbackUrl) {
            const byMime = {
                'image/jpeg': 'jpg',
                'image/jpg': 'jpg',
                'image/png': 'png',
                'image/gif': 'gif',
                'image/bmp': 'bmp',
                'image/webp': 'webp',
            };
            const normalizedMime = String(mimeType || '').toLowerCase();
            if (byMime[normalizedMime]) return byMime[normalizedMime];

            const cleanUrl = String(fallbackUrl || '').split('?')[0];
            const ext = cleanUrl.includes('.') ? cleanUrl.split('.').pop().toLowerCase() : '';
            return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(ext) ? ext : 'jpg';
        }

        async function momApplyMobileImagesToForm(data) {
            const imageUrls = (data && Array.isArray(data.image_urls))
                ? data.image_urls.filter(url => typeof url === 'string' && url !== '')
                : [];
            if (!imageUrls.length) {
                throw new Error('Upload completed, but no mobile images were received.');
            }

            const modalEl = document.getElementById('addMinuteModal');
            if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }

            await momSwitchMethod('desktop');
            momSetMobileStatus(`Syncing ${imageUrls.length} mobile image(s) to this form…`, 'info');

            const fileInput = document.getElementById('image_file');
            if (!fileInput) {
                throw new Error('Minutes image input field not found.');
            }
            if (typeof DataTransfer === 'undefined') {
                throw new Error('Automatic transfer is not supported in this browser.');
            }

            const dt = new DataTransfer();
            let transferred = 0;
            for (let i = 0; i < imageUrls.length; i++) {
                const imageUrl = imageUrls[i];
                try {
                    const response = await fetch(imageUrl, { cache: 'no-store' });
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const blob = await response.blob();
                    const ext = momGuessFileExtension(blob.type, imageUrl);
                    const file = new File([blob], `mobile_minutes_${i + 1}.${ext}`, {
                        type: blob.type || 'image/jpeg',
                        lastModified: Date.now(),
                    });
                    dt.items.add(file);
                    transferred++;
                } catch (error) {
                    console.error('Failed to transfer mobile image:', imageUrl, error);
                }
            }

            if (!transferred) {
                throw new Error('Could not transfer uploaded mobile images to the form.');
            }

            fileInput.files = dt.files;
            await processFilesWithAutoFill(fileInput);
            momSetMobileStatus('Mobile images loaded. OCR auto-fill completed.', 'success');
            momMobileSession = null;
            momQrCreated = false;
        }

        async function momHandleComplete(data) {
            if (momHandledComplete) return;
            momHandledComplete = true;
            momStopRealtime();
            momResetLivePreview();
            try {
                await momApplyMobileImagesToForm(data || {});
            } catch (error) {
                console.error('Unable to apply mobile upload to minutes form:', error);
                const message = error instanceof Error ? error.message : 'Unable to apply mobile upload to this form.';
                momSetMobileStatus(message, 'error');
            }
        }

        function momStopRealtime() {
            clearTimeout(momWsReconnectTimer);
            momWsReconnectTimer = null;
            if (momWs) {
                momWs.onclose = null;
                momWs.close();
                momWs = null;
            }
            if (momPollTimer) {
                clearInterval(momPollTimer);
                momPollTimer = null;
            }
            momResetLivePreview();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('addMinuteModal');
            if (modal) {
                modal.addEventListener('hidden.bs.modal', function () {
                    momStopRealtime();
                    momQrCreated = false;
                    momMobileSession = null;
                    momHandledComplete = false;
                    const statusEl = document.getElementById('mom-mobile-status');
                    if (statusEl) statusEl.classList.add('d-none');
                    momSwitchMethod('desktop');
                });
            }
        });
    })();
    </script>
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jsPDF & html2canvas for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    <!-- OCR now runs via composer_tesseract_ocr.php -->
    <!-- PDF.js for PDF text extraction -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.12.313/pdf.min.js"></script>
    <script>
        // Initialize PDF.js worker
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.12.313/pdf.worker.min.js';

        // Delete confirmation functionality
        document.addEventListener('DOMContentLoaded', function() {
            const deleteConfirmKeyword = 'MINUTES';
            const deleteConfirmInput = document.getElementById('deleteConfirmInput');
            const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
            const deleteActionInput = document.getElementById('deleteActionInput');
            const deleteItemIdInput = document.getElementById('deleteItemId');
            const bulkDeleteIdsContainer = document.getElementById('bulkDeleteIdsContainer');
            const deleteItemTitle = document.getElementById('deleteItemTitle');
            const deleteConfirmMessage = document.getElementById('deleteConfirmMessage');
            const deleteConfirmLabel = document.getElementById('deleteConfirmLabel');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const selectedRowsCount = document.getElementById('selectedRowsCount');
            const selectedRowIds = new Set();
            const selectableRows = Array.from(document.querySelectorAll('#minutesTableBody tr[data-id]'));
            let selectionModeActive = false;

            const setDeleteButtonState = (enabled) => {
                if (enabled) {
                    confirmDeleteBtn.classList.remove('disabled');
                    confirmDeleteBtn.removeAttribute('aria-disabled');
                    confirmDeleteBtn.disabled = false;
                } else {
                    confirmDeleteBtn.classList.add('disabled');
                    confirmDeleteBtn.setAttribute('aria-disabled', 'true');
                    confirmDeleteBtn.disabled = true;
                }
            };

            const updateBulkDeleteState = () => {
                const selectedCount = selectedRowIds.size;
                selectionModeActive = selectedCount > 0;
                selectedRowsCount.textContent = `${selectedCount} selected`;
                const hasSelection = selectedCount > 0;
                bulkDeleteBtn.classList.toggle('disabled', !hasSelection);
                bulkDeleteBtn.disabled = !hasSelection;
                if (hasSelection) {
                    bulkDeleteBtn.removeAttribute('aria-disabled');
                } else {
                    bulkDeleteBtn.setAttribute('aria-disabled', 'true');
                }
            };

            const resetDeleteModal = () => {
                deleteConfirmInput.value = '';
                setDeleteButtonState(false);
                deleteActionInput.value = 'delete';
                deleteItemIdInput.value = '';
                bulkDeleteIdsContainer.innerHTML = '';
                deleteConfirmMessage.textContent = 'Are you sure you want to delete this minute?';
                deleteConfirmLabel.innerHTML = 'Type <strong class="text-danger">MINUTES</strong> to confirm:';
                deleteConfirmInput.placeholder = 'Type MINUTES';
                confirmDeleteBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Delete Minute';
            };

            const openDeleteModal = (ids, label) => {
                resetDeleteModal();

                if (ids.length === 1) {
                    deleteItemTitle.textContent = label;
                    deleteItemIdInput.value = ids[0];
                } else {
                    deleteActionInput.value = 'bulk_delete';
                    deleteConfirmMessage.textContent = 'Are you sure you want to delete the selected minutes?';
                    deleteItemTitle.textContent = `${ids.length} minute(s) selected`;
                    confirmDeleteBtn.innerHTML = `<i class="fas fa-trash me-1"></i>Delete Selected (${ids.length})`;
                    ids.forEach((id) => {
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'ids[]';
                        hiddenInput.value = id;
                        bulkDeleteIdsContainer.appendChild(hiddenInput);
                    });
                }

                const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
                deleteModal.show();
            };

            const toggleRowSelection = (row) => {
                const id = parseInt(row.getAttribute('data-id'), 10);
                if (!Number.isFinite(id) || id <= 0) return;

                if (selectedRowIds.has(id)) {
                    selectedRowIds.delete(id);
                    row.classList.remove('selected-for-delete');
                } else {
                    selectedRowIds.add(id);
                    row.classList.add('selected-for-delete');
                }
                updateBulkDeleteState();
            };

            const isInteractiveTarget = (target) => {
                return target instanceof Element && Boolean(target.closest('a, button, input, textarea, select, label, .modal'));
            };

            // Delete button handlers
            document.querySelectorAll('.delete-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const id = parseInt(this.getAttribute('data-id'), 10);
                    const title = this.getAttribute('data-title');
                    if (!Number.isFinite(id) || id <= 0) return;
                    openDeleteModal([id], title);
                });
            });

            selectableRows.forEach((row) => {
                row.addEventListener('click', function(e) {
                    if (isInteractiveTarget(e.target)) return;
                    const ignoreClickUntil = Number(this.dataset.ignoreClickUntil || 0);
                    if (Date.now() < ignoreClickUntil) return;
                    if (!selectionModeActive) return;
                    toggleRowSelection(this);
                });

                row.addEventListener('dblclick', function(e) {
                    if (isInteractiveTarget(e.target)) return;
                    if (selectionModeActive) return;
                    toggleRowSelection(this);
                });

                row.addEventListener('touchend', function(e) {
                    if (isInteractiveTarget(e.target)) return;
                    const now = Date.now();
                    if (selectionModeActive) {
                        e.preventDefault();
                        this.dataset.lastTapAt = '0';
                        this.dataset.ignoreClickUntil = String(now + 400);
                        toggleRowSelection(this);
                        return;
                    }

                    const lastTap = Number(this.dataset.lastTapAt || 0);
                    if (now - lastTap < 350) {
                        e.preventDefault();
                        this.dataset.lastTapAt = '0';
                        this.dataset.ignoreClickUntil = String(now + 400);
                        toggleRowSelection(this);
                    } else {
                        this.dataset.lastTapAt = String(now);
                    }
                }, { passive: false });
            });

            bulkDeleteBtn.addEventListener('click', function() {
                const selectedIds = Array.from(selectedRowIds).filter((id) => Number.isFinite(id) && id > 0);
                if (selectedIds.length === 0) return;
                openDeleteModal(selectedIds, `${selectedIds.length} minute(s) selected`);
            });

            document.getElementById('deleteConfirmModal').addEventListener('hide.bs.modal', function () {
                resetDeleteModal();
            });

            document.getElementById('deleteConfirmModal').addEventListener('shown.bs.modal', function () {
                if (deleteConfirmInput) {
                    setTimeout(() => deleteConfirmInput.focus(), 0);
                }
            });

            updateBulkDeleteState();

            // Enable delete button only when "MINUTES" is typed
            deleteConfirmInput.addEventListener('input', function() {
                setDeleteButtonState(this.value === deleteConfirmKeyword);
            });

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
            // Remove alert container when alert is closed
            document.addEventListener('closed.bs.alert', function (event) {
                const alertContainer = event.target.closest('.alert-container');
                if (alertContainer) {
                    setTimeout(() => {
                        alertContainer.remove();
                    }, 300);
                }
            });
        });

        // Function to format OCR text into HTML
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

        // Function to update pagination position
        function updatePaginationPosition() {
            const paginationContainer = document.querySelector('.pagination-container');
            const managementContainer = document.querySelector('.management-container');
            if (paginationContainer && managementContainer) {
                const containerRect = managementContainer.getBoundingClientRect();
                const windowHeight = window.innerHeight;
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
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize pagination position
            updatePaginationPosition();
            window.addEventListener('resize', updatePaginationPosition);

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert-message');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });

            const printImagePages = (imageSrcs) => {
                if (!Array.isArray(imageSrcs) || imageSrcs.length === 0) return;
                const printWindow = window.open('', '_blank');
                if (!printWindow) {
                    alert('Please allow pop-ups to print images.');
                    return;
                }

                const pagesHtml = imageSrcs.map((src, index) =>
                    `<div class="print-page"><img src="${String(src).replace(/"/g, '&quot;')}" alt="Image ${index + 1}"></div>`
                ).join('');

                printWindow.document.open();
                printWindow.document.write(`<!DOCTYPE html>
<html>
<head>
    <title>Print Images</title>
    <style>
        body { margin: 0; padding: 0; }
        .print-page {
            page-break-after: always;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12mm;
            box-sizing: border-box;
        }
        .print-page:last-child { page-break-after: auto; }
        img { max-width: 100%; max-height: 100%; object-fit: contain; }
    </style>
</head>
<body>${pagesHtml}
<script>
(function () {
    const images = Array.from(document.images);
    if (!images.length) {
        window.print();
        return;
    }
    let loaded = 0;
    const done = () => {
        loaded += 1;
        if (loaded >= images.length) {
            setTimeout(() => {
                window.focus();
                window.print();
            }, 300);
        }
    };
    images.forEach((img) => {
        if (img.complete) {
            done();
        } else {
            img.onload = done;
            img.onerror = done;
        }
    });
    window.onafterprint = function () { window.close(); };
})();
<\/script>
</body>
</html>`);
                printWindow.document.close();
            };

            // Handle image link clicks
            document.querySelectorAll('.image-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const imageSrcs = this.getAttribute('data-image-src')
                        .split(/[|,]/)
                        .map(src => src.trim())
                        .filter(Boolean);
                    const carouselInner = document.getElementById('carouselInner');
                    const carouselIndicators = document.getElementById('carouselIndicators');
                    const carouselPrev = document.querySelector('.carousel-control-prev');
                    const carouselNext = document.querySelector('.carousel-control-next');
                    carouselInner.innerHTML = '';
                    carouselIndicators.innerHTML = '';

                    imageSrcs.forEach((src, index) => {
                        const carouselItem = document.createElement('div');
                        carouselItem.className = `carousel-item ${index === 0 ? 'active' : ''}`;
                        carouselItem.innerHTML = `<img src="${src}" class="d-center w-50" alt="Image ${index + 1}">`;
                        carouselInner.appendChild(carouselItem);

                        const indicator = document.createElement('button');
                        indicator.type = 'button';
                        indicator.setAttribute('data-bs-target', '#imageCarousel');
                        indicator.setAttribute('data-bs-slide-to', index.toString());
                        indicator.className = index === 0 ? 'active' : '';
                        indicator.setAttribute('aria-current', index === 0 ? 'true' : 'false');
                        indicator.setAttribute('aria-label', `Slide ${index + 1}`);
                        carouselIndicators.appendChild(indicator);
                    });

                    if (imageSrcs.length <= 1) {
                        carouselPrev.style.display = 'none';
                        carouselNext.style.display = 'none';
                        carouselIndicators.style.display = 'none';
                    } else {
                        carouselPrev.style.display = 'block';
                        carouselNext.style.display = 'block';
                        carouselIndicators.style.display = 'flex';
                    }

                    // Set download button to download ALL images
                    const downloadLink = document.getElementById('downloadImage');
                    // Remove old click handlers
                    downloadLink.replaceWith(downloadLink.cloneNode(true));
                    const newDownloadLink = document.getElementById('downloadImage');
                    
                    newDownloadLink.addEventListener('click', async function(e) {
                        e.preventDefault();
                        
                        // Change button text to show downloading
                        const originalHTML = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Downloading...';
                        this.style.pointerEvents = 'none';
                        
                        // Download each image with delay to avoid overwhelming browser
                        for (let i = 0; i < imageSrcs.length; i++) {
                            try {
                                // Fetch the image as blob
                                const response = await fetch(imageSrcs[i]);
                                const blob = await response.blob();
                                
                                // Create download link
                                const url = window.URL.createObjectURL(blob);
                                const a = document.createElement('a');
                                a.style.display = 'none';
                                a.href = url;
                                
                                // Set filename with page number
                                const originalFilename = imageSrcs[i].split('/').pop();
                                const fileExtension = originalFilename.split('.').pop();
                                const baseFilename = originalFilename.replace('.' + fileExtension, '');
                                a.download = imageSrcs.length > 1 ? 
                                    `${baseFilename}_page${i + 1}.${fileExtension}` : 
                                    originalFilename;
                                
                                document.body.appendChild(a);
                                a.click();
                                window.URL.revokeObjectURL(url);
                                document.body.removeChild(a);
                                
                                // Small delay between downloads
                                if (i < imageSrcs.length - 1) {
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                            } catch (error) {
                                console.error(`Error downloading image ${i + 1}:`, error);
                            }
                        }
                        
                        // Reset button
                        this.innerHTML = originalHTML;
                        this.style.pointerEvents = 'auto';
                    });

                    const printButton = document.getElementById('printImage');
                    printButton.replaceWith(printButton.cloneNode(true));
                    const newPrintButton = document.getElementById('printImage');
                    newPrintButton.addEventListener('click', function() {
                        printImagePages(imageSrcs);
                    });

                    const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
                    imageModal.show();
                });
            });

            // Edit button functionality
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    fetch(`?action=get_minute&id=${id}`)
                        .then(response => response.json())
                        .then(minute => {
                            document.getElementById('editMinuteId').value = minute.id;
                            document.getElementById('editTitle').value = minute.title;
                            document.getElementById('editDatePosted').value = minute.date_posted;
                            document.getElementById('editMeetingDate').value = minute.meeting_date;
                            document.getElementById('editMeetingNumber').value = minute.session_number;
                            document.getElementById('editContent').value = minute.content || '';
                            document.getElementById('editExistingImagePath').value = minute.image_path || '';

                            const currentFileInfo = document.getElementById('currentImageInfo');
                            if (minute.image_path) {
                                const imagePaths = minute.image_path
                                    .split(/[|,]/)
                                    .map(path => path.trim())
                                    .filter(Boolean);
                                let imageLinks = '';
                                imagePaths.forEach(path => {
                                    imageLinks += `<a href="${path}" target="_blank" class="d-block">View Image</a>`;
                                });
                                currentFileInfo.innerHTML = `<strong>Current Image(s):</strong>${imageLinks}`;
                            } else {
                                currentFileInfo.innerHTML = '<strong>No image uploaded</strong>';
                            }

                            const editModal = new bootstrap.Modal(document.getElementById('editMinuteModal'));
                            editModal.show();
                        })
                        .catch(error => {
                            console.error('Error fetching minute:', error);
                            alert('Error loading minute data.');
                        });
                });
            });

            // Print button logic
            document.getElementById('printButton').addEventListener('click', function() {
                const printModal = new bootstrap.Modal(document.getElementById('printDateRangeModal'));
                printModal.show();
            });

            document.getElementById('confirmPrint').addEventListener('click', function() {
                const startDate = document.getElementById('printStartDate').value;
                const endDate = document.getElementById('printEndDate').value;
                let printUrl = window.location.pathname + '?print=1';
                if (startDate) printUrl += '&print_start_date=' + encodeURIComponent(startDate);
                if (endDate) printUrl += '&print_end_date=' + encodeURIComponent(endDate);
                window.open(printUrl, '_blank');
                const printModal = bootstrap.Modal.getInstance(document.getElementById('printDateRangeModal'));
                printModal.hide();
            });

            // OCR button functionality
            document.querySelectorAll('.ocr-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const imageSrcs = btn.getAttribute('data-image-src')
                        .split(/[|,]/)
                        .map(src => src.trim())
                        .filter(Boolean);
                    const minuteId = btn.closest('tr').getAttribute('data-id');
                    const ocrLoading = document.getElementById('ocrLoading');
                    const ocrResult = document.getElementById('ocrResult');
                    const ocrActions = document.getElementById('ocrActions');

                    document.getElementById('ocrMinuteId').value = minuteId;
                    document.getElementById('ocrImagePath').value = btn.getAttribute('data-image-src');
                    document.getElementById('formattedView').innerHTML = '';
                    document.querySelector('#rawView pre').textContent = '';
                    document.getElementById('ocrTextEditor').value = '';
                    document.getElementById('editView').style.display = 'none';
                    document.getElementById('formattedView').style.display = 'block';
                    ocrLoading.style.display = 'block';
                    ocrResult.style.display = 'none';
                    ocrActions.style.display = 'none';

                    fetch(`?action=get_minute&id=${minuteId}`)
                        .then(response => response.json())
                        .then(minute => {
                            if (minute && minute.content) {
                                ocrLoading.style.display = 'none';
                                ocrResult.style.display = 'block';
                                ocrActions.style.display = 'flex';
                                document.getElementById('ocrTextEditor').value = minute.content;
                                formatOcrText(minute.content);
                                document.querySelector('#rawView pre').textContent = minute.content;
                            } else {
                                let combinedText = '';
                                let processedImages = 0;

                                imageSrcs.forEach((src, index) => {
                                    if (src.toLowerCase().endsWith('.pdf')) {
                                        extractTextFromPDF(src, (text) => {
                                            combinedText += text + '\n\n---\n\n';
                                            processedImages++;
                                            if (processedImages === imageSrcs.length) {
                                                ocrLoading.style.display = 'none';
                                                ocrResult.style.display = 'block';
                                                ocrActions.style.display = 'flex';
                                                document.getElementById('ocrTextEditor').value = combinedText;
                                                formatOcrText(combinedText);
                                                document.querySelector('#rawView pre').textContent = combinedText;
                                            }
                                        });
                                    } else {
                                        performOCR(src, minuteId, (text) => {
                                            combinedText += text + '\n\n---\n\n';
                                            processedImages++;
                                            if (processedImages === imageSrcs.length) {
                                                ocrLoading.style.display = 'none';
                                                ocrResult.style.display = 'block';
                                                ocrActions.style.display = 'flex';
                                                document.getElementById('ocrTextEditor').value = combinedText;
                                                formatOcrText(combinedText);
                                                document.querySelector('#rawView pre').textContent = combinedText;
                                            }
                                        });
                                    }
                                });
                            }
                        })
                        .catch(() => {
                            let combinedText = '';
                            let processedImages = 0;

                            imageSrcs.forEach((src, index) => {
                                if (src.toLowerCase().endsWith('.pdf')) {
                                    extractTextFromPDF(src, (text) => {
                                        combinedText += text + '\n\n---\n\n';
                                        processedImages++;
                                        if (processedImages === imageSrcs.length) {
                                            ocrLoading.style.display = 'none';
                                            ocrResult.style.display = 'block';
                                            ocrActions.style.display = 'flex';
                                            document.getElementById('ocrTextEditor').value = combinedText;
                                            formatOcrText(combinedText);
                                            document.querySelector('#rawView pre').textContent = combinedText;
                                        }
                                    });
                                } else {
                                    performOCR(src, minuteId, (text) => {
                                        combinedText += text + '\n\n---\n\n';
                                        processedImages++;
                                        if (processedImages === imageSrcs.length) {
                                            ocrLoading.style.display = 'none';
                                            ocrResult.style.display = 'block';
                                            ocrActions.style.display = 'flex';
                                            document.getElementById('ocrTextEditor').value = combinedText;
                                            formatOcrText(combinedText);
                                            document.querySelector('#rawView pre').textContent = combinedText;
                                        }
                                    });
                                }
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
                const minuteId = document.getElementById('ocrMinuteId').value;
                const saveButton = this;
                const originalText = saveButton.innerHTML;

                if (!minuteId || minuteId <= 0) {
                    alert('Error: Invalid minute ID');
                    return;
                }
                if (!editedText.trim()) {
                    alert('Error: OCR text cannot be empty');
                    return;
                }

                saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';
                saveButton.disabled = true;

                const formData = new FormData();
                formData.append('id', minuteId);
                formData.append('document_type', 'minute');
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
                        formatOcrText(editedText);
                        document.querySelector('#rawView pre').textContent = editedText;
                        document.getElementById('editView').style.display = 'none';
                        document.getElementById('formattedView').style.display = 'block';
                        document.getElementById('rawView').style.display = 'none';

                        const successAlert = document.createElement('div');
                        successAlert.className = 'alert alert-success alert-dismissible fade show mb-3';
                        successAlert.innerHTML = `
                            <i class="fas fa-check-circle me-2"></i>
                            ${data.message || 'OCR content updated successfully!'}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        document.querySelector('#ocrResult').prepend(successAlert);
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
                    const errorAlert = document.createElement('div');
                    errorAlert.className = 'alert alert-danger alert-dismissible fade show mb-3';
                    errorAlert.innerHTML = `
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error updating OCR content: ${error.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
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
            document.getElementById('addMinuteForm').addEventListener('submit', function(e) {
                const fileInput = document.getElementById('image_file');
                if (fileInput.files.length > 0) {
                    for (let i = 0; i < fileInput.files.length; i++) {
                        const file = fileInput.files[i];
                        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp'];
                        if (!allowedTypes.includes(file.type)) {
                            e.preventDefault();
                            alert('Invalid file type. Only JPG, PNG, GIF, and BMP files are allowed.');
                            return;
                        }
                    }
                }
            });

            // Client-side validation for edit form
            document.getElementById('editMinuteForm').addEventListener('submit', function(e) {
                const fileInput = document.getElementById('editImageFile');
                if (fileInput.files.length > 0) {
                    for (let i = 0; i < fileInput.files.length; i++) {
                        const file = fileInput.files[i];
                        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp'];
                        if (!allowedTypes.includes(file.type)) {
                            e.preventDefault();
                            alert('Invalid file type. Only JPG, PNG, GIF, and BMP files are allowed.');
                            return;
                        }
                    }
                }
            });
        });

        // Function to perform OCR using composer backend
        async function runComposerOcr(source, onProgress) {
            if (typeof window.efindComposerOcr === 'function') {
                return window.efindComposerOcr(source, {
                    documentType: 'minutes',
                    onProgress: ({ percent, message }) => {
                        if (typeof onProgress === 'function') {
                            onProgress(percent, message);
                        }
                    },
                });
            }

            let file = null;
            let imageUrl = '';
            if (source instanceof File) {
                file = source;
            } else if (source instanceof Blob) {
                file = new File([source], `ocr_${Date.now()}.jpg`, { type: source.type || 'image/jpeg' });
            } else if (typeof source === 'string' && source.trim() !== '') {
                try {
                    imageUrl = new URL(source.trim(), window.location.href).href;
                } catch (error) {
                    throw new Error('Invalid OCR source URL.');
                }
            } else {
                throw new Error('OCR source is required.');
            }

            if (typeof onProgress === 'function') {
                onProgress(35, 'Uploading image to server OCR...');
            }

            const formData = new FormData();
            if (file) {
                formData.append('file', file);
            } else {
                formData.append('image_url', imageUrl);
            }
            formData.append('document_type', 'minutes');

            const response = await fetch('composer_tesseract_ocr.php', {
                method: 'POST',
                body: formData,
            });

            let result = null;
            try {
                result = await response.json();
            } catch (error) {
                throw new Error(`Invalid OCR response (HTTP ${response.status})`);
            }

            if (!response.ok || !result || !result.success) {
                const message = result && result.error ? result.error : `OCR failed (HTTP ${response.status})`;
                throw new Error(message);
            }

            if (typeof onProgress === 'function') {
                onProgress(100, 'OCR complete.');
            }

            return {
                text: typeof result.text === 'string' ? result.text : '',
                confidence: typeof result.confidence === 'number' ? result.confidence : null,
            };
        }
        function performOCR(imagePath, minuteId, callback) {
            runComposerOcr(imagePath, function (percent, message) {
                const progressElement = document.getElementById('ocrProgress');
                if (!progressElement) return;
                progressElement.textContent = message || `Processing: ${Math.round(percent || 0)}%`;
            }).then(({ text }) => {
                callback(text);
            }).catch(error => {
                console.error('OCR Error:', error);
                callback('');
            });
        }

        // Function to extract text from PDF using PDF.js
        async function extractTextFromPDF(pdfUrl, callback) {
            try {
                const loadingTask = pdfjsLib.getDocument(pdfUrl);
                const pdf = await loadingTask.promise;
                let text = '';

                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);
                    const content = await page.getTextContent();
                    const strings = content.items.map(item => item.str);
                    text += strings.join(' ') + '\n';
                }

                callback(text);
            } catch (error) {
                console.error('PDF Extraction Error:', error);
                callback('');
            }
        }

        // Function to clean OCR text
        function cleanOcrText(text) {
            return text
                .replace(/(\r\n|\r|\n){3,}/g, '\n\n')
                .replace(/[|]/g, 'I')
                .replace(/[0]/g, 'O')
                .replace(/[1]/g, 'I')
                .replace(/ +/g, ' ')
                .replace(/\n /g, '\n')
                .replace(/ \n/g, '\n')
                .split('\n')
                .map(line => line.trim())
                .join('\n')
                .trim();
        }

        // Function to process files with auto-fill
        async function processFilesWithAutoFill(input) {
            const files = input.files;
            if (!files || files.length === 0) return;

            const processingElement = document.getElementById('ocrProcessing');
            processingElement.style.display = 'block';
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

                        const { text } = await runComposerOcr(file, function (percent, message) {
                            const progressElement = document.getElementById('fileOcrProgress');
                            if (progressElement) {
                                progressElement.textContent = message || `Processing: ${Math.round(percent || 0)}%`;
                            }
                        });

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

                if (typeof window.efindHandleDuplicateImageSelection === 'function') {
                    const duplicateState = await window.efindHandleDuplicateImageSelection(input, {
                        documentType: 'minutes',
                        allowFieldId: 'allowDuplicateMinuteImages',
                        strictNoDuplicates: true,
                        formId: 'addMinuteForm'
                    });
                    if (duplicateState && !duplicateState.proceed) {
                        processingElement.innerHTML = `
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                This file has already been uploaded. Please upload a different file.
                            </div>
                        `;
                        return;
                    }
                }

                if (combinedText.trim().length > 0) {
                    const detectedFields = analyzeDocumentContent(combinedText);
                    processingElement.innerHTML = `
                        <div class="d-flex align-items-center">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            <span>Finalizing OCR text to Markdown...</span>
                        </div>
                    `;
                    if (typeof window.efindFinalizeOcrMarkdown === 'function') {
                        const finalizedMarkdown = await window.efindFinalizeOcrMarkdown(detectedFields.content, 'minutes');
                        if (finalizedMarkdown) {
                            detectedFields.content = finalizedMarkdown;
                        }
                    }
                    if (typeof window.efindHandleExtractedTextDuplicate === 'function') {
                        const contentForDuplicateCheck = (detectedFields.content && detectedFields.content.trim())
                            ? detectedFields.content
                            : combinedText;
                        const textDuplicateState = await window.efindHandleExtractedTextDuplicate(contentForDuplicateCheck, {
                            documentType: 'minutes',
                            formId: 'addMinuteForm'
                        });
                        if (textDuplicateState && !textDuplicateState.proceed) {
                            processingElement.innerHTML = `
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    This file has already been uploaded. Please upload a different file.
                                </div>
                            `;
                            return;
                        }
                    }
                    updateFormWithDetectedData(detectedFields);
                    processingElement.innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            Processing complete. Fields have been auto-filled.
                        </div>
                    `;
                } else {
                    processingElement.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No text could be extracted from the images. Please fill the form manually.
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

        // Function to process files for edit form
        async function processFiles(input, formType = 'edit') {
            const allowFieldId = formType === 'edit' ? 'allowDuplicateMinuteEditImages' : 'allowDuplicateMinuteImages';
            if (typeof window.efindHandleDuplicateImageSelection === 'function') {
                const duplicateState = await window.efindHandleDuplicateImageSelection(input, {
                    documentType: 'minutes',
                    allowFieldId
                });
                if (duplicateState && !duplicateState.proceed) {
                    return;
                }
            }
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

                        const { text } = await runComposerOcr(file, function (percent, message) {
                            const progressElement = document.getElementById('fileOcrProgress');
                            if (progressElement) {
                                progressElement.textContent = message || `Processing: ${Math.round(percent || 0)}%`;
                            }
                        });

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
                            No text could be extracted from the images. Please fill the form manually.
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

        // Function to analyze document content and extract structured data
        function analyzeDocumentContent(text) {
            const detectedFields = {
                title: '',
                meetingNumber: '',
                dates: [],
                content: text
            };

            const lines = text.split('\n').filter(line => line.trim().length > 10);
            if (lines.length > 0) {
                detectedFields.title = lines[0].substring(0, 200);
            }

            const meetingPatterns = [
                /Meeting\s+No\.?\s*([A-Z0-9\-]+)/i,
                /Meeting\s+([A-Z0-9\-]+)/i,
                /Meeting\s+Number\s+([A-Z0-9\-]+)/i,
                /MINUTES\s+NO\.?\s*([A-Z0-9\-]+)/i
            ];

            for (const pattern of meetingPatterns) {
                const match = text.match(pattern);
                if (match && match[1]) {
                    detectedFields.meetingNumber = match[1];
                    break;
                }
            }

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

            detectedFields.dates = [...new Set(detectedFields.dates)];
            return detectedFields;
        }

        // Function to update form fields with detected data
        function updateFormWithDetectedData(detectedFields) {
            if (detectedFields.title && !document.getElementById('title').value) {
                document.getElementById('title').value = detectedFields.title;
                document.getElementById('title').classList.add('field-highlight', 'detected-field');
            }

            if (detectedFields.meetingNumber && !document.getElementById('session_number').value) {
                document.getElementById('session_number').value = detectedFields.meetingNumber;
                document.getElementById('session_number').classList.add('field-highlight', 'detected-field');
            }

            if (detectedFields.dates.length > 0) {
                const today = new Date().toISOString().split('T')[0];

                if (!document.getElementById('meeting_date').value) {
                    const meetingDate = parseDate(detectedFields.dates[0]);
                    if (meetingDate) {
                        document.getElementById('meeting_date').value = meetingDate;
                        document.getElementById('meeting_date').classList.add('field-highlight', 'detected-field');
                    }
                }

                if (!document.getElementById('date_posted').value || document.getElementById('date_posted').value === today) {
                    const datePosted = detectedFields.dates.length > 1 ?
                        parseDate(detectedFields.dates[1]) : parseDate(detectedFields.dates[0]);
                    if (datePosted) {
                        document.getElementById('date_posted').value = datePosted;
                        document.getElementById('date_posted').classList.add('field-highlight', 'detected-field');
                    }
                }
            }

            if (detectedFields.content && !document.getElementById('content').value) {
                document.getElementById('content').value = detectedFields.content;
                document.getElementById('content').dispatchEvent(new Event('input', { bubbles: true }));
                if (typeof window.efindSyncTiptapFromTextarea === 'function') {
                    window.efindSyncTiptapFromTextarea('content');
                }
                document.getElementById('content').classList.add('field-highlight', 'detected-field');
            }
        }

        // Function to parse various date formats to YYYY-MM-DD
        function parseDate(dateString) {
            try {
                const date = new Date(dateString);
                if (!isNaN(date.getTime())) {
                    return date.toISOString().split('T')[0];
                }

                const formats = [
                    /(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/,
                    /(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/,
                    /(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}\b/gi,
                    /(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},?\s+\d{4}\b/gi
                ];

                for (const format of formats) {
                    const match = dateString.match(format);
                    if (match) {
                        let year, month, day;

                        if (match[1].length === 4) {
                            year = match[1];
                            month = match[2].padStart(2, '0');
                            day = match[3].padStart(2, '0');
                        } else if (isNaN(match[1])) {
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

        // Function to auto-fill form fields based on extracted text
        function autoFillFormFields(text, formType = 'add') {
            if (!text) return;

            const prefix = formType === 'add' ? '' : 'edit';
            const titleField = document.getElementById(prefix + 'Title');
            const meetingNumberField = document.getElementById(prefix + 'MeetingNumber');
            const datePostedField = document.getElementById(prefix + 'DatePosted');
            const meetingDateField = document.getElementById(prefix + 'MeetingDate');
            const contentField = document.getElementById(prefix + 'Content');

            if (titleField) {
                const lines = text.split('\n').filter(line => line.trim().length > 0);
                if (lines.length > 0) {
                    titleField.value = lines[0].substring(0, 200);
                }
            }

            if (contentField) {
                contentField.value = text;
                contentField.dispatchEvent(new Event('input', { bubbles: true }));
                if (typeof window.efindSyncTiptapFromTextarea === 'function' && contentField.id) {
                    window.efindSyncTiptapFromTextarea(contentField.id);
                }
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
                if (meetingDateField) meetingDateField.value = foundDates[1];
            } else if (foundDates.length === 1) {
                if (datePostedField) datePostedField.value = foundDates[0];
                if (meetingDateField) meetingDateField.value = foundDates[0];
            }

            const meetingPatterns = [
                /Meeting\s+No\.?\s*([A-Z0-9\-]+)/i,
                /Meeting\s+([A-Z0-9\-]+)/i,
                /Meeting\s+Number\s+([A-Z0-9\-]+)/i
            ];

            for (const pattern of meetingPatterns) {
                const match = text.match(pattern);
                if (match && match[1] && meetingNumberField) {
                    meetingNumberField.value = match[1];
                    break;
                }
            }
        }

        // Reset form when modal is closed or cancelled
        document.getElementById('addMinuteModal').addEventListener('hide.bs.modal', function () {
            const form = document.getElementById('addMinuteForm');
            if (form) {
                form.reset();
                if (typeof window.efindResetDuplicateSubmitState === 'function') {
                    window.efindResetDuplicateSubmitState('addMinuteForm');
                }
                // Clear file input specifically
                const fileInput = document.getElementById('image_file');
                if (fileInput) {
                    fileInput.value = '';
                }
                // Hide OCR processing indicator if visible
                const ocrProcessing = document.getElementById('ocrProcessing');
                if (ocrProcessing) {
                    ocrProcessing.style.display = 'none';
                }
            }
        });
    </script>
    <footer class="bg-white p-3">
        <?php include(__DIR__ . '/includes/footer.php'); ?>
    </footer>
    
    <!-- AI Chatbot Widget -->
    <?php include(__DIR__ . '/includes/chatbot_widget.php'); ?>
</body>
</html>

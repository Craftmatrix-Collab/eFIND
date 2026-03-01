<?php
include('includes/auth.php');
include('includes/config.php');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_type = $_POST['document_type'];
    $title = $_POST['title'];
    $reference_number = $_POST['reference_number'] ?? '';
    $date_issued = $_POST['date_issued'];
    $description = $_POST['description'];
    $updated_by = $_SESSION['username'] ?? $_SESSION['staff_username'] ?? 'admin';

    // Validate required fields
    if (empty($title) || empty($date_issued) || empty($_FILES["document_file"]["name"])) {
        $_SESSION['error'] = "Please fill all required fields";
        header("Location: add_documents.php");
        exit();
    }

    // Create uploads directory if it doesn't exist
    $target_dir = "uploads/documents/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    // File upload handling
    $file_name = basename($_FILES["document_file"]["name"]);
    $target_file = $target_dir . uniqid() . '_' . $file_name;
    $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // Check file size (limit to 5MB)
    if ($_FILES["document_file"]["size"] > 5000000) {
        $_SESSION['error'] = "Sorry, your file is too large (max 5MB)";
        header("Location: add_documents.php");
        exit();
    }

    // Allow certain file formats
    $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    if (!in_array($file_type, $allowed_types)) {
        $_SESSION['error'] = "Sorry, only PDF, Word, Excel & PowerPoint files are allowed";
        header("Location: add_documents.php");
        exit();
    }

    // Validate actual MIME type (not just extension)
    $allowed_mimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected_mime = finfo_file($finfo, $_FILES["document_file"]["tmp_name"]);
    finfo_close($finfo);
    if (!in_array($detected_mime, $allowed_mimes)) {
        $_SESSION['error'] = "Invalid file content detected. Only PDF, Word, Excel & PowerPoint files are allowed.";
        header("Location: add_documents.php");
        exit();
    }

    // Require document number for executive_orders and resolutions
    if (in_array($document_type, ['executive_order', 'resolution']) && empty($reference_number)) {
        $_SESSION['error'] = "Reference/document number is required for executive_orders and resolutions.";
        header("Location: add_documents.php");
        exit();
    }

    // Try to upload file
    if (move_uploaded_file($_FILES["document_file"]["tmp_name"], $target_file)) {
        // Insert into appropriate table based on document type
        try {
            $hasExecutiveOrderUpdatedByColumn = false;
            switch ($document_type) {
                case 'executive_order':
                    $updatedByColumnCheck = $conn->query("SHOW COLUMNS FROM executive_orders LIKE 'updated_by'");
                    if ($updatedByColumnCheck && $updatedByColumnCheck->num_rows > 0) {
                        $hasExecutiveOrderUpdatedByColumn = true;
                    }
                    // executive_order_number is NOT NULL; use reference_number as fallback
                    if ($hasExecutiveOrderUpdatedByColumn) {
                        $sql = "INSERT INTO executive_orders (title, executive_order_number, reference_number, date_issued, description, file_path, uploaded_by, updated_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    } else {
                        $sql = "INSERT INTO executive_orders (title, executive_order_number, reference_number, date_issued, description, file_path, uploaded_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                    }
                    break;
                case 'resolution':
                    // resolution_number, content, image_path are NOT NULL
                    $sql = "INSERT INTO resolutions (title, resolution_number, reference_number, date_issued, description, content, image_path, uploaded_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    break;
                case 'meeting_minutes':
                    // Correct table: minutes_of_meeting; session_number, content, meeting_date are NOT NULL
                    $sql = "INSERT INTO minutes_of_meeting (title, session_number, date_posted, meeting_date, content, image_path, reference_number, uploaded_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    break;
                default:
                    $_SESSION['error'] = "Invalid document type selected";
                    header("Location: add_documents.php");
                    exit();
            }

            $stmt = $conn->prepare($sql);

            if ($document_type === 'executive_order') {
                if ($hasExecutiveOrderUpdatedByColumn) {
                    $stmt->bind_param("ssssssss", $title, $reference_number, $reference_number, $date_issued, $description, $target_file, $updated_by, $updated_by);
                } else {
                    $stmt->bind_param("sssssss", $title, $reference_number, $reference_number, $date_issued, $description, $target_file, $updated_by);
                }
            } elseif ($document_type === 'resolution') {
                $stmt->bind_param("ssssssss", $title, $reference_number, $reference_number, $date_issued, $description, $description, $target_file, $updated_by);
            } else {
                $session_number = $_POST['session_number'] ?? '';
                $meeting_date   = $_POST['meeting_date'] ?? $date_issued;
                $stmt->bind_param("ssssssss", $title, $session_number, $date_issued, $meeting_date, $description, $target_file, $reference_number, $updated_by);
            }

            if ($stmt->execute()) {
                $_SESSION['success'] = "Document added successfully!";
                header("Location: dashboard.php");
                exit();
            } else {
                // Delete the uploaded file if database insert failed
                unlink($target_file);
                $_SESSION['error'] = "Error adding document to database";
            }
        } catch (Exception $e) {
            // Delete the uploaded file if there was an error
            if (file_exists($target_file)) {
                unlink($target_file);
            }
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Sorry, there was an error uploading your file.";
    }
    header("Location: add_documents.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Document - eFIND System</title>
    <link rel="icon" type="image/png" href="images/eFind_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&display=swap" rel="stylesheet">
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
        }
        
        .document-form-container {
            padding: 20px;
            margin-top: 80px;
        }
        
        .document-form {
            background: var(--white);
            border-radius: 16px;
            padding: 30px;
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
        }
        
        .form-header {
            color: var(--secondary-blue);
            border-bottom: 2px solid var(--light-blue);
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-family: 'Montserrat', sans-serif;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--dark-gray);
        }
        
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        
        .btn-submit {
            background-color: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-submit:hover {
            background-color: var(--secondary-blue);
            transform: translateY(-2px);
        }
        
        .file-upload-container {
            border: 2px dashed var(--medium-gray);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .file-upload-container:hover {
            border-color: var(--primary-blue);
            background-color: var(--light-blue);
        }
        
        .file-upload-icon {
            font-size: 2rem;
            color: var(--primary-blue);
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .document-form-container {
                margin-top: 70px;
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <?php include('includes/navbar.php'); ?>

    <div class="document-form-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="document-form">
                        <h3 class="form-header"><i class="fas fa-file-upload me-2"></i>Add New Document</h3>
                        
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
                        <?php endif; ?>
                        
                        <form action="add_documents.php" method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="document_type" class="form-label required-field">Document Type</label>
                                <select class="form-select" id="document_type" name="document_type" required>
                                    <option value="">Select document type</option>
                                    <option value="executive_order">Executive Order</option>
                                    <option value="resolution">Resolution</option>
                                    <option value="meeting_minutes">Meeting Minutes</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="title" class="form-label required-field">Title</label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>
                            
                            <div class="mb-3" id="reference_number_field">
                                <label for="reference_number" class="form-label" id="reference_number_label">Reference Number</label>
                                <input type="text" class="form-control" id="reference_number" name="reference_number">
                            </div>

                            <div class="mb-3 d-none" id="session_number_field">
                                <label for="session_number" class="form-label required-field">Session Number</label>
                                <input type="text" class="form-control" id="session_number" name="session_number">
                            </div>
                            
                            <div class="mb-3">
                                <label for="date_issued" class="form-label required-field">
                                    <?php echo isset($_POST['document_type']) && $_POST['document_type'] == 'meeting_minutes' ? 'Date Posted' : 'Date Issued'; ?>
                                </label>
                                <input type="date" class="form-control" id="date_issued" name="date_issued" required>
                            </div>

                            <div class="mb-3 d-none" id="meeting_date_field">
                                <label for="meeting_date" class="form-label required-field">Meeting Date</label>
                                <input type="date" class="form-control" id="meeting_date" name="meeting_date">
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label required-field">Document File</label>
                                <div class="file-upload-container" onclick="document.getElementById('document_file').click()">
                                    <div class="file-upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <p class="mb-1">Click to upload or drag and drop</p>
                                    <p class="small text-muted">PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX (Max. 5MB)</p>
                                    <input type="file" id="document_file" name="document_file" class="d-none" required>
                                    <div id="file-name" class="mt-2 text-primary fw-bold"></div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-submit">
                                    <i class="fas fa-save me-1"></i> Save Document
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Show/hide reference number field based on document type
            $('#document_type').change(function() {
                const type = $(this).val();
                if (type === 'meeting_minutes') {
                    $('#reference_number_field').hide();
                    $('#reference_number').removeAttr('required');
                    $('#session_number_field').removeClass('d-none');
                    $('#session_number').attr('required', 'required');
                    $('#meeting_date_field').removeClass('d-none');
                    $('#meeting_date').attr('required', 'required');
                    $('label[for="date_issued"]').text('Date Posted');
                    $('#reference_number_label').text('Reference Number');
                } else {
                    $('#reference_number_field').show();
                    $('#reference_number').attr('required', 'required');
                    $('#session_number_field').addClass('d-none');
                    $('#session_number').removeAttr('required');
                    $('#meeting_date_field').addClass('d-none');
                    $('#meeting_date').removeAttr('required');
                    $('label[for="date_issued"]').text('Date Issued');
                    if (type === 'executive_order') {
                        $('#reference_number_label').text('Executive Order Number *');
                    } else if (type === 'resolution') {
                        $('#reference_number_label').text('Resolution Number *');
                    } else {
                        $('#reference_number_label').text('Reference Number');
                        $('#reference_number').removeAttr('required');
                    }
                }
            });
            
            // Display selected file name
            $('#document_file').change(function() {
                if (this.files && this.files[0]) {
                    $('#file-name').text(this.files[0].name);
                }
            });
            
            // Drag and drop functionality
            const fileUploadContainer = $('.file-upload-container')[0];
            
            fileUploadContainer.addEventListener('dragover', (e) => {
                e.preventDefault();
                fileUploadContainer.style.borderColor = '#4361ee';
                fileUploadContainer.style.backgroundColor = '#e8f0fe';
            });
            
            fileUploadContainer.addEventListener('dragleave', () => {
                fileUploadContainer.style.borderColor = '#8d99ae';
                fileUploadContainer.style.backgroundColor = 'transparent';
            });
            
            fileUploadContainer.addEventListener('drop', (e) => {
                e.preventDefault();
                fileUploadContainer.style.borderColor = '#8d99ae';
                fileUploadContainer.style.backgroundColor = 'transparent';
                
                if (e.dataTransfer.files.length) {
                    document.getElementById('document_file').files = e.dataTransfer.files;
                    $('#file-name').text(e.dataTransfer.files[0].name);
                }
            });
        });
    </script>
</body>
</html>

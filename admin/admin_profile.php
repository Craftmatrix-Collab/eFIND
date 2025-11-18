<?php
// Start the session
session_start();

// Check if the user is logged in, if not redirect to login page
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Include database configuration
include('includes/config.php');

// Fetch admin details from the database
$user_id = $_SESSION['user_id'];
$query = "SELECT * FROM admin_users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #f8f9fa;
            --accent-color: #ffd166;
            --dark-font-color: #212529;
            --light-font-color: #f8f9fa;
            --border-color: #e9ecef;
        }

        body {
            background-color: var(--secondary-color);
            color: var(--dark-font-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .card {
            border: none;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }

        .card-header {
            background-color: var(--primary-color);
            color: var(--light-font-color);
            padding: 1rem;
        }

        .card-body {
            padding: 2rem;
        }

        .profile-icon {
            font-size: 6rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .form-label {
            font-weight: bold;
            color: var(--dark-font-color);
        }

        .form-control-static {
            background-color: var(--secondary-color);
            padding: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            width: 100%;
            margin-top: 1rem;
        }

        .btn-primary:hover {
            background-color: #3a56d4;
            border-color: #3a56d4;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="text-center">Admin Profile</h3>
                    </div>
                    <div class="card-body text-center">
                        <div class="mb-4">
                            <i class="fas fa-user-circle profile-icon"></i>
                        </div>
                        <div class="mb-3 text-start">
                            <label class="form-label">Name:</label>
                            <p class="form-control-static"><?php echo htmlspecialchars($user['name']); ?></p>
                        </div>
                        <div class="mb-3 text-start">
                            <label class="form-label">Email:</label>
                            <p class="form-control-static"><?php echo htmlspecialchars($user['email']); ?></p>
                        </div>
                        <!-- Add more fields as necessary -->
                        <div class="text-center">
                            <a href="edit_profile.php" class="btn btn-primary">Edit Profile</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

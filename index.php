<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('admin/includes/config.php');
include('admin/includes/auth.php');

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: admin/dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eFIND Landing Page</title>
    <link rel="icon" type="image/png" href="admin/images/eFind_logo.png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #1f4e79 0%, #2d7fb5 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .container {
            width: 100%;
            max-width: 860px;
            text-align: center;
        }

        h1 {
            font-size: 2.2rem;
            margin-bottom: 8px;
        }

        p {
            opacity: 0.95;
            margin-bottom: 28px;
        }

        .button-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            justify-items: center;
        }

        .square-button {
            width: 220px;
            height: 220px;
            border-radius: 16px;
            text-decoration: none;
            color: #1f4e79;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 1.3rem;
            font-weight: bold;
            padding: 16px;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.22);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .square-button:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 34px rgba(0, 0, 0, 0.26);
        }

        .square-button:focus-visible {
            outline: 3px solid #ffd166;
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <main class="container">
        <h1>eFIND</h1>
        <p>Select a module to continue.</p>
        <div class="button-grid">
            <a class="square-button" href="/admin/login.php?redirect=%2Fadmin%2Fdashboard.php">Document Archiving</a>
        </div>
    </main>
</body>
</html>

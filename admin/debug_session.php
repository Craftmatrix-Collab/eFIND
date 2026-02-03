<?php
session_start();
include('includes/config.php');

echo "<h2>Session Debug Information</h2>";
echo "<h3>Current Session Variables:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Login Status Checks:</h3>";
echo "admin_logged_in: " . (isset($_SESSION['admin_logged_in']) ? 'YES (' . $_SESSION['admin_logged_in'] . ')' : 'NO') . "<br>";
echo "staff_logged_in: " . (isset($_SESSION['staff_logged_in']) ? 'YES (' . $_SESSION['staff_logged_in'] . ')' : 'NO') . "<br>";
echo "role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET') . "<br>";
echo "user_id: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET') . "<br>";
echo "admin_id: " . (isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : 'NOT SET') . "<br>";

// Check if the user exists in admin_users table
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    echo "<h3>Database Check for user_id = $user_id:</h3>";
    
    // Check in admin_users
    $stmt = $conn->prepare("SELECT id, username, full_name, 'admin' as source FROM admin_users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo "<strong>Found in admin_users table:</strong><br>";
        echo "<pre>";
        print_r($row);
        echo "</pre>";
    } else {
        echo "NOT found in admin_users table<br>";
    }
    $stmt->close();
    
    // Check in users (staff) table
    $stmt = $conn->prepare("SELECT id, username, full_name, role, 'users' as source FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo "<strong>Found in users (staff) table:</strong><br>";
        echo "<pre>";
        print_r($row);
        echo "</pre>";
    } else {
        echo "NOT found in users table<br>";
    }
    $stmt->close();
}

// Check username if set
if (isset($_SESSION['admin_username'])) {
    $username = $_SESSION['admin_username'];
    echo "<h3>Database Check for username = '$username':</h3>";
    
    // Check in admin_users
    $stmt = $conn->prepare("SELECT id, username, full_name FROM admin_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo "<strong>Found '$username' in admin_users table:</strong><br>";
        echo "<pre>";
        print_r($row);
        echo "</pre>";
    }
    $stmt->close();
    
    // Check in users
    $stmt = $conn->prepare("SELECT id, username, full_name, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo "<strong>Also found '$username' in users (staff) table:</strong><br>";
        echo "<pre>";
        print_r($row);
        echo "</pre>";
        echo "<div style='background:yellow; padding:10px; margin:10px 0;'>";
        echo "<strong>⚠️ WARNING: Username exists in BOTH tables! This may cause confusion.</strong>";
        echo "</div>";
    }
    $stmt->close();
}

echo "<h3>Recommendations:</h3>";
echo "<ul>";
echo "<li>If you are logged in as superadmin/admin, the 'admin_logged_in' should be true and 'role' should be 'admin'</li>";
echo "<li>Check if your admin username exists in both admin_users AND users tables</li>";
echo "<li>If username exists in both tables, remove it from the users table or rename it</li>";
echo "<li>The system checks admin_users table first, then users table during login</li>";
echo "</ul>";

$conn->close();
?>

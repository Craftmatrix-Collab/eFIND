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

// Check if the user exists in users table
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    echo "<h3>Database Check for user_id = $user_id:</h3>";
    
    // Check in users table
    $stmt = $conn->prepare("SELECT id, username, full_name, role, 'users' as source FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo "<strong>Found in users table:</strong><br>";
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
    
    // Check in users
    $stmt = $conn->prepare("SELECT id, username, full_name, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo "<strong>Found '$username' in users table:</strong><br>";
        echo "<pre>";
        print_r($row);
        echo "</pre>";
    } else {
        echo "NOT found in users table<br>";
    }
    $stmt->close();
}

echo "<h3>Recommendations:</h3>";
echo "<ul>";
echo "<li>If you are logged in as superadmin/admin, the 'admin_logged_in' should be true and role should be admin or superadmin.</li>";
echo "<li>All account lookups should now resolve from the users table.</li>";
echo "<li>Use role values (superadmin/admin/staff) to confirm account privileges.</li>";
echo "</ul>";

$conn->close();
?>

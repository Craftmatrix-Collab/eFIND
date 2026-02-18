<?php
// Debug script for admin profile loading issue
session_start();

header('Content-Type: text/html; charset=utf-8');
echo '<h2>Admin Profile Debug Report</h2>';
echo '<style>
    body { font-family: monospace; padding: 20px; background: #f5f5f5; }
    .section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #4361ee; }
    .error { color: red; font-weight: bold; }
    .success { color: green; font-weight: bold; }
    .warning { color: orange; font-weight: bold; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
</style>';

// 1. Check Session Status
echo '<div class="section">';
echo '<h3>1. Session Status</h3>';
echo '<pre>';
echo 'Session Status: ' . (session_status() === PHP_SESSION_ACTIVE ? '<span class="success">ACTIVE ✓</span>' : '<span class="error">INACTIVE ✗</span>') . "\n";
echo 'Session ID: ' . session_id() . "\n";
echo '</pre>';
echo '</div>';

// 2. Check Session Variables
echo '<div class="section">';
echo '<h3>2. Session Variables</h3>';
echo '<pre>';
if (empty($_SESSION)) {
    echo '<span class="error">⚠ SESSION IS EMPTY!</span>' . "\n";
} else {
    echo "All Session Variables:\n";
    foreach ($_SESSION as $key => $value) {
        if (is_array($value) || is_object($value)) {
            echo "$key => " . print_r($value, true) . "\n";
        } else {
            echo "$key => " . htmlspecialchars((string)$value) . "\n";
        }
    }
}

echo "\n--- Key Session Variables Check ---\n";
echo 'admin_id: ' . (isset($_SESSION['admin_id']) ? '<span class="success">' . $_SESSION['admin_id'] . ' ✓</span>' : '<span class="error">NOT SET ✗</span>') . "\n";
echo 'user_id: ' . (isset($_SESSION['user_id']) ? '<span class="success">' . $_SESSION['user_id'] . ' ✓</span>' : '<span class="error">NOT SET ✗</span>') . "\n";
echo 'admin_username: ' . (isset($_SESSION['admin_username']) ? '<span class="success">' . $_SESSION['admin_username'] . ' ✓</span>' : '<span class="error">NOT SET ✗</span>') . "\n";
echo 'full_name: ' . (isset($_SESSION['full_name']) ? '<span class="success">' . $_SESSION['full_name'] . ' ✓</span>' : '<span class="error">NOT SET ✗</span>') . "\n";
echo '</pre>';
echo '</div>';

// 3. Check Database Connection
echo '<div class="section">';
echo '<h3>3. Database Connection</h3>';
echo '<pre>';
try {
    include 'includes/config.php';
    
    if ($conn && $conn->ping()) {
        echo '<span class="success">Database Connected ✓</span>' . "\n";
        echo 'Server Info: ' . $conn->server_info . "\n";
        echo 'Host Info: ' . $conn->host_info . "\n";
    } else {
        echo '<span class="error">Database Not Connected ✗</span>' . "\n";
    }
} catch (Exception $e) {
    echo '<span class="error">Database Error: ' . $e->getMessage() . ' ✗</span>' . "\n";
}
echo '</pre>';
echo '</div>';

// 4. Check User Data Fetch
echo '<div class="section">';
echo '<h3>4. User Data Fetch Test</h3>';
echo '<pre>';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo '<span class="error">✗ NOT AUTHENTICATED - No admin_id or user_id in session</span>' . "\n";
    echo "\nThis is why admin_profile_content.php shows 'loading...'!\n";
    echo "The AJAX call likely returns an auth error that the modal doesn't handle properly.\n";
} else {
    try {
        $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
        $is_admin = isset($_SESSION['admin_id']);
        $table = $is_admin ? 'admin_users' : 'users';
        
        echo "User ID: $user_id\n";
        echo "User Type: " . ($is_admin ? 'Admin' : 'Staff') . "\n";
        echo "Table: $table\n\n";
        
        $query = "SELECT id, full_name, username, email, contact_number, profile_picture, last_login, created_at, updated_at
                  FROM $table
                  WHERE id = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            echo '<span class="error">Query Prepare Failed: ' . $conn->error . ' ✗</span>' . "\n";
        } else {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if (!$user) {
                echo '<span class="error">✗ NO USER FOUND with ID: ' . $user_id . '</span>' . "\n";
            } else {
                echo '<span class="success">✓ User Found Successfully!</span>' . "\n\n";
                echo "User Data:\n";
                foreach ($user as $key => $value) {
                    if ($key === 'password') continue; // Skip password
                    echo "  $key => " . htmlspecialchars((string)$value) . "\n";
                }
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        echo '<span class="error">Exception: ' . $e->getMessage() . ' ✗</span>' . "\n";
    }
}
echo '</pre>';
echo '</div>';

// 5. Test admin_profile_content.php directly
echo '<div class="section">';
echo '<h3>5. Test admin_profile_content.php Output</h3>';
echo '<pre>';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo '<span class="warning">Skipped - Not authenticated</span>' . "\n";
} else {
    echo "Capturing output from admin_profile_content.php...\n\n";
    ob_start();
    try {
        include 'admin_profile_content.php';
        $content = ob_get_clean();
        
        if (strpos($content, 'Unauthorized access') !== false) {
            echo '<span class="error">✗ Auth Error Detected in Output</span>' . "\n";
        } elseif (strpos($content, 'alert-danger') !== false) {
            echo '<span class="error">✗ Error Alert Detected in Output</span>' . "\n";
        } elseif (strlen($content) < 100) {
            echo '<span class="error">✗ Output Too Short (Only ' . strlen($content) . ' bytes)</span>' . "\n";
        } else {
            echo '<span class="success">✓ Content Generated Successfully (' . strlen($content) . ' bytes)</span>' . "\n";
        }
        
        echo "\nFirst 500 characters of output:\n";
        echo htmlspecialchars(substr($content, 0, 500));
    } catch (Exception $e) {
        ob_end_clean();
        echo '<span class="error">Exception: ' . $e->getMessage() . ' ✗</span>' . "\n";
    }
}
echo '</pre>';
echo '</div>';

// 6. Check Browser/Request Info
echo '<div class="section">';
echo '<h3>6. Request Information</h3>';
echo '<pre>';
echo 'Request Method: ' . $_SERVER['REQUEST_METHOD'] . "\n";
echo 'Request URI: ' . $_SERVER['REQUEST_URI'] . "\n";
echo 'User Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A') . "\n";
echo 'Remote Address: ' . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n";
echo 'Referer: ' . ($_SERVER['HTTP_REFERER'] ?? 'N/A') . "\n";
echo '</pre>';
echo '</div>';

// 7. Diagnosis & Recommendations
echo '<div class="section">';
echo '<h3>7. Diagnosis & Recommendations</h3>';
echo '<pre>';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    echo '<span class="error">PRIMARY ISSUE FOUND:</span>' . "\n\n";
    echo "The session is either:\n";
    echo "  1. Not being preserved across requests (session storage issue)\n";
    echo "  2. Being destroyed/cleared somewhere\n";
    echo "  3. User is not actually logged in\n\n";
    
    echo "RECOMMENDED ACTIONS:\n";
    echo "  ✓ Check if you're logged in (go to login.php and login)\n";
    echo "  ✓ Check browser cookies - session cookie should be present\n";
    echo "  ✓ Check session.save_path permissions\n";
    echo "  ✓ Look for session_destroy() calls in code\n";
    echo "  ✓ Check if there are multiple PHP.ini files (CLI vs Web)\n";
} elseif (!isset($user) || empty($user)) {
    echo '<span class="error">SECONDARY ISSUE FOUND:</span>' . "\n\n";
    echo "Session exists but user data not found in database.\n\n";
    
    echo "RECOMMENDED ACTIONS:\n";
    echo "  ✓ Verify user still exists in database\n";
    echo "  ✓ Check if session user_id/admin_id matches database\n";
    echo "  ✓ Re-login to refresh session\n";
} else {
    echo '<span class="success">✓ All checks passed! Profile should be working.</span>' . "\n\n";
    echo "If modal still shows 'loading...', check:\n";
    echo "  - Browser console for JavaScript errors\n";
    echo "  - Network tab for AJAX request status\n";
    echo "  - Modal's error handling code in navbar.php\n";
}

echo '</pre>';
echo '</div>';

echo '<div class="section">';
echo '<h3>Quick Access</h3>';
echo '<a href="index.php">← Back to Dashboard</a> | ';
echo '<a href="login.php">Go to Login</a> | ';
echo '<a href="logout.php">Logout</a>';
echo '</div>';
?>

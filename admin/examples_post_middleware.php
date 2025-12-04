<?php
/**
 * POST Middleware Usage Examples
 * This file demonstrates how to use the POST middleware in your admin endpoints
 */

require_once 'includes/post_middleware.php';
require_once 'includes/config.php';
require_once 'includes/auth.php';

// ============================================================================
// EXAMPLE 1: Simple POST-only endpoint
// ============================================================================
function example1_simple_post_only() {
    // Just require POST method
    requirePostMethod();
    
    // Your code here
    $data = $_POST;
    // Process data...
}

// ============================================================================
// EXAMPLE 2: POST with CSRF protection
// ============================================================================
function example2_post_with_csrf() {
    // Require POST + CSRF token validation
    requirePostMiddleware([], true);
    
    // Your code here
    $username = sanitizePost('username');
    // Process...
}

// ============================================================================
// EXAMPLE 3: POST with required fields
// ============================================================================
function example3_post_with_required_fields() {
    // Require POST + CSRF + specific fields
    requirePostMiddleware(['username', 'password'], true);
    
    // Fields are guaranteed to exist and not be empty
    $username = sanitizePost('username');
    $password = $_POST['password']; // Don't sanitize passwords
    
    // Process login...
}

// ============================================================================
// EXAMPLE 4: API endpoint with JSON
// ============================================================================
function example4_json_api() {
    requirePostMethod();
    requireJsonContent();
    
    $data = getJsonPostData();
    
    // Process JSON data
    sendJsonResponse([
        'success' => true,
        'message' => 'Data received',
        'data' => $data
    ]);
}

// ============================================================================
// EXAMPLE 5: Complete protected endpoint
// ============================================================================
function example5_complete_protection() {
    // 1. Check authentication first
    if (!isLoggedIn()) {
        sendJsonResponse([
            'success' => false,
            'error' => 'Authentication required'
        ], 401);
    }
    
    // 2. Require POST with CSRF and fields
    requirePostMiddleware(['action', 'data'], true);
    
    // 3. Check permissions
    if (!isAdmin()) {
        sendJsonResponse([
            'success' => false,
            'error' => 'Admin access required'
        ], 403);
    }
    
    // 4. Process the request
    $action = sanitizePost('action');
    $data = sanitizePost('data');
    
    // Your business logic here...
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Action completed'
    ]);
}

// ============================================================================
// EXAMPLE 6: Login endpoint (no CSRF on login page)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && basename(__FILE__) === 'example_login.php') {
    // Login doesn't need CSRF (user doesn't have session yet)
    requirePostMiddleware(['username', 'password'], false);
    
    $username = sanitizePost('username');
    $password = $_POST['password'];
    
    // Perform login...
    sendJsonResponse([
        'success' => true,
        'redirect' => 'dashboard.php'
    ]);
}

?>

<!-- HTML FORM EXAMPLE with CSRF Token -->
<!DOCTYPE html>
<html>
<head>
    <title>POST Middleware Example</title>
</head>
<body>
    <h1>Example Form with CSRF Protection</h1>
    
    <form method="POST" action="process.php">
        <!-- Include CSRF token in all forms -->
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <label>Username:</label>
        <input type="text" name="username" required>
        
        <label>Email:</label>
        <input type="email" name="email" required>
        
        <button type="submit">Submit</button>
    </form>
    
    <h2>AJAX Example with CSRF</h2>
    <script>
    // JavaScript/jQuery AJAX example
    function submitData() {
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        formData.append('username', 'john');
        formData.append('email', 'john@example.com');
        
        fetch('api_endpoint.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('Success:', data);
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
    
    // JSON API example
    function submitJson() {
        fetch('api_endpoint.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                csrf_token: '<?php echo generateCsrfToken(); ?>',
                action: 'update',
                data: { name: 'John' }
            })
        })
        .then(response => response.json())
        .then(data => console.log(data));
    }
    </script>
</body>
</html>

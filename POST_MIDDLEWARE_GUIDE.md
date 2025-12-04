# POST Middleware Implementation Guide

## Overview
A comprehensive middleware system for enforcing POST-only requests, CSRF protection, and input validation in the eFIND admin panel.

## Files Created

### 1. `/admin/includes/post_middleware.php`
Core middleware library with security functions.

### 2. `/admin/examples_post_middleware.php`
Complete examples and usage patterns.

### 3. Updated `/admin/.htaccess`
Apache-level POST enforcement for sensitive endpoints.

---

## Quick Start

### Basic Usage - POST Only
```php
<?php
require_once 'includes/post_middleware.php';

// Enforce POST method
requirePostMethod();

// Your code here
$data = $_POST;
?>
```

### POST with CSRF Protection
```php
<?php
require_once 'includes/post_middleware.php';

// Enforce POST + CSRF validation
requirePostMiddleware([], true);

// Safe to process
$username = sanitizePost('username');
?>
```

### POST with Required Fields
```php
<?php
require_once 'includes/post_middleware.php';

// Enforce POST + CSRF + required fields
requirePostMiddleware(['username', 'email', 'action'], true);

// Fields are guaranteed to exist
$username = sanitizePost('username');
$email = sanitizePost('email');
?>
```

---

## Available Functions

### 1. `requirePostMethod()`
Enforces POST method. Returns 405 error for GET/other methods.

### 2. `requirePostMiddleware($fields, $checkCsrf)`
Complete middleware combining POST, CSRF, and field validation.
- `$fields`: Array of required field names
- `$checkCsrf`: Boolean, enable CSRF checking (default: true)

### 3. `generateCsrfToken()`
Generates and returns CSRF token for forms.

### 4. `validateCsrfToken()`
Validates CSRF token from POST data.

### 5. `requireCsrfToken()`
Validates CSRF or exits with 403 error.

### 6. `sanitizePost($key, $default)`
Safely retrieves and sanitizes POST data.

### 7. `requirePostFields($fields)`
Ensures required fields exist or exits with 400 error.

### 8. `requireJsonContent()`
Validates Content-Type is JSON.

### 9. `getJsonPostData()`
Parses and validates JSON POST body.

### 10. `sendJsonResponse($data, $statusCode)`
Sends standardized JSON response.

---

## HTML Form Integration

### Basic Form with CSRF
```html
<form method="POST" action="process.php">
    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
    
    <input type="text" name="username" required>
    <input type="email" name="email" required>
    
    <button type="submit">Submit</button>
</form>
```

### AJAX with CSRF
```javascript
const formData = new FormData();
formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
formData.append('username', 'john');

fetch('api_endpoint.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => console.log(data));
```

### JSON API with CSRF
```javascript
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
```

---

## Complete Examples

### Example 1: Login Endpoint (No CSRF)
```php
<?php
require_once 'includes/post_middleware.php';
require_once 'includes/config.php';

// Login doesn't need CSRF (user doesn't have session yet)
requirePostMiddleware(['username', 'password'], false);

$username = sanitizePost('username');
$password = $_POST['password']; // Don't sanitize passwords

// Perform authentication...

sendJsonResponse([
    'success' => true,
    'redirect' => 'dashboard.php'
]);
?>
```

### Example 2: Update Profile (Full Protection)
```php
<?php
require_once 'includes/post_middleware.php';
require_once 'includes/auth.php';

// Check authentication
if (!isLoggedIn()) {
    sendJsonResponse(['error' => 'Not authenticated'], 401);
}

// Enforce POST + CSRF + required fields
requirePostMiddleware(['full_name', 'email'], true);

// Safe to process
$fullName = sanitizePost('full_name');
$email = sanitizePost('email');

// Update database...

sendJsonResponse(['success' => true]);
?>
```

### Example 3: JSON API Endpoint
```php
<?php
require_once 'includes/post_middleware.php';
require_once 'includes/auth.php';

// Check auth
if (!isAdmin()) {
    sendJsonResponse(['error' => 'Admin only'], 403);
}

// Require JSON content
requirePostMethod();
requireJsonContent();

$data = getJsonPostData();

// Validate data
if (!isset($data['action'])) {
    sendJsonResponse(['error' => 'Missing action'], 400);
}

// Process...

sendJsonResponse([
    'success' => true,
    'result' => $result
]);
?>
```

---

## Apache .htaccess Integration

The updated `.htaccess` file enforces POST method at the server level:

```apache
# Force POST method for sensitive endpoints
<FilesMatch "^(login|register|update_|add_|delete_|process_|api).*\.php$">
    <LimitExcept POST>
        Require all denied
    </LimitExcept>
</FilesMatch>
```

This applies to files matching:
- `login*.php`
- `register*.php`
- `update_*.php`
- `add_*.php`
- `delete_*.php`
- `process_*.php`
- `api*.php`

---

## Security Features

### ✅ Implemented
1. **POST-only enforcement** - Prevents GET-based attacks
2. **CSRF protection** - Prevents cross-site request forgery
3. **Input sanitization** - XSS protection
4. **Required field validation** - Data integrity
5. **JSON validation** - API security
6. **HTTP status codes** - Proper error reporting
7. **Apache-level enforcement** - Defense in depth

### 🔒 Additional Recommendations
1. Rate limiting (use mod_evasive)
2. Input length limits
3. SQL injection prevention (use prepared statements)
4. Session hijacking protection (already in auth.php)
5. IP-based blocking for failed attempts

---

## Migrating Existing Files

### Before (Old Code)
```php
<?php
session_start();
include 'includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$username = $_POST['username'];
$email = $_POST['email'];
// Process...
?>
```

### After (With Middleware)
```php
<?php
session_start();
include 'includes/config.php';
include 'includes/post_middleware.php';

requirePostMiddleware(['username', 'email'], true);

$username = sanitizePost('username');
$email = sanitizePost('email');
// Process...
?>
```

---

## Testing

### Test POST Enforcement
```bash
# Should fail with 405
curl -X GET http://localhost:7070/admin/update_password.php

# Should work
curl -X POST http://localhost:7070/admin/update_password.php \
  -d "csrf_token=xxx&current_password=xxx&new_password=xxx"
```

### Test CSRF Protection
```bash
# Should fail with 403
curl -X POST http://localhost:7070/admin/api.php \
  -d "action=test"

# Should work with valid token
curl -X POST http://localhost:7070/admin/api.php \
  -d "csrf_token=VALID_TOKEN&action=test"
```

---

## Error Responses

### 405 Method Not Allowed
```json
{
    "success": false,
    "error": "Method not allowed. Only POST requests are accepted."
}
```

### 403 Forbidden (CSRF)
```json
{
    "success": false,
    "error": "Invalid CSRF token. Please refresh and try again."
}
```

### 400 Bad Request (Missing Fields)
```json
{
    "success": false,
    "error": "Missing required fields: username, email"
}
```

### 415 Unsupported Media Type
```json
{
    "success": false,
    "error": "Content-Type must be application/json"
}
```

---

## Files to Update

Recommended files to migrate to POST middleware:

1. ✅ `update_password.php` - Already updated
2. `login.php` - Use without CSRF
3. `register.php` - Use without CSRF
4. `add_staff.php` - Use with CSRF
5. `add_documents.php` - Use with CSRF
6. `update_profile.php` - Use with CSRF
7. `process_ocr.php` - Use with CSRF
8. `api.php` - Use with CSRF + JSON

---

## Deployment Checklist

- [x] Create `post_middleware.php`
- [x] Update `.htaccess` with POST enforcement
- [x] Update `update_password.php` as example
- [x] Copy files to Docker container
- [x] Reload Apache configuration
- [ ] Add CSRF tokens to all forms
- [ ] Update remaining POST endpoints
- [ ] Test all forms and APIs
- [ ] Update frontend JavaScript
- [ ] Monitor error logs

---

## Support

For questions or issues:
1. Check `examples_post_middleware.php` for usage patterns
2. Review error logs: `docker logs efind-web-1`
3. Test with curl commands above
4. Verify CSRF tokens are included in forms

---

**Created:** 2025-12-04  
**Status:** Active  
**Version:** 1.0

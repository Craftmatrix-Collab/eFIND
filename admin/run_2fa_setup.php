<?php
// This script will set up the 2FA database tables
include('includes/config.php');

echo "Starting 2FA database setup...\n\n";

// Create two_factor_codes table
$sql1 = "CREATE TABLE IF NOT EXISTS two_factor_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('admin', 'staff') NOT NULL DEFAULT 'admin',
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_lookup (user_id, user_type),
    INDEX idx_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql1) === TRUE) {
    echo "✓ Table 'two_factor_codes' created successfully\n";
} else {
    echo "✗ Error creating two_factor_codes table: " . $conn->error . "\n";
}

// Check if email column exists
$result = $conn->query("SHOW COLUMNS FROM admin_users LIKE 'email'");
if ($result->num_rows == 0) {
    $sql2 = "ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) NULL AFTER username";
    if ($conn->query($sql2) === TRUE) {
        echo "✓ Column 'email' added to admin_users\n";
    } else {
        echo "✗ Error adding email column: " . $conn->error . "\n";
    }
} else {
    echo "✓ Column 'email' already exists in admin_users\n";
}

// Check if two_fa_enabled column exists
$result = $conn->query("SHOW COLUMNS FROM admin_users LIKE 'two_fa_enabled'");
if ($result->num_rows == 0) {
    $sql3 = "ALTER TABLE admin_users ADD COLUMN two_fa_enabled TINYINT(1) DEFAULT 1 AFTER email";
    if ($conn->query($sql3) === TRUE) {
        echo "✓ Column 'two_fa_enabled' added to admin_users\n";
    } else {
        echo "✗ Error adding two_fa_enabled column: " . $conn->error . "\n";
    }
} else {
    echo "✓ Column 'two_fa_enabled' already exists in admin_users\n";
}

echo "\n=== Current admin_users structure ===\n";
$result = $conn->query("DESCRIBE admin_users");
while ($row = $result->fetch_assoc()) {
    echo "  {$row['Field']} - {$row['Type']}\n";
}

echo "\n=== Current admin users ===\n";
$result = $conn->query("SELECT id, username, email, two_fa_enabled FROM admin_users");
while ($row = $result->fetch_assoc()) {
    echo "  ID: {$row['id']}, Username: {$row['username']}, Email: " . ($row['email'] ?? 'NOT SET') . ", 2FA: " . ($row['two_fa_enabled'] ? 'Enabled' : 'Disabled') . "\n";
}

echo "\n=== NEXT STEPS ===\n";
echo "1. Update admin email addresses:\n";
echo "   UPDATE admin_users SET email = 'your-email@example.com' WHERE username = 'admin';\n\n";
echo "2. Change Resend FROM email in includes/resend.php if you have a verified domain\n";
echo "3. Test login to receive 2FA code\n\n";
echo "Setup complete!\n";

$conn->close();

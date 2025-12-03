-- Run this SQL to set up the database for 2FA
-- Execute this in your MariaDB database

-- Step 1: Create the two_factor_codes table
CREATE TABLE IF NOT EXISTS two_factor_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('admin', 'staff') NOT NULL DEFAULT 'admin',
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_lookup (user_id, user_type),
    INDEX idx_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 2: Add email and two_fa_enabled columns to admin_users if they don't exist
ALTER TABLE admin_users 
ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL AFTER username,
ADD COLUMN IF NOT EXISTS two_fa_enabled TINYINT(1) DEFAULT 1 AFTER email;

-- Step 3: View current structure
SELECT * FROM admin_users;

-- Step 4: Update admin email addresses (REPLACE WITH YOUR ACTUAL ADMIN EMAILS)
-- Example:
-- UPDATE admin_users SET email = 'admin@example.com' WHERE username = 'admin';
-- UPDATE admin_users SET email = 'cjacedelfin10@gmail.com' WHERE username = 'Eys07';

SELECT 'Setup complete! Now update admin email addresses using the UPDATE command above.' AS message;

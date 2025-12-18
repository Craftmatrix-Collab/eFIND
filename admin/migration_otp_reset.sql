-- OTP Password Reset Migration
-- Add necessary columns to admin_users table for OTP functionality

-- Check if columns exist before adding them
-- Run this migration on your database

-- Add reset_token column if it doesn't exist
SET @dbname = 'barangay_poblacion_south';
SET @tablename = 'admin_users';
SET @columnname1 = 'reset_token';
SET @columnname2 = 'reset_expires';

-- Add reset_token column
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = @columnname1
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname1, ' VARCHAR(255) NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add reset_expires column
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = @columnname2
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname2, ' DATETIME NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Verify columns were added
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'barangay_poblacion_south'
    AND TABLE_NAME = 'admin_users'
    AND COLUMN_NAME IN ('reset_token', 'reset_expires');

-- Optional: Add index for faster lookups
-- CREATE INDEX idx_reset_token ON admin_users(reset_token);
-- CREATE INDEX idx_reset_expires ON admin_users(reset_expires);

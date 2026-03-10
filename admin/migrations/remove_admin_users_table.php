<?php

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

function removeAdminUsersRun(mysqli $conn, string $sql, string $label): void
{
    if (!$conn->query($sql)) {
        throw new RuntimeException("{$label} failed: {$conn->error}");
    }
    echo "[OK] {$label}\n";
}

function removeAdminUsersColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function removeAdminUsersTableType(mysqli $conn, string $table): ?string
{
    $stmt = $conn->prepare(
        "SELECT TABLE_TYPE
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return (string)($row['TABLE_TYPE'] ?? '');
}

function removeAdminUsersEnsureUsersColumns(mysqli $conn): void
{
    $columnMigrations = [
        'two_fa_enabled' => "ALTER TABLE `users` ADD COLUMN `two_fa_enabled` TINYINT(1) NULL DEFAULT 0 AFTER `email`",
        'verification_token' => "ALTER TABLE `users` ADD COLUMN `verification_token` VARCHAR(64) NULL DEFAULT NULL AFTER `email_verified`",
        'token_expiry' => "ALTER TABLE `users` ADD COLUMN `token_expiry` DATETIME NULL DEFAULT NULL AFTER `verification_token`",
    ];

    foreach ($columnMigrations as $column => $sql) {
        if (!removeAdminUsersColumnExists($conn, 'users', $column)) {
            removeAdminUsersRun($conn, $sql, "Add users.{$column}");
        }
    }
}

try {
    $usersType = removeAdminUsersTableType($conn, 'users');
    if ($usersType !== 'BASE TABLE') {
        throw new RuntimeException('users table is missing or not a base table.');
    }

    removeAdminUsersRun($conn, "SET SESSION sql_safe_updates = 0", "Disable sql_safe_updates");
    removeAdminUsersEnsureUsersColumns($conn);

    removeAdminUsersRun(
        $conn,
        "UPDATE `users` SET `role` = LOWER(TRIM(`role`)) WHERE `role` IS NOT NULL",
        "Normalize users.role casing"
    );
    removeAdminUsersRun(
        $conn,
        "UPDATE `users` SET `role` = 'staff' WHERE `role` IS NULL OR `role` = ''",
        "Set empty users.role to staff"
    );
    removeAdminUsersRun(
        $conn,
        "UPDATE `users` SET `role` = 'admin' WHERE `role` IN ('administrator')",
        "Normalize administrator role token"
    );
    removeAdminUsersRun(
        $conn,
        "UPDATE `users` SET `role` = 'superadmin' WHERE `role` IN ('super_admin', 'super-admin')",
        "Normalize superadmin role token"
    );

    $roleDefaultStmt = $conn->query(
        "SELECT COLUMN_DEFAULT
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'role'
         LIMIT 1"
    );
    $roleDefaultRow = $roleDefaultStmt ? $roleDefaultStmt->fetch_assoc() : null;
    $roleDefault = $roleDefaultRow['COLUMN_DEFAULT'] ?? null;
    if ($roleDefault !== 'admin') {
        removeAdminUsersRun(
            $conn,
            "ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'admin'",
            "Set users.role default to admin"
        );
    }

    $adminUsersType = removeAdminUsersTableType($conn, 'admin_users');
    if ($adminUsersType === 'BASE TABLE') {
        removeAdminUsersRun(
            $conn,
            "INSERT INTO `users` (
                `full_name`,
                `contact_number`,
                `email`,
                `username`,
                `password`,
                `role`,
                `profile_picture`,
                `last_login`,
                `created_at`,
                `updated_at`,
                `reset_token`,
                `reset_expires`,
                `email_verified`,
                `verification_token`,
                `token_expiry`,
                `failed_login_attempts`,
                `account_locked`,
                `account_locked_at`,
                `password_changed_at`,
                `failed_window_started_at`,
                `lockout_level`,
                `lockout_until`,
                `two_fa_enabled`
            )
            SELECT
                au.`full_name`,
                au.`contact_number`,
                au.`email`,
                au.`username`,
                au.`password_hash`,
                CASE
                    WHEN LOWER(TRIM(au.`username`)) = 'superadmin' THEN 'superadmin'
                    ELSE 'admin'
                END,
                au.`profile_picture`,
                au.`last_login`,
                COALESCE(au.`created_at`, NOW()),
                COALESCE(au.`updated_at`, NOW()),
                au.`reset_token`,
                au.`reset_expires`,
                COALESCE(au.`is_verified`, 1),
                au.`verification_token`,
                au.`token_expiry`,
                COALESCE(au.`failed_login_attempts`, 0),
                COALESCE(au.`account_locked`, 0),
                au.`account_locked_at`,
                au.`password_changed_at`,
                au.`failed_window_started_at`,
                COALESCE(au.`lockout_level`, 0),
                au.`lockout_until`,
                COALESCE(au.`two_fa_enabled`, 0)
            FROM `admin_users` au
            ON DUPLICATE KEY UPDATE
                `password` = CASE WHEN `users`.`password` = '' THEN VALUES(`password`) ELSE `users`.`password` END,
                `role` = CASE
                    WHEN LOWER(TRIM(`users`.`role`)) IN ('admin', 'superadmin') THEN `users`.`role`
                    ELSE VALUES(`role`)
                END,
                `email_verified` = GREATEST(COALESCE(`users`.`email_verified`, 0), COALESCE(VALUES(`email_verified`), 0)),
                `verification_token` = COALESCE(`users`.`verification_token`, VALUES(`verification_token`)),
                `token_expiry` = COALESCE(`users`.`token_expiry`, VALUES(`token_expiry`)),
                `failed_login_attempts` = GREATEST(COALESCE(`users`.`failed_login_attempts`, 0), COALESCE(VALUES(`failed_login_attempts`), 0)),
                `account_locked` = GREATEST(COALESCE(`users`.`account_locked`, 0), COALESCE(VALUES(`account_locked`), 0)),
                `account_locked_at` = COALESCE(`users`.`account_locked_at`, VALUES(`account_locked_at`)),
                `password_changed_at` = COALESCE(`users`.`password_changed_at`, VALUES(`password_changed_at`)),
                `failed_window_started_at` = COALESCE(`users`.`failed_window_started_at`, VALUES(`failed_window_started_at`)),
                `lockout_level` = GREATEST(COALESCE(`users`.`lockout_level`, 0), COALESCE(VALUES(`lockout_level`), 0)),
                `lockout_until` = COALESCE(`users`.`lockout_until`, VALUES(`lockout_until`)),
                `two_fa_enabled` = GREATEST(COALESCE(`users`.`two_fa_enabled`, 0), COALESCE(VALUES(`two_fa_enabled`), 0))",
            "Migrate admin_users rows into users"
        );

        removeAdminUsersRun($conn, "DROP TABLE `admin_users`", "Drop admin_users table");
    } elseif ($adminUsersType === 'VIEW') {
        removeAdminUsersRun($conn, "DROP VIEW `admin_users`", "Drop existing admin_users view");
    }

    echo "admin_users table removal completed successfully.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "admin_users removal failed: " . $e->getMessage() . "\n");
    exit(1);
}

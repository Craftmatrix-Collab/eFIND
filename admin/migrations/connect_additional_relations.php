<?php

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

function relationRunQuery(mysqli $conn, string $sql, string $label): void
{
    if (!$conn->query($sql)) {
        throw new RuntimeException("{$label} failed: {$conn->error}");
    }
    echo "[OK] {$label}\n";
}

function relationTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function relationColumnExists(mysqli $conn, string $table, string $column): bool
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

function relationHasIndexForColumn(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.STATISTICS
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

function relationHasForeignKeyOnColumn(mysqli $conn, string $table, string $column, string $referencedTable): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
           AND REFERENCED_TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param('sss', $table, $column, $referencedTable);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function relationEnsurePrimaryLoginSessions(mysqli $conn): void
{
    if (!relationTableExists($conn, 'primary_login_sessions') || !relationTableExists($conn, 'users')) {
        return;
    }

    relationRunQuery(
        $conn,
        "DELETE pls
         FROM `primary_login_sessions` pls
         LEFT JOIN `users` u ON u.id = pls.account_id
         WHERE u.id IS NULL",
        "Delete orphan primary_login_sessions rows"
    );

    if (!relationHasIndexForColumn($conn, 'primary_login_sessions', 'account_id')) {
        relationRunQuery(
            $conn,
            "ALTER TABLE `primary_login_sessions` ADD INDEX `idx_account_id` (`account_id`)",
            "Add primary_login_sessions.account_id index"
        );
    }

    if (!relationHasForeignKeyOnColumn($conn, 'primary_login_sessions', 'account_id', 'users')) {
        relationRunQuery(
            $conn,
            "ALTER TABLE `primary_login_sessions`
             ADD CONSTRAINT `fk_primary_login_sessions_user`
             FOREIGN KEY (`account_id`) REFERENCES `users`(`id`)
             ON UPDATE CASCADE
             ON DELETE CASCADE",
            "Add primary_login_sessions -> users FK"
        );
    }
}

function relationEnsureRecycleBin(mysqli $conn): void
{
    if (!relationTableExists($conn, 'recycle_bin')) {
        return;
    }

    $relations = [
        ['column' => 'executive_order_id', 'table' => 'executive_orders', 'index' => 'idx_recycle_bin_exec_order_id', 'fk' => 'fk_recycle_bin_exec_order'],
        ['column' => 'resolution_id', 'table' => 'resolutions', 'index' => 'idx_recycle_bin_resolution_id', 'fk' => 'fk_recycle_bin_resolution'],
        ['column' => 'minutes_of_meeting_id', 'table' => 'minutes_of_meeting', 'index' => 'idx_recycle_bin_minutes_id', 'fk' => 'fk_recycle_bin_minutes'],
    ];

    foreach ($relations as $relation) {
        if (!relationColumnExists($conn, 'recycle_bin', $relation['column'])) {
            relationRunQuery(
                $conn,
                "ALTER TABLE `recycle_bin` ADD COLUMN `{$relation['column']}` INT NULL",
                "Add recycle_bin.{$relation['column']}"
            );
        }

        if (!relationHasIndexForColumn($conn, 'recycle_bin', $relation['column'])) {
            relationRunQuery(
                $conn,
                "ALTER TABLE `recycle_bin` ADD INDEX `{$relation['index']}` (`{$relation['column']}`)",
                "Add recycle_bin index {$relation['index']}"
            );
        }
    }

    relationRunQuery(
        $conn,
        "UPDATE `recycle_bin`
         SET `executive_order_id` = NULL,
             `resolution_id` = NULL,
             `minutes_of_meeting_id` = NULL",
        "Reset recycle_bin relation columns"
    );

    relationRunQuery(
        $conn,
        "UPDATE `recycle_bin` rb
         JOIN `executive_orders` eo ON eo.id = rb.original_id
         SET rb.executive_order_id = rb.original_id
         WHERE rb.original_id IS NOT NULL
           AND LOWER(TRIM(COALESCE(rb.original_table, ''))) IN ('executive_order', 'executive_orders')",
        "Backfill recycle_bin executive_order_id"
    );

    relationRunQuery(
        $conn,
        "UPDATE `recycle_bin` rb
         JOIN `resolutions` rs ON rs.id = rb.original_id
         SET rb.resolution_id = rb.original_id
         WHERE rb.original_id IS NOT NULL
           AND LOWER(TRIM(COALESCE(rb.original_table, ''))) IN ('resolution', 'resolutions')",
        "Backfill recycle_bin resolution_id"
    );

    relationRunQuery(
        $conn,
        "UPDATE `recycle_bin` rb
         JOIN `minutes_of_meeting` mm ON mm.id = rb.original_id
         SET rb.minutes_of_meeting_id = rb.original_id
         WHERE rb.original_id IS NOT NULL
           AND LOWER(TRIM(COALESCE(rb.original_table, ''))) IN ('minutes', 'minute', 'meeting', 'meeting_minutes', 'minutes_of_meeting')",
        "Backfill recycle_bin minutes_of_meeting_id"
    );

    foreach ($relations as $relation) {
        if (!relationTableExists($conn, $relation['table'])) {
            continue;
        }

        if (!relationHasForeignKeyOnColumn($conn, 'recycle_bin', $relation['column'], $relation['table'])) {
            relationRunQuery(
                $conn,
                "ALTER TABLE `recycle_bin`
                 ADD CONSTRAINT `{$relation['fk']}`
                 FOREIGN KEY (`{$relation['column']}`) REFERENCES `{$relation['table']}`(`id`)
                 ON UPDATE CASCADE
                 ON DELETE SET NULL",
                "Add recycle_bin FK {$relation['fk']}"
            );
        }
    }

    relationRunQuery($conn, "DROP TRIGGER IF EXISTS `trg_recycle_bin_relations_bi`", "Drop recycle_bin before-insert trigger");
    relationRunQuery(
        $conn,
        "CREATE TRIGGER `trg_recycle_bin_relations_bi`
         BEFORE INSERT ON `recycle_bin`
         FOR EACH ROW
         BEGIN
             SET NEW.executive_order_id = NULL;
             SET NEW.resolution_id = NULL;
             SET NEW.minutes_of_meeting_id = NULL;

             IF NEW.original_id IS NOT NULL THEN
                 IF LOWER(TRIM(COALESCE(NEW.original_table, ''))) IN ('executive_order', 'executive_orders') THEN
                     IF EXISTS (SELECT 1 FROM executive_orders WHERE id = NEW.original_id) THEN
                         SET NEW.executive_order_id = NEW.original_id;
                     END IF;
                 ELSEIF LOWER(TRIM(COALESCE(NEW.original_table, ''))) IN ('resolution', 'resolutions') THEN
                     IF EXISTS (SELECT 1 FROM resolutions WHERE id = NEW.original_id) THEN
                         SET NEW.resolution_id = NEW.original_id;
                     END IF;
                 ELSEIF LOWER(TRIM(COALESCE(NEW.original_table, ''))) IN ('minutes', 'minute', 'meeting', 'meeting_minutes', 'minutes_of_meeting') THEN
                     IF EXISTS (SELECT 1 FROM minutes_of_meeting WHERE id = NEW.original_id) THEN
                         SET NEW.minutes_of_meeting_id = NEW.original_id;
                     END IF;
                 END IF;
             END IF;
         END",
        "Create recycle_bin before-insert trigger"
    );

    relationRunQuery($conn, "DROP TRIGGER IF EXISTS `trg_recycle_bin_relations_bu`", "Drop recycle_bin before-update trigger");
    relationRunQuery(
        $conn,
        "CREATE TRIGGER `trg_recycle_bin_relations_bu`
         BEFORE UPDATE ON `recycle_bin`
         FOR EACH ROW
         BEGIN
             SET NEW.executive_order_id = NULL;
             SET NEW.resolution_id = NULL;
             SET NEW.minutes_of_meeting_id = NULL;

             IF NEW.original_id IS NOT NULL THEN
                 IF LOWER(TRIM(COALESCE(NEW.original_table, ''))) IN ('executive_order', 'executive_orders') THEN
                     IF EXISTS (SELECT 1 FROM executive_orders WHERE id = NEW.original_id) THEN
                         SET NEW.executive_order_id = NEW.original_id;
                     END IF;
                 ELSEIF LOWER(TRIM(COALESCE(NEW.original_table, ''))) IN ('resolution', 'resolutions') THEN
                     IF EXISTS (SELECT 1 FROM resolutions WHERE id = NEW.original_id) THEN
                         SET NEW.resolution_id = NEW.original_id;
                     END IF;
                 ELSEIF LOWER(TRIM(COALESCE(NEW.original_table, ''))) IN ('minutes', 'minute', 'meeting', 'meeting_minutes', 'minutes_of_meeting') THEN
                     IF EXISTS (SELECT 1 FROM minutes_of_meeting WHERE id = NEW.original_id) THEN
                         SET NEW.minutes_of_meeting_id = NEW.original_id;
                     END IF;
                 END IF;
             END IF;
         END",
        "Create recycle_bin before-update trigger"
    );
}

function relationEnsureChatLogs(mysqli $conn): void
{
    if (!relationTableExists($conn, 'chat_logs') || !relationTableExists($conn, 'users')) {
        return;
    }

    if (!relationColumnExists($conn, 'chat_logs', 'sender_user_id')) {
        relationRunQuery(
            $conn,
            "ALTER TABLE `chat_logs` ADD COLUMN `sender_user_id` INT NULL AFTER `sender`",
            "Add chat_logs.sender_user_id"
        );
    }

    if (!relationHasIndexForColumn($conn, 'chat_logs', 'sender_user_id')) {
        relationRunQuery(
            $conn,
            "ALTER TABLE `chat_logs` ADD INDEX `idx_chat_logs_sender_user_id` (`sender_user_id`)",
            "Add chat_logs sender_user_id index"
        );
    }

    if (!relationHasForeignKeyOnColumn($conn, 'chat_logs', 'sender_user_id', 'users')) {
        relationRunQuery(
            $conn,
            "ALTER TABLE `chat_logs`
             ADD CONSTRAINT `fk_chat_logs_sender_user`
             FOREIGN KEY (`sender_user_id`) REFERENCES `users`(`id`)
             ON UPDATE CASCADE
             ON DELETE SET NULL",
            "Add chat_logs sender_user_id FK"
        );
    }

    relationRunQuery($conn, "DROP TRIGGER IF EXISTS `trg_chat_logs_sender_user_bi`", "Drop chat_logs before-insert trigger");
    relationRunQuery(
        $conn,
        "CREATE TRIGGER `trg_chat_logs_sender_user_bi`
         BEFORE INSERT ON `chat_logs`
         FOR EACH ROW
         BEGIN
             IF LOWER(TRIM(COALESCE(NEW.sender, ''))) = 'bot' THEN
                 SET NEW.sender_user_id = NULL;
             END IF;
         END",
        "Create chat_logs before-insert trigger"
    );

    relationRunQuery($conn, "DROP TRIGGER IF EXISTS `trg_chat_logs_sender_user_bu`", "Drop chat_logs before-update trigger");
    relationRunQuery(
        $conn,
        "CREATE TRIGGER `trg_chat_logs_sender_user_bu`
         BEFORE UPDATE ON `chat_logs`
         FOR EACH ROW
         BEGIN
             IF LOWER(TRIM(COALESCE(NEW.sender, ''))) = 'bot' THEN
                 SET NEW.sender_user_id = NULL;
             END IF;
         END",
        "Create chat_logs before-update trigger"
    );
}

try {
    relationRunQuery($conn, "SET SESSION sql_safe_updates = 0", "Disable sql_safe_updates");
    $conn->begin_transaction();

    relationEnsurePrimaryLoginSessions($conn);
    relationEnsureRecycleBin($conn);
    relationEnsureChatLogs($conn);

    $conn->commit();
    echo "Additional relations migration completed successfully.\n";
} catch (Throwable $e) {
    if ($conn->ping()) {
        $conn->rollback();
    }
    fwrite(STDERR, "Additional relations migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

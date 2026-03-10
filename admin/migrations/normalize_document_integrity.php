<?php

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

function migrationTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function migrationColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function migrationHasIndexForColumn(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function migrationHasForeignKeyOnColumn(mysqli $conn, string $table, string $column, string $referencedTable): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ? LIMIT 1");
    $stmt->bind_param('sss', $table, $column, $referencedTable);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function migrationRunQuery(mysqli $conn, string $sql, string $label): void
{
    if (!$conn->query($sql)) {
        throw new RuntimeException("{$label} failed: {$conn->error}");
    }
    echo "[OK] {$label}\n";
}

function migrationQuotedList(array $values): string
{
    $parts = [];
    foreach ($values as $value) {
        $parts[] = "'" . str_replace("'", "''", (string)$value) . "'";
    }
    return implode(', ', $parts);
}

function migrationNormalizeTypeColumn(
    mysqli $conn,
    string $table,
    string $column,
    string $executiveValue,
    string $resolutionValue,
    string $minutesValue,
    array $executiveAliases,
    array $resolutionAliases,
    array $minutesAliases
): void {
    if (!migrationTableExists($conn, $table) || !migrationColumnExists($conn, $table, $column)) {
        return;
    }

    $allAliases = array_merge($executiveAliases, $resolutionAliases, $minutesAliases);
    $allAliasSql = migrationQuotedList($allAliases);
    $execAliasSql = migrationQuotedList($executiveAliases);
    $resAliasSql = migrationQuotedList($resolutionAliases);
    $minAliasSql = migrationQuotedList($minutesAliases);

    $sql = "UPDATE `{$table}`
            SET `{$column}` = CASE
                WHEN LOWER(TRIM(`{$column}`)) IN ({$execAliasSql}) THEN '{$executiveValue}'
                WHEN LOWER(TRIM(`{$column}`)) IN ({$resAliasSql}) THEN '{$resolutionValue}'
                WHEN LOWER(TRIM(`{$column}`)) IN ({$minAliasSql}) THEN '{$minutesValue}'
                ELSE `{$column}`
            END
            WHERE `{$column}` IS NOT NULL
              AND LOWER(TRIM(`{$column}`)) IN ({$allAliasSql})";

    migrationRunQuery($conn, $sql, "Normalize {$table}.{$column}");
}

function migrationEnsureRelationColumnsAndKeys(mysqli $conn, string $table): void
{
    if (!migrationTableExists($conn, $table)) {
        return;
    }

    $relations = [
        ['column' => 'executive_order_id', 'ref_table' => 'executive_orders', 'index' => "idx_{$table}_exec_order_id", 'fk' => "fk_{$table}_exec_order"],
        ['column' => 'resolution_id', 'ref_table' => 'resolutions', 'index' => "idx_{$table}_resolution_id", 'fk' => "fk_{$table}_resolution"],
        ['column' => 'minutes_of_meeting_id', 'ref_table' => 'minutes_of_meeting', 'index' => "idx_{$table}_minutes_id", 'fk' => "fk_{$table}_minutes"],
    ];

    foreach ($relations as $relation) {
        $column = $relation['column'];
        if (!migrationColumnExists($conn, $table, $column)) {
            migrationRunQuery($conn, "ALTER TABLE `{$table}` ADD COLUMN `{$column}` INT NULL", "Add {$table}.{$column}");
        }

        if (!migrationHasIndexForColumn($conn, $table, $column)) {
            migrationRunQuery($conn, "ALTER TABLE `{$table}` ADD INDEX `{$relation['index']}` (`{$column}`)", "Add index {$relation['index']}");
        }

        if (!migrationHasForeignKeyOnColumn($conn, $table, $column, $relation['ref_table'])) {
            migrationRunQuery(
                $conn,
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$relation['fk']}` FOREIGN KEY (`{$column}`) REFERENCES `{$relation['ref_table']}`(`id`) ON UPDATE CASCADE ON DELETE SET NULL",
                "Add FK {$relation['fk']}"
            );
        }
    }
}

function migrationBackfillRelationColumns(mysqli $conn, string $table, string $idColumn, string $typeColumn): void
{
    if (!migrationTableExists($conn, $table)) {
        return;
    }

    $requiredColumns = [$idColumn, $typeColumn, 'executive_order_id', 'resolution_id', 'minutes_of_meeting_id'];
    foreach ($requiredColumns as $column) {
        if (!migrationColumnExists($conn, $table, $column)) {
            return;
        }
    }

    migrationRunQuery(
        $conn,
        "UPDATE `{$table}` SET `executive_order_id` = NULL, `resolution_id` = NULL, `minutes_of_meeting_id` = NULL",
        "Reset relation columns for {$table}"
    );

    migrationRunQuery(
        $conn,
        "UPDATE `{$table}` t
         JOIN `executive_orders` eo ON eo.id = t.`{$idColumn}`
         SET t.`executive_order_id` = t.`{$idColumn}`
         WHERE t.`{$idColumn}` IS NOT NULL
           AND LOWER(TRIM(COALESCE(t.`{$typeColumn}`, ''))) IN ('executive_order', 'executive_orders', 'ordinance', 'ordinances')",
        "Backfill executive order links for {$table}"
    );

    migrationRunQuery(
        $conn,
        "UPDATE `{$table}` t
         JOIN `resolutions` rs ON rs.id = t.`{$idColumn}`
         SET t.`resolution_id` = t.`{$idColumn}`
         WHERE t.`{$idColumn}` IS NOT NULL
           AND LOWER(TRIM(COALESCE(t.`{$typeColumn}`, ''))) IN ('resolution', 'resolutions')",
        "Backfill resolution links for {$table}"
    );

    migrationRunQuery(
        $conn,
        "UPDATE `{$table}` t
         JOIN `minutes_of_meeting` mm ON mm.id = t.`{$idColumn}`
         SET t.`minutes_of_meeting_id` = t.`{$idColumn}`
         WHERE t.`{$idColumn}` IS NOT NULL
           AND LOWER(TRIM(COALESCE(t.`{$typeColumn}`, ''))) IN ('minutes', 'minute', 'meeting', 'meeting_minutes', 'minutes_of_meeting')",
        "Backfill minutes links for {$table}"
    );
}

try {
    migrationRunQuery($conn, "SET SESSION sql_safe_updates = 0", "Disable sql_safe_updates");

    $conn->begin_transaction();

    if (migrationTableExists($conn, 'document_ocr_content') && migrationColumnExists($conn, 'document_ocr_content', 'document_type')) {
        $metaStmt = $conn->prepare("SELECT DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_ocr_content' AND COLUMN_NAME = 'document_type' LIMIT 1");
        $metaStmt->execute();
        $meta = $metaStmt->get_result()->fetch_assoc();
        $metaStmt->close();

        if (($meta['DATA_TYPE'] ?? '') === 'enum') {
            $nullableSql = (strtoupper((string)($meta['IS_NULLABLE'] ?? 'YES')) === 'NO') ? 'NOT NULL' : 'NULL';
            migrationRunQuery($conn, "ALTER TABLE `document_ocr_content` MODIFY COLUMN `document_type` VARCHAR(50) {$nullableSql}", "Convert document_ocr_content.document_type to VARCHAR(50)");
        }
    }

    $executiveAliases = ['executive_order', 'executive_orders', 'executive-order', 'executiveorder', 'ordinance', 'ordinances'];
    $resolutionAliases = ['resolution', 'resolutions'];
    $minutesAliases = ['minutes', 'minute', 'meeting', 'meeting_minutes', 'meeting-minutes', 'minutes_of_meeting'];

    migrationNormalizeTypeColumn($conn, 'activity_logs', 'document_type', 'executive_order', 'resolution', 'minutes', $executiveAliases, $resolutionAliases, $minutesAliases);
    migrationNormalizeTypeColumn($conn, 'document_image_hashes', 'document_type', 'executive_order', 'resolution', 'minutes', $executiveAliases, $resolutionAliases, $minutesAliases);
    migrationNormalizeTypeColumn($conn, 'document_ocr_content', 'document_type', 'executive_order', 'resolution', 'minutes', $executiveAliases, $resolutionAliases, $minutesAliases);
    migrationNormalizeTypeColumn($conn, 'mobile_upload_sessions', 'doc_type', 'executive_orders', 'resolutions', 'minutes', $executiveAliases, $resolutionAliases, $minutesAliases);

    migrationEnsureRelationColumnsAndKeys($conn, 'activity_logs');
    migrationEnsureRelationColumnsAndKeys($conn, 'document_image_hashes');
    migrationEnsureRelationColumnsAndKeys($conn, 'document_ocr_content');
    migrationEnsureRelationColumnsAndKeys($conn, 'mobile_upload_sessions');

    if (migrationTableExists($conn, 'document_image_hashes')) {
        migrationRunQuery(
            $conn,
            "DELETE dih
             FROM `document_image_hashes` dih
             LEFT JOIN `executive_orders` eo ON eo.id = dih.document_id AND dih.document_type = 'executive_order'
             LEFT JOIN `resolutions` rs ON rs.id = dih.document_id AND dih.document_type = 'resolution'
             LEFT JOIN `minutes_of_meeting` mm ON mm.id = dih.document_id AND dih.document_type = 'minutes'
             WHERE dih.document_type IN ('executive_order', 'resolution', 'minutes')
               AND eo.id IS NULL
               AND rs.id IS NULL
               AND mm.id IS NULL",
            "Delete orphan document_image_hashes rows"
        );
    }

    if (migrationTableExists($conn, 'document_ocr_content')) {
        migrationRunQuery(
            $conn,
            "DELETE doc
             FROM `document_ocr_content` doc
             LEFT JOIN `executive_orders` eo ON eo.id = doc.document_id AND doc.document_type = 'executive_order'
             LEFT JOIN `resolutions` rs ON rs.id = doc.document_id AND doc.document_type = 'resolution'
             LEFT JOIN `minutes_of_meeting` mm ON mm.id = doc.document_id AND doc.document_type = 'minutes'
             WHERE doc.document_type IN ('executive_order', 'resolution', 'minutes')
               AND eo.id IS NULL
               AND rs.id IS NULL
               AND mm.id IS NULL",
            "Delete orphan document_ocr_content rows"
        );
    }

    if (migrationTableExists($conn, 'mobile_upload_sessions')
        && migrationColumnExists($conn, 'mobile_upload_sessions', 'result_id')
        && migrationColumnExists($conn, 'mobile_upload_sessions', 'executive_order_id')
        && migrationColumnExists($conn, 'mobile_upload_sessions', 'resolution_id')
        && migrationColumnExists($conn, 'mobile_upload_sessions', 'minutes_of_meeting_id')
    ) {
        migrationRunQuery(
            $conn,
            "UPDATE `mobile_upload_sessions` s
             LEFT JOIN `executive_orders` eo ON eo.id = s.result_id AND s.doc_type = 'executive_orders'
             LEFT JOIN `resolutions` rs ON rs.id = s.result_id AND s.doc_type = 'resolutions'
             LEFT JOIN `minutes_of_meeting` mm ON mm.id = s.result_id AND s.doc_type = 'minutes'
             SET s.result_id = NULL,
                 s.executive_order_id = NULL,
                 s.resolution_id = NULL,
                 s.minutes_of_meeting_id = NULL
             WHERE s.result_id IS NOT NULL
               AND s.doc_type IN ('executive_orders', 'resolutions', 'minutes')
               AND eo.id IS NULL
               AND rs.id IS NULL
               AND mm.id IS NULL",
            "Clear invalid mobile_upload_sessions result references"
        );
    }

    migrationBackfillRelationColumns($conn, 'activity_logs', 'document_id', 'document_type');
    migrationBackfillRelationColumns($conn, 'document_image_hashes', 'document_id', 'document_type');
    migrationBackfillRelationColumns($conn, 'document_ocr_content', 'document_id', 'document_type');
    migrationBackfillRelationColumns($conn, 'mobile_upload_sessions', 'result_id', 'doc_type');

    $conn->commit();
    echo "Migration completed successfully.\n";
} catch (Throwable $e) {
    if ($conn->ping()) {
        $conn->rollback();
    }
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}


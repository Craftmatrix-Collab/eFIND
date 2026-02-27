-- Migration: Rename legacy ordinance schema/data to executive order naming
-- Run this once against the target database.

DELIMITER //

DROP PROCEDURE IF EXISTS migrate_ordinance_to_executive_order //
CREATE PROCEDURE migrate_ordinance_to_executive_order()
BEGIN
    DECLARE v_old_table_exists INT DEFAULT 0;
    DECLARE v_new_table_exists INT DEFAULT 0;
    DECLARE v_old_column_exists INT DEFAULT 0;
    DECLARE v_new_column_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_old_table_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ordinances';

    SELECT COUNT(*) INTO v_new_table_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'executive_orders';

    IF v_old_table_exists = 1 AND v_new_table_exists = 0 THEN
        RENAME TABLE ordinances TO executive_orders;
    END IF;

    SELECT COUNT(*) INTO v_old_column_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'executive_orders'
      AND COLUMN_NAME = 'ordinance_number';

    SELECT COUNT(*) INTO v_new_column_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'executive_orders'
      AND COLUMN_NAME = 'executive_order_number';

    IF v_old_column_exists = 1 AND v_new_column_exists = 0 THEN
        SELECT CONCAT(
            'ALTER TABLE `executive_orders` CHANGE `ordinance_number` `executive_order_number` ',
            COLUMN_TYPE,
            CASE WHEN IS_NULLABLE = 'NO' THEN ' NOT NULL' ELSE ' NULL' END,
            CASE
                WHEN COLUMN_DEFAULT IS NULL THEN ''
                WHEN COLUMN_DEFAULT = 'CURRENT_TIMESTAMP' THEN ' DEFAULT CURRENT_TIMESTAMP'
                ELSE CONCAT(' DEFAULT ', QUOTE(COLUMN_DEFAULT))
            END,
            CASE WHEN EXTRA = '' THEN '' ELSE CONCAT(' ', EXTRA) END
        )
        INTO @sql_stmt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'executive_orders'
          AND COLUMN_NAME = 'ordinance_number'
        LIMIT 1;

        PREPARE stmt FROM @sql_stmt;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO v_old_column_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'executive_orders'
      AND COLUMN_NAME = 'ordinance_date';

    SELECT COUNT(*) INTO v_new_column_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'executive_orders'
      AND COLUMN_NAME = 'executive_order_date';

    IF v_old_column_exists = 1 AND v_new_column_exists = 0 THEN
        SELECT CONCAT(
            'ALTER TABLE `executive_orders` CHANGE `ordinance_date` `executive_order_date` ',
            COLUMN_TYPE,
            CASE WHEN IS_NULLABLE = 'NO' THEN ' NOT NULL' ELSE ' NULL' END,
            CASE
                WHEN COLUMN_DEFAULT IS NULL THEN ''
                WHEN COLUMN_DEFAULT = 'CURRENT_TIMESTAMP' THEN ' DEFAULT CURRENT_TIMESTAMP'
                ELSE CONCAT(' DEFAULT ', QUOTE(COLUMN_DEFAULT))
            END,
            CASE WHEN EXTRA = '' THEN '' ELSE CONCAT(' ', EXTRA) END
        )
        INTO @sql_stmt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'executive_orders'
          AND COLUMN_NAME = 'ordinance_date'
        LIMIT 1;

        PREPARE stmt FROM @sql_stmt;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'executive_orders'
          AND INDEX_NAME = 'idx_ordinance_number'
    ) THEN
        ALTER TABLE executive_orders RENAME INDEX idx_ordinance_number TO idx_executive_order_number;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'executive_orders'
          AND INDEX_NAME = 'idx_ordinance_date'
    ) THEN
        ALTER TABLE executive_orders RENAME INDEX idx_ordinance_date TO idx_executive_order_date;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'executive_orders'
          AND COLUMN_NAME = 'reference_number'
    ) THEN
        UPDATE executive_orders
        SET reference_number = CONCAT('EO', SUBSTRING(reference_number, 4))
        WHERE reference_number REGEXP '^ORD[0-9]';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'document_ocr_content'
          AND COLUMN_NAME = 'document_type'
    ) THEN
        UPDATE document_ocr_content
        SET document_type = 'executive_order'
        WHERE LOWER(document_type) IN ('ordinance', 'ordinances');
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'document_image_hashes'
          AND COLUMN_NAME = 'document_type'
    ) THEN
        UPDATE document_image_hashes
        SET document_type = 'executive_order'
        WHERE LOWER(document_type) IN ('ordinance', 'ordinances');
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'activity_logs'
          AND COLUMN_NAME = 'document_type'
    ) THEN
        UPDATE activity_logs
        SET document_type = 'executive_order'
        WHERE LOWER(document_type) IN ('ordinance', 'ordinances');
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'recycle_bin'
          AND COLUMN_NAME = 'original_table'
    ) THEN
        UPDATE recycle_bin
        SET original_table = 'executive_orders'
        WHERE original_table = 'ordinances';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'recycle_bin'
          AND COLUMN_NAME = 'data'
    ) THEN
        UPDATE recycle_bin
        SET data = JSON_REMOVE(
            JSON_SET(data, '$.executive_order_number', JSON_EXTRACT(data, '$.ordinance_number')),
            '$.ordinance_number'
        )
        WHERE JSON_VALID(data)
          AND original_table = 'executive_orders'
          AND JSON_EXTRACT(data, '$.executive_order_number') IS NULL
          AND JSON_EXTRACT(data, '$.ordinance_number') IS NOT NULL;

        UPDATE recycle_bin
        SET data = JSON_REMOVE(
            JSON_SET(data, '$.executive_order_date', JSON_EXTRACT(data, '$.ordinance_date')),
            '$.ordinance_date'
        )
        WHERE JSON_VALID(data)
          AND original_table = 'executive_orders'
          AND JSON_EXTRACT(data, '$.executive_order_date') IS NULL
          AND JSON_EXTRACT(data, '$.ordinance_date') IS NOT NULL;
    END IF;
END //

CALL migrate_ordinance_to_executive_order() //
DROP PROCEDURE IF EXISTS migrate_ordinance_to_executive_order //

DELIMITER ;

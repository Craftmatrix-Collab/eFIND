-- Fix: Increase image_path column from VARCHAR(255) to TEXT in all three document tables
-- This prevents silent truncation when multiple MinIO URLs are stored separated by '|'

ALTER TABLE `ordinances` MODIFY COLUMN `image_path` TEXT DEFAULT NULL;
ALTER TABLE `resolutions` MODIFY COLUMN `image_path` TEXT NOT NULL DEFAULT '';
ALTER TABLE `minutes_of_meeting` MODIFY COLUMN `image_path` TEXT DEFAULT NULL;

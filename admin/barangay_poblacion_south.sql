/*
 Navicat Premium Dump SQL

 Source Server         : Localhost
 Source Server Type    : MySQL
 Source Server Version : 100432 (10.4.32-MariaDB)
 Source Host           : localhost:3306
 Source Schema         : barangay_poblacion_south

 Target Server Type    : MySQL
 Target Server Version : 100432 (10.4.32-MariaDB)
 File Encoding         : 65001

 Date: 23/09/2025 13:08:42
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for activity_logs
-- ----------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NULL DEFAULT NULL,
  `user_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `log_time` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `user_id`(`user_id` ASC) USING BTREE,
  INDEX `action`(`action` ASC) USING BTREE,
  INDEX `log_time`(`log_time` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of activity_logs
-- ----------------------------

-- ----------------------------
-- Table structure for admin_users
-- ----------------------------
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `contact_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `profile_picture` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `last_login` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE CURRENT_TIMESTAMP,
  `reset_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `reset_expires` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `username`(`username` ASC) USING BTREE,
  UNIQUE INDEX `email`(`email` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of admin_users
-- ----------------------------
INSERT INTO `admin_users` VALUES (1, 'Sierra Pearl Pacilan', 'sierra.pacilan1', 'sierra.pacilan1@gmail.com', '$2y$10$qwdd25JZaMk7.TQ9o41q7u8w5M54uqWwYCkdPmTTPiMQINV2uh64a', '0 9973 1902 16', NULL, '2025-09-23 09:47:42', '2025-09-16 10:30:03', '2025-09-23 09:47:42', NULL, NULL);

-- ----------------------------
-- Table structure for combined_documents
-- ----------------------------
DROP TABLE IF EXISTS `combined_documents`;
CREATE TABLE `combined_documents`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `date` date NULL DEFAULT NULL,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `updated_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of combined_documents
-- ----------------------------

-- ----------------------------
-- Table structure for document_downloads
-- ----------------------------
DROP TABLE IF EXISTS `document_downloads`;
CREATE TABLE `document_downloads`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `download_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `document_id`(`document_id` ASC) USING BTREE,
  INDEX `document_type`(`document_type` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of document_downloads
-- ----------------------------

-- ----------------------------
-- Table structure for document_tags
-- ----------------------------
DROP TABLE IF EXISTS `document_tags`;
CREATE TABLE `document_tags`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_type` enum('ordinance','resolution','meeting_minute') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `document_id` int NOT NULL,
  `tag_id` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `unique_document_tag`(`document_type` ASC, `document_id` ASC, `tag_id` ASC) USING BTREE,
  INDEX `tag_id`(`tag_id` ASC) USING BTREE,
  CONSTRAINT `document_tags_ibfk_1` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 24 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of document_tags
-- ----------------------------
INSERT INTO `document_tags` VALUES (1, 'ordinance', 1, 1, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (2, 'ordinance', 1, 3, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (3, 'ordinance', 1, 6, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (4, 'ordinance', 2, 2, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (5, 'ordinance', 2, 7, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (6, 'ordinance', 3, 3, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (7, 'ordinance', 3, 7, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (8, 'ordinance', 3, 9, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (9, 'resolution', 1, 4, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (10, 'resolution', 1, 1, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (11, 'resolution', 2, 2, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (12, 'resolution', 2, 5, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (13, 'resolution', 3, 3, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (14, 'resolution', 3, 8, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (15, 'resolution', 3, 9, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (16, 'meeting_minute', 1, 1, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (17, 'meeting_minute', 1, 3, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (18, 'meeting_minute', 1, 7, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (19, 'meeting_minute', 2, 2, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (20, 'meeting_minute', 2, 7, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (21, 'meeting_minute', 2, 10, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (22, 'meeting_minute', 3, 4, '2025-09-16 16:06:00');
INSERT INTO `document_tags` VALUES (23, 'meeting_minute', 3, 1, '2025-09-16 16:06:00');

-- ----------------------------
-- Table structure for document_views
-- ----------------------------
DROP TABLE IF EXISTS `document_views`;
CREATE TABLE `document_views`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `view_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `document_id`(`document_id` ASC) USING BTREE,
  INDEX `document_type`(`document_type` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of document_views
-- ----------------------------

-- ----------------------------
-- Table structure for meeting_minutes
-- ----------------------------
DROP TABLE IF EXISTS `meeting_minutes`;
CREATE TABLE `meeting_minutes`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_posted` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `meeting_date` datetime NOT NULL,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `updated_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  FULLTEXT INDEX `title`(`title`, `content`)
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of meeting_minutes
-- ----------------------------

-- ----------------------------
-- Table structure for minutes_of_meeting
-- ----------------------------
DROP TABLE IF EXISTS `minutes_of_meeting`;
CREATE TABLE `minutes_of_meeting`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `date_posted` date NOT NULL,
  `meeting_date` date NOT NULL,
  `session_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('Active','Inactive','Pending','Approved','Rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of minutes_of_meeting
-- ----------------------------

-- ----------------------------
-- Table structure for notifications
-- ----------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NULL DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `is_read` tinyint(1) NULL DEFAULT 0,
  `created_at` datetime NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of notifications
-- ----------------------------

-- ----------------------------
-- Table structure for ocr_queue
-- ----------------------------
DROP TABLE IF EXISTS `ocr_queue`;
CREATE TABLE `ocr_queue`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `ordinance_id` int NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('pending','processed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `ordinance_id`(`ordinance_id` ASC) USING BTREE,
  CONSTRAINT `ocr_queue_ibfk_1` FOREIGN KEY (`ordinance_id`) REFERENCES `ordinances` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of ocr_queue
-- ----------------------------

-- ----------------------------
-- Table structure for ordinance_history
-- ----------------------------
DROP TABLE IF EXISTS `ordinance_history`;
CREATE TABLE `ordinance_history`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `ordinance_id` int NULL DEFAULT NULL,
  `changed_by` int NULL DEFAULT NULL,
  `change_date` datetime NULL DEFAULT NULL,
  `old_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `new_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `action` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of ordinance_history
-- ----------------------------

-- ----------------------------
-- Table structure for ordinances
-- ----------------------------
DROP TABLE IF EXISTS `ordinances`;
CREATE TABLE `ordinances`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `ordinance_date` datetime NULL DEFAULT NULL,
  `date_posted` datetime NULL DEFAULT NULL,
  `ordinance_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_issued` date NOT NULL,
  `date_approved` date NULL DEFAULT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `document_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `views` int NOT NULL DEFAULT 0,
  `downloads` int NOT NULL DEFAULT 0,
  `reference_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `updated_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `ocr_status` enum('pending','processed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'pending',
  `ocr_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `ocr_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  PRIMARY KEY (`id`) USING BTREE,
  FULLTEXT INDEX `title`(`title`, `reference_number`, `description`),
  FULLTEXT INDEX `content`(`content`),
  FULLTEXT INDEX `content_2`(`content`),
  FULLTEXT INDEX `ocr_content`(`ocr_content`)
) ENGINE = InnoDB AUTO_INCREMENT = 10 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of ordinances
-- ----------------------------
INSERT INTO `ordinances` VALUES (8, 'sasfr', '', 'wer', '2025-09-23 00:00:00', '2025-09-23 00:00:00', 'rf', '0000-00-00', NULL, 'uploads/68d2285321e16_Sample-of-Resolution-Format.jpg', '', NULL, '2025-09-23 12:55:47', 0, 0, 'ORD-2025-09-001', NULL, 'pending', NULL, 'Active', NULL);
INSERT INTO `ordinances` VALUES (9, 'sasfr', '', 'wer', '2025-09-23 00:00:00', '2025-09-23 00:00:00', 'rf', '0000-00-00', NULL, 'uploads/68d22afc6d5a4_Sample-of-Resolution-Format.jpg', '', NULL, '2025-09-23 13:07:08', 0, 0, 'ORD-2025-09-002', NULL, 'pending', NULL, 'Active', NULL);

-- ----------------------------
-- Table structure for resolutions
-- ----------------------------
DROP TABLE IF EXISTS `resolutions`;
CREATE TABLE `resolutions`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `resolution_date` datetime NULL DEFAULT NULL,
  `resolution_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_issued` date NOT NULL,
  `date_approved` datetime NULL DEFAULT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `views` int NOT NULL DEFAULT 0,
  `downloads` int NOT NULL DEFAULT 0,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `updated_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  FULLTEXT INDEX `title`(`title`, `reference_number`, `description`)
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of resolutions
-- ----------------------------

-- ----------------------------
-- Table structure for tags
-- ----------------------------
DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '#6c757d',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `name`(`name` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 11 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of tags
-- ----------------------------
INSERT INTO `tags` VALUES (1, 'Budget', 'Related to financial matters and budgeting', '#007bff', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (2, 'Environment', 'Environmental protection and conservation', '#28a745', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (3, 'Infrastructure', 'Public works and infrastructure projects', '#fd7e14', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (4, 'Education', 'Schools and educational programs', '#e83e8c', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (5, 'Health', 'Healthcare and public health initiatives', '#dc3545', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (6, 'Taxation', 'Tax-related legislation', '#6f42c1', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (7, 'Zoning', 'Land use and zoning regulations', '#20c997', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (8, 'Public Safety', 'Police, fire, and emergency services', '#ffc107', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (9, 'Transportation', 'Roads, traffic, and public transit', '#17a2b8', '2025-09-16 16:05:21');
INSERT INTO `tags` VALUES (10, 'Housing', 'Housing policies and regulations', '#6610f2', '2025-09-16 16:05:21');

-- ----------------------------
-- Table structure for users
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `profile_picture` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `email`(`email` ASC) USING BTREE,
  UNIQUE INDEX `username`(`username` ASC) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of users
-- ----------------------------

SET FOREIGN_KEY_CHECKS = 1;

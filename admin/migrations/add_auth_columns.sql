-- Migration: Add email verification and password-reset columns
-- Run this once against the barangay_poblacion_south database.
-- Safe to re-run: uses IF NOT EXISTS so it won't fail if columns already exist.

-- ── admin_users ──────────────────────────────────────────────────────────────

ALTER TABLE `admin_users`
  ADD COLUMN IF NOT EXISTS `is_verified`          TINYINT(1)   NOT NULL DEFAULT 0      AFTER `profile_picture`,
  ADD COLUMN IF NOT EXISTS `verification_token`   VARCHAR(64)  NULL     DEFAULT NULL   AFTER `is_verified`,
  ADD COLUMN IF NOT EXISTS `token_expiry`         DATETIME     NULL     DEFAULT NULL   AFTER `verification_token`,
  ADD COLUMN IF NOT EXISTS `reset_token`          VARCHAR(10)  NULL     DEFAULT NULL   AFTER `token_expiry`,
  ADD COLUMN IF NOT EXISTS `reset_expires`        DATETIME     NULL     DEFAULT NULL   AFTER `reset_token`;

-- Mark any existing admin accounts as already verified so they are not locked out
UPDATE `admin_users` SET `is_verified` = 1 WHERE `is_verified` = 0;

-- ── users (staff) ─────────────────────────────────────────────────────────────

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `reset_token`    VARCHAR(10)  NULL DEFAULT NULL  AFTER `password`,
  ADD COLUMN IF NOT EXISTS `reset_expires`  DATETIME     NULL DEFAULT NULL  AFTER `reset_token`;

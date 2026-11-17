-- ============================================================
--  011 — password reset
--
--  adds to users:
--    reset_code        6 digit verification code
--    reset_expires_at  expiry time (Unix timestamp)
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `reset_code` varchar(6) DEFAULT NULL AFTER `seller_level`,
  ADD COLUMN `reset_expires_at` int DEFAULT NULL AFTER `reset_code`;

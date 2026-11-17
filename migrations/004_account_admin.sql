-- ============================================================
--  004 — account management / admin back-office fields
--
--  users gains:
--    avatar_path  relative avatar path (uploads/...); NULL means use the username initial
--    is_active    whether the account is enabled, 1=enabled; disabling kills login and any live session
--
--  Note: 001 is the initial schema; this is an incremental migration so existing databases are unaffected.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `avatar_path` varchar(255) DEFAULT NULL AFTER `email`,
  ADD COLUMN `is_active`   tinyint(1) NOT NULL DEFAULT '1' AFTER `is_admin`;

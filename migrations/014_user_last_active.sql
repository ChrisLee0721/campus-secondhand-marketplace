-- ============================================================
--  014 — seller's last active time
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `last_active_at` timestamp NULL DEFAULT current_timestamp() AFTER `balance`;

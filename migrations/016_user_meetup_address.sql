-- ============================================================
--  016 — seller's default meetup address
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `meetup_address` varchar(255) DEFAULT NULL AFTER `last_active_at`;

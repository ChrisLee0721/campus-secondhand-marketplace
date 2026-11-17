-- ============================================================
--  014 —— 卖家最近活跃时间
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `last_active_at` timestamp NULL DEFAULT current_timestamp() AFTER `balance`;

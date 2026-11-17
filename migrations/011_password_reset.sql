-- ============================================================
--  011 —— 密码重置
--
--  users 增加：
--    reset_code        6 位验证码
--    reset_expires_at  过期时间（Unix 时间戳）
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `reset_code` varchar(6) DEFAULT NULL AFTER `seller_level`,
  ADD COLUMN `reset_expires_at` int DEFAULT NULL AFTER `reset_code`;

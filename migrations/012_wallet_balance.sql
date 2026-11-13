-- ============================================================
--  012 —— 模拟支付钱包
--
--  users 增加：
--    balance  可用余额（HK$）
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `balance` decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `reset_expires_at`;

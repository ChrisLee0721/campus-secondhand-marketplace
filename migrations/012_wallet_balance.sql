-- ============================================================
--  012 — simulated payment wallet
--
--  users gains:
--    balance  available balance (HK$)
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `balance` decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `reset_expires_at`;

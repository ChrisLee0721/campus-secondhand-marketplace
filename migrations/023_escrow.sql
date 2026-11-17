-- ============================================================
--  023 —— 担保交易 / 管理员手动放款与退款
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `orders`
  ADD COLUMN `escrow_status` enum('unpaid','held','released','refunded') NOT NULL DEFAULT 'unpaid' AFTER `status`,
  ADD COLUMN `paid_at` timestamp NULL DEFAULT NULL AFTER `escrow_status`,
  ADD COLUMN `released_at` timestamp NULL DEFAULT NULL AFTER `paid_at`,
  ADD COLUMN `refunded_at` timestamp NULL DEFAULT NULL AFTER `released_at`;

UPDATE `orders`
   SET `escrow_status` = 'released',
       `paid_at` = `order_date`,
       `released_at` = `order_date`
 WHERE `status` = 'completed';

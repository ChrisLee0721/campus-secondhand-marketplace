-- ============================================================
--  021 —— 双向评价（买家评卖家 / 卖家评买家）
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `reviews`
  ADD COLUMN `direction` enum('buyer_to_seller','seller_to_buyer') NOT NULL DEFAULT 'buyer_to_seller' AFTER `order_id`;

ALTER TABLE `reviews`
  ADD UNIQUE KEY `uniq_order_direction` (`order_id`, `direction`);

ALTER TABLE `reviews`
  DROP INDEX `uniq_order`;

-- ============================================================
--  013 — platform fee and seller payout
--
--  orders gains:
--    platform_fee  platform fee (10% of the price)
--    seller_income  what the seller actually receives (90% of the price)
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `orders`
  ADD COLUMN `platform_fee` decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `total_price`,
  ADD COLUMN `seller_income` decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `platform_fee`;

-- Historical orders have no split amount, so backfill the seller's full payout under the old rule
UPDATE `orders` SET `seller_income` = `total_price` WHERE `seller_income` = 0.00;

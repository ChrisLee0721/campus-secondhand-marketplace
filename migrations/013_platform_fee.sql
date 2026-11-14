-- ============================================================
--  013 —— 平台抽成与卖家所得
--
--  orders 增加：
--    platform_fee  平台手续费（原价 10%）
--    seller_income 卖家实际所得（原价 90%）
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `orders`
  ADD COLUMN `platform_fee` decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `total_price`,
  ADD COLUMN `seller_income` decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `platform_fee`;

-- 历史订单没有拆分金额，按旧口径补成卖家全额所得
UPDATE `orders` SET `seller_income` = `total_price` WHERE `seller_income` = 0.00;

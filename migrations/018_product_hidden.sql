-- ============================================================
--  018 —— 商品软下架（与已售出分离）
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `products`
  ADD COLUMN `is_hidden` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_sold`;

-- ============================================================
--  018 — soft-hide a listing (separate from sold)
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `products`
  ADD COLUMN `is_hidden` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_sold`;

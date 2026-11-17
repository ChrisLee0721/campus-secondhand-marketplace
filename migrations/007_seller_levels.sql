-- ============================================================
--  007 — seller tiers (Lv1-Lv6, auto-upgrade on completed sales, never downgrade)
--
--  users gains:
--    seller_level  seller tier 1-6, kept separate from the buyer level
--
--  Thresholds: >=1 -> 2, >=10 -> 3, >=100 -> 4, >=1000 -> 5, >=2000 -> 6
--
--  Note: the sales count is not stored; it is derived by COUNT over completed orders when upgrading;
--  this backfills the history, completed orders only.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `seller_level` tinyint(3) unsigned NOT NULL DEFAULT '1' AFTER `level`;

UPDATE `users` u
LEFT JOIN (
    SELECT p.`seller_id`, COUNT(*) AS sales
      FROM `orders` o
      JOIN `products` p ON o.`product_id` = p.`id`
     WHERE o.`status` = 'completed'
     GROUP BY p.`seller_id`
) s ON s.`seller_id` = u.`id`
SET u.`seller_level` = CASE
    WHEN COALESCE(s.sales, 0) >= 2000 THEN 6
    WHEN COALESCE(s.sales, 0) >= 1000 THEN 5
    WHEN COALESCE(s.sales, 0) >= 100  THEN 4
    WHEN COALESCE(s.sales, 0) >= 10   THEN 3
    WHEN COALESCE(s.sales, 0) >= 1    THEN 2
    ELSE 1
  END;

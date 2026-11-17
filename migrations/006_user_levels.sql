-- ============================================================
--  006 — user tiers (Lv1-Lv6, auto-upgrade on cumulative spend, never downgrade)
--
--  users gains:
--    total_spent  cumulative spend on completed orders (orders.status='completed')
--    level        current tier 1-6; only increases on upgrade, never drops
--
--  Thresholds: >=10 -> 2, >=100 -> 3, >=1000 -> 4, >=10000 -> 5, >=100000 -> 6
--
--  Note: 001 is the initial schema, so this is an incremental migration; setup.sh prefers importing
--  database/online_marketplace.sql, and that dump already has the columns synced.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `total_spent` decimal(12,2) NOT NULL DEFAULT '0.00' AFTER `is_active`,
  ADD COLUMN `level` tinyint(3) unsigned NOT NULL DEFAULT '1' AFTER `total_spent`;

-- Backfill: completed orders only
UPDATE `users` u
LEFT JOIN (
    SELECT `buyer_id`, SUM(`total_price`) AS spent
      FROM `orders`
     WHERE `status` = 'completed'
     GROUP BY `buyer_id`
) s ON s.`buyer_id` = u.`id`
SET u.`total_spent` = COALESCE(s.spent, 0.00);

UPDATE `users`
   SET `level` = CASE
       WHEN `total_spent` >= 100000 THEN 6
       WHEN `total_spent` >= 10000  THEN 5
       WHEN `total_spent` >= 1000   THEN 4
       WHEN `total_spent` >= 100    THEN 3
       WHEN `total_spent` >= 10     THEN 2
       ELSE 1
   END;

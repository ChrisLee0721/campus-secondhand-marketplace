-- ============================================================
--  006 —— 用户分级（Lv1~Lv6，按累计消费自动升级，只升不降）
--
--  users 增加：
--    total_spent  已完成订单（orders.status='completed'）的累计消费
--    level        当前等级 1~6，只在升级时变大，不会下降
--
--  阈值：≥10 → 2，≥100 → 3，≥1000 → 4，≥10000 → 5，≥100000 → 6
--
--  说明：001 是初始 schema，这里用增量迁移；setup.sh 优先导入
--  database/online_marketplace.sql，那个 dump 也已同步加列。
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `total_spent` decimal(12,2) NOT NULL DEFAULT '0.00' AFTER `is_active`,
  ADD COLUMN `level` tinyint(3) unsigned NOT NULL DEFAULT '1' AFTER `total_spent`;

-- 回填：只统计已完成订单
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

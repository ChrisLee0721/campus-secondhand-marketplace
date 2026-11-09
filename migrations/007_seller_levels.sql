-- ============================================================
--  007 —— 卖家等级（Lv1~Lv6，按完成卖出订单数自动升级，只升不降）
--
--  users 增加：
--    seller_level  卖家等级 1~6，与买家的 level 分开
--
--  阈值：≥1 → 2，≥10 → 3，≥100 → 4，≥1000 → 5，≥2000 → 6
--
--  说明：卖出单数不落库，升级时按 orders 中 completed 的记录 COUNT 派生；
--  这里回填历史数据，只看已完成订单。
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

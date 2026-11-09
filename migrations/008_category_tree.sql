-- ============================================================
--  008 —— 两级分类（大类 / 小类）
--
--  categories 增加 parent_id：
--    NULL      = 大类
--    指向大类  = 小类
--
--  旧 6 个分类保留原 id，改成小类；111 个商品的 category_id 一律不动。
--  新增 8 个大类；家具 / 美妆护肤 / 首饰配件 暂无小类。
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `categories`
  ADD COLUMN `parent_id` int DEFAULT NULL AFTER `name`,
  ADD KEY `idx_categories_parent` (`parent_id`),
  ADD CONSTRAINT `categories_parent_fk`
    FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

INSERT INTO `categories` (`id`, `name`, `parent_id`) VALUES
  (7,  'Fashion',              NULL),
  (8,  'Beauty & Skincare',    NULL),
  (9,  'Home & Living',        NULL),
  (10, 'Furniture',            NULL),
  (11, 'Jewelry & Accessories', NULL),
  (12, 'Food',                 NULL),
  (13, 'Tech & Gadgets',       NULL),
  (14, 'Books & Study',        NULL),
  (15, 'Other',                NULL);

INSERT INTO `categories` (`id`, `name`, `parent_id`) VALUES
  (16, 'Uncategorized', 15);

UPDATE `categories` SET `parent_id` = 7  WHERE `name` = 'Clothing';
UPDATE `categories` SET `parent_id` = 9  WHERE `name` = 'Home Goods';
UPDATE `categories` SET `parent_id` = 12 WHERE `name` = 'Food & Drink';
UPDATE `categories` SET `parent_id` = 13 WHERE `name` = 'Electronics';
UPDATE `categories` SET `parent_id` = 14 WHERE `name` = 'Books';
UPDATE `categories` SET `parent_id` = 14 WHERE `name` = 'Study Supplies';

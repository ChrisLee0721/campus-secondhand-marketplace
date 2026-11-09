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
  (7,  '穿搭',     NULL),
  (8,  '美妆护肤', NULL),
  (9,  '家居日用', NULL),
  (10, '家具',     NULL),
  (11, '首饰配件', NULL),
  (12, '食品',     NULL),
  (13, '数码电子', NULL),
  (14, '图书学习', NULL);

UPDATE `categories` SET `parent_id` = 7  WHERE `name` = '服装';
UPDATE `categories` SET `parent_id` = 9  WHERE `name` = '家居用品';
UPDATE `categories` SET `parent_id` = 12 WHERE `name` = '食品饮料';
UPDATE `categories` SET `parent_id` = 13 WHERE `name` = '电子产品';
UPDATE `categories` SET `parent_id` = 14 WHERE `name` = '图书';
UPDATE `categories` SET `parent_id` = 14 WHERE `name` = '学习用品';

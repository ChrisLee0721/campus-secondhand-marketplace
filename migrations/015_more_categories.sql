-- ============================================================
--  015 —— 校园二手分类树扩充
--
--  保留原有分类 ID，避免商品外键失效：
--  旧分类改名/挂到新大类下；新增缺失的叶子分类。
--  原 "Food & Drink" 商品并入 "Uncategorized"。
-- ============================================================

SET NAMES utf8mb4;

START TRANSACTION;

UPDATE `products` SET `category_id` = 16 WHERE `category_id` = 6;

UPDATE `categories` SET `name` = 'Computers', `parent_id` = 13 WHERE `id` = 2;
UPDATE `categories` SET `name` = 'Textbooks', `parent_id` = 14 WHERE `id` = 1;
UPDATE `categories` SET `name` = 'Stationery', `parent_id` = 14 WHERE `id` = 3;
UPDATE `categories` SET `name` = 'Clothing', `parent_id` = 7 WHERE `id` = 4;
UPDATE `categories` SET `name` = 'Storage', `parent_id` = 9 WHERE `id` = 5;
UPDATE `categories` SET `name` = 'Leisure', `parent_id` = NULL WHERE `id` = 6;
UPDATE `categories` SET `name` = 'Beauty', `parent_id` = NULL WHERE `id` = 8;
UPDATE `categories` SET `name` = 'Dorm & Furniture', `parent_id` = NULL WHERE `id` = 9;
UPDATE `categories` SET `name` = 'Bags', `parent_id` = 7 WHERE `id` = 11;
UPDATE `categories` SET `name` = 'Sports', `parent_id` = NULL WHERE `id` = 12;
UPDATE `categories` SET `name` = 'Electronics', `parent_id` = NULL WHERE `id` = 13;
UPDATE `categories` SET `name` = 'Furniture', `parent_id` = 9 WHERE `id` = 10;
UPDATE `categories` SET `name` = 'Other', `parent_id` = NULL WHERE `id` = 15;
UPDATE `categories` SET `name` = 'Uncategorized', `parent_id` = 15 WHERE `id` = 16;

INSERT INTO `categories` (`id`, `name`, `parent_id`) VALUES
(17, 'Vehicles', NULL),
(18, 'Phones', 13),
(19, 'Cameras', 13),
(20, 'Audio', 13),
(21, 'Exam Prep', 14),
(22, 'Fiction', 14),
(23, 'Appliances', 9),
(24, 'Decor', 9),
(25, 'Shoes', 7),
(26, 'Skincare', 8),
(27, 'Personal Care', 8),
(28, 'Fitness', 12),
(29, 'Ball Sports', 12),
(30, 'Outdoor', 12),
(31, 'Instruments', 6),
(32, 'Games', 6),
(33, 'Collectibles', 6),
(34, 'Bikes & Boards', 17),
(35, 'Scooters', 17);

COMMIT;

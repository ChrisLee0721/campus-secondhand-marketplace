-- ============================================================
--  008 — two-level categories (parent / child)
--
--  categories gains parent_id:
--    NULL       = parent category
--    points at a parent = child category
--
--  The 6 old categories keep their ids and become child categories; the category_id of all 111 listings is untouched.
--  8 new parent categories are added; Furniture / Beauty & Skincare / Jewelry & Accessories have no children yet.
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

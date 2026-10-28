-- ============================================================
--  001 —— 初始 schema
--  来源：1/online_marketplace_*.sql（保留原设计：外键、enum、
--  CHECK 约束、买卖双方分离、评价挂在 order 上）
--  改动：仅加了必要的索引与 utf8mb4 统一，结构未动。
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `cart`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;
-- 迁移标记表也必须清掉，否则重跑 setup 时 migrate_legacy.php 会误以为已经迁过
DROP TABLE IF EXISTS `_migrations`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- 用户 ----------
CREATE TABLE `users` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `username`   varchar(50)  NOT NULL,
  `password`   varchar(255) NOT NULL,
  `email`      varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_admin`   tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 分类 ----------
CREATE TABLE `categories` (
  `id`   int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 商品 ----------
CREATE TABLE `products` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `title`       varchar(100) NOT NULL,
  `description` text,
  `price`       decimal(10,2) NOT NULL,
  `category_id` int DEFAULT NULL,
  `status`      enum('new','used','damaged') NOT NULL,
  `image_path`  varchar(255) DEFAULT NULL,
  `seller_id`   int NOT NULL,
  `created_at`  timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_sold`     tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `seller_id` (`seller_id`),
  -- 列表页的主查询是 WHERE is_sold=0 ORDER BY created_at DESC, id DESC
  KEY `idx_listing` (`is_sold`, `created_at`, `id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`seller_id`)   REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 购物车 ----------
CREATE TABLE `cart` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `user_id`    int NOT NULL,
  `product_id` int NOT NULL,
  `quantity`   int DEFAULT '1',
  `added_at`   timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- 同一用户同一商品只允许一行（配合 CartItem::add 的去重逻辑）
  UNIQUE KEY `uniq_user_product` (`user_id`, `product_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  -- 二手商品只有一份，购物车行数量恒为 1
  CONSTRAINT `cart_qty_chk` CHECK (`quantity` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 订单 ----------
-- 二手平台的模型：一件商品只有一份，所以一个订单行 = 一件商品。
-- 一次结算 N 个购物车项会产生 N 条记录（原 schema 就这么设计的，保留）。
CREATE TABLE `orders` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `buyer_id`    int NOT NULL,
  `product_id`  int NOT NULL,
  `quantity`    int NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `order_date`  timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status`      enum('pending','completed','cancelled') DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `buyer_id` (`buyer_id`),
  KEY `product_id` (`product_id`),
  KEY `idx_buyer_date` (`buyer_id`, `order_date`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`buyer_id`)   REFERENCES `users` (`id`),
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `orders_qty_chk` CHECK (`quantity` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 评价 ----------
-- 评价挂在 order 上而不是 product 上 —— 评价属于「一次交易」，不是「一件商品」。
CREATE TABLE `reviews` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `order_id`   int NOT NULL,
  `seller_id`  int NOT NULL,
  `buyer_id`   int NOT NULL,
  `rating`     int NOT NULL,
  `comment`    text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- 一个订单只能评一次
  UNIQUE KEY `uniq_order` (`order_id`),
  KEY `seller_id` (`seller_id`),
  KEY `buyer_id` (`buyer_id`),
  CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`order_id`)  REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`),
  CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`buyer_id`)  REFERENCES `users` (`id`),
  CONSTRAINT `reviews_chk_1` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

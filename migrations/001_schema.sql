-- ============================================================
--  001 — initial schema
--  Source: 1/online_marketplace_*.sql (keeps the original design: foreign keys, enum,
--  CHECK constraints, buyer/seller split, reviews attached to the order)
--  Changes: only the required indexes and utf8mb4 unification; structure untouched.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `cart`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;
-- The migration marker table must go too, otherwise re-running setup makes migrate_legacy.php think the migration already ran
DROP TABLE IF EXISTS `_migrations`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Users ----------
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

-- ---------- Categories ----------
CREATE TABLE `categories` (
  `id`   int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Listings ----------
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
-- The main list query is WHERE is_sold=0 ORDER BY created_at DESC, id DESC
  KEY `idx_listing` (`is_sold`, `created_at`, `id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`seller_id`)   REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Cart ----------
CREATE TABLE `cart` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `user_id`    int NOT NULL,
  `product_id` int NOT NULL,
  `quantity`   int DEFAULT '1',
  `added_at`   timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
-- One row per user and listing (backs the dedup logic in CartItem::add)
  UNIQUE KEY `uniq_user_product` (`user_id`, `product_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
-- Second-hand listings are unique, so cart rows always hold quantity 1
  CONSTRAINT `cart_qty_chk` CHECK (`quantity` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Orders ----------
-- Marketplace model: each listing is unique, so one order row = one listing.
-- Checking out N cart items creates N rows (the original schema worked this way; kept).
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

-- ---------- Reviews ----------
-- Reviews hang off the order, not the product: a review belongs to a transaction, not a listing.
CREATE TABLE `reviews` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `order_id`   int NOT NULL,
  `seller_id`  int NOT NULL,
  `buyer_id`   int NOT NULL,
  `rating`     int NOT NULL,
  `comment`    text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
-- One review per order
  UNIQUE KEY `uniq_order` (`order_id`),
  KEY `seller_id` (`seller_id`),
  KEY `buyer_id` (`buyer_id`),
  CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`order_id`)  REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`),
  CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`buyer_id`)  REFERENCES `users` (`id`),
  CONSTRAINT `reviews_chk_1` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

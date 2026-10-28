-- ============================================================
--  005 —— 评价回复 / 内容隐藏 / 商品留言 / 个人主页留言 / 个人简介
--
--  评价仍然挂在 order 上；这里只扩展"卖家回复 + 后台隐藏"。
--  商品留言和主页留言是独立的表，不参与评分。
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `reviews`
  ADD COLUMN `reply_text` text NULL AFTER `comment`,
  ADD COLUMN `replied_at` timestamp NULL AFTER `reply_text`,
  ADD COLUMN `is_hidden` tinyint(1) NOT NULL DEFAULT '0' AFTER `replied_at`;

ALTER TABLE `users`
  ADD COLUMN `bio` varchar(255) DEFAULT NULL AFTER `avatar_path`;

-- ---------- 商品留言（售前问答） ----------
CREATE TABLE `product_comments` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `user_id`    int NOT NULL,
  `parent_id`  int DEFAULT NULL,
  `body`       varchar(1000) NOT NULL,
  `is_hidden`  tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_time` (`product_id`, `created_at`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `pc_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pc_user_fk`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 个人主页留言板 ----------
CREATE TABLE `profile_comments` (
  `id`              int NOT NULL AUTO_INCREMENT,
  `profile_user_id` int NOT NULL,
  `author_id`       int NOT NULL,
  `body`            varchar(1000) NOT NULL,
  `is_hidden`       tinyint(1) NOT NULL DEFAULT '0',
  `created_at`      timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_profile_time` (`profile_user_id`, `created_at`),
  KEY `idx_author` (`author_id`),
  CONSTRAINT `pfc_profile_fk` FOREIGN KEY (`profile_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pfc_author_fk`  FOREIGN KEY (`author_id`)      REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

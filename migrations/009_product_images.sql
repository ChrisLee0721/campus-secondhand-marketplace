-- ============================================================
--  009 —— 商品图片迁移到数据库 BLOB
--
--  products 增加：
--    image_blob  LONGBLOB     图片二进制（NULL = 还没入库）
--    image_mime  varchar(100) 图片 MIME，如 image/jpeg
--
--  旧 111 个商品的 image_path 保留，image_blob 不回填；
--  读取接口在 image_blob 为空时回退到 image_path 的本地文件。
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `products`
  ADD COLUMN `image_blob` longblob DEFAULT NULL AFTER `image_path`,
  ADD COLUMN `image_mime` varchar(100) DEFAULT NULL AFTER `image_blob`;

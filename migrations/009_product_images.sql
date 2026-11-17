-- ============================================================
--  009 — move listing images into database BLOBs
--
--  products gains:
--    image_blob  LONGBLOB     image bytes (NULL = not stored yet)
--    image_mime  varchar(100) image MIME type, e.g. image/jpeg
--
--  image_path is kept for the 111 existing listings and image_blob is not backfilled;
--  the read endpoint falls back to the local file in image_path when image_blob is empty.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `products`
  ADD COLUMN `image_blob` longblob DEFAULT NULL AFTER `image_path`,
  ADD COLUMN `image_mime` varchar(100) DEFAULT NULL AFTER `image_blob`;

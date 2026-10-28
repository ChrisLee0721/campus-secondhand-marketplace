-- ============================================================
--  004 —— 账号管理 / 管理员后台所需字段
--
--  users 增加：
--    avatar_path  头像相对路径（uploads/...），NULL 表示用用户名首字
--    is_active    账号是否启用，1=启用；禁用后登录和已登录会话都会失效
--
--  说明：001 是初始 schema，这里用增量迁移，避免影响已有数据库。
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `avatar_path` varchar(255) DEFAULT NULL AFTER `email`,
  ADD COLUMN `is_active`   tinyint(1) NOT NULL DEFAULT '1' AFTER `is_admin`;

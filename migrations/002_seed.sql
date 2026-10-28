-- ============================================================
--  002 —— 种子数据：分类 + 演示账号
--
--  分类沿用 online_marketplace 的 5 个，另加"食品饮料"
--  （从 db_01 迁移过来的 100 件商品里有 20 件是食品，原 schema 没有对应分类）。
--  名称改成中文，与前端界面语言一致。
-- ============================================================

SET NAMES utf8mb4;

INSERT INTO `categories` (`id`, `name`) VALUES
  (1, '图书'),
  (2, '电子产品'),
  (3, '学习用品'),
  (4, '服装'),
  (5, '家居用品'),
  (6, '食品饮料')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 演示账号。密码都是 BCrypt，明文见下（不要用于线上）：
--   admin / admin1234   (is_admin = 1)
--   alice / alice1234
--   bob   / bob1234
INSERT INTO `users` (`id`, `username`, `password`, `email`, `is_admin`) VALUES
  (1, 'admin', '$2y$10$0Lvboi85blgrpmZIgnd9SO/kXPRMX6DXLBBhvt4uhCwSMe2Xe4ir6', 'admin@example.com', 1),
  (2, 'alice', '$2y$10$aO1MVXM9ai.mChp5DkGsSOBH24kC6c18Wt4pPwWXaTlMvIwFXrdOC', 'alice@example.com', 0),
  (3, 'bob',   '$2y$10$GgbOCMhcjdO5zhOtffMB0.b690So0jiJtHIQAfpVkRlb9iPE5bVh.', 'bob@example.com',   0)
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

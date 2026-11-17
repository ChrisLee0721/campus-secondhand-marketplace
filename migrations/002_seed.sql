-- ============================================================
--  002 — seed data: categories + demo accounts
--
--  Reuses the 5 categories from online_marketplace, plus "Food & Drink"
--  (20 of the 100 listings migrated from db_01 are food, and the original schema had no such category).
--  Names were changed to Chinese to match the front-end UI language.
-- ============================================================

SET NAMES utf8mb4;

INSERT INTO `categories` (`id`, `name`) VALUES
  (1, 'Books'),
  (2, 'Electronics'),
  (3, 'Study Supplies'),
  (4, 'Clothing'),
  (5, 'Home Goods'),
  (6, 'Food & Drink')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Demo accounts. All passwords are BCrypt; plaintext below (do not use in production):
--   admin / admin1234   (is_admin = 1)
--   alice / alice1234
--   bob   / bob1234
INSERT INTO `users` (`id`, `username`, `password`, `email`, `is_admin`) VALUES
  (1, 'admin', '$2y$10$0Lvboi85blgrpmZIgnd9SO/kXPRMX6DXLBBhvt4uhCwSMe2Xe4ir6', 'admin@example.com', 1),
  (2, 'alice', '$2y$10$aO1MVXM9ai.mChp5DkGsSOBH24kC6c18Wt4pPwWXaTlMvIwFXrdOC', 'alice@example.com', 0),
  (3, 'bob',   '$2y$10$GgbOCMhcjdO5zhOtffMB0.b690So0jiJtHIQAfpVkRlb9iPE5bVh.', 'bob@example.com',   0)
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

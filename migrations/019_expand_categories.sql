-- ============================================================
--  019 — expand campus-scenario categories
-- ============================================================

SET NAMES utf8mb4;

START TRANSACTION;

INSERT INTO `categories` (`id`, `name`, `parent_id`) VALUES
(36, 'Daily Essentials', NULL),
(37, 'Snacks & Food', 36),
(38, 'Bath & Laundry', 36),
(39, 'Tools & Hardware', 36),
(40, 'Art & Craft', NULL),
(41, 'Art Supplies', 40),
(42, 'DIY Materials', 40),
(43, 'Posters & Prints', 40),
(44, 'Tickets & Coupons', NULL),
(45, 'Event Tickets', 44),
(46, 'Memberships', 44),
(47, 'Gift Cards', 44),
(48, 'Virtual Items', NULL),
(49, 'Game Accounts', 48),
(50, 'Software / Courses', 48);

COMMIT;

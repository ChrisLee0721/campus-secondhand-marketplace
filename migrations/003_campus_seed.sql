-- ============================================================
--  003 — campus second-hand listings seed
--
--  Source: real product photos scraped from Wikimedia Commons (CC licensed),
--  checked one by one against the listing name; only photos that matched were kept.
--
--  Why only 12:
--    I first wrote 40 campus listings but had no images. Wikimedia search is a literal
--    keyword match, and only these 12 really matched a listing — e.g. "dumbbell"
--    returns the Dumbbell Nebula and "hoodie" returns a crow on a lawn. Rather than pad the set
--    with wrong images, only the matches are kept; the rest come from the 99 real listings
--    migrated by migrations/migrate_legacy.php (those images are intact).
--
--  created_at is deliberately staggered across minutes — the original db_01 batch of 100 rows
--  all shared the same timestamp, which was the root cause of the "sort key has no discrimination -> pagination drops listings" bug.
-- ============================================================

SET NAMES utf8mb4;

INSERT INTO `products`
  (`title`, `description`, `price`, `category_id`, `status`, `image_path`, `seller_id`, `created_at`, `is_sold`)
VALUES
  ('C Programming, Tan Haoqiang', 'Classic textbook; creased cover, clean pages.', 20.00, 1, 'used', 'uploads/seed2/book-cprog.jpg', 3, '2026-09-03 14:05:00', 0),
  ('Xiaomi Power Strip, 3 Outlets', 'Two USB ports; used for one semester, works fine.', 25.00, 5, 'used', 'uploads/seed2/home-powerstrip.jpg', 1, '2026-09-06 16:45:00', 0),
  ('Thermos Bottle 500ml', 'Thermos-style bottle that keeps drinks hot. Small dent on the base, does not affect use.', 45.00, 5, 'damaged', 'uploads/seed2/home-thermos.jpg', 3, '2026-09-07 13:15:00', 0),
  ('Storage Box 60L', 'Has wheels, previously held clothes. One crack in the lid but it still latches.', 30.00, 5, 'damaged', 'uploads/seed2/home-storagebox.jpg', 3, '2026-09-07 20:05:00', 0),
  ('Monitor Arm', 'Single gas-spring arm, fits 17-27 inch monitors. Selling because I changed desks.', 90.00, 5, 'used', 'uploads/seed2/home-monitorarm.jpg', 3, '2026-09-10 18:00:00', 0),
  ('Down Jacket, Men Size M', '180g fill; just right for the library in winter. Slight pilling at the cuffs.', 260.00, 4, 'used', 'uploads/seed2/cloth-down.jpg', 2, '2026-09-12 10:05:00', 0),
  ('Drawing Tablet Wacom CTL-472', 'Entry-level tablet; pen and spare nibs included.', 240.00, 3, 'used', 'uploads/seed2/study-tablet.jpg', 1, '2026-09-14 09:50:00', 0),
  ('Electric Kettle 1.5L', 'Bought before high-wattage appliances were banned in the dorm; used for half a year. Auto shut-off works.', 55.00, 5, 'used', 'uploads/seed2/home-kettle.jpg', 2, '2026-09-15 08:45:00', 0),
  ('Instant Noodle Bowl with Lid', 'Ceramic and microwave safe. Bought a duplicate, so it is brand new and unused.', 18.00, 5, 'new', 'uploads/seed2/home-bowl.jpg', 3, '2026-09-15 13:30:00', 0),
  ('Electric Hand Mixer', 'Left over from a baking club event; used three times.', 45.00, 6, 'used', 'uploads/seed2/food-mixer.jpg', 1, '2026-09-16 15:35:00', 0),
  ('Blackout Curtain 1.5x2m', 'Used in a dorm; blocks light well. Already washed.', 45.00, 5, 'used', 'uploads/seed2/home-curtain.jpg', 2, '2026-09-21 11:55:00', 0),
  ('Camera, Sony A6000', 'Kit lens 16-50mm, about 12k shutter actuations. Two batteries and a camera bag included.', 2100.00, 2, 'used', 'uploads/seed2/elec-camera.jpg', 1, '2026-09-22 13:45:00', 0);

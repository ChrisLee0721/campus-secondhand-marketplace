-- ============================================================
--  020 —— Rich campus marketplace seed
-- ============================================================

SET NAMES utf8mb4;

START TRANSACTION;

INSERT INTO `products` (`id`,`title`,`description`,`price`,`category_id`,`status`,`image_path`,`image_blob`,`image_mime`,`seller_id`,`created_at`,`is_sold`,`is_hidden`) VALUES
(501,'Sony WH-1000XM4','Industry-leading noise cancellation, 30-hour battery and a warm, controlled sound. Light wear on the earcups; case and cable included.',980.00,20,'used',NULL,NULL,NULL,2,NOW(),0,0),
(502,'Keychron K8 Mechanical Keyboard','A hot-swappable 87-key board with tactile brown switches and white backlight. Used at a desk, never carried.',420.00,2,'used',NULL,NULL,NULL,1,NOW(),0,0),
(503,'Fujifilm X-T30 Camera Body','A compact APS-C body with the classic Fujifilm colour science. 8,400 shutter actuations; two batteries and a leather half-case.',1850.00,19,'used',NULL,NULL,NULL,3,NOW(),0,0),
(504,'Calculus: Early Transcendentals (8th Edition)','Stewart''s essential calculus text. A few highlighted theorems, otherwise clean and ready for problem sets.',180.00,1,'used',NULL,NULL,NULL,1,NOW(),0,0),
(505,'Klara and the Sun','Kazuo Ishiguro''s luminous novel about love and artificial intelligence. One careful reader; no markings.',60.00,22,'used',NULL,NULL,NULL,3,NOW(),0,0),
(506,'Moleskine Art Notebook Set','Three blank notebooks with heavy paper for ink and light wash. Unused, still wrapped.',95.00,3,'new',NULL,NULL,NULL,2,NOW(),0,0),
(507,'IKEA Forsa Desk Lamp','A brushed-metal work lamp with an adjustable arm. Warm bulb included; a small patina at the base.',120.00,24,'used',NULL,NULL,NULL,1,NOW(),0,0),
(508,'MUJI Beechwood Folding Chair','Solid beech, folds flat and fits a small room. Light wear on the seat, structure completely sound.',260.00,10,'used',NULL,NULL,NULL,2,NOW(),0,0),
(509,'Tiger Mini Rice Cooker','A compact 1.5-cup rice cooker for dorm kitchens. Non-stick inner pot and measuring cup included.',220.00,23,'used',NULL,NULL,NULL,3,NOW(),0,0),
(510,'Nike Air Force 1 ''07','The white-on-white classic, men''s US 9. Light creasing at the toe box, soles cleaned.',480.00,25,'used',NULL,NULL,NULL,1,NOW(),0,0),
(511,'Vintage Levi''s Denim Jacket','A faded mid-wash trucker jacket, men''s medium. Soft from wear, no rips; a genuine archive piece.',320.00,4,'used',NULL,NULL,NULL,2,NOW(),0,0),
(512,'La Roche-Posay Effaclar Duo+','A sealed 40ml tube of the clarifying serum. Bought for a skincare restock; expiry 2027.',85.00,26,'new',NULL,NULL,NULL,3,NOW(),0,0),
(513,'Dyson Supersonic Attachment Set','The flyaway and smoothing attachments for the Supersonic dryer. Unused, in the original box.',150.00,27,'new',NULL,NULL,NULL,1,NOW(),0,0),
(514,'Manduka PRO Yoga Mat','A dense, grippy 6mm mat for daily practice. Wiped down; one small mark near the corner.',450.00,28,'used',NULL,NULL,NULL,2,NOW(),0,0),
(515,'Naturehike Cloud Up 2 Tent','A lightweight two-person tent for weekend escapes. Used twice, no leaks; footprint included.',680.00,30,'used',NULL,NULL,NULL,3,NOW(),0,0),
(516,'Kalimba 17-Key Thumb Piano','A solid mahogany kalimba with a clear, bell-like tone. New in box with tuning hammer and case.',160.00,31,'new',NULL,NULL,NULL,1,NOW(),0,0),
(517,'Catan 5th Edition','The strategy classic, complete and organised. Cards are sleeved; box shows shelf wear.',180.00,32,'used',NULL,NULL,NULL,2,NOW(),0,0),
(518,'Decathlon Riverside 500 Hybrid Bike','A versatile city bike with a lightweight frame and 700c wheels. Recently serviced; lock included.',1500.00,34,'used',NULL,NULL,NULL,3,NOW(),0,0),
(519,'Xiaomi Mi Electric Scooter 3','A foldable commuter scooter with a 600W peak motor. 1,200km on the odometer; new tyres fitted.',1200.00,35,'used',NULL,NULL,NULL,1,NOW(),0,0),
(520,'Japanese Snack Box','A curated box of seasonal Japanese sweets and savoury snacks. Unopened; perfect for a study-night treat.',120.00,37,'new',NULL,NULL,NULL,2,NOW(),0,0),
(521,'Bosch 12V Drill Set','A compact cordless drill with two batteries, charger and a 40-piece bit set. Used for one move.',380.00,39,'used',NULL,NULL,NULL,3,NOW(),0,0),
(522,'Copic Sketch Marker Set (36)','The illustrator''s choice — 36 blendable alcohol markers in a carry case. Light use, caps all matched.',780.00,41,'used',NULL,NULL,NULL,1,NOW(),0,0),
(523,'Epoxy Resin Craft Kit','A beginner resin set with pigments, moulds and gloves. Unopened; everything needed for a first pour.',140.00,42,'new',NULL,NULL,NULL,2,NOW(),0,0),
(524,'Bauhaus Exhibition Poster','An archival print from the 2019 Bauhaus centenary. 50×70cm, stored flat; never framed.',220.00,43,'new',NULL,NULL,NULL,3,NOW(),0,0),
(525,'Clockenflap Weekend Pass','A three-day pass for Hong Kong''s waterfront music festival. Digital transfer; buyer details required.',950.00,45,'new',NULL,NULL,NULL,1,NOW(),0,0),
(526,'Starbucks Gift Card HK$200','A loaded Starbucks card with HK$200 balance. Valid at all Hong Kong stores; expiry 2028.',180.00,47,'new',NULL,NULL,NULL,2,NOW(),0,0),
(527,'Coursera UX Specialization','Full access to the Google UX Design certificate track. Self-paced, certificate included.',300.00,50,'new',NULL,NULL,NULL,3,NOW(),0,0),
(528,'Steam Account — 40-Game Library','A curated account with 40 indie and strategy titles. Email change supported; no VAC bans.',420.00,49,'used',NULL,NULL,NULL,1,NOW(),0,0),
(529,'MUJI Acrylic Storage Case','A stackable two-drawer case for desk clutter. Clear acrylic, one small scuff on the top.',70.00,16,'used',NULL,NULL,NULL,2,NOW(),0,0),
(530,'Vintage Film Camera','A 1980s 35mm rangefinder with a working light meter. Untested with film; sold as a display piece.',650.00,16,'used',NULL,NULL,NULL,3,NOW(),0,0);

COMMIT;

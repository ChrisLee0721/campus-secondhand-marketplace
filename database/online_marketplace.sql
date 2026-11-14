/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `_migrations` (
  `name` varchar(100) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `_migrations` WRITE;
/*!40000 ALTER TABLE `_migrations` DISABLE KEYS */;
INSERT INTO `_migrations` VALUES
('legacy_db_01_products','2026-10-28 14:50:57');
/*!40000 ALTER TABLE `_migrations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `cart`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `added_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_product` (`user_id`,`product_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_qty_chk` CHECK (`quantity` = 1)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `cart` WRITE;
/*!40000 ALTER TABLE `cart` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE` (`name`),
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `categories_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES
(1,'Textbooks',14),
(2,'Computers',13),
(3,'Stationery',14),
(4,'Clothing',7),
(5,'Storage',9),
(6,'Leisure',NULL),
(7,'Fashion',NULL),
(8,'Beauty',NULL),
(9,'Dorm & Furniture',NULL),
(10,'Furniture',9),
(11,'Bags',7),
(12,'Sports',NULL),
(13,'Electronics',NULL),
(14,'Books & Study',NULL),
(15,'Other',NULL),
(16,'Uncategorized',15),
(17,'Vehicles',NULL),
(18,'Phones',13),
(19,'Cameras',13),
(20,'Audio',13),
(21,'Exam Prep',14),
(22,'Fiction',14),
(23,'Appliances',9),
(24,'Decor',9),
(25,'Shoes',7),
(26,'Skincare',8),
(27,'Personal Care',8),
(28,'Fitness',12),
(29,'Ball Sports',12),
(30,'Outdoor',12),
(31,'Instruments',6),
(32,'Games',6),
(33,'Collectibles',6),
(34,'Bikes & Boards',17),
(35,'Scooters',17);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `buyer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `platform_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `seller_income` decimal(10,2) NOT NULL DEFAULT 0.00,
  `order_date` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('pending','completed','cancelled') DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `buyer_id` (`buyer_id`),
  KEY `product_id` (`product_id`),
  KEY `idx_buyer_date` (`buyer_id`,`order_date`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `orders_qty_chk` CHECK (`quantity` = 1)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `product_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `body` varchar(1000) NOT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_time` (`product_id`,`created_at`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `pc_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pc_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `product_comments` WRITE;
/*!40000 ALTER TABLE `product_comments` DISABLE KEYS */;
INSERT INTO `product_comments` VALUES
(10,39,1,NULL,'看起来不错！',0,'2026-10-28 17:06:33'),
(13,39,1,10,'谢谢！',0,'2026-10-28 17:06:40'),
(14,39,1,10,'太棒了！',0,'2026-10-28 17:06:46');
/*!40000 ALTER TABLE `product_comments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `status` enum('new','used','damaged') NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `image_blob` longblob DEFAULT NULL,
  `image_mime` varchar(100) DEFAULT NULL,
  `seller_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_sold` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `seller_id` (`seller_id`),
  KEY `idx_listing` (`is_sold`,`created_at`,`id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products`
  (`id`,`title`,`description`,`price`,`category_id`,`status`,`image_path`,`seller_id`,`created_at`,`is_sold`)
VALUES
(3,'C 程序设计 谭浩强','经典教材，封面有折痕，内页干净。',20.00,1,'used','uploads/seed2/book-cprog.jpg',3,'2026-09-03 06:05:00',0),
(7,'小米插线板 3 位','带 2 个 USB 口，用了一学期，功能正常。',25.00,5,'used','uploads/seed2/home-powerstrip.jpg',1,'2026-09-06 08:45:00',0),
(8,'保温杯 500ml','膳魔师同款，保温效果好。杯底有一点磕碰，不影响使用。',45.00,5,'damaged','uploads/seed2/home-thermos.jpg',3,'2026-09-07 05:15:00',0),
(9,'收纳箱 60L','带滑轮，装过衣服。盖子有一道裂纹但还能扣住。',30.00,5,'damaged','uploads/seed2/home-storagebox.jpg',3,'2026-09-07 12:05:00',0),
(15,'显示器支架','单臂气压式，支持 17-27 寸。因为换桌子了所以出。',90.00,5,'used','uploads/seed2/home-monitorarm.jpg',3,'2026-09-10 10:00:00',0),
(18,'羽绒服 男款 M 码','充绒量 180g，冬天在图书馆穿刚好。袖口有一点起球。',260.00,4,'used','uploads/seed2/cloth-down.jpg',2,'2026-09-12 02:05:00',0),
(22,'绘图板 Wacom CTL-472','入门款数位板，笔和备用笔尖都在。',240.00,3,'used','uploads/seed2/study-tablet.jpg',1,'2026-09-14 01:50:00',0),
(24,'电热水壶 1.5L','宿舍禁用大功率前买的，用了半年。自动断电正常。',55.00,5,'used','uploads/seed2/home-kettle.jpg',2,'2026-09-15 00:45:00',0),
(25,'泡面碗 带盖','陶瓷材质，微波炉可用。买重了，全新未用。',18.00,5,'new','uploads/seed2/home-bowl.jpg',3,'2026-09-15 05:30:00',0),
(27,'电动打蛋器','烘焙社活动剩下，用过三次。',45.00,6,'used','uploads/seed2/food-mixer.jpg',1,'2026-09-16 07:35:00',0),
(36,'遮光窗帘 1.5×2m','宿舍用，遮光效果不错。已洗过。',45.00,5,'used','uploads/seed2/home-curtain.jpg',2,'2026-09-21 03:55:00',0),
(39,'相机 索尼 A6000','套机 16-50mm，快门数约 1.2 万。附两块电池和相机包。',2100.00,2,'used','uploads/seed2/elec-camera.jpg',1,'2026-09-22 05:45:00',0),
(41,'iPhone 13 Pro','苹果最新旗舰智能手机，A15仿生芯片，超视网膜XDR显示屏',8999.00,2,'used','uploads/seed/ext-photo-1601784551446.jpg',2,'2025-03-29 20:58:47',0),
(42,'MacBook Pro 14英寸','M1 Pro芯片，Liquid视网膜XDR显示屏，16GB内存，512GB SSD',14999.00,2,'used','uploads/seed/ext-photo-1611186871348.jpg',3,'2025-03-29 21:28:47',0),
(43,'AirPods Pro','主动降噪无线耳机，通透模式，空间音频',1999.00,2,'new','uploads/seed/ext-photo-1590658268037.jpg',1,'2025-03-29 21:58:47',0),
(44,'iPad Air','10.9英寸全面屏，A14仿生芯片，支持Apple Pencil',4799.00,2,'used','uploads/seed/ext-photo-1587314168485.jpg',2,'2025-03-29 22:28:47',0),
(45,'Apple Watch Series 7','45毫米GPS版，全天候视网膜显示屏，血氧监测',3199.00,2,'used','uploads/seed/ext-photo-1546868871.jpg',3,'2025-03-29 22:58:47',0),
(46,'三星Galaxy S22','6.1英寸Dynamic AMOLED 2X显示屏，骁龙8 Gen1处理器',5999.00,2,'new','uploads/seed/ext-photo-1610945265064.jpg',1,'2025-03-29 23:28:47',0),
(47,'索尼PlayStation 5','光驱版，825GB SSD，支持4K游戏',4299.00,2,'used','uploads/seed/ext-photo-1607853202273.jpg',2,'2025-03-29 23:58:47',0),
(48,'任天堂Switch OLED','7英寸OLED屏幕，64GB存储，支持TV/桌面/掌机模式',2599.00,2,'used','uploads/seed/ext-photo-1592899677977.jpg',3,'2025-03-30 00:28:47',0),
(49,'Bose QuietComfort 45','主动降噪头戴式耳机，24小时续航',2299.00,2,'new','uploads/seed/ext-photo-1593784991095.jpg',1,'2025-03-30 00:58:47',0),
(50,'佳能EOS R6','全画幅微单相机，2010万像素，4K视频拍摄',15999.00,2,'damaged','uploads/seed/ext-photo-1512790182412.jpg',2,'2025-03-30 01:28:47',0),
(51,'小米12 Pro','6.73英寸AMOLED曲面屏，骁龙8 Gen1，120W快充',4999.00,2,'used','uploads/seed/ext-photo-1631729371254.jpg',3,'2025-03-30 01:58:47',0),
(52,'华为MatePad Pro','12.6英寸OLED全面屏，麒麟9000E芯片',5499.00,2,'new','uploads/seed/ext-photo-1592899677977.jpg',1,'2025-03-30 02:28:47',0),
(53,'罗技MX Master 3','无线蓝牙鼠标，人体工学设计，精准追踪',699.00,2,'used','uploads/seed/ext-photo-1527814050087.jpg',2,'2025-03-30 02:58:47',0),
(54,'戴尔XPS 13','13.4英寸4K触控屏，11代酷睿i7，16GB内存',10999.00,2,'used','uploads/seed/ext-photo-1593642632823.jpg',3,'2025-03-30 03:28:47',0),
(55,'索尼WH-1000XM4','无线降噪耳机，30小时续航，智能免摘对话',2299.00,2,'new','uploads/seed/ext-photo-1590658268037.jpg',1,'2025-03-30 03:58:47',0),
(56,'Kindle Paperwhite','6.8英寸电子墨水屏，300ppi，防水设计',1068.00,2,'used','uploads/seed/ext-photo-1527814050087.jpg',2,'2025-03-30 04:28:47',0),
(57,'Anker 20000mAh移动电源','双向快充，支持PD协议，可带上飞机',399.00,2,'used','uploads/seed/ext-photo-1585771724684.jpg',3,'2025-03-30 04:58:47',0),
(58,'飞利浦电动牙刷HX6730','3种清洁模式，31000次/分钟震动',369.00,2,'new','uploads/seed/ext-photo-1594744803329.jpg',1,'2025-03-30 05:28:47',0),
(59,'大疆Air 2S','1英寸CMOS传感器，5.4K视频，大师镜头功能',6499.00,2,'used','uploads/seed/ext-photo-1579829366248.jpg',2,'2025-03-30 05:58:47',0),
(60,'小米手环6','1.56英寸AMOLED全面屏，血氧监测，30种运动模式',229.00,2,'damaged','uploads/seed/ext-photo-1589487391730.jpg',3,'2025-03-30 06:28:47',0),
(61,'男士纯棉T恤','100%纯棉，舒适透气，多种颜色可选',99.00,4,'new','uploads/seed/ext-photo-1529374255404.jpg',1,'2025-03-30 06:58:47',0),
(62,'女士高腰牛仔裤','弹力牛仔面料，修身显瘦，四季可穿',199.00,4,'used','uploads/seed/ext-photo-1542272604.jpg',2,'2025-03-30 07:28:47',0),
(63,'男士休闲西装外套','轻薄透气，商务休闲两用，多色可选',399.00,4,'used','uploads/seed/ext-photo-1591047139829.jpg',3,'2025-03-30 07:58:47',0),
(64,'女士羊毛大衣','100%羊毛，保暖舒适，经典款式',899.00,4,'new','uploads/seed/ext-photo-1551232864.jpg',1,'2025-03-30 08:28:47',0),
(65,'运动连帽卫衣','男女同款，速干面料，适合运动休闲',159.00,4,'used','uploads/seed/ext-photo-1527719327859.jpg',2,'2025-03-30 08:58:47',0),
(66,'男士商务衬衫','免烫抗皱，修身剪裁，适合职场穿着',199.00,4,'used','uploads/seed/ext-photo-1620799140408.jpg',3,'2025-03-30 09:28:47',0),
(67,'女士雪纺连衣裙','轻盈飘逸，V领设计，春夏必备',259.00,4,'new','uploads/seed/ext-photo-1539109136881.jpg',1,'2025-03-30 09:58:47',0),
(68,'儿童纯棉套装','A类标准，柔软亲肤，多款图案可选',129.00,4,'used','uploads/seed/ext-photo-1527719327859.jpg',2,'2025-03-30 10:28:47',0),
(69,'男士速干运动短裤','透气快干，带内衬，适合跑步健身',139.00,4,'used','uploads/seed/ext-photo-1591047139829.jpg',3,'2025-03-30 10:58:47',0),
(70,'女士瑜伽裤','高弹力，提臀塑形，运动休闲两穿',179.00,4,'damaged','uploads/seed/ext-photo-1551232864.jpg',1,'2025-03-30 11:28:47',0),
(71,'男士真皮腰带','头层牛皮，简约商务风格',159.00,4,'used','uploads/seed/ext-photo-1593030103066.jpg',2,'2025-03-30 11:58:47',0),
(72,'女士羊毛围巾','100%澳洲羊毛，保暖舒适，多色可选',129.00,4,'used','uploads/seed/ext-photo-1620799140408.jpg',3,'2025-03-30 12:28:47',0),
(73,'情侣款羽绒服','90%白鸭绒填充，防风防水，冬季保暖',599.00,4,'new','uploads/seed/ext-photo-1551232864.jpg',1,'2025-03-30 12:58:47',0),
(74,'儿童运动鞋','防滑耐磨，透气网面，多色可选',199.00,4,'used','uploads/seed/ext-photo-1593030103066.jpg',2,'2025-03-30 13:28:47',0),
(75,'男士休闲皮鞋','真皮材质，舒适透气，商务休闲两用',299.00,4,'used','uploads/seed/ext-photo-1591047139829.jpg',3,'2025-03-30 13:58:47',0),
(76,'女士高跟鞋','5cm跟高，羊皮材质，优雅舒适',259.00,4,'new','uploads/seed/ext-photo-1542272604.jpg',1,'2025-03-30 14:28:47',0),
(77,'运动防风夹克','轻便防风，多口袋设计，户外运动必备',329.00,4,'used','uploads/seed/ext-photo-1527719327859.jpg',2,'2025-03-30 14:58:47',0),
(78,'男士棉麻短袖衬衫','天然棉麻，透气吸汗，夏季必备',169.00,4,'used','uploads/seed/ext-photo-1620799140408.jpg',3,'2025-03-30 15:28:47',0),
(79,'女士真丝睡衣套装','100%桑蚕丝，舒适亲肤，提升睡眠质量',499.00,4,'new','uploads/seed/ext-photo-1539109136881.jpg',1,'2025-03-30 15:58:47',0),
(80,'儿童羽绒马甲','轻薄保暖，无袖设计，方便活动',199.00,4,'damaged','uploads/seed/ext-photo-1539109136881.jpg',2,'2025-03-30 16:28:47',0),
(81,'记忆棉枕头','符合人体工学，缓解颈椎压力',199.00,5,'used','uploads/seed/ext-photo-1505693416388.jpg',3,'2025-03-30 16:58:47',0),
(82,'陶瓷餐具套装','16件套，釉下彩工艺，安全无毒',299.00,5,'new','uploads/seed/ext-photo-1581578731548.jpg',1,'2025-03-30 17:28:47',0),
(83,'无印风懒人沙发','可拆洗外套，多种颜色可选',399.00,5,'used','uploads/seed/ext-photo-1592078615290.jpg',2,'2025-03-30 17:58:47',0),
(84,'智能空气净化器','高效过滤PM2.5，除菌除味',1299.00,5,'used','uploads/seed/ext-photo-1581578731548.jpg',3,'2025-03-30 18:28:47',0),
(85,'北欧简约茶几','实木材质，环保清漆，多尺寸可选',599.00,5,'new','uploads/seed/ext-photo-1592078615290.jpg',1,'2025-03-30 18:58:47',0),
(86,'全棉四件套','100%新疆长绒棉，亲肤透气',359.00,5,'used','uploads/seed/ext-photo-1505693416388.jpg',2,'2025-03-30 19:28:47',0),
(87,'智能马桶盖','即热式，多档调节，暖风烘干',999.00,5,'used','uploads/seed/ext-photo-1581578731548.jpg',3,'2025-03-30 19:58:47',0),
(88,'不锈钢炒锅','三层复合底，不粘涂层，32cm',199.00,5,'new','uploads/seed/ext-photo-1592078615290.jpg',1,'2025-03-30 20:28:47',0),
(89,'LED护眼台灯','无频闪，多档调光，USB充电',159.00,5,'used','uploads/seed/ext-photo-1505693416388.jpg',2,'2025-03-30 20:58:47',0),
(90,'加湿器','静音设计，大容量，智能恒湿',259.00,5,'damaged','uploads/seed/ext-photo-1581578731548.jpg',3,'2025-03-30 21:28:47',0),
(91,'电动牙刷架','紫外线杀菌，自动挤牙膏',89.00,5,'new','uploads/seed/ext-photo-1592078615290.jpg',1,'2025-03-30 21:58:47',0),
(92,'厨房置物架','免打孔设计，多层收纳',129.00,5,'used','uploads/seed/ext-photo-1505693416388.jpg',2,'2025-03-30 22:28:47',0),
(93,'智能门锁','指纹/密码/钥匙三种开锁方式',1299.00,5,'used','uploads/seed/ext-photo-1581578731548.jpg',3,'2025-03-30 22:58:47',0),
(94,'地毯','北欧风格，短毛设计，易清洁',299.00,5,'used','uploads/seed/ext-photo-1505693416388.jpg',2,'2025-03-30 23:58:47',0),
(95,'玻璃水壶','耐高温，带过滤网，1.5L容量',69.00,5,'used','uploads/seed/ext-photo-1581578731548.jpg',3,'2025-03-31 00:28:47',0),
(96,'收纳箱','大容量，带滑轮，可叠放',79.00,5,'new','uploads/seed2/home-storagebox.jpg',1,'2025-03-31 00:58:47',0),
(97,'香薰机','超声波雾化，LED夜灯',149.00,5,'used','uploads/seed/ext-photo-1505693416388.jpg',2,'2025-03-31 01:28:47',0),
(98,'扫地机器人','激光导航，扫拖一体，智能规划',1999.00,5,'used','uploads/seed/ext-photo-1581578731548.jpg',3,'2025-03-31 01:58:47',0),
(99,'保温壶','不锈钢内胆，24小时保温',129.00,5,'damaged','uploads/seed/ext-photo-1592078615290.jpg',1,'2025-03-31 02:28:47',0),
(100,'特级初榨橄榄油','500ml，冷压初榨，酸度≤0.5%',89.00,6,'used','uploads/seed/ext-photo-1519735777090.jpg',2,'2025-03-31 02:58:47',0),
(101,'有机大米','5kg装，东北产区，真空包装',69.00,6,'used','uploads/seed/ext-photo-1606787366850.jpg',3,'2025-03-31 03:28:47',0),
(102,'黑巧克力','85%可可含量，无添加糖',39.00,6,'new','uploads/seed/ext-photo-1571115177098.jpg',1,'2025-03-31 03:58:47',0),
(103,'进口红酒','法国波尔多AOC级，750ml',199.00,6,'used','uploads/seed/ext-photo-1551024506.jpg',2,'2025-03-31 04:28:47',0),
(104,'有机牛奶','1L装，巴氏杀菌，高钙高蛋白',29.00,6,'used','uploads/seed/ext-photo-1550583724.jpg',3,'2025-03-31 04:58:47',0),
(105,'坚果礼盒','混合坚果，无添加，健康零食',129.00,6,'new','uploads/seed/ext-photo-1519735777090.jpg',1,'2025-03-31 05:28:47',0),
(106,'速溶咖啡','100%阿拉比卡豆，微研磨工艺',59.00,6,'used','uploads/seed/ext-photo-1606787366850.jpg',2,'2025-03-31 05:58:47',0),
(107,'有机蜂蜜','500g，天然成熟蜜，无添加',99.00,6,'used','uploads/seed/ext-photo-1571115177098.jpg',3,'2025-03-31 06:28:47',0),
(108,'冷冻牛排','澳洲谷饲眼肉牛排，200g/块',69.00,6,'new','uploads/seed/ext-photo-1551024506.jpg',1,'2025-03-31 06:58:47',0),
(109,'茶叶礼盒','特级龙井，250g，清明前采摘',299.00,6,'damaged','uploads/seed/ext-photo-1550583724.jpg',2,'2025-03-31 07:28:47',0),
(110,'意大利面','杜兰小麦制作，1kg装',29.00,6,'used','uploads/seed/ext-photo-1519735777090.jpg',3,'2025-03-31 07:58:47',0),
(111,'有机鸡蛋','15枚装，散养土鸡蛋',39.00,6,'new','uploads/seed/ext-photo-1606787366850.jpg',1,'2025-03-31 08:28:47',0),
(112,'矿泉水','天然矿泉水，500ml×24瓶',49.00,6,'used','uploads/seed/ext-photo-1571115177098.jpg',2,'2025-03-31 08:58:47',0),
(113,'燕麦片','即食燕麦，无添加，1kg装',39.00,6,'used','uploads/seed/ext-photo-1551024506.jpg',3,'2025-03-31 09:28:47',0),
(114,'冷冻虾仁','去壳去虾线，200g/包',59.00,6,'new','uploads/seed/ext-photo-1550583724.jpg',1,'2025-03-31 09:58:47',0),
(115,'水果干','混合水果干，无添加糖',45.00,6,'used','uploads/seed/ext-photo-1519735777090.jpg',2,'2025-03-31 10:28:47',0),
(116,'酱油','酿造酱油，500ml，特级',29.00,6,'used','uploads/seed/ext-photo-1606787366850.jpg',3,'2025-03-31 10:58:47',0),
(117,'能量棒','高蛋白，低糖，健身代餐',15.00,6,'new','uploads/seed/ext-photo-1571115177098.jpg',1,'2025-03-31 11:28:47',0),
(118,'气泡水','0糖0卡，多种口味可选',5.00,6,'used','uploads/seed/ext-photo-1551024506.jpg',2,'2025-03-31 11:58:47',0),
(119,'冷冻混合蔬菜','胡萝卜/玉米/豌豆，500g',19.00,6,'damaged','uploads/seed/ext-photo-1550583724.jpg',3,'2025-03-31 12:28:47',0),
(120,'三体全集','刘慈欣科幻巨著，雨果奖获奖作品',128.00,1,'new','uploads/seed/ext-photo-1544947950.jpg',1,'2025-03-31 12:58:47',0),
(121,'活着','余华经典小说，讲述中国农民的苦难人生',39.00,1,'used','uploads/seed/ext-photo-1589998059171.jpg',2,'2025-03-31 13:28:47',0),
(122,'人类简史','尤瓦尔·赫拉利著，从动物到上帝',68.00,1,'used','uploads/seed/ext-photo-1544947950.jpg',3,'2025-03-31 13:58:47',0),
(123,'Python编程从入门到实践','Python学习经典教材，实战项目丰富',89.00,1,'new','uploads/seed/ext-photo-1589998059171.jpg',1,'2025-03-31 14:28:47',0),
(124,'百年孤独','马尔克斯代表作，魔幻现实主义经典',55.00,1,'used','uploads/seed/ext-photo-1544947950.jpg',2,'2025-03-31 14:58:47',0),
(125,'小王子','圣埃克苏佩里经典童话，中英双语版',36.00,1,'used','uploads/seed/ext-photo-1589998059171.jpg',3,'2025-03-31 15:28:47',0),
(126,'原则','桥水基金创始人瑞·达利欧的人生经验',98.00,1,'new','uploads/seed/ext-photo-1544947950.jpg',1,'2025-03-31 15:58:47',0),
(127,'时间简史','霍金科普经典，探索宇宙的奥秘',45.00,1,'used','uploads/seed/ext-photo-1589998059171.jpg',2,'2025-03-31 16:28:47',0),
(128,'解忧杂货店','东野圭吾温情小说，穿越时空的治愈',49.00,1,'used','uploads/seed/ext-photo-1544947950.jpg',3,'2025-03-31 16:58:47',0),
(129,'明朝那些事儿','当年明月著，幽默讲述明朝历史',358.00,1,'damaged','uploads/seed/ext-photo-1589998059171.jpg',1,'2025-03-31 17:28:47',0),
(130,'追风筝的人','卡勒德·胡赛尼感人至深的小说',39.00,1,'used','uploads/seed/ext-photo-1544947950.jpg',2,'2025-03-31 17:58:47',0),
(131,'白夜行','东野圭吾悬疑推理经典',59.00,1,'used','uploads/seed/ext-photo-1589998059171.jpg',3,'2025-03-31 18:28:47',0),
(132,'经济学原理','曼昆经典经济学教材，第7版',128.00,1,'new','uploads/seed/ext-photo-1544947950.jpg',1,'2025-03-31 18:58:47',0),
(133,'围城','钱钟书经典小说，幽默讽刺的婚恋观',39.00,1,'used','uploads/seed/ext-photo-1589998059171.jpg',2,'2025-03-31 19:28:47',0),
(134,'沉默的大多数','王小波杂文集，犀利幽默的思想随笔',45.00,1,'used','uploads/seed/ext-photo-1544947950.jpg',3,'2025-03-31 19:58:47',0),
(135,'JavaScript高级程序设计','前端开发必读经典，第4版',119.00,1,'new','uploads/seed/ext-photo-1589998059171.jpg',1,'2025-03-31 20:28:47',0),
(136,'平凡的世界','路遥现实主义力作，茅盾文学奖作品',138.00,1,'used','uploads/seed/ext-photo-1544947950.jpg',2,'2025-03-31 20:58:47',0),
(137,'霍乱时期的爱情','马尔克斯爱情小说，跨越半个世纪的爱情',55.00,1,'used','uploads/seed/ext-photo-1589998059171.jpg',3,'2025-03-31 21:28:47',0),
(138,'刻意练习','安德斯·艾利克森著，如何从新手到大师',49.00,1,'new','uploads/seed/ext-photo-1544947950.jpg',1,'2025-03-31 21:58:47',0),
(139,'枪炮、病菌与钢铁','贾雷德·戴蒙德著，人类社会的命运',68.00,1,'damaged','uploads/seed/ext-photo-1589998059171.jpg',2,'2025-03-31 22:28:47',0);
UPDATE `products` SET `category_id` = 16 WHERE `category_id` = 6;

/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `profile_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `profile_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_user_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `body` varchar(1000) NOT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_profile_time` (`profile_user_id`,`created_at`),
  KEY `idx_author` (`author_id`),
  CONSTRAINT `pfc_author_fk` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pfc_profile_fk` FOREIGN KEY (`profile_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `profile_comments` WRITE;
/*!40000 ALTER TABLE `profile_comments` DISABLE KEYS */;
INSERT INTO `profile_comments` VALUES
(7,1,1,'看起来不错！',0,'2026-10-28 17:07:17');
/*!40000 ALTER TABLE `profile_comments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `reply_text` text DEFAULT NULL,
  `replied_at` timestamp NULL DEFAULT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order` (`order_id`),
  KEY `seller_id` (`seller_id`),
  KEY `buyer_id` (`buyer_id`),
  CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`),
  CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `reviews_chk_1` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reviews` WRITE;
/*!40000 ALTER TABLE `reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `reviews` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `bio` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_admin` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `total_spent` decimal(12,2) NOT NULL DEFAULT 0.00,
  `level` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `seller_level` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `reset_code` varchar(6) DEFAULT NULL,
  `reset_expires_at` int(11) DEFAULT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_active_at` timestamp NULL DEFAULT current_timestamp(),
  `meetup_address` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'admin','$2y$10$0Lvboi85blgrpmZIgnd9SO/kXPRMX6DXLBBhvt4uhCwSMe2Xe4ir6','admin@example.com',NULL,NULL,'2026-10-28 14:50:57',1,1,0.00,1,1,NULL,NULL,0.00,'2026-10-28 14:50:57',NULL),
(2,'alice','$2y$10$aO1MVXM9ai.mChp5DkGsSOBH24kC6c18Wt4pPwWXaTlMvIwFXrdOC','alice@example.com',NULL,NULL,'2026-10-28 14:50:57',0,1,0.00,1,1,NULL,NULL,0.00,'2026-10-28 14:50:57',NULL),
(3,'bob','$2y$10$GgbOCMhcjdO5zhOtffMB0.b690So0jiJtHIQAfpVkRlb9iPE5bVh.','bob@example.com',NULL,NULL,'2026-10-28 14:50:57',0,1,0.00,1,1,NULL,NULL,0.00,'2026-10-28 14:50:57',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Curated English catalogue for the Campus Market demo inventory.
START TRANSACTION;

UPDATE products SET title = 'C Programming (Tan Haoqiang)', description = 'A classic textbook with a creased cover and clean, unmarked pages. The fundamentals, still intact.' WHERE id = 3;
UPDATE products SET title = 'Xiaomi Power Strip (3-Outlet)', description = 'Three outlets and two USB ports; one semester of use, fully functional.' WHERE id = 7;
UPDATE products SET title = 'Thermos Bottle 500ml', description = 'Insulated steel that keeps tea hot through a long library session. A small dent at the base, purely cosmetic.' WHERE id = 8;
UPDATE products SET title = 'Storage Box 60L', description = 'A 60L wheeled box, previously used for clothes. A hairline crack in the lid, but it still clips shut.' WHERE id = 9;
UPDATE products SET title = 'Monitor Arm', description = 'Single-arm gas-spring mount for 17–27-inch screens. Rehomed after a desk change.' WHERE id = 15;
UPDATE products SET title = 'Down Jacket (Men''s M)', description = '180g fill, warm enough for winter study sessions in the library. Light pilling at the cuffs.' WHERE id = 18;
UPDATE products SET title = 'Wacom CTL-472 Drawing Tablet', description = 'An entry-level Wacom with pen and spare nibs. A gentle start for a drawing practice.' WHERE id = 22;
UPDATE products SET title = 'Electric Kettle 1.5L', description = 'Bought before the dorm banned high-power appliances. Used for six months.' WHERE id = 24;
UPDATE products SET title = 'Ceramic Noodle Bowl with Lid', description = 'Microwave-safe ceramic with a lid; bought twice by mistake, unused.' WHERE id = 25;
UPDATE products SET title = 'Electric Hand Mixer', description = 'Left over from a baking society event; used three times.' WHERE id = 27;
UPDATE products SET title = 'Blackout Curtain 1.5×2m', description = 'A dorm-ready blackout curtain with good light blocking. Washed and ready to hang.' WHERE id = 36;
UPDATE products SET title = 'Sony A6000 Camera Kit', description = 'A6000 kit with 16–50mm lens, roughly 12,000 shutter actuations. Two batteries and a camera bag included.' WHERE id = 39;
UPDATE products SET title = 'iPhone 13 Pro', description = 'Apple''s flagship smartphone with A15 Bionic and a Super Retina XDR display.' WHERE id = 41;
UPDATE products SET title = 'MacBook Pro 14"', description = 'M1 Pro, Liquid Retina XDR, 16GB memory and a 512GB SSD. A quiet workhorse.' WHERE id = 42;
UPDATE products SET title = 'AirPods Pro', description = 'Active noise cancellation, Transparency mode and Spatial Audio.' WHERE id = 43;
UPDATE products SET title = 'iPad Air', description = 'A 10.9-inch edge-to-edge display, A14 Bionic and Apple Pencil support.' WHERE id = 44;
UPDATE products SET title = 'Apple Watch Series 7', description = '45mm GPS model with an Always-On Retina display and blood-oxygen sensing.' WHERE id = 45;
UPDATE products SET title = 'Samsung Galaxy S22', description = 'A 6.1-inch Dynamic AMOLED 2X display powered by Snapdragon 8 Gen 1.' WHERE id = 46;
UPDATE products SET title = 'Sony PlayStation 5', description = 'Disc edition with an 825GB SSD and 4K gaming.' WHERE id = 47;
UPDATE products SET title = 'Nintendo Switch OLED', description = 'A seven-inch OLED screen, 64GB storage and TV, tabletop and handheld modes.' WHERE id = 48;
UPDATE products SET title = 'Bose QuietComfort 45', description = 'Active noise-cancelling over-ear headphones with 24-hour battery life.' WHERE id = 49;
UPDATE products SET title = 'Canon EOS R6', description = 'A full-frame mirrorless camera with a 20.1MP sensor and 4K video.' WHERE id = 50;
UPDATE products SET title = 'Xiaomi 12 Pro', description = 'A 6.73-inch curved AMOLED, Snapdragon 8 Gen 1 and 120W fast charging.' WHERE id = 51;
UPDATE products SET title = 'Huawei MatePad Pro', description = 'A 12.6-inch OLED display powered by the Kirin 9000E.' WHERE id = 52;
UPDATE products SET title = 'Logitech MX Master 3', description = 'A wireless Bluetooth mouse with an ergonomic shape and precise tracking.' WHERE id = 53;
UPDATE products SET title = 'Dell XPS 13', description = 'A 13.4-inch 4K touch display, 11th-gen Core i7 and 16GB memory.' WHERE id = 54;
UPDATE products SET title = 'Sony WH-1000XM4', description = 'Wireless noise-cancelling headphones with 30-hour battery and Speak-to-Chat.' WHERE id = 55;
UPDATE products SET title = 'Kindle Paperwhite', description = 'A 6.8-inch E Ink display, 300ppi, waterproof.' WHERE id = 56;
UPDATE products SET title = 'Anker 20000mAh Power Bank', description = '20,000mAh with two-way fast charging and PD support; flight-safe.' WHERE id = 57;
UPDATE products SET title = 'Philips Sonicare HX6730', description = 'Three cleaning modes and 31,000 sonic vibrations per minute.' WHERE id = 58;
UPDATE products SET title = 'DJI Air 2S', description = 'A one-inch CMOS sensor, 5.4K video and MasterShots.' WHERE id = 59;
UPDATE products SET title = 'Xiaomi Mi Band 6', description = 'A 1.56-inch AMOLED display, blood-oxygen tracking and 30 workout modes.' WHERE id = 60;
UPDATE products SET title = 'Men''s Cotton T-Shirt', description = '100% cotton, soft and breathable; several colours.' WHERE id = 61;
UPDATE products SET title = 'Women''s High-Waist Jeans', description = 'Stretch denim with a high waist and a close, flattering fit; all seasons.' WHERE id = 62;
UPDATE products SET title = 'Men''s Casual Blazer', description = 'A light, breathable blazer that moves between work and weekends; several colours.' WHERE id = 63;
UPDATE products SET title = 'Women''s Wool Coat', description = '100% wool, warm and classic.' WHERE id = 64;
UPDATE products SET title = 'Hooded Sweatshirt', description = 'A unisex quick-dry hoodie for training and off-duty days.' WHERE id = 65;
UPDATE products SET title = 'Men''s Business Shirt', description = 'Wrinkle-resistant, tailored and ready for the office.' WHERE id = 66;
UPDATE products SET title = 'Women''s Chiffon Dress', description = 'Light chiffon with a V neck; a spring and summer staple.' WHERE id = 67;
UPDATE products SET title = 'Children''s Cotton Set', description = 'A-class cotton, soft against the skin; several prints.' WHERE id = 68;
UPDATE products SET title = 'Men''s Quick-Dry Shorts', description = 'Breathable quick-dry shorts with a liner, made for running and training.' WHERE id = 69;
UPDATE products SET title = 'Women''s Yoga Leggings', description = 'High-stretch leggings with a lifting cut; gym to weekend.' WHERE id = 70;
UPDATE products SET title = 'Men''s Leather Belt', description = 'Top-grain leather in a clean, understated profile.' WHERE id = 71;
UPDATE products SET title = 'Women''s Wool Scarf', description = '100% Australian wool, warm and soft; several colours.' WHERE id = 72;
UPDATE products SET title = 'Couple''s Down Jacket', description = '90% white duck down, wind- and water-resistant for winter.' WHERE id = 73;
UPDATE products SET title = 'Kids'' Sneakers', description = 'Non-slip, durable soles with breathable mesh; several colours.' WHERE id = 74;
UPDATE products SET title = 'Men''s Leather Loafers', description = 'Leather loafers, comfortable and breathable; office or weekend.' WHERE id = 75;
UPDATE products SET title = 'Women''s Heels', description = 'A five-centimetre heel in soft sheepskin; elegant and comfortable.' WHERE id = 76;
UPDATE products SET title = 'Windproof Sport Jacket', description = 'A lightweight windproof shell with multiple pockets for the outdoors.' WHERE id = 77;
UPDATE products SET title = 'Men''s Linen-Blend Shirt', description = 'A natural cotton-linen blend, breathable and absorbent for summer.' WHERE id = 78;
UPDATE products SET title = 'Women''s Silk Pyjama Set', description = '100% mulberry silk, soft against the skin and kind to sleep.' WHERE id = 79;
UPDATE products SET title = 'Kids'' Down Vest', description = 'A lightweight down vest with a sleeveless cut for easy movement.' WHERE id = 80;
UPDATE products SET title = 'Memory Foam Pillow', description = 'Ergonomic memory foam that eases pressure on the neck.' WHERE id = 81;
UPDATE products SET title = 'Ceramic Dinnerware Set (16 Pieces)', description = 'A 16-piece underglaze ceramic set, food-safe and quietly practical.' WHERE id = 82;
UPDATE products SET title = 'Muji-Style Floor Sofa', description = 'Removable, washable cover; several colours; the softest corner of a room.' WHERE id = 83;
UPDATE products SET title = 'Smart Air Purifier', description = 'High-efficiency filtration for PM2.5, bacteria and odours.' WHERE id = 84;
UPDATE products SET title = 'Scandinavian Coffee Table', description = 'Solid wood with an eco-friendly lacquer; several sizes.' WHERE id = 85;
UPDATE products SET title = 'Cotton Bedding Set (4 Pieces)', description = '100% Xinjiang long-staple cotton, soft and breathable.' WHERE id = 86;
UPDATE products SET title = 'Smart Bidet Seat', description = 'Instant heating, multiple settings and warm-air drying.' WHERE id = 87;
UPDATE products SET title = 'Stainless Steel Wok', description = 'A three-layer composite base with a non-stick coating; 32cm.' WHERE id = 88;
UPDATE products SET title = 'LED Desk Lamp', description = 'Flicker-free, multi-level dimming and USB charging.' WHERE id = 89;
UPDATE products SET title = 'Humidifier', description = 'Quiet operation, a generous tank and smart humidity control.' WHERE id = 90;
UPDATE products SET title = 'Electric Toothbrush Holder', description = 'UV sterilisation and automatic toothpaste dispensing.' WHERE id = 91;
UPDATE products SET title = 'Kitchen Storage Rack', description = 'Drill-free, multi-tier storage for a small kitchen.' WHERE id = 92;
UPDATE products SET title = 'Smart Door Lock', description = 'Fingerprint, passcode and key entry.' WHERE id = 93;
UPDATE products SET title = 'Scandinavian Rug', description = 'A short-pile rug in a quiet Scandinavian palette; easy to clean.' WHERE id = 94;
UPDATE products SET title = 'Glass Kettle 1.5L', description = 'Heat-resistant glass with a filter; 1.5L.' WHERE id = 95;
UPDATE products SET title = 'Storage Box (Large)', description = 'Large capacity, wheels and stackable.' WHERE id = 96;
UPDATE products SET title = 'Aroma Diffuser', description = 'Ultrasonic mist and a soft LED night light.' WHERE id = 97;
UPDATE products SET title = 'Robot Vacuum', description = 'Laser navigation, vacuum and mop in one, with smart mapping.' WHERE id = 98;
UPDATE products SET title = 'Thermal Flask', description = 'A stainless steel interior that keeps drinks hot for 24 hours.' WHERE id = 99;
UPDATE products SET title = 'Extra Virgin Olive Oil', description = '500ml cold-pressed extra virgin olive oil, acidity ≤0.5%.' WHERE id = 100;
UPDATE products SET title = 'Organic Rice', description = '5kg vacuum-packed rice from Northeast China.' WHERE id = 101;
UPDATE products SET title = 'Dark Chocolate', description = '85% cocoa, no added sugar.' WHERE id = 102;
UPDATE products SET title = 'Imported Bordeaux Rouge', description = 'French Bordeaux AOC, 750ml.' WHERE id = 103;
UPDATE products SET title = 'Organic Milk', description = '1L pasteurised organic milk, high in calcium and protein.' WHERE id = 104;
UPDATE products SET title = 'Mixed Nut Gift Box', description = 'A mixed nut gift box, no additives.' WHERE id = 105;
UPDATE products SET title = 'Instant Coffee', description = '100% Arabica, micro-ground.' WHERE id = 106;
UPDATE products SET title = 'Organic Honey', description = '500g raw, naturally matured honey.' WHERE id = 107;
UPDATE products SET title = 'Frozen Ribeye Steak', description = 'Australian grain-fed ribeye, 200g per steak.' WHERE id = 108;
UPDATE products SET title = 'Longjing Tea Gift Box', description = '250g pre-Qingming Longjing tea in a gift box.' WHERE id = 109;
UPDATE products SET title = 'Italian Pasta', description = 'Durum wheat pasta, 1kg.' WHERE id = 110;
UPDATE products SET title = 'Organic Eggs', description = '15 free-range organic eggs.' WHERE id = 111;
UPDATE products SET title = 'Mineral Water', description = 'Natural mineral water, 500ml × 24.' WHERE id = 112;
UPDATE products SET title = 'Rolled Oats', description = 'Instant rolled oats, no additives, 1kg.' WHERE id = 113;
UPDATE products SET title = 'Frozen Shrimp', description = 'Peeled and deveined frozen shrimp, 200g.' WHERE id = 114;
UPDATE products SET title = 'Dried Fruit Mix', description = 'Mixed dried fruit, no added sugar.' WHERE id = 115;
UPDATE products SET title = 'Soy Sauce', description = 'Brewed soy sauce, 500ml, premium grade.' WHERE id = 116;
UPDATE products SET title = 'Protein Bars', description = 'High-protein, low-sugar bars for training days.' WHERE id = 117;
UPDATE products SET title = 'Sparkling Water', description = 'Zero sugar, zero calories, several flavours.' WHERE id = 118;
UPDATE products SET title = 'Frozen Mixed Vegetables', description = 'Carrot, corn and pea mix, 500g.' WHERE id = 119;
UPDATE products SET title = 'The Three-Body Problem (Trilogy)', description = 'Liu Cixin''s Hugo-winning science-fiction trilogy.' WHERE id = 120;
UPDATE products SET title = 'To Live', description = 'Yu Hua''s landmark novel of endurance and loss.' WHERE id = 121;
UPDATE products SET title = 'Sapiens', description = 'Yuval Noah Harari''s sweeping history of humankind.' WHERE id = 122;
UPDATE products SET title = 'Python Crash Course', description = 'A hands-on Python classic with practical projects.' WHERE id = 123;
UPDATE products SET title = 'One Hundred Years of Solitude', description = 'García Márquez''s masterpiece of magical realism.' WHERE id = 124;
UPDATE products SET title = 'The Little Prince', description = 'Saint-Exupéry''s timeless tale, in a bilingual edition.' WHERE id = 125;
UPDATE products SET title = 'Principles', description = 'Ray Dalio''s principles for work and life.' WHERE id = 126;
UPDATE products SET title = 'A Brief History of Time', description = 'Stephen Hawking''s guide to the universe.' WHERE id = 127;
UPDATE products SET title = 'The Miracles of the Namiya General Store', description = 'Higashino''s time-bending story of small miracles.' WHERE id = 128;
UPDATE products SET title = 'The Ming Dynasty', description = 'A witty, vivid history of the Ming dynasty.' WHERE id = 129;
UPDATE products SET title = 'The Kite Runner', description = 'Khaled Hosseini''s unforgettable story of friendship and guilt.' WHERE id = 130;
UPDATE products SET title = 'Journey Under the Midnight Sun', description = 'Higashino''s masterful mystery of love and shadows.' WHERE id = 131;
UPDATE products SET title = 'Principles of Economics', description = 'Mankiw''s classic introduction to economics, seventh edition.' WHERE id = 132;
UPDATE products SET title = 'Fortress Besieged', description = 'Qian Zhongshu''s comic, sharp novel of marriage and ambition.' WHERE id = 133;
UPDATE products SET title = 'The Silent Majority', description = 'Wang Xiaobo''s essays: wry, lucid and quietly radical.' WHERE id = 134;
UPDATE products SET title = 'Professional JavaScript for Web Developers', description = 'The front-end classic, fourth edition.' WHERE id = 135;
UPDATE products SET title = 'Ordinary World', description = 'Lu Yao''s realist epic, winner of the Mao Dun Prize.' WHERE id = 136;
UPDATE products SET title = 'Love in the Time of Cholera', description = 'García Márquez''s love story across half a century.' WHERE id = 137;
UPDATE products SET title = 'Peak', description = 'Anders Ericsson on how deliberate practice makes mastery.' WHERE id = 138;
UPDATE products SET title = 'Guns, Germs, and Steel', description = 'Jared Diamond''s account of geography, power and civilisation.' WHERE id = 139;

START TRANSACTION;

UPDATE product_comments SET body = 'Looks promising.' WHERE id = 10;
UPDATE product_comments SET body = 'Thank you.' WHERE id = 13;
UPDATE product_comments SET body = 'Beautiful.' WHERE id = 14;
UPDATE profile_comments SET body = 'Looks good.' WHERE id = 7;

COMMIT;
UPDATE products SET title = 'Lucky Bamboo', description = 'A resilient lucky bamboo, already rooted and easy to keep.' WHERE id = 245;
UPDATE products SET title = 'iPhone 18', description = 'Unopened. The next generation, ahead of its time.' WHERE id = 406;
UPDATE products SET title = 'Egg Noir Sleep Mask', description = 'A soft blackout eye mask for daytime sleeps between lectures.' WHERE id = 449;
UPDATE products SET title = 'Clover', description = 'A small potted clover; a quiet green corner for a desk.' WHERE id = 457;

COMMIT;

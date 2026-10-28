-- MySQL dump 10.13  Distrib 8.0.41, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: db_01
-- ------------------------------------------------------
-- Server version	8.2.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `tb_01`
--

DROP TABLE IF EXISTS `tb_01`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_01` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  PRIMARY KEY (`id`,`email`),
  UNIQUE KEY `id_UNIQUE` (`id`),
  UNIQUE KEY `email_UNIQUE` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_01`
--

LOCK TABLES `tb_01` WRITE;
/*!40000 ALTER TABLE `tb_01` DISABLE KEYS */;
INSERT INTO `tb_01` VALUES (1,'admin','admin','admin@admin.com1'),(7,'admin','$2y$10$via75x.JcmjZhDUK53.kL.71CxCG5Ed5q24kKEgwUE/i.GB/8SdnS','ad1min@admin.com'),(8,'admin','$2y$10$/kruJU2yRD3WSZXxkaNnkORyuGC3SaEDBMvGMW17lZ7a5Q98noyn2','admin@admin.com'),(9,'1','$2y$10$5ZbOOUecBFm9zU255nY1d.pChkj2qvzzPPa37.WOHAFt7a47iFDzK',''),(10,'1','$2y$10$O2L8AqSFqWNTvOLdeD3yXetI.ONh4hHupujmsDcwlHY3P1BpklMZK','1@22.com'),(11,'123','$2y$10$iotc3tO98nQMED3pMzu6oeul3CUyQKjCxwMQ5TBmBBOkLnuYW3Cdu','123@123.com'),(12,'123','$2y$10$aPw.mZjR1sa2DIntoTLFGeglAalAXeBbv3ru5UOkhrgc0OlhF1PJC','1231@123.com'),(13,'123','$2y$10$6e/8QVAglOcFvBwh1w6eFuH5IsZVTqjEOIXBS8BratdBNV6KZyRBG','12311@123.com');
/*!40000 ALTER TABLE `tb_01` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-03-31  0:48:15

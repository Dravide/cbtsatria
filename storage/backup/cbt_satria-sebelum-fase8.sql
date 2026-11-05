-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: cbt_satria
-- ------------------------------------------------------
-- Server version	8.4.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `academic_years`
--

DROP TABLE IF EXISTS `academic_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_years` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_years_school_id_name_unique` (`school_id`,`name`),
  UNIQUE KEY `academic_years_school_id_id_unique` (`school_id`,`id`),
  CONSTRAINT `academic_years_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_years`
--

LOCK TABLES `academic_years` WRITE;
/*!40000 ALTER TABLE `academic_years` DISABLE KEYS */;
INSERT INTO `academic_years` VALUES (1,1,'2026/2027','2026-07-01','2027-06-30',1,'2026-11-03 09:42:24','2026-11-03 09:42:24');
/*!40000 ALTER TABLE `academic_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint unsigned DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_logs_user_id_foreign` (`user_id`),
  KEY `activity_logs_school_id_created_at_index` (`school_id`,`created_at`),
  KEY `activity_logs_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `activity_logs_school_id_user_id_index` (`school_id`,`user_id`),
  CONSTRAINT `activity_logs_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activity_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,1,NULL,'Menambah soal','Question',1,'{\"kode\": \"Q-1-1\", \"jenis\": \"pilihan_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(2,1,NULL,'Menambah soal','Question',2,'{\"kode\": \"Q-1-2\", \"jenis\": \"jawaban_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(3,1,NULL,'Menambah soal','Question',3,'{\"kode\": \"Q-1-3\", \"jenis\": \"benar_salah\"}','127.0.0.1','2026-11-03 09:42:28'),(4,1,NULL,'Menambah soal','Question',4,'{\"kode\": \"Q-1-4\", \"jenis\": \"isian_singkat\"}','127.0.0.1','2026-11-03 09:42:28'),(5,1,NULL,'Menambah soal','Question',5,'{\"kode\": \"Q-1-5\", \"jenis\": \"menjodohkan\"}','127.0.0.1','2026-11-03 09:42:28'),(6,1,NULL,'Menambah soal','Question',6,'{\"kode\": \"Q-1-6\", \"jenis\": \"mengurutkan\"}','127.0.0.1','2026-11-03 09:42:28'),(7,1,NULL,'Menambah soal','Question',7,'{\"kode\": \"Q-1-7\", \"jenis\": \"angka\"}','127.0.0.1','2026-11-03 09:42:28'),(8,1,NULL,'Menambah soal','Question',8,'{\"kode\": \"Q-1-8\", \"jenis\": \"uraian\"}','127.0.0.1','2026-11-03 09:42:28'),(9,1,NULL,'Menambah soal','Question',9,'{\"kode\": \"Q-1-9\", \"jenis\": \"media\"}','127.0.0.1','2026-11-03 09:42:28'),(10,1,NULL,'Membuat paket soal','QuestionPackage',1,'{\"nama\": \"UAS Ganjil Matematika X\"}','127.0.0.1','2026-11-03 09:42:28'),(11,1,NULL,'Menambah butir ke paket','QuestionPackage',1,'{\"nama\": \"UAS Ganjil Matematika X\", \"ditambah\": 9}','127.0.0.1','2026-11-03 09:42:28'),(12,1,NULL,'Menandai paket siap','QuestionPackage',1,'{\"nama\": \"UAS Ganjil Matematika X\"}','127.0.0.1','2026-11-03 09:42:28'),(13,1,NULL,'Menambah soal','Question',10,'{\"kode\": \"Q-2-1\", \"jenis\": \"pilihan_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(14,1,NULL,'Menambah soal','Question',11,'{\"kode\": \"Q-2-2\", \"jenis\": \"jawaban_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(15,1,NULL,'Menambah soal','Question',12,'{\"kode\": \"Q-2-3\", \"jenis\": \"benar_salah\"}','127.0.0.1','2026-11-03 09:42:28'),(16,1,NULL,'Menambah soal','Question',13,'{\"kode\": \"Q-2-4\", \"jenis\": \"isian_singkat\"}','127.0.0.1','2026-11-03 09:42:28'),(17,1,NULL,'Menambah soal','Question',14,'{\"kode\": \"Q-2-5\", \"jenis\": \"menjodohkan\"}','127.0.0.1','2026-11-03 09:42:28'),(18,1,NULL,'Menambah soal','Question',15,'{\"kode\": \"Q-2-6\", \"jenis\": \"mengurutkan\"}','127.0.0.1','2026-11-03 09:42:28'),(19,1,NULL,'Menambah soal','Question',16,'{\"kode\": \"Q-2-7\", \"jenis\": \"angka\"}','127.0.0.1','2026-11-03 09:42:28'),(20,1,NULL,'Menambah soal','Question',17,'{\"kode\": \"Q-2-8\", \"jenis\": \"uraian\"}','127.0.0.1','2026-11-03 09:42:28'),(21,1,NULL,'Menambah soal','Question',18,'{\"kode\": \"Q-2-9\", \"jenis\": \"media\"}','127.0.0.1','2026-11-03 09:42:28'),(22,1,NULL,'Membuat paket soal','QuestionPackage',2,'{\"nama\": \"UAS Ganjil Bahasa Indonesia X\"}','127.0.0.1','2026-11-03 09:42:28'),(23,1,NULL,'Menambah butir ke paket','QuestionPackage',2,'{\"nama\": \"UAS Ganjil Bahasa Indonesia X\", \"ditambah\": 9}','127.0.0.1','2026-11-03 09:42:28'),(24,1,NULL,'Menandai paket siap','QuestionPackage',2,'{\"nama\": \"UAS Ganjil Bahasa Indonesia X\"}','127.0.0.1','2026-11-03 09:42:28'),(25,1,NULL,'Menambah soal','Question',19,'{\"kode\": \"Q-3-1\", \"jenis\": \"pilihan_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(26,1,NULL,'Menambah soal','Question',20,'{\"kode\": \"Q-3-2\", \"jenis\": \"jawaban_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(27,1,NULL,'Menambah soal','Question',21,'{\"kode\": \"Q-3-3\", \"jenis\": \"benar_salah\"}','127.0.0.1','2026-11-03 09:42:28'),(28,1,NULL,'Menambah soal','Question',22,'{\"kode\": \"Q-3-4\", \"jenis\": \"isian_singkat\"}','127.0.0.1','2026-11-03 09:42:28'),(29,1,NULL,'Menambah soal','Question',23,'{\"kode\": \"Q-3-5\", \"jenis\": \"menjodohkan\"}','127.0.0.1','2026-11-03 09:42:28'),(30,1,NULL,'Menambah soal','Question',24,'{\"kode\": \"Q-3-6\", \"jenis\": \"mengurutkan\"}','127.0.0.1','2026-11-03 09:42:28'),(31,1,NULL,'Menambah soal','Question',25,'{\"kode\": \"Q-3-7\", \"jenis\": \"angka\"}','127.0.0.1','2026-11-03 09:42:28'),(32,1,NULL,'Menambah soal','Question',26,'{\"kode\": \"Q-3-8\", \"jenis\": \"uraian\"}','127.0.0.1','2026-11-03 09:42:28'),(33,1,NULL,'Menambah soal','Question',27,'{\"kode\": \"Q-3-9\", \"jenis\": \"media\"}','127.0.0.1','2026-11-03 09:42:28'),(34,1,NULL,'Membuat paket soal','QuestionPackage',3,'{\"nama\": \"UAS Ganjil Bahasa Inggris X\"}','127.0.0.1','2026-11-03 09:42:28'),(35,1,NULL,'Menambah butir ke paket','QuestionPackage',3,'{\"nama\": \"UAS Ganjil Bahasa Inggris X\", \"ditambah\": 9}','127.0.0.1','2026-11-03 09:42:28'),(36,1,NULL,'Menandai paket siap','QuestionPackage',3,'{\"nama\": \"UAS Ganjil Bahasa Inggris X\"}','127.0.0.1','2026-11-03 09:42:28'),(37,1,NULL,'Menambah soal','Question',28,'{\"kode\": \"Q-4-1\", \"jenis\": \"pilihan_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(38,1,NULL,'Menambah soal','Question',29,'{\"kode\": \"Q-4-2\", \"jenis\": \"jawaban_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(39,1,NULL,'Menambah soal','Question',30,'{\"kode\": \"Q-4-3\", \"jenis\": \"benar_salah\"}','127.0.0.1','2026-11-03 09:42:28'),(40,1,NULL,'Menambah soal','Question',31,'{\"kode\": \"Q-4-4\", \"jenis\": \"isian_singkat\"}','127.0.0.1','2026-11-03 09:42:28'),(41,1,NULL,'Menambah soal','Question',32,'{\"kode\": \"Q-4-5\", \"jenis\": \"menjodohkan\"}','127.0.0.1','2026-11-03 09:42:28'),(42,1,NULL,'Menambah soal','Question',33,'{\"kode\": \"Q-4-6\", \"jenis\": \"mengurutkan\"}','127.0.0.1','2026-11-03 09:42:28'),(43,1,NULL,'Menambah soal','Question',34,'{\"kode\": \"Q-4-7\", \"jenis\": \"angka\"}','127.0.0.1','2026-11-03 09:42:28'),(44,1,NULL,'Menambah soal','Question',35,'{\"kode\": \"Q-4-8\", \"jenis\": \"uraian\"}','127.0.0.1','2026-11-03 09:42:28'),(45,1,NULL,'Menambah soal','Question',36,'{\"kode\": \"Q-4-9\", \"jenis\": \"media\"}','127.0.0.1','2026-11-03 09:42:28'),(46,1,NULL,'Membuat paket soal','QuestionPackage',4,'{\"nama\": \"UAS Ganjil Fisika X\"}','127.0.0.1','2026-11-03 09:42:28'),(47,1,NULL,'Menambah butir ke paket','QuestionPackage',4,'{\"nama\": \"UAS Ganjil Fisika X\", \"ditambah\": 9}','127.0.0.1','2026-11-03 09:42:28'),(48,1,NULL,'Menandai paket siap','QuestionPackage',4,'{\"nama\": \"UAS Ganjil Fisika X\"}','127.0.0.1','2026-11-03 09:42:28'),(49,1,NULL,'Menambah soal','Question',37,'{\"kode\": \"Q-5-1\", \"jenis\": \"pilihan_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(50,1,NULL,'Menambah soal','Question',38,'{\"kode\": \"Q-5-2\", \"jenis\": \"jawaban_ganda\"}','127.0.0.1','2026-11-03 09:42:28'),(51,1,NULL,'Menambah soal','Question',39,'{\"kode\": \"Q-5-3\", \"jenis\": \"benar_salah\"}','127.0.0.1','2026-11-03 09:42:28'),(52,1,NULL,'Menambah soal','Question',40,'{\"kode\": \"Q-5-4\", \"jenis\": \"isian_singkat\"}','127.0.0.1','2026-11-03 09:42:29'),(53,1,NULL,'Menambah soal','Question',41,'{\"kode\": \"Q-5-5\", \"jenis\": \"menjodohkan\"}','127.0.0.1','2026-11-03 09:42:29'),(54,1,NULL,'Menambah soal','Question',42,'{\"kode\": \"Q-5-6\", \"jenis\": \"mengurutkan\"}','127.0.0.1','2026-11-03 09:42:29'),(55,1,NULL,'Menambah soal','Question',43,'{\"kode\": \"Q-5-7\", \"jenis\": \"angka\"}','127.0.0.1','2026-11-03 09:42:29'),(56,1,NULL,'Menambah soal','Question',44,'{\"kode\": \"Q-5-8\", \"jenis\": \"uraian\"}','127.0.0.1','2026-11-03 09:42:29'),(57,1,NULL,'Menambah soal','Question',45,'{\"kode\": \"Q-5-9\", \"jenis\": \"media\"}','127.0.0.1','2026-11-03 09:42:29'),(58,1,NULL,'Membuat paket soal','QuestionPackage',5,'{\"nama\": \"UAS Ganjil Kimia X\"}','127.0.0.1','2026-11-03 09:42:29'),(59,1,NULL,'Menambah butir ke paket','QuestionPackage',5,'{\"nama\": \"UAS Ganjil Kimia X\", \"ditambah\": 9}','127.0.0.1','2026-11-03 09:42:29'),(60,1,NULL,'Menandai paket siap','QuestionPackage',5,'{\"nama\": \"UAS Ganjil Kimia X\"}','127.0.0.1','2026-11-03 09:42:29'),(61,1,NULL,'Menambah soal','Question',46,'{\"kode\": \"Q-6-1\", \"jenis\": \"pilihan_ganda\"}','127.0.0.1','2026-11-03 09:42:29'),(62,1,NULL,'Menambah soal','Question',47,'{\"kode\": \"Q-6-2\", \"jenis\": \"jawaban_ganda\"}','127.0.0.1','2026-11-03 09:42:29'),(63,1,NULL,'Menambah soal','Question',48,'{\"kode\": \"Q-6-3\", \"jenis\": \"benar_salah\"}','127.0.0.1','2026-11-03 09:42:29'),(64,1,NULL,'Menambah soal','Question',49,'{\"kode\": \"Q-6-4\", \"jenis\": \"isian_singkat\"}','127.0.0.1','2026-11-03 09:42:29'),(65,1,NULL,'Menambah soal','Question',50,'{\"kode\": \"Q-6-5\", \"jenis\": \"menjodohkan\"}','127.0.0.1','2026-11-03 09:42:29'),(66,1,NULL,'Menambah soal','Question',51,'{\"kode\": \"Q-6-6\", \"jenis\": \"mengurutkan\"}','127.0.0.1','2026-11-03 09:42:29'),(67,1,NULL,'Menambah soal','Question',52,'{\"kode\": \"Q-6-7\", \"jenis\": \"angka\"}','127.0.0.1','2026-11-03 09:42:29'),(68,1,NULL,'Menambah soal','Question',53,'{\"kode\": \"Q-6-8\", \"jenis\": \"uraian\"}','127.0.0.1','2026-11-03 09:42:29'),(69,1,NULL,'Menambah soal','Question',54,'{\"kode\": \"Q-6-9\", \"jenis\": \"media\"}','127.0.0.1','2026-11-03 09:42:29'),(70,1,NULL,'Membuat paket soal','QuestionPackage',6,'{\"nama\": \"UAS Ganjil Jaringan Dasar X\"}','127.0.0.1','2026-11-03 09:42:29'),(71,1,NULL,'Menambah butir ke paket','QuestionPackage',6,'{\"nama\": \"UAS Ganjil Jaringan Dasar X\", \"ditambah\": 9}','127.0.0.1','2026-11-03 09:42:29'),(72,1,NULL,'Menandai paket siap','QuestionPackage',6,'{\"nama\": \"UAS Ganjil Jaringan Dasar X\"}','127.0.0.1','2026-11-03 09:42:29'),(73,1,NULL,'Mengunggah berkas impor soal','QuestionImportBatch',1,'{\"baris\": 0, \"nama_asli\": \"impor-bersih.csv\"}','127.0.0.1','2026-11-03 09:42:30'),(74,1,NULL,'Mempratinjau berkas impor soal','QuestionImportBatch',1,'{\"sah\": 4, \"gagal\": 0}','127.0.0.1','2026-11-03 09:42:30'),(75,1,NULL,'Menambah soal','Question',55,'{\"kode\": \"Q-1-10\", \"jenis\": \"pilihan_ganda\"}','127.0.0.1','2026-11-03 09:42:30'),(76,1,NULL,'Menambah soal','Question',56,'{\"kode\": \"Q-1-11\", \"jenis\": \"isian_singkat\"}','127.0.0.1','2026-11-03 09:42:30'),(77,1,NULL,'Menambah soal','Question',57,'{\"kode\": \"Q-1-12\", \"jenis\": \"benar_salah\"}','127.0.0.1','2026-11-03 09:42:30'),(78,1,NULL,'Menambah soal','Question',58,'{\"kode\": \"Q-1-13\", \"jenis\": \"uraian\"}','127.0.0.1','2026-11-03 09:42:30'),(79,1,NULL,'Mengimpor soal dari berkas','QuestionImportBatch',1,'{\"gagal\": 0, \"ke_paket\": false, \"tersimpan\": 4}','127.0.0.1','2026-11-03 09:42:30'),(80,1,NULL,'Mengunggah berkas impor soal','QuestionImportBatch',2,'{\"baris\": 0, \"nama_asli\": \"impor-beralat.csv\"}','127.0.0.1','2026-11-03 09:42:30'),(81,1,NULL,'Mempratinjau berkas impor soal','QuestionImportBatch',2,'{\"sah\": 2, \"gagal\": 2}','127.0.0.1','2026-11-03 09:42:30'),(82,1,NULL,'Menambah soal','Question',59,'{\"kode\": \"Q-1-14\", \"jenis\": \"angka\"}','127.0.0.1','2026-11-03 09:42:31'),(83,1,NULL,'Menambah soal','Question',60,'{\"kode\": \"Q-1-15\", \"jenis\": \"mengurutkan\"}','127.0.0.1','2026-11-03 09:42:31'),(84,1,NULL,'Mengimpor soal dari berkas','QuestionImportBatch',2,'{\"gagal\": 2, \"ke_paket\": false, \"tersimpan\": 2}','127.0.0.1','2026-11-03 09:42:31'),(85,1,1,'Login','User',1,NULL,'127.0.0.1','2026-11-03 09:42:42'),(86,1,8,'Login','User',8,NULL,'127.0.0.1','2026-11-03 09:43:08'),(87,1,1,'Membuka token sesi','SchoolEvent',1,'{\"sesi\": \"S-1\"}','127.0.0.1','2026-11-03 09:43:29'),(88,1,2,'Login','User',2,NULL,'127.0.0.1','2026-11-03 09:56:26'),(89,1,2,'Logout','User',2,NULL,'127.0.0.1','2026-11-03 10:14:29'),(90,1,6,'Login','User',6,NULL,'127.0.0.1','2026-11-03 10:14:36'),(91,1,1,'Login','User',1,NULL,'127.0.0.1','2026-11-03 16:03:55'),(92,1,1,'Logout','User',1,NULL,'127.0.0.1','2026-11-03 16:05:05');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attempt_questions`
--

DROP TABLE IF EXISTS `attempt_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attempt_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_attempt_id` bigint unsigned NOT NULL,
  `exam_question_id` bigint unsigned NOT NULL,
  `display_order` int unsigned NOT NULL,
  `option_order_json` json DEFAULT NULL,
  `item_order_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attempt_soal_unik` (`school_id`,`exam_attempt_id`,`exam_question_id`),
  UNIQUE KEY `attempt_urutan_unik` (`school_id`,`exam_attempt_id`,`display_order`),
  UNIQUE KEY `attempt_questions_school_id_id_unique` (`school_id`,`id`),
  KEY `attempt_soal_indeks` (`school_id`,`exam_attempt_id`),
  KEY `attempt_questions_school_id_exam_question_id_foreign` (`school_id`,`exam_question_id`),
  CONSTRAINT `attempt_questions_school_id_exam_attempt_id_foreign` FOREIGN KEY (`school_id`, `exam_attempt_id`) REFERENCES `exam_attempts` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `attempt_questions_school_id_exam_question_id_foreign` FOREIGN KEY (`school_id`, `exam_question_id`) REFERENCES `exam_questions` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `attempt_questions_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attempt_questions`
--

LOCK TABLES `attempt_questions` WRITE;
/*!40000 ALTER TABLE `attempt_questions` DISABLE KEYS */;
INSERT INTO `attempt_questions` VALUES (1,1,1,2,1,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(2,1,1,6,2,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(3,1,1,9,3,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(4,1,1,8,4,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(5,1,1,7,5,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(6,1,1,3,6,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(7,1,1,5,7,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(8,1,1,1,8,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(9,1,1,4,9,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(10,1,2,6,1,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(11,1,2,1,2,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(12,1,2,5,3,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(13,1,2,9,4,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(14,1,2,2,5,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(15,1,2,7,6,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(16,1,2,8,7,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(17,1,2,3,8,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(18,1,2,4,9,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(19,1,3,4,1,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(20,1,3,8,2,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(21,1,3,1,3,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(22,1,3,9,4,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(23,1,3,5,5,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(24,1,3,3,6,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(25,1,3,2,7,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(26,1,3,6,8,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(27,1,3,7,9,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(28,1,4,4,1,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(29,1,4,6,2,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(30,1,4,5,3,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(31,1,4,8,4,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(32,1,4,9,5,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(33,1,4,3,6,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(34,1,4,2,7,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(35,1,4,7,8,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(36,1,4,1,9,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30');
/*!40000 ALTER TABLE `attempt_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_logs`
--

DROP TABLE IF EXISTS `attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `attendance_record_id` bigint unsigned NOT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  `pelaku_label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_logs_school_id_attendance_record_id_index` (`school_id`,`attendance_record_id`),
  KEY `attendance_logs_school_id_changed_by_foreign` (`school_id`,`changed_by`),
  CONSTRAINT `attendance_logs_school_id_attendance_record_id_foreign` FOREIGN KEY (`school_id`, `attendance_record_id`) REFERENCES `attendance_records` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_logs_school_id_changed_by_foreign` FOREIGN KEY (`school_id`, `changed_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `attendance_logs_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_logs`
--

LOCK TABLES `attendance_logs` WRITE;
/*!40000 ALTER TABLE `attendance_logs` DISABLE KEYS */;
INSERT INTO `attendance_logs` VALUES (1,1,9,NULL,'R-01 (tautan ruang)','pending','tidak_hadir','Data contoh Fase 6.','2026-11-03 09:42:30'),(2,1,8,NULL,'R-01 (tautan ruang)','pending','izin','Data contoh Fase 6.','2026-11-03 09:42:30'),(3,1,7,NULL,'R-01 (tautan ruang)','pending','terlambat','Data contoh Fase 6.','2026-11-03 09:42:30'),(4,1,1,NULL,'R-01 (tautan ruang)','pending','hadir',NULL,'2026-11-03 09:42:30'),(5,1,10,NULL,'R-01 (tautan ruang)','pending','hadir',NULL,'2026-11-03 09:42:30'),(6,1,11,NULL,'R-01 (tautan ruang)','pending','hadir',NULL,'2026-11-03 09:42:30'),(7,1,12,NULL,'R-01 (tautan ruang)','pending','hadir',NULL,'2026-11-03 09:42:30'),(8,1,2,NULL,'R-01 (tautan ruang)','pending','hadir',NULL,'2026-11-03 09:42:30'),(9,1,3,NULL,'R-01 (tautan ruang)','pending','hadir',NULL,'2026-11-03 09:42:30'),(10,1,4,NULL,'R-01 (tautan ruang)','pending','hadir',NULL,'2026-11-03 09:42:30');
/*!40000 ALTER TABLE `attendance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_records`
--

DROP TABLE IF EXISTS `attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_participant_id` bigint unsigned NOT NULL,
  `attendance_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `pelaku_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kehadiran_unik` (`school_id`,`exam_participant_id`),
  UNIQUE KEY `attendance_records_school_id_id_unique` (`school_id`,`id`),
  KEY `attendance_records_school_id_attendance_status_index` (`school_id`,`attendance_status`),
  KEY `attendance_records_school_id_verified_by_foreign` (`school_id`,`verified_by`),
  CONSTRAINT `attendance_records_school_id_exam_participant_id_foreign` FOREIGN KEY (`school_id`, `exam_participant_id`) REFERENCES `exam_participants` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_records_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_records_school_id_verified_by_foreign` FOREIGN KEY (`school_id`, `verified_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_records`
--

LOCK TABLES `attendance_records` WRITE;
/*!40000 ALTER TABLE `attendance_records` DISABLE KEYS */;
INSERT INTO `attendance_records` VALUES (1,1,1,'hadir',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(2,1,2,'hadir',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(3,1,3,'hadir',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(4,1,4,'hadir',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(5,1,5,'pending',NULL,NULL,NULL,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,6,'pending',NULL,NULL,NULL,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(7,1,7,'terlambat',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(8,1,8,'izin',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(9,1,9,'tidak_hadir',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(10,1,10,'hadir',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(11,1,11,'hadir',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30'),(12,1,12,'hadir',NULL,'2026-11-03 09:42:30','R-01 (tautan ruang)',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:30');
/*!40000 ALTER TABLE `attendance_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_enrollments`
--

DROP TABLE IF EXISTS `class_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_enrollments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `academic_year_id` bigint unsigned NOT NULL,
  `enrollment_date` date DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_enrollments_school_id_class_id_student_id_unique` (`school_id`,`class_id`,`student_id`),
  KEY `class_enrollments_school_id_student_id_index` (`school_id`,`student_id`),
  KEY `class_enrollments_school_id_academic_year_id_foreign` (`school_id`,`academic_year_id`),
  CONSTRAINT `class_enrollments_school_id_academic_year_id_foreign` FOREIGN KEY (`school_id`, `academic_year_id`) REFERENCES `academic_years` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `class_enrollments_school_id_class_id_foreign` FOREIGN KEY (`school_id`, `class_id`) REFERENCES `classes` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `class_enrollments_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_enrollments_school_id_student_id_foreign` FOREIGN KEY (`school_id`, `student_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_enrollments`
--

LOCK TABLES `class_enrollments` WRITE;
/*!40000 ALTER TABLE `class_enrollments` DISABLE KEYS */;
INSERT INTO `class_enrollments` VALUES (1,1,1,8,1,'2026-07-15','aktif','2026-11-03 09:42:25','2026-11-03 09:42:25'),(2,1,1,9,1,'2026-07-15','aktif','2026-11-03 09:42:26','2026-11-03 09:42:26'),(3,1,1,10,1,'2026-07-15','aktif','2026-11-03 09:42:26','2026-11-03 09:42:26'),(4,1,1,11,1,'2026-07-15','aktif','2026-11-03 09:42:26','2026-11-03 09:42:26'),(5,1,1,12,1,'2026-07-15','aktif','2026-11-03 09:42:26','2026-11-03 09:42:26'),(6,1,1,13,1,'2026-07-15','aktif','2026-11-03 09:42:27','2026-11-03 09:42:27'),(7,1,2,14,1,'2026-07-15','aktif','2026-11-03 09:42:27','2026-11-03 09:42:27'),(8,1,2,15,1,'2026-07-15','aktif','2026-11-03 09:42:27','2026-11-03 09:42:27'),(9,1,2,16,1,'2026-07-15','aktif','2026-11-03 09:42:27','2026-11-03 09:42:27'),(10,1,2,17,1,'2026-07-15','aktif','2026-11-03 09:42:27','2026-11-03 09:42:27'),(11,1,2,18,1,'2026-07-15','aktif','2026-11-03 09:42:28','2026-11-03 09:42:28'),(12,1,2,19,1,'2026-07-15','aktif','2026-11-03 09:42:28','2026-11-03 09:42:28');
/*!40000 ALTER TABLE `class_enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classes`
--

DROP TABLE IF EXISTS `classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `classes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `academic_year_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grade_level` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `homeroom_teacher_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `classes_school_id_academic_year_id_name_unique` (`school_id`,`academic_year_id`,`name`),
  UNIQUE KEY `classes_school_id_id_unique` (`school_id`,`id`),
  KEY `classes_school_id_homeroom_teacher_id_foreign` (`school_id`,`homeroom_teacher_id`),
  CONSTRAINT `classes_school_id_academic_year_id_foreign` FOREIGN KEY (`school_id`, `academic_year_id`) REFERENCES `academic_years` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `classes_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `classes_school_id_homeroom_teacher_id_foreign` FOREIGN KEY (`school_id`, `homeroom_teacher_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classes`
--

LOCK TABLES `classes` WRITE;
/*!40000 ALTER TABLE `classes` DISABLE KEYS */;
INSERT INTO `classes` VALUES (1,1,1,'X-1','X',2,'aktif','2026-11-03 09:42:25','2026-11-03 09:42:25'),(2,1,1,'X TKJ 2','X',3,'aktif','2026-11-03 09:42:25','2026-11-03 09:42:25');
/*!40000 ALTER TABLE `classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `essay_rubric_criteria`
--

DROP TABLE IF EXISTS `essay_rubric_criteria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `essay_rubric_criteria` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `criterion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `max_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `position` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rubrik_urutan` (`school_id`,`question_id`,`position`),
  CONSTRAINT `essay_rubric_criteria_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `essay_rubric_criteria_school_id_question_id_foreign` FOREIGN KEY (`school_id`, `question_id`) REFERENCES `questions` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `essay_rubric_criteria`
--

LOCK TABLES `essay_rubric_criteria` WRITE;
/*!40000 ALTER TABLE `essay_rubric_criteria` DISABLE KEYS */;
INSERT INTO `essay_rubric_criteria` VALUES (1,1,8,'Ketepatan konsep','Memakai konsep yang sesuai dengan pertanyaan.',5.00,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,8,'Kelengkapan langkah','Menuliskan langkah pengerjaan, bukan hanya hasilnya.',3.00,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,8,'Kesimpulan','Menutup jawaban dengan kesimpulan yang menjawab pertanyaan.',2.00,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,17,'Ketepatan konsep','Memakai konsep yang sesuai dengan pertanyaan.',5.00,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,17,'Kelengkapan langkah','Menuliskan langkah pengerjaan, bukan hanya hasilnya.',3.00,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(6,1,17,'Kesimpulan','Menutup jawaban dengan kesimpulan yang menjawab pertanyaan.',2.00,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(7,1,26,'Ketepatan konsep','Memakai konsep yang sesuai dengan pertanyaan.',5.00,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(8,1,26,'Kelengkapan langkah','Menuliskan langkah pengerjaan, bukan hanya hasilnya.',3.00,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(9,1,26,'Kesimpulan','Menutup jawaban dengan kesimpulan yang menjawab pertanyaan.',2.00,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(10,1,35,'Ketepatan konsep','Memakai konsep yang sesuai dengan pertanyaan.',5.00,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(11,1,35,'Kelengkapan langkah','Menuliskan langkah pengerjaan, bukan hanya hasilnya.',3.00,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(12,1,35,'Kesimpulan','Menutup jawaban dengan kesimpulan yang menjawab pertanyaan.',2.00,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(13,1,44,'Ketepatan konsep','Memakai konsep yang sesuai dengan pertanyaan.',5.00,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(14,1,44,'Kelengkapan langkah','Menuliskan langkah pengerjaan, bukan hanya hasilnya.',3.00,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(15,1,44,'Kesimpulan','Menutup jawaban dengan kesimpulan yang menjawab pertanyaan.',2.00,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(16,1,53,'Ketepatan konsep','Memakai konsep yang sesuai dengan pertanyaan.',5.00,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(17,1,53,'Kelengkapan langkah','Menuliskan langkah pengerjaan, bukan hanya hasilnya.',3.00,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(18,1,53,'Kesimpulan','Menutup jawaban dengan kesimpulan yang menjawab pertanyaan.',2.00,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(19,1,58,'Langkah pengerjaan',NULL,3.00,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(20,1,58,'Alasan',NULL,1.00,1,'2026-11-03 09:42:30','2026-11-03 09:42:30');
/*!40000 ALTER TABLE `essay_rubric_criteria` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_activities`
--

DROP TABLE IF EXISTS `event_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_activities_school_id_event_id_code_unique` (`school_id`,`event_id`,`code`),
  UNIQUE KEY `event_activities_school_id_id_unique` (`school_id`,`id`),
  CONSTRAINT `event_activities_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_activities_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_activities`
--

LOCK TABLES `event_activities` WRITE;
/*!40000 ALTER TABLE `event_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `event_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_classes`
--

DROP TABLE IF EXISTS `event_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_classes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_classes_school_id_event_id_class_id_unique` (`school_id`,`event_id`,`class_id`),
  UNIQUE KEY `event_classes_school_id_id_unique` (`school_id`,`id`),
  KEY `event_classes_school_id_class_id_foreign` (`school_id`,`class_id`),
  CONSTRAINT `event_classes_school_id_class_id_foreign` FOREIGN KEY (`school_id`, `class_id`) REFERENCES `classes` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_classes_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_classes_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_classes`
--

LOCK TABLES `event_classes` WRITE;
/*!40000 ALTER TABLE `event_classes` DISABLE KEYS */;
INSERT INTO `event_classes` VALUES (1,1,1,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,2,'2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `event_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_committee_members`
--

DROP TABLE IF EXISTS `event_committee_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_committee_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'event',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `committee_unik` (`school_id`,`event_id`,`name`),
  UNIQUE KEY `event_committee_members_school_id_id_unique` (`school_id`,`id`),
  KEY `event_committee_members_school_id_user_id_foreign` (`school_id`,`user_id`),
  CONSTRAINT `event_committee_members_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_committee_members_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_committee_members_school_id_user_id_foreign` FOREIGN KEY (`school_id`, `user_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_committee_members`
--

LOCK TABLES `event_committee_members` WRITE;
/*!40000 ALTER TABLE `event_committee_members` DISABLE KEYS */;
INSERT INTO `event_committee_members` VALUES (1,1,1,6,'Sri Handayani','pengawas',NULL,NULL,'ruang','aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,7,'Joko Prasetyo','pengawas',NULL,NULL,'ruang','aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,1,2,'Ani Mulyani','ketua',NULL,NULL,'event','aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(4,1,1,NULL,'Hendra Wijaya (Komite)','anggota','komite@smkn1.sch.id','0812-3456-7890','event','aktif','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `event_committee_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_committee_rooms`
--

DROP TABLE IF EXISTS `event_committee_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_committee_rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `committee_member_id` bigint unsigned NOT NULL,
  `room_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `committee_room_unik` (`school_id`,`committee_member_id`,`room_id`),
  KEY `event_committee_rooms_school_id_room_id_foreign` (`school_id`,`room_id`),
  CONSTRAINT `event_committee_rooms_school_id_committee_member_id_foreign` FOREIGN KEY (`school_id`, `committee_member_id`) REFERENCES `event_committee_members` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_committee_rooms_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_committee_rooms_school_id_room_id_foreign` FOREIGN KEY (`school_id`, `room_id`) REFERENCES `rooms` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_committee_rooms`
--

LOCK TABLES `event_committee_rooms` WRITE;
/*!40000 ALTER TABLE `event_committee_rooms` DISABLE KEYS */;
INSERT INTO `event_committee_rooms` VALUES (1,1,1,1),(2,1,1,2),(3,1,2,4);
/*!40000 ALTER TABLE `event_committee_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_participants`
--

DROP TABLE IF EXISTS `event_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kelas',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'terdaftar',
  `registered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_participants_school_id_event_id_student_id_unique` (`school_id`,`event_id`,`student_id`),
  UNIQUE KEY `event_participants_school_id_id_unique` (`school_id`,`id`),
  KEY `event_participants_school_id_event_id_status_index` (`school_id`,`event_id`,`status`),
  KEY `event_participants_school_id_student_id_foreign` (`school_id`,`student_id`),
  KEY `event_participants_school_id_class_id_foreign` (`school_id`,`class_id`),
  CONSTRAINT `event_participants_school_id_class_id_foreign` FOREIGN KEY (`school_id`, `class_id`) REFERENCES `classes` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `event_participants_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_participants_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_participants_school_id_student_id_foreign` FOREIGN KEY (`school_id`, `student_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_participants`
--

LOCK TABLES `event_participants` WRITE;
/*!40000 ALTER TABLE `event_participants` DISABLE KEYS */;
INSERT INTO `event_participants` VALUES (1,1,1,8,1,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,9,1,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,1,10,1,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(4,1,1,11,1,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(5,1,1,12,1,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,1,13,1,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(7,1,1,14,2,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(8,1,1,15,2,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(9,1,1,16,2,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(10,1,1,17,2,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(11,1,1,18,2,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(12,1,1,19,2,'kelas','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `event_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_question_packages`
--

DROP TABLE IF EXISTS `event_question_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_question_packages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `event_subject_id` bigint unsigned NOT NULL,
  `question_package_id` bigint unsigned NOT NULL,
  `assigned_by` bigint unsigned NOT NULL,
  `replaced_package_id` bigint unsigned DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `catatan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pemasangan_event` (`school_id`,`event_id`),
  KEY `pemasangan_mapel` (`school_id`,`event_subject_id`,`assigned_at`),
  KEY `event_question_packages_school_id_question_package_id_foreign` (`school_id`,`question_package_id`),
  KEY `pemasangan_oleh_foreign` (`school_id`,`assigned_by`),
  CONSTRAINT `event_question_packages_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_question_packages_school_id_event_subject_id_foreign` FOREIGN KEY (`school_id`, `event_subject_id`) REFERENCES `event_subjects` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_question_packages_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_question_packages_school_id_question_package_id_foreign` FOREIGN KEY (`school_id`, `question_package_id`) REFERENCES `question_packages` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `pemasangan_oleh_foreign` FOREIGN KEY (`school_id`, `assigned_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_question_packages`
--

LOCK TABLES `event_question_packages` WRITE;
/*!40000 ALTER TABLE `event_question_packages` DISABLE KEYS */;
INSERT INTO `event_question_packages` VALUES (1,1,1,1,1,1,NULL,'2026-11-03 09:42:29','Data contoh Fase 4.','2026-11-03 09:42:29'),(2,1,1,2,2,1,NULL,'2026-11-03 09:42:29','Data contoh Fase 4.','2026-11-03 09:42:29'),(3,1,1,3,3,1,NULL,'2026-11-03 09:42:29','Data contoh Fase 4.','2026-11-03 09:42:29'),(4,1,1,4,4,1,NULL,'2026-11-03 09:42:29','Data contoh Fase 4.','2026-11-03 09:42:29'),(5,1,1,5,5,1,NULL,'2026-11-03 09:42:29','Data contoh Fase 4.','2026-11-03 09:42:29'),(6,1,1,6,6,1,NULL,'2026-11-03 09:42:29','Data contoh Fase 4.','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `event_question_packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_rooms`
--

DROP TABLE IF EXISTS `event_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `room_id` bigint unsigned NOT NULL,
  `capacity_override` int unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_rooms_school_id_event_id_room_id_unique` (`school_id`,`event_id`,`room_id`),
  UNIQUE KEY `event_rooms_school_id_id_unique` (`school_id`,`id`),
  KEY `event_rooms_school_id_room_id_foreign` (`school_id`,`room_id`),
  CONSTRAINT `event_rooms_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_rooms_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_rooms_school_id_room_id_foreign` FOREIGN KEY (`school_id`, `room_id`) REFERENCES `rooms` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_rooms`
--

LOCK TABLES `event_rooms` WRITE;
/*!40000 ALTER TABLE `event_rooms` DISABLE KEYS */;
INSERT INTO `event_rooms` VALUES (1,1,1,1,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,2,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,1,4,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `event_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_subjects`
--

DROP TABLE IF EXISTS `event_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_subjects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `question_package_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_subjects_school_id_event_id_subject_id_unique` (`school_id`,`event_id`,`subject_id`),
  UNIQUE KEY `event_subjects_school_id_id_unique` (`school_id`,`id`),
  KEY `event_subjects_school_id_subject_id_foreign` (`school_id`,`subject_id`),
  KEY `event_mapel_paket_foreign` (`school_id`,`question_package_id`),
  CONSTRAINT `event_mapel_paket_foreign` FOREIGN KEY (`school_id`, `question_package_id`) REFERENCES `question_packages` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `event_subjects_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_subjects_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_subjects_school_id_subject_id_foreign` FOREIGN KEY (`school_id`, `subject_id`) REFERENCES `subjects` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_subjects`
--

LOCK TABLES `event_subjects` WRITE;
/*!40000 ALTER TABLE `event_subjects` DISABLE KEYS */;
INSERT INTO `event_subjects` VALUES (1,1,1,1,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,2,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,1,3,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(4,1,1,4,4,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(5,1,1,5,5,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,1,6,6,'2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `event_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_teacher_assignments`
--

DROP TABLE IF EXISTS `event_teacher_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_teacher_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned NOT NULL,
  `activity_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_teaching_unik` (`school_id`,`event_id`,`teacher_id`,`subject_id`,`class_id`),
  KEY `event_teacher_assignments_school_id_teacher_id_foreign` (`school_id`,`teacher_id`),
  KEY `event_teacher_assignments_school_id_subject_id_foreign` (`school_id`,`subject_id`),
  KEY `event_teacher_assignments_school_id_class_id_foreign` (`school_id`,`class_id`),
  KEY `event_teacher_assignments_school_id_activity_id_foreign` (`school_id`,`activity_id`),
  CONSTRAINT `event_teacher_assignments_school_id_activity_id_foreign` FOREIGN KEY (`school_id`, `activity_id`) REFERENCES `event_activities` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_teacher_assignments_school_id_class_id_foreign` FOREIGN KEY (`school_id`, `class_id`) REFERENCES `classes` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_teacher_assignments_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_teacher_assignments_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_teacher_assignments_school_id_subject_id_foreign` FOREIGN KEY (`school_id`, `subject_id`) REFERENCES `subjects` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `event_teacher_assignments_school_id_teacher_id_foreign` FOREIGN KEY (`school_id`, `teacher_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_teacher_assignments`
--

LOCK TABLES `event_teacher_assignments` WRITE;
/*!40000 ALTER TABLE `event_teacher_assignments` DISABLE KEYS */;
INSERT INTO `event_teacher_assignments` VALUES (1,1,1,2,1,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,2,1,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,1,3,2,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(4,1,1,3,2,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(5,1,1,4,3,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,1,4,3,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(7,1,1,5,4,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(8,1,1,5,4,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(9,1,1,5,5,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(10,1,1,5,5,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(11,1,1,5,6,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(12,1,1,5,6,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `event_teacher_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_attempts`
--

DROP TABLE IF EXISTS `exam_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_participant_id` bigint unsigned NOT NULL,
  `attempt_number` tinyint unsigned NOT NULL DEFAULT '1',
  `started_at` timestamp NULL DEFAULT NULL,
  `deadline_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `last_heartbeat_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `device_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'berjalan',
  `violation_count` int unsigned NOT NULL DEFAULT '0',
  `violation_worst_severity` tinyint unsigned NOT NULL DEFAULT '0',
  `lock_reason` text COLLATE utf8mb4_unicode_ci,
  `locked_at` timestamp NULL DEFAULT NULL,
  `guard_flags_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attempt_unik` (`school_id`,`exam_participant_id`,`attempt_number`),
  UNIQUE KEY `exam_attempts_school_id_id_unique` (`school_id`,`id`),
  KEY `exam_attempts_school_id_status_index` (`school_id`,`status`),
  CONSTRAINT `exam_attempts_school_id_exam_participant_id_foreign` FOREIGN KEY (`school_id`, `exam_participant_id`) REFERENCES `exam_participants` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_attempts_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_attempts`
--

LOCK TABLES `exam_attempts` WRITE;
/*!40000 ALTER TABLE `exam_attempts` DISABLE KEYS */;
INSERT INTO `exam_attempts` VALUES (1,1,1,1,'2026-11-03 09:30:30','2026-11-03 11:00:30','2026-11-03 09:44:14','2026-11-03 09:44:03','2026-11-03 09:44:03','desktop','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:159.0) Gecko/20100101 Firefox/159.0','dikirim',2,1,NULL,NULL,'{\"progres\": \"9 / 9 terjawab\", \"pelindung\": true, \"perangkat\": \"36510a257ecda32bc513140a17d4f39a\", \"denyut_pada\": \"2026-11-03T16:44:03+00:00\", \"layar_penuh\": false}','2026-11-03 09:42:30','2026-11-03 09:44:14'),(2,1,10,1,'2026-11-03 09:21:30','2026-11-03 10:51:30',NULL,'2026-11-03 09:42:10','2026-11-03 09:42:10','desktop','10.10.20.32','Seeder Fase 7','berjalan',1,2,NULL,NULL,'{\"progres\": \"7 / 9 terjawab\", \"pelindung\": true, \"denyut_pada\": \"2026-11-03T16:42:10+00:00\", \"layar_penuh\": true}','2026-11-03 09:42:30','2026-11-03 09:42:30'),(3,1,11,1,'2026-11-03 09:12:30','2026-11-03 10:42:30',NULL,'2026-11-03 09:42:10','2026-11-03 09:42:10','desktop','10.10.20.33','Seeder Fase 7','dikunci',1,5,'Ujian dikunci: Sesi ganda.','2026-11-03 09:42:30','{\"progres\": \"5 / 9 terjawab\", \"pelindung\": true, \"sesi_ganda\": true, \"denyut_pada\": \"2026-11-03T16:42:10+00:00\", \"layar_penuh\": true}','2026-11-03 09:42:30','2026-11-03 09:42:30'),(4,1,12,1,'2026-11-03 09:03:30','2026-11-03 10:33:30','2026-11-03 09:42:30','2026-11-03 09:35:30','2026-11-03 09:35:30',NULL,NULL,NULL,'dikirim',0,0,NULL,NULL,'{\"progres\": \"3 / 9 terjawab\"}','2026-11-03 09:42:30','2026-11-03 09:42:30');
/*!40000 ALTER TABLE `exam_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_classes`
--

DROP TABLE IF EXISTS `exam_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_classes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_classes_school_id_exam_id_class_id_unique` (`school_id`,`exam_id`,`class_id`),
  UNIQUE KEY `exam_classes_school_id_id_unique` (`school_id`,`id`),
  KEY `exam_classes_school_id_class_id_foreign` (`school_id`,`class_id`),
  CONSTRAINT `exam_classes_school_id_class_id_foreign` FOREIGN KEY (`school_id`, `class_id`) REFERENCES `classes` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_classes_school_id_exam_id_foreign` FOREIGN KEY (`school_id`, `exam_id`) REFERENCES `exams` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_classes_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_classes`
--

LOCK TABLES `exam_classes` WRITE;
/*!40000 ALTER TABLE `exam_classes` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_incidents`
--

DROP TABLE IF EXISTS `exam_incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_incidents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_session_room_id` bigint unsigned NOT NULL,
  `exam_participant_id` bigint unsigned DEFAULT NULL,
  `supervisor_id` bigint unsigned DEFAULT NULL,
  `pelaku_label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `incident_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_at` timestamp NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'terbuka',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_incidents_school_id_id_unique` (`school_id`,`id`),
  KEY `exam_incidents_school_id_exam_session_room_id_status_index` (`school_id`,`exam_session_room_id`,`status`),
  KEY `exam_incidents_school_id_exam_participant_id_foreign` (`school_id`,`exam_participant_id`),
  KEY `exam_incidents_school_id_supervisor_id_foreign` (`school_id`,`supervisor_id`),
  CONSTRAINT `exam_incidents_school_id_exam_participant_id_foreign` FOREIGN KEY (`school_id`, `exam_participant_id`) REFERENCES `exam_participants` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `exam_incidents_school_id_exam_session_room_id_foreign` FOREIGN KEY (`school_id`, `exam_session_room_id`) REFERENCES `exam_session_rooms` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `exam_incidents_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_incidents_school_id_supervisor_id_foreign` FOREIGN KEY (`school_id`, `supervisor_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_incidents`
--

LOCK TABLES `exam_incidents` WRITE;
/*!40000 ALTER TABLE `exam_incidents` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_incidents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_participants`
--

DROP TABLE IF EXISTS `exam_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_id` bigint unsigned NOT NULL,
  `event_participant_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `exam_session_id` bigint unsigned DEFAULT NULL,
  `exam_session_room_id` bigint unsigned DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'terdaftar',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ujian_peserta_unik` (`school_id`,`exam_id`,`student_id`),
  UNIQUE KEY `exam_participants_school_id_id_unique` (`school_id`,`id`),
  KEY `exam_participants_school_id_exam_session_id_index` (`school_id`,`exam_session_id`),
  KEY `exam_participants_school_id_event_participant_id_foreign` (`school_id`,`event_participant_id`),
  KEY `exam_participants_school_id_student_id_foreign` (`school_id`,`student_id`),
  KEY `exam_participants_school_id_exam_session_room_id_foreign` (`school_id`,`exam_session_room_id`),
  CONSTRAINT `exam_participants_school_id_event_participant_id_foreign` FOREIGN KEY (`school_id`, `event_participant_id`) REFERENCES `event_participants` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_participants_school_id_exam_id_foreign` FOREIGN KEY (`school_id`, `exam_id`) REFERENCES `exams` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_participants_school_id_exam_session_id_foreign` FOREIGN KEY (`school_id`, `exam_session_id`) REFERENCES `exam_sessions` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `exam_participants_school_id_exam_session_room_id_foreign` FOREIGN KEY (`school_id`, `exam_session_room_id`) REFERENCES `exam_session_rooms` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `exam_participants_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_participants_school_id_student_id_foreign` FOREIGN KEY (`school_id`, `student_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_participants`
--

LOCK TABLES `exam_participants` WRITE;
/*!40000 ALTER TABLE `exam_participants` DISABLE KEYS */;
INSERT INTO `exam_participants` VALUES (1,1,1,1,8,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,2,9,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,1,3,10,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(4,1,1,4,11,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(5,1,1,5,12,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,1,6,13,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(7,1,1,7,14,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(8,1,1,8,15,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(9,1,1,9,16,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(10,1,1,10,17,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(11,1,1,11,18,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29'),(12,1,1,12,19,1,1,'2026-11-03 09:42:29','terdaftar','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `exam_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_questions`
--

DROP TABLE IF EXISTS `exam_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_id` bigint unsigned NOT NULL,
  `position` smallint unsigned NOT NULL,
  `bobot` decimal(6,2) NOT NULL DEFAULT '1.00',
  `question_id` bigint unsigned DEFAULT NULL,
  `kode` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tingkat` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `judul` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `soal_json` json NOT NULL,
  `opsi_json` json DEFAULT NULL,
  `butir_json` json DEFAULT NULL,
  `kunci_json` json DEFAULT NULL,
  `rubrik_json` json DEFAULT NULL,
  `skor_json` json DEFAULT NULL,
  `pembahasan_json` json DEFAULT NULL,
  `media_json` json DEFAULT NULL,
  `dibekukan_pada` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ujian_butir_unik` (`school_id`,`exam_id`,`position`),
  UNIQUE KEY `ujian_butir_id_unik` (`school_id`,`id`),
  KEY `ujian_asal_soal` (`school_id`,`question_id`),
  CONSTRAINT `exam_questions_school_id_exam_id_foreign` FOREIGN KEY (`school_id`, `exam_id`) REFERENCES `exams` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_questions_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_questions`
--

LOCK TABLES `exam_questions` WRITE;
/*!40000 ALTER TABLE `exam_questions` DISABLE KEYS */;
INSERT INTO `exam_questions` VALUES (1,1,1,1,14.00,1,'Q-1-1','pilihan_ganda','sedang','Pilihan ganda - barisan dan deret aritmetika','{\"teks\": \"<p>Diketahui barisan aritmetika <b>4, 7, 10, 13, ...</b></p><p>Rumus suku ke-n: <b>U<sub>n</sub> = a + (n − 1)b</b></p><p>Berapakah suku ke-10 dari barisan tersebut?</p>\"}','[{\"teks\": {\"teks\": \"31\"}, \"kunci\": \"a\"}, {\"teks\": {\"teks\": \"30\"}, \"kunci\": \"b\"}, {\"teks\": {\"teks\": \"34\"}, \"kunci\": \"c\"}, {\"teks\": {\"teks\": \"28\"}, \"kunci\": \"d\"}]',NULL,'{\"opsi\": [\"a\"]}',NULL,'{\"bobot\": 14}','{\"teks\": \"<p>a = 4 dan b = 3, sehingga U<sub>10</sub> = 4 + 9 × 3 = 31.</p>\"}',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,2,12.00,2,'Q-1-2','jawaban_ganda','sulit','Jawaban ganda - barisan dan deret aritmetika','{\"teks\": \"<p>Manakah pernyataan yang <b>benar</b> tentang barisan aritmetika? (Pilih lebih dari satu)</p>\"}','[{\"teks\": {\"teks\": \"31\"}, \"kunci\": \"a\"}, {\"teks\": {\"teks\": \"30\"}, \"kunci\": \"b\"}, {\"teks\": {\"teks\": \"34\"}, \"kunci\": \"c\"}, {\"teks\": {\"teks\": \"28\"}, \"kunci\": \"d\"}]',NULL,'{\"opsi\": [\"a\", \"c\"]}',NULL,'{\"bobot\": 12, \"skor_parsial\": true}','{\"teks\": \"<p>a = 4 dan b = 3, sehingga U<sub>10</sub> = 4 + 9 × 3 = 31.</p>\"}',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,1,3,12.00,3,'Q-1-3','benar_salah','mudah','Benar/salah - barisan dan deret aritmetika','{\"teks\": \"Beda pada barisan aritmetika selalu bernilai tetap.\"}','[{\"teks\": {\"teks\": \"Benar\"}, \"kunci\": \"b\"}, {\"teks\": {\"teks\": \"Salah\"}, \"kunci\": \"s\"}]',NULL,'{\"opsi\": [\"b\"]}',NULL,'{\"bobot\": 12}','{\"teks\": \"<p>a = 4 dan b = 3, sehingga U<sub>10</sub> = 4 + 9 × 3 = 31.</p>\"}',NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(4,1,1,4,10.00,4,'Q-1-4','isian_singkat','sedang','Isian singkat - barisan dan deret aritmetika','{\"teks\": \"<p>Suku ke-5 dari barisan 4, 7, 10, ... adalah ...</p>\"}',NULL,NULL,'{\"teks\": [\"16\", \"enam belas\"]}',NULL,'{\"bobot\": 10}',NULL,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(5,1,1,5,12.00,5,'Q-1-5','menjodohkan','sedang','Menjodohkan - barisan dan deret aritmetika','{\"teks\": \"<p>Jodohkan istilah pada barisan aritmetika dengan lambangnya.</p>\"}',NULL,'[{\"grup\": \"kiri\", \"teks\": {\"teks\": \"Suku pertama\"}, \"kunci\": \"k1\", \"posisi\": null, \"pasangan\": null}, {\"grup\": \"kiri\", \"teks\": {\"teks\": \"Beda\"}, \"kunci\": \"k2\", \"posisi\": null, \"pasangan\": null}, {\"grup\": \"kiri\", \"teks\": {\"teks\": \"Suku ke-n\"}, \"kunci\": \"k3\", \"posisi\": null, \"pasangan\": null}, {\"grup\": \"kanan\", \"teks\": {\"teks\": \"a\"}, \"kunci\": \"a\", \"posisi\": null, \"pasangan\": null}, {\"grup\": \"kanan\", \"teks\": {\"teks\": \"b\"}, \"kunci\": \"b\", \"posisi\": null, \"pasangan\": null}, {\"grup\": \"kanan\", \"teks\": {\"teks\": \"U<sub>n</sub>\"}, \"kunci\": \"c\", \"posisi\": null, \"pasangan\": null}]','{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}',NULL,'{\"bobot\": 12}',NULL,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,1,6,10.00,6,'Q-1-6','mengurutkan','sulit','Mengurutkan - barisan dan deret aritmetika','{\"teks\": \"<p>Urutkan langkah menyelesaikan soal barisan aritmetika dari yang pertama.</p>\"}',NULL,'[{\"grup\": \"kiri\", \"teks\": {\"teks\": \"Menuliskan suku pertama dan beda\"}, \"kunci\": \"k1\", \"posisi\": null, \"pasangan\": null}, {\"grup\": \"kiri\", \"teks\": {\"teks\": \"Menyusun rumus suku ke-n\"}, \"kunci\": \"k2\", \"posisi\": null, \"pasangan\": null}, {\"grup\": \"kiri\", \"teks\": {\"teks\": \"Menghitung suku yang diminta\"}, \"kunci\": \"k3\", \"posisi\": null, \"pasangan\": null}]','{\"urutan\": [\"k3\", \"k1\", \"k2\"]}',NULL,'{\"bobot\": 10}',NULL,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(7,1,1,7,10.00,7,'Q-1-7','angka','sedang','Angka - barisan dan deret aritmetika','{\"teks\": \"<p>Pada barisan 4, 7, 10, ... berapakah bedanya?</p>\"}',NULL,NULL,'{\"nilai\": 3, \"satuan\": \"\", \"toleransi\": 0}',NULL,'{\"bobot\": 10}',NULL,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(8,1,1,8,10.00,8,'Q-1-8','uraian','sulit','Uraian - barisan dan deret aritmetika','{\"teks\": \"<p>Sebuah gedung pertemuan memiliki 20 baris kursi. Baris pertama berisi 12 kursi, dan setiap baris berikutnya bertambah 3 kursi. Berapa banyak kursi di baris terakhir? Tuliskan langkah pengerjaanmu.</p>\"}',NULL,NULL,NULL,'[{\"maks\": 5, \"kriteria\": \"Ketepatan konsep\", \"keterangan\": \"Memakai konsep yang sesuai dengan pertanyaan.\"}, {\"maks\": 3, \"kriteria\": \"Kelengkapan langkah\", \"keterangan\": \"Menuliskan langkah pengerjaan, bukan hanya hasilnya.\"}, {\"maks\": 2, \"kriteria\": \"Kesimpulan\", \"keterangan\": \"Menutup jawaban dengan kesimpulan yang menjawab pertanyaan.\"}]','{\"bobot\": 10}',NULL,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(9,1,1,9,10.00,9,'Q-1-9','media','sedang','Media - barisan dan deret aritmetika','{\"teks\": \"<p>Perhatikan grafik hasil pengukuran pada gambar, lalu jawab pertanyaannya.</p>\"}','[{\"teks\": {\"teks\": \"Nilai tertinggi ada di batang ketiga\"}, \"kunci\": \"a\"}, {\"teks\": {\"teks\": \"Nilai tertinggi ada di batang pertama\"}, \"kunci\": \"b\"}, {\"teks\": {\"teks\": \"Seluruh batang sama tinggi\"}, \"kunci\": \"c\"}, {\"teks\": {\"teks\": \"Nilai terendah ada di batang kedua\"}, \"kunci\": \"d\"}]',NULL,'{\"opsi\": [\"a\"]}',NULL,'{\"bobot\": 10, \"mekanisme\": \"pilihan\"}',NULL,'[{\"id\": 1, \"mime\": \"image/png\", \"nama\": \"grafik-batang-matematika.png\", \"tipe\": \"gambar\", \"ukuran\": 1293, \"metadata\": {\"judul\": \"Grafik hasil pengukuran\"}}]','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `exam_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_session_rooms`
--

DROP TABLE IF EXISTS `exam_session_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_session_rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_session_id` bigint unsigned NOT NULL,
  `event_room_id` bigint unsigned NOT NULL,
  `capacity_override` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sesi_ruang_unik` (`school_id`,`exam_session_id`,`event_room_id`),
  UNIQUE KEY `exam_session_rooms_school_id_id_unique` (`school_id`,`id`),
  KEY `exam_session_rooms_school_id_event_room_id_foreign` (`school_id`,`event_room_id`),
  CONSTRAINT `exam_session_rooms_school_id_event_room_id_foreign` FOREIGN KEY (`school_id`, `event_room_id`) REFERENCES `event_rooms` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_session_rooms_school_id_exam_session_id_foreign` FOREIGN KEY (`school_id`, `exam_session_id`) REFERENCES `exam_sessions` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_session_rooms_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_session_rooms`
--

LOCK TABLES `exam_session_rooms` WRITE;
/*!40000 ALTER TABLE `exam_session_rooms` DISABLE KEYS */;
INSERT INTO `exam_session_rooms` VALUES (1,1,1,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,2,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(4,1,2,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(5,1,3,1,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,3,2,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `exam_session_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_sessions`
--

DROP TABLE IF EXISTS `exam_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_id` bigint unsigned NOT NULL,
  `session_number` tinyint unsigned NOT NULL,
  `access_opens_at` timestamp NOT NULL,
  `access_closes_at` timestamp NOT NULL,
  `hard_end_at` timestamp NOT NULL,
  `duration_minutes` int unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sesi_nomor_unik` (`school_id`,`exam_id`,`session_number`),
  UNIQUE KEY `exam_sessions_school_id_id_unique` (`school_id`,`id`),
  KEY `exam_sessions_school_id_status_index` (`school_id`,`status`),
  CONSTRAINT `exam_sessions_school_id_exam_id_foreign` FOREIGN KEY (`school_id`, `exam_id`) REFERENCES `exams` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_sessions_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_sessions`
--

LOCK TABLES `exam_sessions` WRITE;
/*!40000 ALTER TABLE `exam_sessions` DISABLE KEYS */;
INSERT INTO `exam_sessions` VALUES (1,1,1,1,'2026-11-03 09:12:29','2026-11-03 10:42:29','2026-11-03 11:12:29',90,'berjalan','2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,2,'2026-11-04 00:30:00','2026-11-04 02:00:00','2026-11-04 02:30:00',90,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,1,3,'2026-11-05 00:30:00','2026-11-05 02:00:00','2026-11-05 02:30:00',90,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `exam_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_token_views`
--

DROP TABLE IF EXISTS `exam_token_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_token_views` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_token_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `pelaku_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `room_id` bigint unsigned DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `jenis` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dibuka',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exam_token_views_school_id_exam_token_id_index` (`school_id`,`exam_token_id`),
  KEY `exam_token_views_school_id_user_id_foreign` (`school_id`,`user_id`),
  KEY `exam_token_views_school_id_room_id_foreign` (`school_id`,`room_id`),
  CONSTRAINT `exam_token_views_school_id_exam_token_id_foreign` FOREIGN KEY (`school_id`, `exam_token_id`) REFERENCES `exam_tokens` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_token_views_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_token_views_school_id_room_id_foreign` FOREIGN KEY (`school_id`, `room_id`) REFERENCES `rooms` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `exam_token_views_school_id_user_id_foreign` FOREIGN KEY (`school_id`, `user_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_token_views`
--

LOCK TABLES `exam_token_views` WRITE;
/*!40000 ALTER TABLE `exam_token_views` DISABLE KEYS */;
INSERT INTO `exam_token_views` VALUES (1,1,1,NULL,'Seeder Fase 7',NULL,'2026-11-03 09:42:30','dibuka','2026-11-03 09:42:30'),(2,1,1,NULL,'Seeder Fase 7',NULL,'2026-11-03 09:42:30','dibuka','2026-11-03 09:42:30'),(3,1,1,NULL,'Seeder Fase 7',NULL,'2026-11-03 09:42:30','dibuka','2026-11-03 09:42:30'),(4,1,1,NULL,'Seeder Fase 7',NULL,'2026-11-03 09:42:30','dibuka','2026-11-03 09:42:30'),(5,1,1,NULL,'Seeder Fase 7',NULL,'2026-11-03 09:42:30','dibuka','2026-11-03 09:42:30'),(6,1,1,1,'Admin SMK Negeri 1',NULL,'2026-11-03 09:43:29','dibuka','2026-11-03 09:43:29'),(7,1,1,6,'Sri Handayani',2,'2026-11-03 10:15:10','dibuka','2026-11-03 10:15:10');
/*!40000 ALTER TABLE `exam_token_views` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_tokens`
--

DROP TABLE IF EXISTS `exam_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_session_id` bigint unsigned NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_encrypted` text COLLATE utf8mb4_unicode_ci,
  `valid_from` timestamp NULL DEFAULT NULL,
  `valid_until` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_tokens_school_id_id_unique` (`school_id`,`id`),
  UNIQUE KEY `exam_tokens_token_hash_unique` (`token_hash`),
  KEY `exam_tokens_school_id_exam_session_id_index` (`school_id`,`exam_session_id`),
  KEY `exam_tokens_school_id_created_by_foreign` (`school_id`,`created_by`),
  CONSTRAINT `exam_tokens_school_id_created_by_foreign` FOREIGN KEY (`school_id`, `created_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `exam_tokens_school_id_exam_session_id_foreign` FOREIGN KEY (`school_id`, `exam_session_id`) REFERENCES `exam_sessions` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_tokens_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_tokens`
--

LOCK TABLES `exam_tokens` WRITE;
/*!40000 ALTER TABLE `exam_tokens` DISABLE KEYS */;
INSERT INTO `exam_tokens` VALUES (1,1,1,'f7e1cc213eb07a4564c2a44901f730ae479ed59b84c5a87102219f8ed5b0723a','eyJpdiI6Im1VY0ZBWGZVa05ESUJCcmJybDYxV0E9PSIsInZhbHVlIjoid2EzU29mRmZrVThvWkcvZUtvWUhPQT09IiwibWFjIjoiNmU3ZDAxNDZmNTNlNTg0NGY3YmM3NDM4ZGQ1MzhhNTRhYTc5MTZhZmNlNTljNTA3NGE0ZWVkOTQzYzI2ZTRiNiIsInRhZyI6IiJ9','2026-11-03 09:12:29','2026-11-03 11:12:29',NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,2,'a26310d88e6eb8ba11191c896c9b217e5c657b4e09c541a41cf95d3987ae8191','eyJpdiI6Ilcydzhnc0Z1ajR0amZXNVdQLzcrSUE9PSIsInZhbHVlIjoiNk04L280Y1dlUWcra3NBWWJIVFpzdz09IiwibWFjIjoiY2QzYmExMGZhYmEyZWVjMmJjMjAzMWJiMjIxZTI4ODg2MzE1NmUzYWRjN2VmNGVmMTFkYThjN2Q2OTI1NjFmZCIsInRhZyI6IiJ9','2026-11-04 00:30:00','2026-11-04 02:30:00',NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,3,'ad2b57b2653a6d6f345cf92672e47ef373eccb13d9a08a1672e19f618c33f7b3','eyJpdiI6IklQUVJGMzRxemJQRkdpd3dWc3I3R0E9PSIsInZhbHVlIjoiWk9vZW1CaFBPb2I2bXNldllZYUdMZz09IiwibWFjIjoiNWMyM2YwODM1MzFlYzkxYzJlYjUzZTc3NDY2OWQyYjNkYjdiNmY0YjE3ZGY1OWViMjdmMmVmM2NmY2U0YmM3MyIsInRhZyI6IiJ9','2026-11-05 00:30:00','2026-11-05 02:30:00',NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `exam_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_violations`
--

DROP TABLE IF EXISTS `exam_violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_violations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_attempt_id` bigint unsigned NOT NULL,
  `exam_participant_id` bigint unsigned NOT NULL,
  `violation_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` tinyint unsigned NOT NULL DEFAULT '1',
  `occurred_at` timestamp NOT NULL,
  `duration_ms` int unsigned DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `reported_by_client` tinyint(1) NOT NULL DEFAULT '1',
  `resolved_by` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pelanggaran_waktu` (`school_id`,`exam_attempt_id`,`occurred_at`),
  KEY `exam_violations_school_id_exam_participant_id_index` (`school_id`,`exam_participant_id`),
  KEY `exam_violations_school_id_resolved_by_foreign` (`school_id`,`resolved_by`),
  CONSTRAINT `exam_violations_school_id_exam_attempt_id_foreign` FOREIGN KEY (`school_id`, `exam_attempt_id`) REFERENCES `exam_attempts` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_violations_school_id_exam_participant_id_foreign` FOREIGN KEY (`school_id`, `exam_participant_id`) REFERENCES `exam_participants` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exam_violations_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_violations_school_id_resolved_by_foreign` FOREIGN KEY (`school_id`, `resolved_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_violations`
--

LOCK TABLES `exam_violations` WRITE;
/*!40000 ALTER TABLE `exam_violations` DISABLE KEYS */;
INSERT INTO `exam_violations` VALUES (1,1,1,1,'pindah_tab',1,'2026-11-03 09:42:30',4000,NULL,1,NULL,NULL,'2026-11-03 09:42:30'),(2,1,1,1,'keluar_layar_penuh',1,'2026-11-03 09:42:30',NULL,NULL,1,NULL,NULL,'2026-11-03 09:42:30'),(3,1,2,10,'pintasan_terlarang',2,'2026-11-03 09:42:30',NULL,NULL,1,NULL,NULL,'2026-11-03 09:42:30'),(4,1,3,11,'sesi_ganda',5,'2026-11-03 09:42:30',NULL,NULL,1,NULL,NULL,'2026-11-03 09:42:30');
/*!40000 ALTER TABLE `exam_violations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exams`
--

DROP TABLE IF EXISTS `exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `event_activity_id` bigint unsigned DEFAULT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `question_package_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exam_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instructions` text COLLATE utf8mb4_unicode_ci,
  `duration_minutes` int unsigned NOT NULL DEFAULT '60',
  `max_attempts` tinyint unsigned NOT NULL DEFAULT '1',
  `shuffle_questions` tinyint(1) NOT NULL DEFAULT '0',
  `shuffle_options` tinyint(1) NOT NULL DEFAULT '0',
  `allow_back_navigation` tinyint(1) NOT NULL DEFAULT '1',
  `allow_late_entry` tinyint(1) NOT NULL DEFAULT '0',
  `late_entry_deadline` timestamp NULL DEFAULT NULL,
  `publish_results` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exams_school_id_event_id_code_unique` (`school_id`,`event_id`,`code`),
  UNIQUE KEY `exams_school_id_id_unique` (`school_id`,`id`),
  KEY `exams_school_id_event_id_status_index` (`school_id`,`event_id`,`status`),
  KEY `exams_school_id_event_activity_id_foreign` (`school_id`,`event_activity_id`),
  KEY `exams_school_id_subject_id_foreign` (`school_id`,`subject_id`),
  KEY `exams_school_id_created_by_foreign` (`school_id`,`created_by`),
  CONSTRAINT `exams_school_id_created_by_foreign` FOREIGN KEY (`school_id`, `created_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `exams_school_id_event_activity_id_foreign` FOREIGN KEY (`school_id`, `event_activity_id`) REFERENCES `event_activities` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exams_school_id_event_id_foreign` FOREIGN KEY (`school_id`, `event_id`) REFERENCES `school_events` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `exams_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exams_school_id_subject_id_foreign` FOREIGN KEY (`school_id`, `subject_id`) REFERENCES `subjects` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exams`
--

LOCK TABLES `exams` WRITE;
/*!40000 ALTER TABLE `exams` DISABLE KEYS */;
INSERT INTO `exams` VALUES (1,1,1,NULL,1,1,1,'UJ-2026-001','Ujian Akhir Semester Matematika','Ujian akhir semester','Kerjakan seluruh soal. Perangkat hanya boleh dipakai untuk ujian.',90,1,1,1,1,1,NULL,1,'draft','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_schools_and_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'0001_01_01_000003_create_sessions_and_password_resets_table',1),(5,'0001_01_01_000004_create_activity_logs_table',1),(6,'0002_01_01_000005_create_profile_tables',1),(7,'0002_01_01_000006_create_academic_tables',1),(8,'0002_01_01_000007_create_rooms_and_entry_links_table',1),(9,'0003_01_01_000008_create_event_tables',1),(10,'0004_01_01_000011_create_question_tables',1),(11,'0004_01_01_000012_add_package_to_event_subjects',1),(12,'0004_01_01_000015_add_subject_to_question_import_batches',1),(13,'0005_01_01_000009_create_exam_tables',1),(14,'0005_01_01_000014_create_exam_questions_table',1),(15,'0006_01_01_000010_create_attendance_tables',1),(16,'0007_01_01_000016_add_id_unique_to_exam_questions',1),(17,'0007_01_01_000017_create_attempt_answer_tables',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `question_import_batches`
--

DROP TABLE IF EXISTS `question_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `question_import_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `question_package_id` bigint unsigned DEFAULT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_asli` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `berkas_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pratinjau_json` json DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'menunggu',
  `total_rows` int unsigned NOT NULL DEFAULT '0',
  `success_rows` int unsigned NOT NULL DEFAULT '0',
  `failed_rows` int unsigned NOT NULL DEFAULT '0',
  `error_report_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `impor_berkas_unik` (`school_id`,`teacher_id`,`berkas_hash`),
  KEY `impor_guru_status` (`school_id`,`teacher_id`,`status`),
  KEY `question_import_batches_school_id_question_package_id_foreign` (`school_id`,`question_package_id`),
  KEY `impor_mapel_asing` (`school_id`,`subject_id`),
  CONSTRAINT `impor_mapel_asing` FOREIGN KEY (`school_id`, `subject_id`) REFERENCES `subjects` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `question_import_batches_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `question_import_batches_school_id_question_package_id_foreign` FOREIGN KEY (`school_id`, `question_package_id`) REFERENCES `question_packages` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `question_import_batches_school_id_teacher_id_foreign` FOREIGN KEY (`school_id`, `teacher_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `question_import_batches`
--

LOCK TABLES `question_import_batches` WRITE;
/*!40000 ALTER TABLE `question_import_batches` DISABLE KEYS */;
INSERT INTO `question_import_batches` VALUES (1,1,2,1,NULL,'laporan/impor/1/1/WrNBZCZ6TfzVSrmEJFRlZscqObfG9x0GaqimikZt.csv','impor-bersih.csv','b39e9d1f344377673769a26ffb9bb0cda44afc6de06c921a182dae40b7b230be','{\"sah\": 4, \"baris\": [{\"sah\": true, \"jenis\": \"pilihan_ganda\", \"kolom\": null, \"nomor\": 2, \"pesan\": \"Siap disimpan.\", \"muatan\": {\"code\": null, \"opsi\": [{\"position\": 0, \"option_key\": \"a\", \"content_json\": {\"teks\": \"Pilihan pertama\"}}, {\"position\": 1, \"option_key\": \"b\", \"content_json\": {\"teks\": \"Pilihan kedua\"}}, {\"position\": 2, \"option_key\": \"c\", \"content_json\": {\"teks\": \"Pilihan ketiga\"}}, {\"position\": 3, \"option_key\": \"d\", \"content_json\": {\"teks\": \"Pilihan keempat\"}}], \"butir\": [], \"title\": \"Pengertian dasar Matematika\", \"rubrik\": [], \"status\": \"siap\", \"difficulty\": \"mudah\", \"subject_id\": 1, \"prompt_json\": {\"teks\": \"Pernyataan berikut yang paling tepat menggambarkan Matematika adalah...\"}, \"question_type\": \"pilihan_ganda\", \"answer_key_json\": {\"opsi\": [\"a\"]}, \"explanation_json\": null, \"scoring_config_json\": {\"bobot\": 1}}, \"ringkas\": \"Pernyataan berikut yang paling tepat menggambarkan Matematika adalah.…\", \"jenis_pendek\": \"PG\"}, {\"sah\": true, \"jenis\": \"isian_singkat\", \"kolom\": null, \"nomor\": 3, \"pesan\": \"Siap disimpan.\", \"muatan\": {\"code\": null, \"opsi\": [], \"butir\": [], \"title\": \"Istilah kunci Matematika\", \"rubrik\": [], \"status\": \"siap\", \"difficulty\": \"sedang\", \"subject_id\": 1, \"prompt_json\": {\"teks\": \"Sebutkan istilah yang dipakai untuk menyebut Matematika pada tingkat dasar.\"}, \"question_type\": \"isian_singkat\", \"answer_key_json\": {\"teks\": [\"istilah\", \"istilahnya\"]}, \"explanation_json\": null, \"scoring_config_json\": {\"bobot\": 2}}, \"ringkas\": \"Sebutkan istilah yang dipakai untuk menyebut Matematika pada tingkat …\", \"jenis_pendek\": \"Isian\"}, {\"sah\": true, \"jenis\": \"benar_salah\", \"kolom\": null, \"nomor\": 4, \"pesan\": \"Siap disimpan.\", \"muatan\": {\"code\": null, \"opsi\": [{\"position\": 0, \"option_key\": \"b\", \"content_json\": {\"teks\": \"Benar\"}}, {\"position\": 1, \"option_key\": \"s\", \"content_json\": {\"teks\": \"Salah\"}}], \"butir\": [], \"title\": \"Pernyataan Matematika\", \"rubrik\": [], \"status\": \"siap\", \"difficulty\": \"mudah\", \"subject_id\": 1, \"prompt_json\": {\"teks\": \"Seluruh materi Matematika di tingkat X bersifat hafalan.\"}, \"question_type\": \"benar_salah\", \"answer_key_json\": {\"opsi\": [\"s\"]}, \"explanation_json\": null, \"scoring_config_json\": {\"bobot\": 1}}, \"ringkas\": \"Seluruh materi Matematika di tingkat X bersifat hafalan.\", \"jenis_pendek\": \"B/S\"}, {\"sah\": true, \"jenis\": \"uraian\", \"kolom\": null, \"nomor\": 5, \"pesan\": \"Siap disimpan.\", \"muatan\": {\"code\": null, \"opsi\": [], \"butir\": [], \"title\": \"Uraian Matematika\", \"rubrik\": [{\"position\": 0, \"criterion\": \"Langkah pengerjaan\", \"max_score\": 3, \"description\": null}, {\"position\": 1, \"criterion\": \"Alasan\", \"max_score\": 1, \"description\": null}], \"status\": \"siap\", \"difficulty\": \"sulit\", \"subject_id\": 1, \"prompt_json\": {\"teks\": \"Jelaskan langkah-langkah menyelesaikan persoalan Matematika beserta alasannya.\"}, \"question_type\": \"uraian\", \"answer_key_json\": null, \"explanation_json\": null, \"scoring_config_json\": {\"bobot\": 4}}, \"ringkas\": \"Jelaskan langkah-langkah menyelesaikan persoalan Matematika beserta a…\", \"jenis_pendek\": \"Uraian\"}], \"gagal\": 0, \"kolom\": [\"kode\", \"judul\", \"jenis\", \"tingkat\", \"bobot\", \"soal\", \"opsi_a\", \"opsi_b\", \"opsi_c\", \"opsi_d\", \"opsi_e\", \"kunci\", \"rubrik\", \"pembahasan\"], \"baris_judul\": 1}','dikonfirmasi',4,4,0,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30','2026-11-03 09:42:30'),(2,1,2,1,NULL,'laporan/impor/1/2/dBxRsKVSnhd7GBKBlquIM1zvqgAdYyU1WQuEFCBV.csv','impor-beralat.csv','0fa83a7c3df3665ab387b904872a4dc03b408ec46b4cc271f1da5b9c9433918c','{\"sah\": 2, \"baris\": [{\"sah\": true, \"jenis\": \"angka\", \"kolom\": null, \"nomor\": 2, \"pesan\": \"Siap disimpan.\", \"muatan\": {\"code\": null, \"opsi\": [], \"butir\": [], \"title\": \"Soal angka Matematika\", \"rubrik\": [], \"status\": \"siap\", \"difficulty\": \"sedang\", \"subject_id\": 1, \"prompt_json\": {\"teks\": \"Berapa hasil perhitungan pada soal Matematika berikut?\"}, \"question_type\": \"angka\", \"answer_key_json\": {\"nilai\": 12.5, \"toleransi\": 0}, \"explanation_json\": null, \"scoring_config_json\": {\"bobot\": 2}}, \"ringkas\": \"Berapa hasil perhitungan pada soal Matematika berikut?\", \"jenis_pendek\": \"Angka\"}, {\"sah\": true, \"jenis\": \"mengurutkan\", \"kolom\": null, \"nomor\": 3, \"pesan\": \"Siap disimpan.\", \"muatan\": {\"code\": null, \"opsi\": [], \"butir\": [{\"item_key\": \"a\", \"position\": 0, \"item_group\": \"kiri\", \"content_json\": {\"teks\": \"Langkah pertama\"}, \"correct_position\": null}, {\"item_key\": \"b\", \"position\": 1, \"item_group\": \"kiri\", \"content_json\": {\"teks\": \"Langkah kedua\"}, \"correct_position\": null}, {\"item_key\": \"c\", \"position\": 2, \"item_group\": \"kiri\", \"content_json\": {\"teks\": \"Langkah ketiga\"}, \"correct_position\": null}], \"title\": \"Soal urut Matematika\", \"rubrik\": [], \"status\": \"siap\", \"difficulty\": \"sedang\", \"subject_id\": 1, \"prompt_json\": {\"teks\": \"Urutkan langkah penyelesaian Matematika dari yang pertama.\"}, \"question_type\": \"mengurutkan\", \"answer_key_json\": {\"urutan\": [\"c\", \"a\", \"b\"]}, \"explanation_json\": null, \"scoring_config_json\": {\"bobot\": 2}}, \"ringkas\": \"Urutkan langkah penyelesaian Matematika dari yang pertama.\", \"jenis_pendek\": \"Urut\"}, {\"sah\": false, \"jenis\": \"menjodohkan\", \"kolom\": \"kunci\", \"nomor\": 4, \"pesan\": \"Item kiri \\\"c\\\" belum punya pasangan. Pasangan ditulis di kolom kunci, misalnya \\\"a=Jakarta; b=Bandung\\\".\", \"muatan\": null, \"ringkas\": \"Jodohkan istilah Matematika dengan artinya.\", \"jenis_pendek\": \"Jodoh\"}, {\"sah\": false, \"jenis\": null, \"kolom\": \"soal\", \"nomor\": 5, \"pesan\": \"Kolom teks soal kosong.\", \"muatan\": null, \"ringkas\": \"Soal tanpa teks Matematika\", \"jenis_pendek\": \"—\"}], \"gagal\": 2, \"kolom\": [\"kode\", \"judul\", \"jenis\", \"tingkat\", \"bobot\", \"soal\", \"opsi_a\", \"opsi_b\", \"opsi_c\", \"opsi_d\", \"opsi_e\", \"kunci\", \"rubrik\", \"pembahasan\"], \"baris_judul\": 1}','dikonfirmasi',4,2,2,'laporan/impor/1/2/laporan-galat-E2xfGXiFXR6PG4GktGsh.csv','2026-11-03 09:42:31','2026-11-03 09:42:30','2026-11-03 09:42:31');
/*!40000 ALTER TABLE `question_import_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `question_items`
--

DROP TABLE IF EXISTS `question_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `question_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `item_group` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kiri',
  `item_key` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_json` json NOT NULL,
  `correct_match_key` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correct_position` smallint unsigned DEFAULT NULL,
  `position` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `butir_item_unik` (`school_id`,`question_id`,`item_key`),
  KEY `butir_grup` (`school_id`,`question_id`,`item_group`),
  CONSTRAINT `question_items_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `question_items_school_id_question_id_foreign` FOREIGN KEY (`school_id`, `question_id`) REFERENCES `questions` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `question_items`
--

LOCK TABLES `question_items` WRITE;
/*!40000 ALTER TABLE `question_items` DISABLE KEYS */;
INSERT INTO `question_items` VALUES (1,1,5,'kiri','k1','{\"teks\": \"Suku pertama\"}',NULL,NULL,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,5,'kiri','k2','{\"teks\": \"Beda\"}',NULL,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,5,'kiri','k3','{\"teks\": \"Suku ke-n\"}',NULL,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,5,'kanan','a','{\"teks\": \"a\"}',NULL,NULL,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,5,'kanan','b','{\"teks\": \"b\"}',NULL,NULL,4,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(6,1,5,'kanan','c','{\"teks\": \"U<sub>n</sub>\"}',NULL,NULL,5,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(7,1,6,'kiri','k1','{\"teks\": \"Menuliskan suku pertama dan beda\"}',NULL,NULL,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(8,1,6,'kiri','k2','{\"teks\": \"Menyusun rumus suku ke-n\"}',NULL,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(9,1,6,'kiri','k3','{\"teks\": \"Menghitung suku yang diminta\"}',NULL,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(10,1,14,'kiri','k1','{\"teks\": \"Paragraf pembuka\"}',NULL,NULL,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(11,1,14,'kiri','k2','{\"teks\": \"Paragraf isi\"}',NULL,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(12,1,14,'kiri','k3','{\"teks\": \"Paragraf penutup\"}',NULL,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(13,1,14,'kanan','a','{\"teks\": \"Pernyataan umum\"}',NULL,NULL,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(14,1,14,'kanan','b','{\"teks\": \"Argumen pendukung\"}',NULL,NULL,4,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(15,1,14,'kanan','c','{\"teks\": \"Penegasan ulang\"}',NULL,NULL,5,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(16,1,15,'kiri','k1','{\"teks\": \"Membaca judul\"}',NULL,NULL,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(17,1,15,'kiri','k2','{\"teks\": \"Menemukan gagasan utama\"}',NULL,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(18,1,15,'kiri','k3','{\"teks\": \"Menyimpulkan isi teks\"}',NULL,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(19,1,23,'kiri','k1','{\"teks\": \"I\"}',NULL,NULL,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(20,1,23,'kiri','k2','{\"teks\": \"She\"}',NULL,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(21,1,23,'kiri','k3','{\"teks\": \"They\"}',NULL,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(22,1,23,'kanan','a','{\"teks\": \"write\"}',NULL,NULL,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(23,1,23,'kanan','b','{\"teks\": \"writes\"}',NULL,NULL,4,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(24,1,23,'kanan','c','{\"teks\": \"write\"}',NULL,NULL,5,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(25,1,24,'kiri','k1','{\"teks\": \"Identify the subject\"}',NULL,NULL,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(26,1,24,'kiri','k2','{\"teks\": \"Choose the verb form\"}',NULL,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(27,1,24,'kiri','k3','{\"teks\": \"Complete the sentence\"}',NULL,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(28,1,32,'kiri','k1','{\"teks\": \"Kecepatan\"}',NULL,NULL,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(29,1,32,'kiri','k2','{\"teks\": \"Percepatan\"}',NULL,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(30,1,32,'kiri','k3','{\"teks\": \"Perpindahan\"}',NULL,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(31,1,32,'kanan','a','{\"teks\": \"m/s\"}',NULL,NULL,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(32,1,32,'kanan','b','{\"teks\": \"m/s<sup>2</sup>\"}',NULL,NULL,4,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(33,1,32,'kanan','c','{\"teks\": \"m\"}',NULL,NULL,5,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(34,1,33,'kiri','k1','{\"teks\": \"Mencatat besaran yang diketahui\"}',NULL,NULL,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(35,1,33,'kiri','k2','{\"teks\": \"Memilih rumus yang sesuai\"}',NULL,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(36,1,33,'kiri','k3','{\"teks\": \"Menghitung dan menuliskan satuan\"}',NULL,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(37,1,41,'kiri','k1','{\"teks\": \"Proton\"}',NULL,NULL,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(38,1,41,'kiri','k2','{\"teks\": \"Elektron\"}',NULL,NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(39,1,41,'kiri','k3','{\"teks\": \"Neutron\"}',NULL,NULL,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(40,1,41,'kanan','a','{\"teks\": \"Positif\"}',NULL,NULL,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(41,1,41,'kanan','b','{\"teks\": \"Negatif\"}',NULL,NULL,4,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(42,1,41,'kanan','c','{\"teks\": \"Netral\"}',NULL,NULL,5,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(43,1,42,'kiri','k1','{\"teks\": \"Menuliskan lambang atom\"}',NULL,NULL,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(44,1,42,'kiri','k2','{\"teks\": \"Menentukan nomor atom\"}',NULL,NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(45,1,42,'kiri','k3','{\"teks\": \"Menghitung jumlah partikel\"}',NULL,NULL,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(46,1,50,'kiri','k1','{\"teks\": \"Gateway\"}',NULL,NULL,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(47,1,50,'kiri','k2','{\"teks\": \"Subnet mask\"}',NULL,NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(48,1,50,'kiri','k3','{\"teks\": \"DNS\"}',NULL,NULL,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(49,1,50,'kanan','a','{\"teks\": \"Pintu keluar jaringan\"}',NULL,NULL,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(50,1,50,'kanan','b','{\"teks\": \"Penentu batas jaringan\"}',NULL,NULL,4,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(51,1,50,'kanan','c','{\"teks\": \"Penerjemah nama domain\"}',NULL,NULL,5,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(52,1,51,'kiri','k1','{\"teks\": \"Menentukan prefix jaringan\"}',NULL,NULL,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(53,1,51,'kiri','k2','{\"teks\": \"Menghitung jumlah host\"}',NULL,NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(54,1,51,'kiri','k3','{\"teks\": \"Menetapkan rentang alamat\"}',NULL,NULL,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(55,1,60,'kiri','a','{\"teks\": \"Langkah pertama\"}',NULL,NULL,0,'2026-11-03 09:42:31','2026-11-03 09:42:31'),(56,1,60,'kiri','b','{\"teks\": \"Langkah kedua\"}',NULL,NULL,1,'2026-11-03 09:42:31','2026-11-03 09:42:31'),(57,1,60,'kiri','c','{\"teks\": \"Langkah ketiga\"}',NULL,NULL,2,'2026-11-03 09:42:31','2026-11-03 09:42:31');
/*!40000 ALTER TABLE `question_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `question_media`
--

DROP TABLE IF EXISTS `question_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `question_media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `option_id` bigint unsigned DEFAULT NULL,
  `media_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_asli` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ukuran` int unsigned NOT NULL DEFAULT '0',
  `metadata_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `media_soal` (`school_id`,`question_id`),
  KEY `question_media_school_id_option_id_foreign` (`school_id`,`option_id`),
  CONSTRAINT `question_media_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `question_media_school_id_option_id_foreign` FOREIGN KEY (`school_id`, `option_id`) REFERENCES `question_options` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `question_media_school_id_question_id_foreign` FOREIGN KEY (`school_id`, `question_id`) REFERENCES `questions` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `question_media`
--

LOCK TABLES `question_media` WRITE;
/*!40000 ALTER TABLE `question_media` DISABLE KEYS */;
INSERT INTO `question_media` VALUES (1,1,9,NULL,'gambar','image/png','soal/1/9/yC97EDl1NQlmoVrUeqEUF8tFTKK8Fiie2PmIGiqX.png','grafik-batang-matematika.png',1293,'{\"judul\": \"Grafik hasil pengukuran\"}','2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,18,NULL,'gambar','image/png','soal/1/18/cYmNoBX8rEeAteWQkhdOFia9hDOffStLQsyOI0lX.png','ilustrasi-bahasa-indonesia.png',1293,'{\"judul\": \"Ilustrasi kegiatan literasi\"}','2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,27,NULL,'gambar','image/png','soal/1/27/CovxmLlUj0d25YAPVg98G1sPl6PmWz9C3N3rs04a.png','ilustrasi-english.png',1293,'{\"judul\": \"Daily routine\"}','2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,36,NULL,'gambar','image/png','soal/1/36/LoufRXEyw3EYHAqVmg7KS8PCI8g8HTsTI8ycEPw4.png','grafik-kecepatan-fisika.png',1293,'{\"judul\": \"Grafik kecepatan terhadap waktu\"}','2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,45,NULL,'gambar','image/png','soal/1/45/1igOcQMaHjg3Dd7krulYXug7cYuBkjYSV3v76AbL.png','ilustrasi-kimia.png',1293,'{\"judul\": \"Susunan partikel atom\"}','2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,54,NULL,'gambar','image/png','soal/1/54/cIRxNzsFpHJRHbxxwddC3AHsHgN2LKiW9VIrHEbQ.png','topologi-jaringan.png',1293,'{\"judul\": \"Topologi jaringan lab\"}','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `question_media` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `question_options`
--

DROP TABLE IF EXISTS `question_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `question_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `option_key` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_json` json NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT '0',
  `position` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `opsi_kunci_unik` (`school_id`,`question_id`,`option_key`),
  UNIQUE KEY `question_options_school_id_id_unique` (`school_id`,`id`),
  KEY `opsi_urutan` (`school_id`,`question_id`,`position`),
  CONSTRAINT `question_options_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `question_options_school_id_question_id_foreign` FOREIGN KEY (`school_id`, `question_id`) REFERENCES `questions` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `question_options`
--

LOCK TABLES `question_options` WRITE;
/*!40000 ALTER TABLE `question_options` DISABLE KEYS */;
INSERT INTO `question_options` VALUES (1,1,1,'a','{\"teks\": \"31\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,1,'b','{\"teks\": \"30\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,1,'c','{\"teks\": \"34\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,1,'d','{\"teks\": \"28\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,2,'a','{\"teks\": \"31\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(6,1,2,'b','{\"teks\": \"30\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(7,1,2,'c','{\"teks\": \"34\"}',1,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(8,1,2,'d','{\"teks\": \"28\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(9,1,3,'b','{\"teks\": \"Benar\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(10,1,3,'s','{\"teks\": \"Salah\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(11,1,9,'a','{\"teks\": \"Nilai tertinggi ada di batang ketiga\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(12,1,9,'b','{\"teks\": \"Nilai tertinggi ada di batang pertama\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(13,1,9,'c','{\"teks\": \"Seluruh batang sama tinggi\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(14,1,9,'d','{\"teks\": \"Nilai terendah ada di batang kedua\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(15,1,10,'a','{\"teks\": \"Pernyataan umum tentang topik\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(16,1,10,'b','{\"teks\": \"Argumen pendukung penulis\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(17,1,10,'c','{\"teks\": \"Penegasan ulang\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(18,1,10,'d','{\"teks\": \"Contoh dari penulis lain\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(19,1,11,'a','{\"teks\": \"Pernyataan umum tentang topik\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(20,1,11,'b','{\"teks\": \"Argumen pendukung penulis\"}',1,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(21,1,11,'c','{\"teks\": \"Penegasan ulang\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(22,1,11,'d','{\"teks\": \"Contoh dari penulis lain\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(23,1,12,'b','{\"teks\": \"Benar\"}',0,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(24,1,12,'s','{\"teks\": \"Salah\"}',1,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(25,1,18,'a','{\"teks\": \"Gambar menunjukkan kegiatan membaca\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(26,1,18,'b','{\"teks\": \"Gambar menunjukkan kegiatan menulis\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(27,1,18,'c','{\"teks\": \"Gambar menunjukkan kegiatan berdiskusi\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(28,1,18,'d','{\"teks\": \"Gambar menunjukkan kegiatan olahraga\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(29,1,19,'a','{\"teks\": \"goes\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(30,1,19,'b','{\"teks\": \"go\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(31,1,19,'c','{\"teks\": \"going\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(32,1,19,'d','{\"teks\": \"gone\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(33,1,20,'a','{\"teks\": \"goes\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(34,1,20,'b','{\"teks\": \"go\"}',1,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(35,1,20,'c','{\"teks\": \"going\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(36,1,20,'d','{\"teks\": \"gone\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(37,1,21,'b','{\"teks\": \"Benar\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(38,1,21,'s','{\"teks\": \"Salah\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(39,1,27,'a','{\"teks\": \"The picture shows a morning routine\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(40,1,27,'b','{\"teks\": \"The picture shows an evening routine\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(41,1,27,'c','{\"teks\": \"The picture shows a holiday\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(42,1,27,'d','{\"teks\": \"The picture shows a classroom\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(43,1,28,'a','{\"teks\": \"10 m/s\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(44,1,28,'b','{\"teks\": \"6 m/s\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(45,1,28,'c','{\"teks\": \"12 m/s\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(46,1,28,'d','{\"teks\": \"8 m/s\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(47,1,29,'a','{\"teks\": \"10 m/s\"}',0,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(48,1,29,'b','{\"teks\": \"6 m/s\"}',1,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(49,1,29,'c','{\"teks\": \"12 m/s\"}',1,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(50,1,29,'d','{\"teks\": \"8 m/s\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(51,1,30,'b','{\"teks\": \"Benar\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(52,1,30,'s','{\"teks\": \"Salah\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(53,1,36,'a','{\"teks\": \"Batang tertinggi ada di batang ketiga\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(54,1,36,'b','{\"teks\": \"Batang tertinggi ada di batang pertama\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(55,1,36,'c','{\"teks\": \"Semua batang sama tinggi\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(56,1,36,'d','{\"teks\": \"Batang terendah ada di batang ketiga\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(57,1,37,'a','{\"teks\": \"6\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(58,1,37,'b','{\"teks\": \"12\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(59,1,37,'c','{\"teks\": \"7\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(60,1,37,'d','{\"teks\": \"14\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(61,1,38,'a','{\"teks\": \"6\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(62,1,38,'b','{\"teks\": \"12\"}',1,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(63,1,38,'c','{\"teks\": \"7\"}',0,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(64,1,38,'d','{\"teks\": \"14\"}',0,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(65,1,39,'b','{\"teks\": \"Benar\"}',1,0,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(66,1,39,'s','{\"teks\": \"Salah\"}',0,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(67,1,45,'a','{\"teks\": \"Gambar menunjukkan susunan partikel atom\"}',1,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(68,1,45,'b','{\"teks\": \"Gambar menunjukkan reaksi kimia\"}',0,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(69,1,45,'c','{\"teks\": \"Gambar menunjukkan tabel periodik\"}',0,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(70,1,45,'d','{\"teks\": \"Gambar menunjukkan alat laboratorium\"}',0,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(71,1,46,'a','{\"teks\": \"254\"}',1,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(72,1,46,'b','{\"teks\": \"256\"}',0,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(73,1,46,'c','{\"teks\": \"255\"}',0,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(74,1,46,'d','{\"teks\": \"252\"}',0,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(75,1,47,'a','{\"teks\": \"254\"}',1,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(76,1,47,'b','{\"teks\": \"256\"}',0,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(77,1,47,'c','{\"teks\": \"255\"}',1,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(78,1,47,'d','{\"teks\": \"252\"}',0,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(79,1,48,'b','{\"teks\": \"Benar\"}',0,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(80,1,48,'s','{\"teks\": \"Salah\"}',1,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(81,1,54,'a','{\"teks\": \"Gambar menunjukkan topologi jaringan\"}',1,0,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(82,1,54,'b','{\"teks\": \"Gambar menunjukkan rak server\"}',0,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(83,1,54,'c','{\"teks\": \"Gambar menunjukkan kabel listrik\"}',0,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(84,1,54,'d','{\"teks\": \"Gambar menunjukkan papan tulis\"}',0,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(85,1,55,'a','{\"teks\": \"Pilihan pertama\"}',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(86,1,55,'b','{\"teks\": \"Pilihan kedua\"}',0,1,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(87,1,55,'c','{\"teks\": \"Pilihan ketiga\"}',0,2,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(88,1,55,'d','{\"teks\": \"Pilihan keempat\"}',0,3,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(89,1,57,'b','{\"teks\": \"Benar\"}',0,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(90,1,57,'s','{\"teks\": \"Salah\"}',1,1,'2026-11-03 09:42:30','2026-11-03 09:42:30');
/*!40000 ALTER TABLE `question_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `question_package_items`
--

DROP TABLE IF EXISTS `question_package_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `question_package_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `question_package_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `points_override` decimal(6,2) DEFAULT NULL,
  `position` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paket_butir_unik` (`school_id`,`question_package_id`,`question_id`),
  KEY `paket_butir_urutan` (`school_id`,`question_package_id`,`position`),
  KEY `question_package_items_school_id_question_id_foreign` (`school_id`,`question_id`),
  CONSTRAINT `question_package_items_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `question_package_items_school_id_question_id_foreign` FOREIGN KEY (`school_id`, `question_id`) REFERENCES `questions` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `question_package_items_school_id_question_package_id_foreign` FOREIGN KEY (`school_id`, `question_package_id`) REFERENCES `question_packages` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `question_package_items`
--

LOCK TABLES `question_package_items` WRITE;
/*!40000 ALTER TABLE `question_package_items` DISABLE KEYS */;
INSERT INTO `question_package_items` VALUES (1,1,1,1,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,1,2,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,1,3,NULL,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,1,4,NULL,4,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,1,5,NULL,5,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(6,1,1,6,NULL,6,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(7,1,1,7,NULL,7,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(8,1,1,8,NULL,8,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(9,1,1,9,NULL,9,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(10,1,2,10,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(11,1,2,11,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(12,1,2,12,NULL,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(13,1,2,13,NULL,4,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(14,1,2,14,NULL,5,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(15,1,2,15,NULL,6,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(16,1,2,16,NULL,7,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(17,1,2,17,NULL,8,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(18,1,2,18,NULL,9,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(19,1,3,19,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(20,1,3,20,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(21,1,3,21,NULL,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(22,1,3,22,NULL,4,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(23,1,3,23,NULL,5,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(24,1,3,24,NULL,6,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(25,1,3,25,NULL,7,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(26,1,3,26,NULL,8,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(27,1,3,27,NULL,9,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(28,1,4,28,NULL,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(29,1,4,29,NULL,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(30,1,4,30,NULL,3,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(31,1,4,31,NULL,4,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(32,1,4,32,NULL,5,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(33,1,4,33,NULL,6,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(34,1,4,34,NULL,7,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(35,1,4,35,NULL,8,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(36,1,4,36,NULL,9,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(37,1,5,37,NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(38,1,5,38,NULL,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(39,1,5,39,NULL,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(40,1,5,40,NULL,4,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(41,1,5,41,NULL,5,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(42,1,5,42,NULL,6,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(43,1,5,43,NULL,7,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(44,1,5,44,NULL,8,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(45,1,5,45,NULL,9,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(46,1,6,46,NULL,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(47,1,6,47,NULL,2,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(48,1,6,48,NULL,3,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(49,1,6,49,NULL,4,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(50,1,6,50,NULL,5,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(51,1,6,51,NULL,6,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(52,1,6,52,NULL,7,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(53,1,6,53,NULL,8,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(54,1,6,54,NULL,9,'2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `question_package_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `question_packages`
--

DROP TABLE IF EXISTS `question_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `question_packages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grade_level` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paket_nama_unik` (`school_id`,`created_by`,`name`),
  UNIQUE KEY `question_packages_school_id_id_unique` (`school_id`,`id`),
  KEY `paket_mapel_status` (`school_id`,`subject_id`,`status`),
  CONSTRAINT `question_packages_school_id_created_by_foreign` FOREIGN KEY (`school_id`, `created_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `question_packages_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `question_packages_school_id_subject_id_foreign` FOREIGN KEY (`school_id`, `subject_id`) REFERENCES `subjects` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `question_packages`
--

LOCK TABLES `question_packages` WRITE;
/*!40000 ALTER TABLE `question_packages` DISABLE KEYS */;
INSERT INTO `question_packages` VALUES (1,1,2,1,'UAS Ganjil Matematika X','X','Paket contoh Fase 4 untuk Matematika kelas X.','siap','2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,3,2,'UAS Ganjil Bahasa Indonesia X','X','Paket contoh Fase 4 untuk Bahasa Indonesia kelas X.','siap','2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,4,3,'UAS Ganjil Bahasa Inggris X','X','Paket contoh Fase 4 untuk Bahasa Inggris kelas X.','siap','2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,5,4,'UAS Ganjil Fisika X','X','Paket contoh Fase 4 untuk Fisika kelas X.','siap','2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,5,5,'UAS Ganjil Kimia X','X','Paket contoh Fase 4 untuk Kimia kelas X.','siap','2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,5,6,'UAS Ganjil Jaringan Dasar X','X','Paket contoh Fase 4 untuk Jaringan Dasar kelas X.','siap','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `question_packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `questions`
--

DROP TABLE IF EXISTS `questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `question_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `difficulty` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sedang',
  `prompt_json` json NOT NULL,
  `answer_key_json` json DEFAULT NULL,
  `scoring_config_json` json DEFAULT NULL,
  `explanation_json` json DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `soal_kode_unik` (`school_id`,`subject_id`,`code`),
  UNIQUE KEY `questions_school_id_id_unique` (`school_id`,`id`),
  KEY `soal_mapel_status` (`school_id`,`subject_id`,`status`),
  KEY `questions_school_id_created_by_foreign` (`school_id`,`created_by`),
  CONSTRAINT `questions_school_id_created_by_foreign` FOREIGN KEY (`school_id`, `created_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `questions_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questions_school_id_subject_id_foreign` FOREIGN KEY (`school_id`, `subject_id`) REFERENCES `subjects` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `questions`
--

LOCK TABLES `questions` WRITE;
/*!40000 ALTER TABLE `questions` DISABLE KEYS */;
INSERT INTO `questions` VALUES (1,1,2,1,'Q-1-1','Pilihan ganda - barisan dan deret aritmetika','pilihan_ganda','sedang','{\"teks\": \"<p>Diketahui barisan aritmetika <b>4, 7, 10, 13, ...</b></p><p>Rumus suku ke-n: <b>U<sub>n</sub> = a + (n − 1)b</b></p><p>Berapakah suku ke-10 dari barisan tersebut?</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 14}','{\"teks\": \"<p>a = 4 dan b = 3, sehingga U<sub>10</sub> = 4 + 9 × 3 = 31.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,2,1,'Q-1-2','Jawaban ganda - barisan dan deret aritmetika','jawaban_ganda','sulit','{\"teks\": \"<p>Manakah pernyataan yang <b>benar</b> tentang barisan aritmetika? (Pilih lebih dari satu)</p>\"}','{\"opsi\": [\"a\", \"c\"]}','{\"bobot\": 12, \"skor_parsial\": true}','{\"teks\": \"<p>a = 4 dan b = 3, sehingga U<sub>10</sub> = 4 + 9 × 3 = 31.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,2,1,'Q-1-3','Benar/salah - barisan dan deret aritmetika','benar_salah','mudah','{\"teks\": \"Beda pada barisan aritmetika selalu bernilai tetap.\"}','{\"opsi\": [\"b\"]}','{\"bobot\": 12}','{\"teks\": \"<p>a = 4 dan b = 3, sehingga U<sub>10</sub> = 4 + 9 × 3 = 31.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,2,1,'Q-1-4','Isian singkat - barisan dan deret aritmetika','isian_singkat','sedang','{\"teks\": \"<p>Suku ke-5 dari barisan 4, 7, 10, ... adalah ...</p>\"}','{\"teks\": [\"16\", \"enam belas\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,2,1,'Q-1-5','Menjodohkan - barisan dan deret aritmetika','menjodohkan','sedang','{\"teks\": \"<p>Jodohkan istilah pada barisan aritmetika dengan lambangnya.</p>\"}','{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}','{\"bobot\": 12}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(6,1,2,1,'Q-1-6','Mengurutkan - barisan dan deret aritmetika','mengurutkan','sulit','{\"teks\": \"<p>Urutkan langkah menyelesaikan soal barisan aritmetika dari yang pertama.</p>\"}','{\"urutan\": [\"k3\", \"k1\", \"k2\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(7,1,2,1,'Q-1-7','Angka - barisan dan deret aritmetika','angka','sedang','{\"teks\": \"<p>Pada barisan 4, 7, 10, ... berapakah bedanya?</p>\"}','{\"nilai\": 3, \"satuan\": \"\", \"toleransi\": 0}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(8,1,2,1,'Q-1-8','Uraian - barisan dan deret aritmetika','uraian','sulit','{\"teks\": \"<p>Sebuah gedung pertemuan memiliki 20 baris kursi. Baris pertama berisi 12 kursi, dan setiap baris berikutnya bertambah 3 kursi. Berapa banyak kursi di baris terakhir? Tuliskan langkah pengerjaanmu.</p>\"}',NULL,'{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(9,1,2,1,'Q-1-9','Media - barisan dan deret aritmetika','media','sedang','{\"teks\": \"<p>Perhatikan grafik hasil pengukuran pada gambar, lalu jawab pertanyaannya.</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 10, \"mekanisme\": \"pilihan\"}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(10,1,3,2,'Q-2-1','Pilihan ganda - teks eksposisi','pilihan_ganda','sedang','{\"teks\": \"<p>Bacalah tabel berikut.</p><table><thead><tr><th>Bagian</th><th>Isi</th></tr></thead><tbody><tr><td>Paragraf 1</td><td>Pernyataan umum</td></tr><tr><td>Paragraf 2</td><td>Argumen pendukung</td></tr><tr><td>Paragraf 3</td><td>Penegasan ulang</td></tr></tbody></table><p>Apa gagasan utama paragraf pertama teks eksposisi tersebut?</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 14}','{\"teks\": \"<p>Paragraf pertama teks eksposisi memuat pernyataan umum.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(11,1,3,2,'Q-2-2','Jawaban ganda - teks eksposisi','jawaban_ganda','sulit','{\"teks\": \"<p>Manakah yang termasuk ciri teks eksposisi? (Pilih lebih dari satu)</p>\"}','{\"opsi\": [\"a\", \"b\"]}','{\"bobot\": 12, \"skor_parsial\": true}','{\"teks\": \"<p>Paragraf pertama teks eksposisi memuat pernyataan umum.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(12,1,3,2,'Q-2-3','Benar/salah - teks eksposisi','benar_salah','mudah','{\"teks\": \"Teks eksposisi selalu memuat unsur cerita fiktif.\"}','{\"opsi\": [\"s\"]}','{\"bobot\": 12}','{\"teks\": \"<p>Paragraf pertama teks eksposisi memuat pernyataan umum.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(13,1,3,2,'Q-2-4','Isian singkat - teks eksposisi','isian_singkat','sedang','{\"teks\": \"<p>Bagian akhir teks eksposisi yang memuat penegasan ulang disebut ...</p>\"}','{\"teks\": [\"penegasan ulang\", \"kesimpulan\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(14,1,3,2,'Q-2-5','Menjodohkan - teks eksposisi','menjodohkan','sedang','{\"teks\": \"<p>Jodohkan bagian teks eksposisi dengan isinya.</p>\"}','{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}','{\"bobot\": 12}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(15,1,3,2,'Q-2-6','Mengurutkan - teks eksposisi','mengurutkan','sulit','{\"teks\": \"<p>Urutkan langkah memahami teks eksposisi dari yang pertama.</p>\"}','{\"urutan\": [\"k3\", \"k1\", \"k2\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(16,1,3,2,'Q-2-7','Angka - teks eksposisi','angka','sedang','{\"teks\": \"<p>Berapa jumlah paragraf pada teks eksposisi di atas?</p>\"}','{\"nilai\": 3, \"satuan\": \"paragraf\", \"toleransi\": 0}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(17,1,3,2,'Q-2-8','Uraian - teks eksposisi','uraian','sulit','{\"teks\": \"<p>Tulislah satu paragraf eksposisi tentang kebersihan lingkungan sekolah. Sertakan pernyataan umum dan satu argumen pendukung.</p>\"}',NULL,'{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(18,1,3,2,'Q-2-9','Media - teks eksposisi','media','sedang','{\"teks\": \"<p>Amati gambar berikut, lalu jawab pertanyaannya.</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 10, \"mekanisme\": \"pilihan\"}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(19,1,4,3,'Q-3-1','Pilihan ganda - simple present tense','pilihan_ganda','sedang','{\"teks\": \"Choose the correct form: \\\"She ___ to school every day.\\\"\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 14}','{\"teks\": \"<p>Subjek orang ketiga tunggal memakai bentuk \\\"goes\\\".</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(20,1,4,3,'Q-3-2','Jawaban ganda - simple present tense','jawaban_ganda','sulit','{\"teks\": \"<p>Which sentences use the simple present tense? (Choose more than one)</p>\"}','{\"opsi\": [\"a\", \"b\"]}','{\"bobot\": 12, \"skor_parsial\": true}','{\"teks\": \"<p>Subjek orang ketiga tunggal memakai bentuk \\\"goes\\\".</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(21,1,4,3,'Q-3-3','Benar/salah - simple present tense','benar_salah','mudah','{\"teks\": \"The simple present tense is used for habits and routines.\"}','{\"opsi\": [\"b\"]}','{\"bobot\": 12}','{\"teks\": \"<p>Subjek orang ketiga tunggal memakai bentuk \\\"goes\\\".</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(22,1,4,3,'Q-3-4','Isian singkat - simple present tense','isian_singkat','sedang','{\"teks\": \"<p>Complete: \\\"They ___ football every Sunday.\\\" (play)</p>\"}','{\"teks\": [\"play\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(23,1,4,3,'Q-3-5','Menjodohkan - simple present tense','menjodohkan','sedang','{\"teks\": \"<p>Match each subject with its correct verb form.</p>\"}','{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}','{\"bobot\": 12}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(24,1,4,3,'Q-3-6','Mengurutkan - simple present tense','mengurutkan','sulit','{\"teks\": \"<p>Put the steps for building a simple present sentence in order.</p>\"}','{\"urutan\": [\"k3\", \"k1\", \"k2\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(25,1,4,3,'Q-3-7','Angka - simple present tense','angka','sedang','{\"teks\": \"<p>How many sentences in the text use the simple present tense?</p>\"}','{\"nilai\": 4, \"satuan\": \"kalimat\", \"toleransi\": 0}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(26,1,4,3,'Q-3-8','Uraian - simple present tense','uraian','sulit','{\"teks\": \"<p>Write a short paragraph about your daily routine using the simple present tense. Use at least five sentences.</p>\"}',NULL,'{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(27,1,4,3,'Q-3-9','Media - simple present tense','media','sedang','{\"teks\": \"<p>Look at the picture, then answer the question.</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 10, \"mekanisme\": \"pilihan\"}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(28,1,5,4,'Q-4-1','Pilihan ganda - gerak lurus berubah beraturan','pilihan_ganda','sedang','{\"teks\": \"<p>Sebuah benda bergerak dengan kecepatan awal <b>4 m/s</b> dan percepatan tetap <b>2 m/s<sup>2</sup></b>.</p><p>Berapa kecepatannya setelah 3 sekon?</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 14}','{\"teks\": \"<p>v = 4 + 2 × 3 = 10 m/s.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(29,1,5,4,'Q-4-2','Jawaban ganda - gerak lurus berubah beraturan','jawaban_ganda','sulit','{\"teks\": \"<p>Manakah besaran yang termasuk besaran turunan? (Pilih lebih dari satu)</p>\"}','{\"opsi\": [\"b\", \"c\"]}','{\"bobot\": 12, \"skor_parsial\": true}','{\"teks\": \"<p>v = 4 + 2 × 3 = 10 m/s.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(30,1,5,4,'Q-4-3','Benar/salah - gerak lurus berubah beraturan','benar_salah','mudah','{\"teks\": \"Percepatan pada gerak lurus berubah beraturan selalu tetap.\"}','{\"opsi\": [\"b\"]}','{\"bobot\": 12}','{\"teks\": \"<p>v = 4 + 2 × 3 = 10 m/s.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(31,1,5,4,'Q-4-4','Isian singkat - gerak lurus berubah beraturan','isian_singkat','sedang','{\"teks\": \"<p>Satuan percepatan dalam SI adalah ...</p>\"}','{\"teks\": [\"m/s2\", \"meter per sekon kuadrat\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(32,1,5,4,'Q-4-5','Menjodohkan - gerak lurus berubah beraturan','menjodohkan','sedang','{\"teks\": \"<p>Jodohkan besaran dengan satuannya.</p>\"}','{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}','{\"bobot\": 12}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(33,1,5,4,'Q-4-6','Mengurutkan - gerak lurus berubah beraturan','mengurutkan','sulit','{\"teks\": \"<p>Urutkan langkah menyelesaikan soal gerak lurus dari yang pertama.</p>\"}','{\"urutan\": [\"k3\", \"k1\", \"k2\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(34,1,5,4,'Q-4-7','Angka - gerak lurus berubah beraturan','angka','sedang','{\"teks\": \"<p>Berapakah percepatan benda pada soal tersebut (dalam m/s²)?</p>\"}','{\"nilai\": 2, \"satuan\": \"m/s2\", \"toleransi\": 0.01}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(35,1,5,4,'Q-4-8','Uraian - gerak lurus berubah beraturan','uraian','sulit','{\"teks\": \"<p>Sebuah mobil dipercepat dari keadaan diam hingga 20 m/s dalam 8 sekon, lalu bergerak dengan kecepatan tetap selama 5 sekon. Hitunglah percepatan mobil dan jarak total yang ditempuh.</p>\"}',NULL,'{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(36,1,5,4,'Q-4-9','Media - gerak lurus berubah beraturan','media','sedang','{\"teks\": \"<p>Perhatikan grafik kecepatan terhadap waktu pada gambar, lalu jawab pertanyaannya.</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 10, \"mekanisme\": \"pilihan\"}',NULL,'siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(37,1,5,5,'Q-5-1','Pilihan ganda - struktur atom','pilihan_ganda','sedang','{\"teks\": \"Berapa jumlah proton pada atom karbon dengan nomor atom 6?\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 14}','{\"teks\": \"<p>Nomor atom menyatakan jumlah proton, yaitu 6.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(38,1,5,5,'Q-5-2','Jawaban ganda - struktur atom','jawaban_ganda','sulit','{\"teks\": \"<p>Manakah yang termasuk partikel penyusun atom? (Pilih lebih dari satu)</p>\"}','{\"opsi\": [\"a\", \"b\"]}','{\"bobot\": 12, \"skor_parsial\": true}','{\"teks\": \"<p>Nomor atom menyatakan jumlah proton, yaitu 6.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(39,1,5,5,'Q-5-3','Benar/salah - struktur atom','benar_salah','mudah','{\"teks\": \"Nomor massa sama dengan jumlah proton dan neutron.\"}','{\"opsi\": [\"b\"]}','{\"bobot\": 12}','{\"teks\": \"<p>Nomor atom menyatakan jumlah proton, yaitu 6.</p>\"}','siap','2026-11-03 09:42:28','2026-11-03 09:42:28','2026-11-03 09:42:28'),(40,1,5,5,'Q-5-4','Isian singkat - struktur atom','isian_singkat','sedang','{\"teks\": \"<p>Partikel atom yang bermuatan negatif disebut ...</p>\"}','{\"teks\": [\"elektron\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(41,1,5,5,'Q-5-5','Menjodohkan - struktur atom','menjodohkan','sedang','{\"teks\": \"<p>Jodohkan partikel atom dengan muatannya.</p>\"}','{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}','{\"bobot\": 12}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(42,1,5,5,'Q-5-6','Mengurutkan - struktur atom','mengurutkan','sulit','{\"teks\": \"<p>Urutkan langkah menentukan jumlah partikel atom dari yang pertama.</p>\"}','{\"urutan\": [\"k3\", \"k1\", \"k2\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(43,1,5,5,'Q-5-7','Angka - struktur atom','angka','sedang','{\"teks\": \"<p>Berapa jumlah elektron pada atom netral dengan nomor atom 6?</p>\"}','{\"nilai\": 6, \"satuan\": \"elektron\", \"toleransi\": 0}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(44,1,5,5,'Q-5-8','Uraian - struktur atom','uraian','sulit','{\"teks\": \"<p>Jelaskan perbedaan nomor atom dan nomor massa, beserta contohnya pada atom karbon.</p>\"}',NULL,'{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(45,1,5,5,'Q-5-9','Media - struktur atom','media','sedang','{\"teks\": \"<p>Amati gambar berikut, lalu jawab pertanyaannya.</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 10, \"mekanisme\": \"pilihan\"}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(46,1,5,6,'Q-6-1','Pilihan ganda - pengalamatan IP','pilihan_ganda','sedang','{\"teks\": \"<p>Sebuah jaringan memakai prefix <b>/24</b>.</p><p>Berapa jumlah alamat host yang tersedia untuk perangkat?</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 14}','{\"teks\": \"<p>256 alamat dikurangi alamat jaringan dan broadcast menjadi 254.</p>\"}','siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(47,1,5,6,'Q-6-2','Jawaban ganda - pengalamatan IP','jawaban_ganda','sulit','{\"teks\": \"<p>Manakah yang termasuk perangkat jaringan? (Pilih lebih dari satu)</p>\"}','{\"opsi\": [\"a\", \"c\"]}','{\"bobot\": 12, \"skor_parsial\": true}','{\"teks\": \"<p>256 alamat dikurangi alamat jaringan dan broadcast menjadi 254.</p>\"}','siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(48,1,5,6,'Q-6-3','Benar/salah - pengalamatan IP','benar_salah','mudah','{\"teks\": \"Alamat 192.168.1.255 pada jaringan /24 dipakai sebagai alamat host.\"}','{\"opsi\": [\"s\"]}','{\"bobot\": 12}','{\"teks\": \"<p>256 alamat dikurangi alamat jaringan dan broadcast menjadi 254.</p>\"}','siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(49,1,5,6,'Q-6-4','Isian singkat - pengalamatan IP','isian_singkat','sedang','{\"teks\": \"<p>Alamat yang dipakai untuk mengirim data ke seluruh perangkat dalam jaringan disebut alamat ...</p>\"}','{\"teks\": [\"broadcast\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(50,1,5,6,'Q-6-5','Menjodohkan - pengalamatan IP','menjodohkan','sedang','{\"teks\": \"<p>Jodohkan istilah jaringan dengan pengertiannya.</p>\"}','{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}','{\"bobot\": 12}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(51,1,5,6,'Q-6-6','Mengurutkan - pengalamatan IP','mengurutkan','sulit','{\"teks\": \"<p>Urutkan langkah perencanaan pengalamatan jaringan dari yang pertama.</p>\"}','{\"urutan\": [\"k3\", \"k1\", \"k2\"]}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(52,1,5,6,'Q-6-7','Angka - pengalamatan IP','angka','sedang','{\"teks\": \"<p>Berapa nilai oktet terakhir dari subnet mask /24?</p>\"}','{\"nilai\": 0, \"satuan\": \"\", \"toleransi\": 0}','{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(53,1,5,6,'Q-6-8','Uraian - pengalamatan IP','uraian','sulit','{\"teks\": \"<p>Sebuah lab komputer berisi 60 perangkat. Rancang pembagian alamat IP-nya, lalu jelaskan mengapa prefix yang kamu pilih mencukupi.</p>\"}',NULL,'{\"bobot\": 10}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(54,1,5,6,'Q-6-9','Media - pengalamatan IP','media','sedang','{\"teks\": \"<p>Perhatikan gambar berikut, lalu jawab pertanyaannya.</p>\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 10, \"mekanisme\": \"pilihan\"}',NULL,'siap','2026-11-03 09:42:29','2026-11-03 09:42:29','2026-11-03 09:42:29'),(55,1,2,1,'Q-1-10','Pengertian dasar Matematika','pilihan_ganda','mudah','{\"teks\": \"Pernyataan berikut yang paling tepat menggambarkan Matematika adalah...\"}','{\"opsi\": [\"a\"]}','{\"bobot\": 1}',NULL,'siap','2026-11-03 09:42:30','2026-11-03 09:42:30','2026-11-03 09:42:30'),(56,1,2,1,'Q-1-11','Istilah kunci Matematika','isian_singkat','sedang','{\"teks\": \"Sebutkan istilah yang dipakai untuk menyebut Matematika pada tingkat dasar.\"}','{\"teks\": [\"istilah\", \"istilahnya\"]}','{\"bobot\": 2}',NULL,'siap','2026-11-03 09:42:30','2026-11-03 09:42:30','2026-11-03 09:42:30'),(57,1,2,1,'Q-1-12','Pernyataan Matematika','benar_salah','mudah','{\"teks\": \"Seluruh materi Matematika di tingkat X bersifat hafalan.\"}','{\"opsi\": [\"s\"]}','{\"bobot\": 1}',NULL,'siap','2026-11-03 09:42:30','2026-11-03 09:42:30','2026-11-03 09:42:30'),(58,1,2,1,'Q-1-13','Uraian Matematika','uraian','sulit','{\"teks\": \"Jelaskan langkah-langkah menyelesaikan persoalan Matematika beserta alasannya.\"}',NULL,'{\"bobot\": 4}',NULL,'siap','2026-11-03 09:42:30','2026-11-03 09:42:30','2026-11-03 09:42:30'),(59,1,2,1,'Q-1-14','Soal angka Matematika','angka','sedang','{\"teks\": \"Berapa hasil perhitungan pada soal Matematika berikut?\"}','{\"nilai\": 12.5, \"toleransi\": 0}','{\"bobot\": 2}',NULL,'siap','2026-11-03 09:42:31','2026-11-03 09:42:31','2026-11-03 09:42:31'),(60,1,2,1,'Q-1-15','Soal urut Matematika','mengurutkan','sedang','{\"teks\": \"Urutkan langkah penyelesaian Matematika dari yang pertama.\"}','{\"urutan\": [\"c\", \"a\", \"b\"]}','{\"bobot\": 2}',NULL,'siap','2026-11-03 09:42:31','2026-11-03 09:42:31','2026-11-03 09:42:31');
/*!40000 ALTER TABLE `questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `room_entry_links`
--

DROP TABLE IF EXISTS `room_entry_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `room_entry_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `room_id` bigint unsigned NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `use_count` int unsigned NOT NULL DEFAULT '0',
  `bound_device_fingerprint` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_entry_links_slug_unique` (`slug`),
  KEY `room_entry_links_school_id_room_id_index` (`school_id`,`room_id`),
  CONSTRAINT `room_entry_links_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `room_entry_links_school_id_room_id_foreign` FOREIGN KEY (`school_id`, `room_id`) REFERENCES `rooms` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `room_entry_links`
--

LOCK TABLES `room_entry_links` WRITE;
/*!40000 ALTER TABLE `room_entry_links` DISABLE KEYS */;
INSERT INTO `room_entry_links` VALUES (1,1,1,'r-01-whys','$2y$12$dyRX/MCA5ECXyqQS7XD8IeRnIQ2SjdXFaUJw3xWhuzLkvbrydnb32','2026-11-03 21:42:29',NULL,0,NULL,NULL,'2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `room_entry_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rooms`
--

DROP TABLE IF EXISTS `rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capacity` int unsigned NOT NULL DEFAULT '0',
  `device_count` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rooms_school_id_code_unique` (`school_id`,`code`),
  UNIQUE KEY `rooms_school_id_id_unique` (`school_id`,`id`),
  CONSTRAINT `rooms_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rooms`
--

LOCK TABLES `rooms` WRITE;
/*!40000 ALTER TABLE `rooms` DISABLE KEYS */;
INSERT INTO `rooms` VALUES (1,1,'R-01','Lab Komputer 1','Gedung A lantai 2',32,32,'aktif','2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,'R-02','Lab Komputer 2','Gedung A lantai 2',32,32,'aktif','2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,'R-03','Ruang Teori 3','Gedung B lantai 1',36,0,'aktif','2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,'R-04','Aula Serbaguna','Gedung C',60,0,'aktif','2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,'R-09','Lab Komputer 3','Gedung A lantai 3',28,28,'perbaikan','2026-11-03 09:42:28','2026-11-03 09:42:28');
/*!40000 ALTER TABLE `rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_events`
--

DROP TABLE IF EXISTS `school_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `school_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `academic_year_id` bigint unsigned NOT NULL,
  `semester_id` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `violation_thresholds` json DEFAULT NULL,
  `enforce_fullscreen` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_events_school_id_code_unique` (`school_id`,`code`),
  UNIQUE KEY `school_events_school_id_id_unique` (`school_id`,`id`),
  KEY `school_events_school_id_status_index` (`school_id`,`status`),
  KEY `school_events_school_id_academic_year_id_foreign` (`school_id`,`academic_year_id`),
  KEY `school_events_school_id_semester_id_foreign` (`school_id`,`semester_id`),
  KEY `school_events_school_id_created_by_foreign` (`school_id`,`created_by`),
  CONSTRAINT `school_events_school_id_academic_year_id_foreign` FOREIGN KEY (`school_id`, `academic_year_id`) REFERENCES `academic_years` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `school_events_school_id_created_by_foreign` FOREIGN KEY (`school_id`, `created_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `school_events_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `school_events_school_id_semester_id_foreign` FOREIGN KEY (`school_id`, `semester_id`) REFERENCES `semesters` (`school_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_events`
--

LOCK TABLES `school_events` WRITE;
/*!40000 ALTER TABLE `school_events` DISABLE KEYS */;
INSERT INTO `school_events` VALUES (1,1,1,1,'EV-2026-001','UAS Ganjil 2026/2027','Ujian akhir semester','Penilaian akhir semester ganjil untuk seluruh tingkat X.','2026-12-01 00:30:00','2026-12-12 05:00:00','berjalan','{\"kunci_ujian\": 7, \"peringatkan\": 3, \"tandai_pengawas\": 5}',1,1,'2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,1,1,'EV-2026-002','Try Out UTBK','Try out',NULL,NULL,NULL,'persiapan','{\"kunci_ujian\": 5, \"peringatkan\": 5, \"tandai_pengawas\": 5}',1,1,'2026-11-03 09:42:30','2026-11-03 09:42:30');
/*!40000 ALTER TABLE `school_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schools`
--

DROP TABLE IF EXISTS `schools`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schools` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `npsn` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Asia/Jakarta',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schools_code_unique` (`code`),
  UNIQUE KEY `schools_npsn_unique` (`npsn`),
  KEY `schools_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schools`
--

LOCK TABLES `schools` WRITE;
/*!40000 ALTER TABLE `schools` DISABLE KEYS */;
INSERT INTO `schools` VALUES (1,'SMKN1','SMK Negeri 1','20512345',NULL,'Jl. Pendidikan No. 12, Kecamatan Sukamaju, Kabupaten Bogor','info@smkn1.sch.id','021-8765432','Asia/Jakarta','aktif',NULL,'2026-11-03 09:42:24','2026-11-03 09:42:24');
/*!40000 ALTER TABLE `schools` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `semesters`
--

DROP TABLE IF EXISTS `semesters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `semesters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `academic_year_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `semesters_school_id_academic_year_id_name_unique` (`school_id`,`academic_year_id`,`name`),
  UNIQUE KEY `semesters_school_id_id_unique` (`school_id`,`id`),
  CONSTRAINT `semesters_school_id_academic_year_id_foreign` FOREIGN KEY (`school_id`, `academic_year_id`) REFERENCES `academic_years` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `semesters_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `semesters`
--

LOCK TABLES `semesters` WRITE;
/*!40000 ALTER TABLE `semesters` DISABLE KEYS */;
INSERT INTO `semesters` VALUES (1,1,1,'Ganjil','2026-07-01','2026-12-31',1,'2026-11-03 09:42:24','2026-11-03 09:42:24'),(2,1,1,'Genap','2027-01-01','2027-06-30',0,'2026-11-03 09:42:24','2026-11-03 09:42:24');
/*!40000 ALTER TABLE `semesters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `session_report_files`
--

DROP TABLE IF EXISTS `session_report_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `session_report_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `session_report_id` bigint unsigned NOT NULL,
  `sisi` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_asli` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ukuran` int unsigned NOT NULL DEFAULT '0',
  `diambil_pada` timestamp NULL DEFAULT NULL,
  `diunggah_pada` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `berkas_laporan_unik` (`school_id`,`session_report_id`,`sisi`),
  UNIQUE KEY `session_report_files_school_id_id_unique` (`school_id`,`id`),
  CONSTRAINT `session_report_files_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `session_report_files_school_id_session_report_id_foreign` FOREIGN KEY (`school_id`, `session_report_id`) REFERENCES `session_reports` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `session_report_files`
--

LOCK TABLES `session_report_files` WRITE;
/*!40000 ALTER TABLE `session_report_files` DISABLE KEYS */;
/*!40000 ALTER TABLE `session_report_files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `session_report_signatures`
--

DROP TABLE IF EXISTS `session_report_signatures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `session_report_signatures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `session_report_id` bigint unsigned NOT NULL,
  `peran` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ditandatangani_pada` timestamp NOT NULL,
  `ditandatangani_oleh` bigint unsigned DEFAULT NULL,
  `pelaku_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ttd_laporan_unik` (`school_id`,`session_report_id`,`peran`),
  UNIQUE KEY `session_report_signatures_school_id_id_unique` (`school_id`,`id`),
  KEY `session_report_signatures_school_id_ditandatangani_oleh_foreign` (`school_id`,`ditandatangani_oleh`),
  CONSTRAINT `session_report_signatures_school_id_ditandatangani_oleh_foreign` FOREIGN KEY (`school_id`, `ditandatangani_oleh`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `session_report_signatures_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `session_report_signatures_school_id_session_report_id_foreign` FOREIGN KEY (`school_id`, `session_report_id`) REFERENCES `session_reports` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `session_report_signatures`
--

LOCK TABLES `session_report_signatures` WRITE;
/*!40000 ALTER TABLE `session_report_signatures` DISABLE KEYS */;
/*!40000 ALTER TABLE `session_report_signatures` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `session_reports`
--

DROP TABLE IF EXISTS `session_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `session_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_session_room_id` bigint unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `catatan` text COLLATE utf8mb4_unicode_ci,
  `pernyataan_presensi` tinyint(1) NOT NULL DEFAULT '0',
  `pernyataan_keluar` tinyint(1) NOT NULL DEFAULT '0',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `dibuat_oleh` bigint unsigned DEFAULT NULL,
  `pelaku_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `laporan_unik` (`school_id`,`exam_session_room_id`),
  UNIQUE KEY `session_reports_school_id_id_unique` (`school_id`,`id`),
  KEY `session_reports_school_id_dibuat_oleh_foreign` (`school_id`,`dibuat_oleh`),
  CONSTRAINT `session_reports_school_id_dibuat_oleh_foreign` FOREIGN KEY (`school_id`, `dibuat_oleh`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `session_reports_school_id_exam_session_room_id_foreign` FOREIGN KEY (`school_id`, `exam_session_room_id`) REFERENCES `exam_session_rooms` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `session_reports_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `session_reports`
--

LOCK TABLES `session_reports` WRITE;
/*!40000 ALTER TABLE `session_reports` DISABLE KEYS */;
INSERT INTO `session_reports` VALUES (1,1,1,'draft',NULL,0,0,NULL,NULL,NULL,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(2,1,2,'draft',NULL,0,0,NULL,NULL,NULL,'2026-11-03 10:14:46','2026-11-03 10:14:46');
/*!40000 ALTER TABLE `session_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('BTl1iMbhTtqnZEoX4mrHNJ6El9MeB0RDxYjQrfdU',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJNWTFXUXlBTTI2QUpUcGJ5OGhvbmRMMVE3aXFEbVJrTjJ6aDRNQTNaIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1793747105),('mZgt3gzYzJ0xCX6ijGI7tPuAmL10kI3VZeSN8Ut0',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:159.0) Gecko/20100101 Firefox/159.0','eyJfdG9rZW4iOiJnNWFnU3ZUWnZyeW81QUZBYWxMUzkwT3FPTklESFhScVlEZ1IwbzR4IiwidXJsIjp7ImludGVuZGVkIjoiaHR0cDpcL1wvbG9jYWxob3N0Ojg3ODFcL3Npc3dhIn0sIl9wcmV2aW91cyI6eyJ1cmwiOiJodHRwOlwvXC9sb2NhbGhvc3Q6ODc4MVwvbG9naW4iLCJyb3V0ZSI6ImxvZ2luIn0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1793746916),('N4Sw646ZkhJfFRkqmYNvPQIcmMBb9f4LKTReZLDQ',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJDZTJRZEg0U3VsbVFPNGxjN3k5ZDk1YUpNQ3o1WjM5VzVreFR3RmFaIiwidXJsIjp7ImludGVuZGVkIjoiaHR0cDpcL1wvbG9jYWxob3N0Ojg3ODFcL2FkbWluXC9wZW5nZ3VuYT90YWI9dGVhY2hlciJ9LCJfcHJldmlvdXMiOnsidXJsIjoiaHR0cDpcL1wvbG9jYWxob3N0Ojg3ODFcL2xvZ2luIiwicm91dGUiOiJsb2dpbiJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1793747107);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_answers`
--

DROP TABLE IF EXISTS `student_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_answers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `attempt_question_id` bigint unsigned NOT NULL,
  `answer_json` json DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `score_awarded` decimal(8,2) DEFAULT NULL,
  `saved_at` timestamp NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `ditandai` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `jawaban_unik` (`school_id`,`attempt_question_id`),
  UNIQUE KEY `student_answers_school_id_id_unique` (`school_id`,`id`),
  CONSTRAINT `student_answers_school_id_attempt_question_id_foreign` FOREIGN KEY (`school_id`, `attempt_question_id`) REFERENCES `attempt_questions` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `student_answers_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_answers`
--

LOCK TABLES `student_answers` WRITE;
/*!40000 ALTER TABLE `student_answers` DISABLE KEYS */;
INSERT INTO `student_answers` VALUES (1,1,1,'{\"opsi\": [\"a\", \"b\"]}',NULL,NULL,'2026-11-03 09:43:53',3,1,'2026-11-03 09:42:30','2026-11-03 09:43:53'),(2,1,2,'{\"urutan\": [\"k2\", \"k3\", \"k1\"]}',NULL,NULL,'2026-11-03 09:43:59',3,0,'2026-11-03 09:42:30','2026-11-03 09:43:59'),(3,1,3,'{\"opsi\": [\"a\"]}',NULL,NULL,'2026-11-03 09:44:03',4,0,'2026-11-03 09:42:30','2026-11-03 09:44:03'),(4,1,4,'{\"teks\": \"Menurut saya, jawabannya dapat dijelaskan sebagai berikut. Pertama, langkah awal dilakukan dengan mengidentifikasi besaran yang diketahui. Kedua, rumus yang sesuai diterapkan. Ketiga, hasilnya disimpulkan.\"}',NULL,NULL,'2026-11-03 09:44:04',3,0,'2026-11-03 09:42:30','2026-11-03 09:44:04'),(5,1,5,'{\"nilai\": \"12\"}',NULL,NULL,'2026-11-03 09:44:05',3,1,'2026-11-03 09:42:30','2026-11-03 09:44:05'),(6,1,6,'{\"opsi\": [\"b\"]}',NULL,NULL,'2026-11-03 09:44:05',3,0,'2026-11-03 09:42:30','2026-11-03 09:44:05'),(7,1,7,'{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}',NULL,NULL,'2026-11-03 09:44:11',4,0,'2026-11-03 09:42:30','2026-11-03 09:44:11'),(8,1,8,'{\"opsi\": [\"a\"]}',NULL,NULL,'2026-11-03 09:44:11',4,0,'2026-11-03 09:42:30','2026-11-03 09:44:11'),(9,1,9,'{\"teks\": \"Jawaban contoh siswa.\"}',NULL,NULL,'2026-11-03 09:43:44',2,1,'2026-11-03 09:42:30','2026-11-03 09:43:44'),(10,1,10,'{\"urutan\": [\"k2\", \"k3\", \"k1\"]}',NULL,NULL,'2026-11-03 09:42:30',1,1,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(11,1,11,'{\"opsi\": [\"a\"]}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(12,1,12,'{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(13,1,13,'{\"opsi\": [\"a\"]}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(14,1,14,'{\"opsi\": [\"a\", \"b\"]}',NULL,NULL,'2026-11-03 09:42:30',1,1,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(15,1,15,'{\"nilai\": \"12\"}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(16,1,16,'{\"teks\": \"Menurut saya, jawabannya dapat dijelaskan sebagai berikut. Pertama, langkah awal dilakukan dengan mengidentifikasi besaran yang diketahui. Kedua, rumus yang sesuai diterapkan. Ketiga, hasilnya disimpulkan.\"}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(17,1,19,'{\"teks\": \"Jawaban contoh siswa.\"}',NULL,NULL,'2026-11-03 09:42:30',1,1,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(18,1,20,'{\"teks\": \"Menurut saya, jawabannya dapat dijelaskan sebagai berikut. Pertama, langkah awal dilakukan dengan mengidentifikasi besaran yang diketahui. Kedua, rumus yang sesuai diterapkan. Ketiga, hasilnya disimpulkan.\"}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(19,1,21,'{\"opsi\": [\"a\"]}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(20,1,22,'{\"opsi\": [\"a\"]}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(21,1,23,'{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}',NULL,NULL,'2026-11-03 09:42:30',1,1,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(22,1,28,'{\"teks\": \"Jawaban contoh siswa.\"}',NULL,NULL,'2026-11-03 09:42:30',1,1,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(23,1,29,'{\"urutan\": [\"k2\", \"k3\", \"k1\"]}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30'),(24,1,30,'{\"pasangan\": {\"k1\": \"a\", \"k2\": \"b\", \"k3\": \"c\"}}',NULL,NULL,'2026-11-03 09:42:30',1,0,'2026-11-03 09:42:30','2026-11-03 09:42:30');
/*!40000 ALTER TABLE `student_answers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_profiles`
--

DROP TABLE IF EXISTS `student_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `nis` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nisn` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_profiles_school_id_user_id_unique` (`school_id`,`user_id`),
  UNIQUE KEY `student_profiles_school_id_nis_unique` (`school_id`,`nis`),
  UNIQUE KEY `student_profiles_school_id_nisn_unique` (`school_id`,`nisn`),
  CONSTRAINT `student_profiles_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_profiles_school_id_user_id_foreign` FOREIGN KEY (`school_id`, `user_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_profiles`
--

LOCK TABLES `student_profiles` WRITE;
/*!40000 ALTER TABLE `student_profiles` DISABLE KEYS */;
INSERT INTO `student_profiles` VALUES (1,1,8,'2024001','0000000001','L',NULL,'2026-11-03 09:42:25','2026-11-03 09:42:25'),(2,1,9,'2024002','0000000002','P',NULL,'2026-11-03 09:42:26','2026-11-03 09:42:26'),(3,1,10,'2024003','0000000003','L',NULL,'2026-11-03 09:42:26','2026-11-03 09:42:26'),(4,1,11,'2024004','0000000004','P',NULL,'2026-11-03 09:42:26','2026-11-03 09:42:26'),(5,1,12,'2024005','0000000005','L',NULL,'2026-11-03 09:42:26','2026-11-03 09:42:26'),(6,1,13,'2024006','0000000006','P',NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27'),(7,1,14,'2024007','0000000007','L',NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27'),(8,1,15,'2024008','0000000008','P',NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27'),(9,1,16,'2024009','0000000009','L',NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27'),(10,1,17,'2024010','0000000010','P',NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27'),(11,1,18,'2024011','0000000011','L',NULL,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(12,1,19,'2024012','0000000012','P',NULL,'2026-11-03 09:42:28','2026-11-03 09:42:28');
/*!40000 ALTER TABLE `student_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subjects`
--

DROP TABLE IF EXISTS `subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subjects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grade_level` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subjects_school_id_code_unique` (`school_id`,`code`),
  UNIQUE KEY `subjects_school_id_id_unique` (`school_id`,`id`),
  CONSTRAINT `subjects_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT INTO `subjects` VALUES (1,1,'MTK','Matematika','Wajib','X','aktif','2026-11-03 09:42:25','2026-11-03 09:42:25'),(2,1,'BIN','Bahasa Indonesia','Wajib','X','aktif','2026-11-03 09:42:25','2026-11-03 09:42:25'),(3,1,'BIG','Bahasa Inggris','Wajib','X','aktif','2026-11-03 09:42:25','2026-11-03 09:42:25'),(4,1,'FIS','Fisika','Peminatan','X','aktif','2026-11-03 09:42:25','2026-11-03 09:42:25'),(5,1,'KIM','Kimia','Peminatan','X','aktif','2026-11-03 09:42:25','2026-11-03 09:42:25'),(6,1,'TKJ','Jaringan Dasar','Kejuruan','X','aktif','2026-11-03 09:42:25','2026-11-03 09:42:25');
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supervision_assignments`
--

DROP TABLE IF EXISTS `supervision_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supervision_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `exam_session_room_id` bigint unsigned NOT NULL,
  `supervisor_id` bigint unsigned NOT NULL,
  `assigned_by` bigint unsigned DEFAULT NULL,
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `penugasan_unik` (`school_id`,`exam_session_room_id`,`supervisor_id`),
  UNIQUE KEY `supervision_assignments_school_id_id_unique` (`school_id`,`id`),
  KEY `supervision_assignments_school_id_supervisor_id_index` (`school_id`,`supervisor_id`),
  KEY `supervision_assignments_school_id_assigned_by_foreign` (`school_id`,`assigned_by`),
  CONSTRAINT `supervision_assignments_school_id_assigned_by_foreign` FOREIGN KEY (`school_id`, `assigned_by`) REFERENCES `users` (`school_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `supervision_assignments_school_id_exam_session_room_id_foreign` FOREIGN KEY (`school_id`, `exam_session_room_id`) REFERENCES `exam_session_rooms` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `supervision_assignments_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supervision_assignments_school_id_supervisor_id_foreign` FOREIGN KEY (`school_id`, `supervisor_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supervision_assignments`
--

LOCK TABLES `supervision_assignments` WRITE;
/*!40000 ALTER TABLE `supervision_assignments` DISABLE KEYS */;
INSERT INTO `supervision_assignments` VALUES (1,1,1,7,1,NULL,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(2,1,2,6,1,NULL,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(3,1,3,6,1,NULL,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(4,1,4,7,1,NULL,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(5,1,5,7,1,NULL,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29'),(6,1,6,6,1,NULL,NULL,'aktif','2026-11-03 09:42:29','2026-11-03 09:42:29');
/*!40000 ALTER TABLE `supervision_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supervisor_profiles`
--

DROP TABLE IF EXISTS `supervisor_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supervisor_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `staff_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supervisor_profiles_school_id_user_id_unique` (`school_id`,`user_id`),
  CONSTRAINT `supervisor_profiles_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supervisor_profiles_school_id_user_id_foreign` FOREIGN KEY (`school_id`, `user_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supervisor_profiles`
--

LOCK TABLES `supervisor_profiles` WRITE;
/*!40000 ALTER TABLE `supervisor_profiles` DISABLE KEYS */;
INSERT INTO `supervisor_profiles` VALUES (1,1,6,'PGW-001',NULL,'2026-11-03 09:42:25','2026-11-03 09:42:25'),(2,1,7,'PGW-002',NULL,'2026-11-03 09:42:25','2026-11-03 09:42:25');
/*!40000 ALTER TABLE `supervisor_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_profiles`
--

DROP TABLE IF EXISTS `teacher_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teacher_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `nip` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_profiles_school_id_user_id_unique` (`school_id`,`user_id`),
  CONSTRAINT `teacher_profiles_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_profiles_school_id_user_id_foreign` FOREIGN KEY (`school_id`, `user_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_profiles`
--

LOCK TABLES `teacher_profiles` WRITE;
/*!40000 ALTER TABLE `teacher_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `teacher_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teaching_assignments`
--

DROP TABLE IF EXISTS `teaching_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teaching_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `academic_year_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `class_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teaching_unik` (`school_id`,`teacher_id`,`subject_id`,`class_id`),
  KEY `teaching_assignments_school_id_subject_id_index` (`school_id`,`subject_id`),
  KEY `teaching_assignments_school_id_academic_year_id_foreign` (`school_id`,`academic_year_id`),
  KEY `teaching_assignments_school_id_class_id_foreign` (`school_id`,`class_id`),
  CONSTRAINT `teaching_assignments_school_id_academic_year_id_foreign` FOREIGN KEY (`school_id`, `academic_year_id`) REFERENCES `academic_years` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_assignments_school_id_class_id_foreign` FOREIGN KEY (`school_id`, `class_id`) REFERENCES `classes` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_assignments_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_assignments_school_id_subject_id_foreign` FOREIGN KEY (`school_id`, `subject_id`) REFERENCES `subjects` (`school_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_assignments_school_id_teacher_id_foreign` FOREIGN KEY (`school_id`, `teacher_id`) REFERENCES `users` (`school_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teaching_assignments`
--

LOCK TABLES `teaching_assignments` WRITE;
/*!40000 ALTER TABLE `teaching_assignments` DISABLE KEYS */;
INSERT INTO `teaching_assignments` VALUES (1,1,1,2,1,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(2,1,1,2,1,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(3,1,1,3,2,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(4,1,1,3,2,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(5,1,1,4,3,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(6,1,1,4,3,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(7,1,1,5,4,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(8,1,1,5,4,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(9,1,1,5,5,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(10,1,1,5,5,2,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(11,1,1,5,6,1,'2026-11-03 09:42:28','2026-11-03 09:42:28'),(12,1,1,5,6,2,'2026-11-03 09:42:28','2026-11-03 09:42:28');
/*!40000 ALTER TABLE `teaching_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `school_id` bigint unsigned NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_school_id_id_unique` (`school_id`,`id`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_school_id_email_unique` (`school_id`,`email`),
  KEY `users_school_id_role_index` (`school_id`,`role`),
  KEY `users_school_id_is_active_index` (`school_id`,`is_active`),
  KEY `users_role_index` (`role`),
  KEY `users_is_active_index` (`is_active`),
  CONSTRAINT `users_school_id_foreign` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,1,'admin','Admin SMK Negeri 1','admin','admin@smkn1.sch.id',NULL,'$2y$12$dE7B72H1L9g2QuFZQ7WLyeZBXZQa3HjOdXpOtU1DuVgFjtKdAll0C',1,'2026-11-03 16:03:55',NULL,'2026-11-03 09:42:24','2026-11-03 16:03:55',NULL),(2,1,'teacher','Ani Mulyani','guru1','guru1@smkn1.sch.id',NULL,'$2y$12$LRoX.yQ/jQ8X2NVLTZC0N.lFTKao5MHG7/KjoFrAwi9IRG2VyHv1C',1,'2026-11-03 09:56:26',NULL,'2026-11-03 09:42:24','2026-11-03 09:56:26',NULL),(3,1,'teacher','Budi Santoso','guru2','guru2@smkn1.sch.id',NULL,'$2y$12$79ZAWN.PkaOafjdNVveMHeAAwpdrOyVbXsSRtb2ThbeE96FJ4gVnq',1,NULL,NULL,'2026-11-03 09:42:24','2026-11-03 09:42:24',NULL),(4,1,'teacher','Rina Wati','guru3','guru3@smkn1.sch.id',NULL,'$2y$12$hIIbZ5H7IbAWPESkxS1SXuwxq.eL6nOc/qDZE5nV30pJqHd76nIem',1,NULL,NULL,'2026-11-03 09:42:25','2026-11-03 09:42:25',NULL),(5,1,'teacher','Dedi Kurniawan','guru4','guru4@smkn1.sch.id',NULL,'$2y$12$wAxZzExnstIpcXrjW2epE.TREWx30ZjyguIVKqzHjRd0y79upPJe6',1,NULL,NULL,'2026-11-03 09:42:25','2026-11-03 09:42:25',NULL),(6,1,'supervisor','Sri Handayani','pengawas1',NULL,NULL,'$2y$12$vv2QtXZ5RANysP7K7W3shOVcyJCNskedZcqb.JgeahqjHXoje2DHq',1,'2026-11-03 10:14:36',NULL,'2026-11-03 09:42:25','2026-11-03 10:14:36',NULL),(7,1,'supervisor','Joko Prasetyo','pengawas2',NULL,NULL,'$2y$12$o2fT54uTR0Yw/aC.4IBiHuLfx/upiz8mrj1b2TnZkk2Y6K6.2V/7m',1,NULL,NULL,'2026-11-03 09:42:25','2026-11-03 09:42:25',NULL),(8,1,'student','Siswa 1','2024001',NULL,NULL,'$2y$12$Mg0DPzlG7moXNh45ka9m9eFNB/k48c9zqCCNpgb64faU/3SRnBcXG',1,'2026-11-03 09:43:08',NULL,'2026-11-03 09:42:25','2026-11-03 09:43:08',NULL),(9,1,'student','Siswa 2','2024002',NULL,NULL,'$2y$12$VjkfxAfLD9upaM5bFUJ61eg3UCsN.6LPfcNByiLUPzWNnCDISq466',1,NULL,NULL,'2026-11-03 09:42:26','2026-11-03 09:42:26',NULL),(10,1,'student','Siswa 3','2024003',NULL,NULL,'$2y$12$N8b1yBhizStpePG5PE39uOWb8Nv6AOSfyhTuDjAVqIFUj1ptiz9QG',1,NULL,NULL,'2026-11-03 09:42:26','2026-11-03 09:42:26',NULL),(11,1,'student','Siswa 4','2024004',NULL,NULL,'$2y$12$Ykd83.Z954Pc0GPG18YPqe3pFdJKhAx0NeFHFNF8mwnblcBIRR9rC',1,NULL,NULL,'2026-11-03 09:42:26','2026-11-03 09:42:26',NULL),(12,1,'student','Siswa 5','2024005',NULL,NULL,'$2y$12$yIGjGD.n/gigVHgxzTZKLewWbyV1amIBH92KUazgtUE3gDTbx4PM.',1,NULL,NULL,'2026-11-03 09:42:26','2026-11-03 09:42:26',NULL),(13,1,'student','Siswa 6','2024006',NULL,NULL,'$2y$12$bMS8cInaBGzU7fh9G0BwbeB1E5xFWoiRVyFc4TZjz0Cd077Pwg93K',1,NULL,NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27',NULL),(14,1,'student','Siswa 7','2024007',NULL,NULL,'$2y$12$sr.aILPszeTD6b.S79D0AOHDq.l62.V3V7aMKbJ3N8QIHdzqdBfXa',1,NULL,NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27',NULL),(15,1,'student','Siswa 8','2024008',NULL,NULL,'$2y$12$8ckdnqp/QfZ2PvNyKuolQe3KtuIDCbAxCgrExh4AGa0nZ0d1bx0Ou',1,NULL,NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27',NULL),(16,1,'student','Siswa 9','2024009',NULL,NULL,'$2y$12$1Fojr22WHl3jTUAwLsSj7e/rSX8Pe/eP2d5rJln2rz81eABNXOO5K',1,NULL,NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27',NULL),(17,1,'student','Siswa 10','2024010',NULL,NULL,'$2y$12$wsikm0LzOJxoXGqM7hCNse5fahvj7OAHbiq/V7BpdPB21FDz.pYqm',1,NULL,NULL,'2026-11-03 09:42:27','2026-11-03 09:42:27',NULL),(18,1,'student','Siswa 11','2024011',NULL,NULL,'$2y$12$ZlIhPbR1WmnazLew91dCF.7t7IjmlXrpTMupDJIeaH4LapYiz/oaG',1,NULL,NULL,'2026-11-03 09:42:28','2026-11-03 09:42:28',NULL),(19,1,'student','Siswa 12','2024012',NULL,NULL,'$2y$12$cNT1zwbB0M5qoCNBiuA3u.XhkASu00wR1dQ6ptBGNnUp0XbXzPUHm',1,NULL,NULL,'2026-11-03 09:42:28','2026-11-03 09:42:28',NULL);
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

-- Dump completed on 2026-11-04  8:29:07

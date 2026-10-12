-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql100.infinityfree.com
-- Generation Time: Aug 26, 2026 at 09:57 AM
-- Server version: 11.4.12-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_41471921_myislam`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_settings`
--

CREATE TABLE `admin_settings` (
  `id` int(11) NOT NULL,
  `site_name` varchar(150) DEFAULT 'MyIslam',
  `default_language` varchar(10) DEFAULT 'EN',
  `default_theme` varchar(20) DEFAULT 'light',
  `allow_user_registration` tinyint(1) DEFAULT 1,
  `enable_announcements` tinyint(1) DEFAULT 1,
  `enable_leaderboard` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_settings`
--

INSERT INTO `admin_settings` (`id`, `site_name`, `default_language`, `default_theme`, `allow_user_registration`, `enable_announcements`, `enable_leaderboard`, `updated_at`) VALUES
(1, 'MyIslam', 'SW', 'dark', 1, 1, 1, '2026-07-14 20:13:04');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` bigint(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `audience_role` enum('all','owner','admin','sheikh','user') NOT NULL DEFAULT 'all',
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) NOT NULL,
  `actor_user_id` int(11) NOT NULL,
  `action` varchar(150) NOT NULL,
  `target_type` varchar(100) DEFAULT NULL,
  `target_id` bigint(20) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `lock_previous` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `subject_id`, `name`, `slug`, `description`, `icon`, `image_path`, `sort_order`, `lock_previous`, `is_active`, `created_at`) VALUES
(1, 1, 'أَرْبَعُ مَسَائِلَ', 'mas-ala-manne', 'Mas-ala Manne', NULL, '/myislam/uploads/categories/category_6a8b01d5e897f.png', 2, 1, 1, '2026-04-18 11:44:29'),
(2, 1, 'سِيرَةٌ مُوجَزَةٌ لِلْإِمَامِ مُحَمَّدِ بْنِ عَبْدِ الْوَهَّابِ', 'historia-fupi-ya-imaam-muhammad-bin-abdil-wahhaab', 'Historia Fupi Ya Imaam Muhammad Bin ‘Abdil-Wahhaab', NULL, '/myislam/uploads/categories/category_6a8b0c38670ff.png', 1, 1, 1, '2026-04-18 14:11:02'),
(4, 2, 'Utangulizi', 'utangulizi', 'مقدمة الطبعة العاشرة\r\nUtangulizi wa Toleo la Kumi', NULL, '/myislam/uploads/categories/category_6a0c724f4b05b.png', 1, 1, 1, '2026-05-17 09:51:16'),
(5, 2, 'Kitabu cha Twahara', 'kitabu-cha-twahara', 'Usafi wa ibadah(Twahara)', NULL, '/myislam/uploads/categories/category_6a0c741410960.png', 2, 0, 1, '2026-05-19 14:30:44'),
(6, 3, 'Mzunguko wa Kwanza', 'mzunguko-wa-kwanza', '', NULL, '', 1, 0, 1, '2026-06-20 01:03:42'),
(7, 3, 'Mzunguko wa pili', 'mzunguko-wa-pili', '', NULL, '', 2, 1, 1, '2026-06-20 07:45:47'),
(8, 1, 'الأصْلُ الأوَّلُ: مَعْرِفَةُ الرَّب', 'category', 'Msingi Wa Kwanza: Kumjua Rabb:', NULL, '/myislam/uploads/categories/category_6a8b20d1ee672.png', 3, 0, 1, '2026-08-23 16:18:03'),
(9, 1, 'الأَصْلُ الثَّانِي: مَعْرِفَةُ دِينِ الإِسْلامِ بِالأَدِلَّةِ.', 'category-1', 'Msingi Wa Pili: Kuijua Dini Ya Kiislamu Kwa Dalili', NULL, '/myislam/uploads/categories/category_6a8b2487d992d.png', 4, 0, 1, '2026-08-23 16:49:12'),
(10, 1, 'الأَصْلُ الثَّالِث: مَعْرِفَةُ نَبِيِّكُمْ مُحَمَّدٍ صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ:', 'category-2', 'Msingi Wa Tatu: Kumjua Nabiy Wenu Muhammad (صلى الله عليه وآله وسلم)', NULL, '/myislam/uploads/categories/category_6a8b2cf366ded.png', 5, 0, 1, '2026-08-23 17:24:19');

-- --------------------------------------------------------

--
-- Table structure for table `category_materials`
--

CREATE TABLE `category_materials` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` enum('pdf','word','excel','powerpoint','image','other') NOT NULL DEFAULT 'pdf',
  `file_size` int(11) DEFAULT NULL COMMENT 'in bytes',
  `thumbnail_path` varchar(500) DEFAULT NULL,
  `is_external_link` tinyint(1) NOT NULL DEFAULT 0,
  `external_url` varchar(500) DEFAULT NULL,
  `allow_download` tinyint(1) NOT NULL DEFAULT 1,
  `view_count` int(11) NOT NULL DEFAULT 0,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `category_materials`
--

INSERT INTO `category_materials` (`id`, `category_id`, `title`, `description`, `file_path`, `file_type`, `file_size`, `thumbnail_path`, `is_external_link`, `external_url`, `allow_download`, `view_count`, `download_count`, `sort_order`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(9, 4, 'ufunguzi', 'maelezo mafupi kuhusu kikitabu hiki', '/myislam/uploads/materials/mat_6a0bb39362d0a.pdf', 'pdf', 214244, '/myislam/uploads/materials/thumbnails/thumb_6a0bb3f20886c.png', 0, NULL, 1, 4, 0, 0, 1, 2, '2026-05-19 00:49:23', '2026-05-31 01:01:52'),
(10, 5, 'Kitabu cha twahara', 'Usafi wa ibadah(Twahara)', '/myislam/uploads/materials/mat_6a0c74dbe8bfa.pdf', 'pdf', 1299834, '/myislam/uploads/materials/thumbnails/thumb_6a0c74dbe8e5b.png', 0, NULL, 1, 17, 0, 1, 1, 2, '2026-05-19 14:34:04', '2026-07-24 16:25:42'),
(11, 6, 'Mzunguko wa Kwanza', 'katika Maisha ya Mtume Muhammad s.a.w', '/myislam/uploads/materials/mat_6a364481dc2d9.pdf', 'pdf', 352368, '/myislam/uploads/materials/thumbnails/thumb_6a364481dc74c.jpg', 0, NULL, 1, 3, 0, 0, 1, 2, '2026-06-20 07:42:58', '2026-06-28 07:51:13'),
(12, 7, 'Mzunguko Wa pili', '', '/myislam/uploads/materials/mat_6a3658f20c667.pdf', 'pdf', 1091868, '/myislam/uploads/materials/thumbnails/thumb_6a3658f20c86a.jpg', 0, NULL, 1, 0, 0, 2, 1, 2, '2026-06-20 09:10:09', '2026-06-20 09:10:09'),
(14, 2, 'سِيرَةٌ مُوجَزَةٌ لِلْإِمَامِ مُحَمَّدِ بْنِ عَبْدِ الْوَهَّابِ', 'historia-fupi-ya-imaam-muhammad-bin-abdil-wahhaab', '/myislam/uploads/materials/mat_6a8bbea2df6ee.pdf', 'pdf', 503451, '/myislam/uploads/materials/thumbnails/thumb_6a8bbea2df8af.png', 0, NULL, 1, 3, 0, 0, 1, 2, '2026-08-24 03:46:43', '2026-08-24 18:33:37'),
(15, 1, 'أَرْبَعُ مَسَائِلَ', 'mas-ala-manne', '/myislam/uploads/materials/mat_6a8bc081e1cf8.pdf', 'pdf', 311870, '/myislam/uploads/materials/thumbnails/thumb_6a8bc081e1e91.png', 0, NULL, 1, 0, 0, 0, 1, 2, '2026-08-24 03:54:42', '2026-08-24 03:54:42'),
(17, 8, 'الأصْلُ الأوَّلُ: مَعْرِفَةُ الرَّب', 'Msingi Wa Kwanza: Kumjua Rabb:', '/myislam/uploads/materials/mat_6a8bc13ac1577.pdf', 'pdf', 377719, '/myislam/uploads/materials/thumbnails/thumb_6a8bc13ac1713.png', 0, NULL, 1, 2, 0, 0, 1, 2, '2026-08-24 03:57:47', '2026-08-24 16:57:49'),
(18, 9, 'الأَصْلُ الثَّانِي: مَعْرِفَةُ دِينِ الإِسْلامِ بِالأَدِلَّةِ.', 'Msingi Wa Pili: Kuijua ADini Ya Kiislamu Kwa DaliliA', '/myislam/uploads/materials/mat_6a8bc1ea73d93.pdf', 'pdf', 412564, '/myislam/uploads/materials/thumbnails/thumb_6a8bc1ea73f18.png', 0, NULL, 1, 0, 0, 0, 1, 2, '2026-08-24 04:00:43', '2026-08-24 04:00:43'),
(20, 10, 'الأَصْلُ الثَّالِث: مَعْرِفَةُ نَبِيِّكُمْ مُحَمَّدٍ صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ:', 'الأَصْلُ الثَّالِث: مَعْرِفَةُ نَبِيِّكُمْ مُحَمَّدٍ صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ:', '/myislam/uploads/materials/mat_6a8bc29b2f3b0.pdf', 'pdf', 423389, '/myislam/uploads/materials/thumbnails/thumb_6a8bc29b2f542.png', 0, NULL, 1, 0, 0, 1, 1, 2, '2026-08-24 04:03:39', '2026-08-24 04:03:39');

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `certificate_code` varchar(80) NOT NULL,
  `certification_type` enum('subject','general') NOT NULL,
  `title` varchar(255) NOT NULL,
  `achievement_summary` text DEFAULT NULL,
  `completed_at` datetime NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','revoked') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certification_exams`
--

CREATE TABLE `certification_exams` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `slug` varchar(255) NOT NULL DEFAULT '',
  `month_year` char(7) DEFAULT NULL,
  `certification_type` enum('subject','general') NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `level` tinyint(4) NOT NULL COMMENT '1, 2, 3, 4',
  `attempt_sequence` int(11) NOT NULL DEFAULT 1,
  `passing_score` int(11) NOT NULL DEFAULT 70,
  `time_limit_minutes` int(11) NOT NULL DEFAULT 30,
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `max_attempts_per_level` int(11) NOT NULL DEFAULT 3,
  `retry_delay_hours` int(11) NOT NULL DEFAULT 24
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `certification_exams`
--

INSERT INTO `certification_exams` (`id`, `title`, `description`, `slug`, `month_year`, `certification_type`, `subject_id`, `level`, `attempt_sequence`, `passing_score`, `time_limit_minutes`, `status`, `created_by`, `created_at`, `updated_at`, `start_date`, `end_date`, `max_attempts_per_level`, `retry_delay_hours`) VALUES
(1, 'qur-an level 1 exam', '', 'qur-an-level-1-exam', '2026-04', 'subject', 1, 1, 1, 70, 1, 'published', 0, '2026-04-23 15:13:10', '2026-04-27 04:50:44', '2026-04-24 20:10:00', '2026-04-27 18:56:00', 3, 24),
(2, 'level 1 exam 2', '', 'level', '2026-04', 'subject', 1, 1, 2, 70, 30, 'published', 2, '2026-04-25 01:07:26', '2026-04-26 08:54:02', '2026-04-25 04:06:00', '2026-04-26 13:15:00', 3, 0),
(3, 'level 1 exam 3', 'just kidding', 'level-1', '2026-04', 'subject', 1, 1, 3, 70, 1, 'published', 2, '2026-04-26 08:56:11', '2026-04-26 09:02:22', '2026-04-26 11:55:00', '2026-04-27 15:59:00', 3, 0),
(4, 'level 2 exam  1', '', 'level-2', '2026-04', 'subject', 1, 2, 1, 70, 1, 'published', 2, '2026-04-26 09:25:13', '2026-04-27 04:51:20', '2026-04-26 12:25:00', '2026-04-27 13:25:00', 3, 24),
(5, 'level 2 exam 2', '', 'level-3', '2026-04', 'subject', 1, 2, 2, 70, 1, 'published', 2, '2026-04-26 09:28:18', '2026-04-26 09:38:36', '2026-04-26 12:28:00', '2026-04-26 13:28:00', 3, 0),
(6, 'level 3 exam 1', '', 'level-4', '2026-04', 'subject', 1, 3, 1, 70, 1, 'published', 2, '2026-04-26 09:32:02', '2026-04-26 11:53:20', '2026-04-26 12:31:00', '2026-04-27 13:31:00', 3, 24),
(7, 'level 4 exam 1', '', 'level-5', '2026-04', 'subject', 1, 4, 1, 70, 1, 'published', 2, '2026-04-26 09:33:24', '2026-04-26 11:01:18', '2026-04-26 12:33:00', '2026-04-27 13:33:00', 3, 24);

-- --------------------------------------------------------

--
-- Table structure for table `certification_exam_attempts`
--

CREATE TABLE `certification_exam_attempts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `attempt_number` int(11) NOT NULL DEFAULT 1,
  `status` enum('started','completed','abandoned','terminated') NOT NULL DEFAULT 'started',
  `score` int(11) DEFAULT 0,
  `total_points` int(11) DEFAULT 0,
  `percentage` decimal(5,2) DEFAULT 0.00,
  `passed` tinyint(1) NOT NULL DEFAULT 0,
  `correct_answers` int(11) DEFAULT 0,
  `total_questions` int(11) DEFAULT 0,
  `answers_json` text DEFAULT NULL,
  `started_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certification_exam_questions`
--

CREATE TABLE `certification_exam_questions` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('multiple_choice','true_false','explanation') NOT NULL DEFAULT 'multiple_choice',
  `points` int(11) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `image_path` varchar(255) DEFAULT NULL,
  `explanation` text DEFAULT NULL,
  `expected_answer` text DEFAULT NULL,
  `keywords_json` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `certification_exam_questions`
--

INSERT INTO `certification_exam_questions` (`id`, `exam_id`, `question_text`, `question_type`, `points`, `sort_order`, `image_path`, `explanation`, `expected_answer`, `keywords_json`) VALUES
(1, 1, 'what is islam', 'explanation', 1, 0, 'uploads/exams/cert_69eb64fc54b522.76284944.jpeg', 'just kidding', 'dinillah', '[{\"word\":\"deen\",\"weight\":1},{\"word\":\"din\",\"weight\":1},{\"word\":\"allah\",\"weight\":1}]'),
(2, 1, 'taja jina la msikiti huu', 'explanation', 1, 1, 'uploads/exams/cert_69eb65999bcd69.70464205.png', NULL, 'al aqsa', '[{\"word\":\"aqsa\",\"weight\":1},{\"word\":\"al\",\"weight\":1},{\"word\":\"al-aqsa\",\"weight\":1}]'),
(3, 1, 'mtume wa mwisho ni muhammad', 'true_false', 1, 2, NULL, 'naam', NULL, NULL),
(4, 1, 'ni kitabu kani alichoshishiwa nabii issa a.s?', 'multiple_choice', 1, 3, NULL, NULL, NULL, NULL),
(5, 2, 'taja nguzo za uislam', 'explanation', 1, 1, 'uploads/exams/cert_69ec1469ede410.65112467.jpeg', NULL, 'shahada, swala , zakat, funga , hijja', '[{\"word\":\"shahada\",\"weight\":1},{\"word\":\"swala\",\"weight\":1},{\"word\":\"zakat\",\"weight\":1},{\"word\":\"funga\",\"weight\":1},{\"word\":\"hijja\",\"weight\":1}]'),
(6, 2, 'huyu ni nani', 'multiple_choice', 1, 2, 'uploads/exams/cert_69ec14a1d78d17.68384750.jpeg', NULL, NULL, NULL),
(7, 3, 'who is that girl?', 'explanation', 1, 1, 'uploads/exams/cert_69edd35f975472.63867873.png', NULL, 'deena salaam', '[{\"word\":\"deen\",\"weight\":1},{\"word\":\"deena\",\"weight\":1},{\"word\":\"salaam\",\"weight\":1}]'),
(8, 3, 'is sheytwaan adua llahu?', 'true_false', 1, 2, 'uploads/exams/cert_69edd398dd4e82.12777462.jpeg', NULL, NULL, NULL),
(9, 3, 'what do you see in the picture below?', 'multiple_choice', 1, 3, 'uploads/exams/cert_69edd3e053a0d5.13308657.png', NULL, NULL, NULL),
(10, 4, 'mwanamke', 'explanation', 1, 1, 'uploads/exams/cert_69edda7e0cfb65.19422584.jpg', NULL, 'mwanamke', '[{\"word\":\"mwana. mwamamke\",\"weight\":1},{\"word\":\"...\",\"weight\":1}]'),
(11, 5, 'what do you see?', 'explanation', 1, 1, 'uploads/exams/cert_69eddaf42f86b1.60918161.png', NULL, 'kuku', NULL),
(12, 6, 'who ?', 'explanation', 1, 1, 'uploads/exams/cert_69eddbaed890e7.34379970.jpeg', NULL, 'zauja', NULL),
(13, 7, 'related to?', 'explanation', 1, 1, 'uploads/exams/cert_69eddc15ef1058.95554162.jpg', NULL, 'nikkah', '[{\"word\":\"nika\",\"weight\":1},{\"word\":\"nikkah\",\"weight\":1}]');

-- --------------------------------------------------------

--
-- Table structure for table `certification_exam_question_options`
--

CREATE TABLE `certification_exam_question_options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_text` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `certification_exam_question_options`
--

INSERT INTO `certification_exam_question_options` (`id`, `question_id`, `option_text`, `image_path`, `is_correct`, `sort_order`) VALUES
(1, 3, 'True', NULL, 1, 0),
(2, 3, 'False', NULL, 0, 1),
(3, 4, 'qur-an', NULL, 0, 0),
(4, 4, 'injil', NULL, 1, 1),
(5, 4, 'zabuur', NULL, 0, 2),
(6, 4, 'taurat', NULL, 0, 3),
(7, 6, 'zauja', NULL, 1, 0),
(8, 6, 'hijr', NULL, 0, 1),
(9, 8, 'True', NULL, 1, 0),
(10, 8, 'False', NULL, 0, 1),
(14, 9, 'cyber bootcamp', NULL, 1, 0),
(15, 9, 'ctf zone', NULL, 0, 1),
(16, 9, 'cyberchef', NULL, 0, 2);

-- --------------------------------------------------------

--
-- Table structure for table `contents`
--

CREATE TABLE `contents` (
  `id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `short_description` text DEFAULT NULL,
  `body` longtext NOT NULL,
  `body_html` text DEFAULT NULL,
  `arabic_text` longtext DEFAULT NULL,
  `translation` longtext DEFAULT NULL,
  `audio_path` varchar(255) DEFAULT NULL,
  `video_path` varchar(255) DEFAULT NULL,
  `video_url` text DEFAULT NULL,
  `reference_text` text DEFAULT NULL,
  `source_name` varchar(255) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('draft','pending','published','rejected') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_featured` tinyint(1) DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `contents`
--

INSERT INTO `contents` (`id`, `subject_id`, `category_id`, `title`, `slug`, `short_description`, `body`, `body_html`, `arabic_text`, `translation`, `audio_path`, `video_path`, `video_url`, `reference_text`, `source_name`, `tags`, `image_path`, `status`, `created_by`, `approved_by`, `sort_order`, `is_featured`, `published_at`, `created_at`, `updated_at`) VALUES
(21, 2, 5, 'الْحَدِيثُ الْأَوَّلُ', 'content-1', '✨ 𝐇𝐀𝐃𝐈𝐓𝐇 𝐘𝐀 𝐍𝐈𝐘𝐀 ✨', 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b6195c749f.mp3', NULL, 'https://www.youtube.com/embed/LPOKGnxYymE', NULL, NULL, NULL, '', 'published', 2, 2, 2, 0, '2026-05-30 08:08:57', '2026-05-22 20:37:09', '2026-08-25 08:53:26'),
(22, 2, 5, 'hadithi ya pili', 'content', 'kutia udhu kabla ya swala', 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b7d846e706.mp3', NULL, 'https://www.youtube.com/embed/eRcsAj5hBXU', NULL, NULL, NULL, '/uploads/contents/content_6a35dad925775.webp', 'published', 2, 2, 3, 0, '2026-05-26 16:48:54', '2026-05-22 21:23:48', '2026-06-20 00:18:27'),
(23, 2, 5, 'hadithi ya tatu', 'hadithi-ya-tatu', 'kufitia udhu mpaka kwenye visigino', 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b84985d43c.mp3', NULL, 'https://www.youtube.com/embed/CX_8pa_qEmY', NULL, NULL, NULL, '', 'published', 2, 2, 4, 0, '2026-05-26 17:02:12', '2026-05-22 21:27:56', '2026-06-20 00:29:26'),
(24, 2, 5, 'hadithi ya nne', 'hadithi-ya-nne', 'udhu', 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b74dca7953.mp3', NULL, 'https://www.youtube.com/embed/x_pj51LmqfQ', NULL, NULL, NULL, '', 'published', 2, 2, 5, 0, '2026-05-26 17:02:46', '2026-05-22 21:31:30', '2026-06-20 00:35:12'),
(25, 2, 5, 'hadithi ya tano', 'hadithi-ya-tano', 'maji yaliyotuama', 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b7f25f20f1.mp3', NULL, 'https://www.youtube.com/embed/SC7fb8lHyPk', NULL, NULL, NULL, '', 'published', 2, 2, 6, 0, '2026-05-30 18:36:55', '2026-05-22 21:33:53', '2026-06-20 00:45:59'),
(26, 2, 5, 'hadithi ya sita', 'hadithi-ya-sita', NULL, 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b7f7cf0b01.mp3', NULL, NULL, NULL, NULL, NULL, '', 'published', 2, 2, 7, 0, '2026-05-30 20:23:24', '2026-05-30 23:07:29', '2026-05-31 00:56:30'),
(29, 2, 5, 'hadithi ya saba', 'hadithi-ya-saba', 'hadithi ya saba', 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b8ed2cbabe.mp3', NULL, NULL, NULL, NULL, NULL, '', 'published', 2, 2, 8, 0, '2026-05-30 20:56:40', '2026-05-31 00:50:04', '2026-05-31 01:28:50'),
(30, 2, 5, 'hadithi ya nane', 'hadithi-ya-nane', NULL, 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b869500117.mp3', NULL, NULL, NULL, NULL, NULL, '', 'published', 2, 2, 9, 0, '2026-05-30 20:56:48', '2026-05-31 00:53:40', '2026-05-31 01:23:38'),
(31, 2, 5, 'Utangulizi wa twahara', 'utangulizi', 'malango wa twahara', 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b8854b6fed.mp3', NULL, NULL, NULL, NULL, NULL, '', 'published', 2, 2, 1, 0, '2026-05-30 21:14:09', '2026-05-31 01:01:08', '2026-06-20 00:11:20'),
(32, 2, 4, 'utangulizi wa kitabu', 'utangulizi-wa-kitabu', NULL, 'Content divided into parts below.', NULL, NULL, NULL, '/uploads/audio/audio_6a1b89d3630fd.mp3', NULL, NULL, NULL, NULL, NULL, '', 'published', 2, NULL, 1, 0, NULL, '2026-05-31 01:07:30', '2026-05-31 01:08:45'),
(33, 3, 6, 'Sehemu ya 1', 'mzunguko-wa-kwanza', 'kuhusu maisha ya Mtume muhammad s.a.w', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/sSWa6-eruBw', NULL, NULL, NULL, '', 'published', 2, 2, 1, 0, '2026-06-19 21:20:44', '2026-06-20 01:11:42', '2026-06-23 20:32:28'),
(34, 3, 6, 'Sehemu ya 2', 'sehemu-ya-pili', 'Nasabu yake Mtume na Kufa Kwa baba yake', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/MAzijkN0wEE', NULL, NULL, NULL, '', 'published', 2, 2, 2, 0, '2026-06-19 22:03:43', '2026-06-20 01:37:42', '2026-06-20 02:03:43'),
(35, 3, 6, 'Sehemu ya 3', 'sehemu-ya-tatu', 'Kuzaliwa kwake na kunyonyweshwa', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/uEhQLN-wneM', NULL, NULL, NULL, '', 'published', 2, 2, 3, 0, '2026-06-19 22:04:00', '2026-06-20 01:44:14', '2026-06-20 02:04:00'),
(36, 3, 6, 'Sehemu ya 4', 'sehemu-ya-nne', 'Kufa kwa mama yake na kulelewa kwake', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/BnRZAJyIrw4', NULL, NULL, NULL, '', 'published', 2, 2, 4, 0, '2026-06-19 22:04:14', '2026-06-20 01:46:32', '2026-06-20 02:04:15'),
(37, 3, 6, 'Sehemu ya 5', 'sehemu-ya-tano', 'Kulelewa kwake na kufa Kwa Babu yake', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/gDgkYXihCbg', NULL, NULL, NULL, '', 'published', 2, 2, 5, 0, '2026-06-19 22:04:26', '2026-06-20 01:49:05', '2026-06-20 02:04:26'),
(38, 3, 6, 'Sehemu ya 6', 'sehemu-ya-sita', 'Kuchunga Kwake Mbuzi na Safari yake ya Kwanza sham', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/aFiIZfstIuQ', NULL, NULL, NULL, '', 'published', 2, 2, 6, 0, '2026-06-19 22:04:59', '2026-06-20 01:51:33', '2026-06-20 02:04:59'),
(39, 3, 6, 'Sehemu ya 7', 'sehemu-ya-saba', 'Safari yake ya pili Kuelekea Sham.', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/Ejc5uNYKDS4', NULL, NULL, NULL, '', 'published', 2, 2, 7, 0, '2026-06-19 22:04:44', '2026-06-20 01:53:51', '2026-06-20 02:04:44'),
(40, 3, 6, 'Sehemu ya 8', 'sehemu-ya-nane', 'Kumuoa Kwake Bi. Khadijah', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/Bdv2PRFRsBg', NULL, NULL, NULL, '', 'published', 2, 2, 8, 0, '2026-06-19 22:05:15', '2026-06-20 01:57:01', '2026-06-20 02:05:15'),
(42, 3, 6, 'Sehem ya 9', 'sehem-ya-tisa', 'Hukmu Yake Mtume Baina Ya Makuraish katika kuliweka Jiwe jeusi', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/-jgRGhJgMbk', NULL, NULL, NULL, '', 'published', 2, 2, 9, 0, '2026-06-19 22:05:31', '2026-06-20 01:59:14', '2026-06-20 02:05:31'),
(43, 3, 6, 'Sehemu ya 10', 'sehemu-ya-10', 'Kuibuka kwake Mtume.', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/XgsGhueEzxY', NULL, NULL, NULL, '', 'published', 2, NULL, 10, 0, NULL, '2026-06-20 02:02:59', '2026-06-20 02:03:12'),
(44, 3, 6, 'Sehemu ya 11', 'sehemu-ya-11', 'Maisha yake Mtume Kabla ya Utume', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/ufA00-3T-QM', NULL, NULL, NULL, '', 'published', 2, NULL, 11, 0, NULL, '2026-06-20 02:07:38', '2026-06-20 02:07:42'),
(45, 3, 6, 'Sehemu ya 12', 'sehemu-ya-12', 'Ufupisho wa Mzunguko Wa Kwanza', 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/24CTMxD6LM4', NULL, NULL, NULL, '', 'published', 2, NULL, 12, 0, NULL, '2026-06-20 02:09:49', '2026-06-20 02:13:18'),
(46, 3, 7, 'Sehemu ya 13', 'sehemu-ya-13', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/W26czJS8ZwI', NULL, NULL, NULL, '', 'published', 2, NULL, 13, 0, NULL, '2026-06-20 07:50:37', '2026-06-20 09:07:24'),
(47, 3, 7, 'Sehemu ya 14', 'sehemu-ya-14', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/-Qo4AuOJf5U', NULL, NULL, NULL, '', 'published', 2, NULL, 14, 0, NULL, '2026-06-20 07:53:33', '2026-06-20 09:07:28'),
(48, 3, 7, 'Sehemu ya 15', 'sehemu-ya-15', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/p2zlrBL-B0o', NULL, NULL, NULL, '', 'published', 2, NULL, 15, 0, NULL, '2026-06-20 07:56:18', '2026-06-20 09:07:57'),
(49, 3, 7, 'Sehemu ya 16', 'sehemu-ya-16', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/UUv7frVtcmE', NULL, NULL, NULL, '', 'published', 2, NULL, 16, 0, NULL, '2026-06-20 07:58:22', '2026-06-20 09:07:22'),
(50, 3, 7, 'Sehemu Ya 17', 'sehemu-ya-17', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/RnZppmduCS0', NULL, NULL, NULL, '', 'published', 2, NULL, 17, 0, NULL, '2026-06-20 08:00:15', '2026-06-20 09:07:33'),
(51, 3, 6, 'Sehemu ya 18', 'sehemu-ya-18', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/MuywfRo7J8M', NULL, NULL, NULL, '', 'published', 2, NULL, 18, 0, NULL, '2026-06-20 08:04:38', '2026-06-20 09:07:38'),
(52, 3, 7, 'Sehemu ya 19', 'sehemu-ya-19', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/AEaQ3OnXWJI', NULL, NULL, NULL, '', 'published', 2, NULL, 19, 0, NULL, '2026-06-20 08:06:19', '2026-06-20 09:07:19'),
(53, 3, 7, 'Sehemu ya 20', 'sehemu-ya-20', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/B2ozIofF-To', NULL, NULL, NULL, '', 'published', 2, NULL, 20, 0, NULL, '2026-06-20 08:08:32', '2026-06-20 09:07:18'),
(54, 3, 7, 'Sehemu ya 21', 'sehemu-ya-21', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/RdTxDEWjiP8', NULL, NULL, NULL, '', 'published', 2, NULL, 21, 0, NULL, '2026-06-20 08:10:01', '2026-06-20 09:07:12'),
(55, 3, 7, 'Sehemu ya 22', 'sehemu-ya-22', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/lSGfpPENEZ4', NULL, NULL, NULL, '', 'published', 2, NULL, 22, 0, NULL, '2026-06-20 08:11:35', '2026-06-20 09:07:11'),
(56, 3, 7, 'Sehemu ya 23', 'sehemu-ya-23', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/fGUbdQ7Nto8', NULL, NULL, NULL, '', 'published', 2, NULL, 23, 0, NULL, '2026-06-20 08:13:13', '2026-06-20 09:07:44'),
(57, 3, 7, 'Sehemu ya 24', 'sehemu-ya-24', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/ZFfPAjf4tNY', NULL, NULL, NULL, '', 'published', 2, NULL, 24, 0, NULL, '2026-06-20 08:14:48', '2026-06-20 09:06:59'),
(58, 3, 7, 'Sehemu Ya 25', 'sehemu-ya-25', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/j1NnvNNAE5s', NULL, NULL, NULL, '', 'published', 2, NULL, 25, 0, NULL, '2026-06-20 08:16:32', '2026-06-20 09:07:48'),
(59, 3, 7, 'Sehemu ya 26', 'kusilimu-kwa-hamza-na-omari-r-a', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/BCCwDSARR0c', NULL, NULL, NULL, '', 'published', 2, 2, 26, 0, '2026-06-20 02:06:57', '2026-06-20 08:18:18', '2026-06-20 09:07:06'),
(60, 3, 7, 'Sehemu ya 27', 'sehemu-ya-27', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/CTrMhI_5aZY', NULL, NULL, NULL, '', 'published', 2, NULL, 27, 0, NULL, '2026-06-20 08:20:03', '2026-06-20 09:06:44'),
(61, 3, 7, 'Sehemu Ya 28', 'sehemu-ya-28', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/2cgzDS2Neew', NULL, NULL, NULL, '', 'published', 2, NULL, 28, 0, NULL, '2026-06-20 08:21:40', '2026-06-20 09:06:45'),
(62, 3, 7, 'Sehemu ya 29', 'sehemu-ya-29', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/4rgKyC3QFlE', NULL, NULL, NULL, '', 'published', 2, NULL, 29, 0, NULL, '2026-06-20 08:23:12', '2026-06-20 09:06:33'),
(63, 3, 7, 'Sehemu ya 30', 'sehemu-ya-30', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/v_5NDQM43_s', NULL, NULL, NULL, '', 'published', 2, NULL, 30, 0, NULL, '2026-06-20 08:24:31', '2026-06-20 09:06:33'),
(64, 3, 7, 'Sehemu ya 31', 'sehemu-ya-31', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/Y6AoeFemjTg', NULL, NULL, NULL, '', 'published', 2, NULL, 31, 0, NULL, '2026-06-20 08:25:52', '2026-06-20 09:06:19'),
(65, 3, 7, 'Sehemu Ya 32', 'sehemu-ya-32', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/I9UZKjX7goA', NULL, NULL, NULL, '', 'published', 2, NULL, 32, 0, NULL, '2026-06-20 08:28:01', '2026-06-20 09:06:19'),
(66, 3, 7, 'Sehemu Ya 33', 'sehemu-ya-33', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/RzI_f2cR-48', NULL, NULL, NULL, '', 'published', 2, NULL, 33, 0, NULL, '2026-06-20 08:29:39', '2026-06-20 09:06:21'),
(67, 3, 7, 'Sehemu ya 34', 'sehemu-ya-34', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/R0jd26CPVmI', NULL, NULL, NULL, '', 'published', 2, NULL, 34, 0, NULL, '2026-06-20 08:31:26', '2026-06-20 09:06:12'),
(68, 3, 7, 'Sehemu Ya 35', 'sehemu-ya-35', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/8ijOMAARRes', NULL, NULL, NULL, '', 'published', 2, NULL, 35, 0, NULL, '2026-06-20 08:32:55', '2026-06-20 09:06:13'),
(69, 3, 7, 'Sehemu ya 36', 'sehemu-ya-36', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/42olFo1_bjA', NULL, NULL, NULL, '', 'published', 2, NULL, 36, 0, NULL, '2026-06-20 08:34:23', '2026-06-20 09:06:29'),
(70, 3, 7, 'Sehemu Ya 37', 'sehemu-ya-37', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/tWyTuuVZCks', NULL, NULL, NULL, '', 'published', 2, NULL, 37, 0, NULL, '2026-06-20 08:35:55', '2026-06-20 09:06:07'),
(71, 3, 7, 'Sehemu Ya 38', 'sehemu-ya-38', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/S03TkXmjCs4', NULL, NULL, NULL, '', 'published', 2, NULL, 38, 0, NULL, '2026-06-20 08:37:10', '2026-06-20 09:06:07'),
(72, 3, 7, 'Sehemu Ya 39', 'sehemu-ya-39', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/aoHA-QHPTCM', NULL, NULL, NULL, '', 'published', 2, NULL, 39, 0, NULL, '2026-06-20 08:38:18', '2026-06-20 09:05:59'),
(73, 3, 7, 'Sehemu ya 40', 'sehemu-ya-40', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/gRYwOC9TXn0', NULL, NULL, NULL, '', 'published', 2, NULL, 40, 0, NULL, '2026-06-20 08:39:46', '2026-06-20 09:05:58'),
(74, 3, 7, 'Sehemu ya 41', 'sehemu-ya-41', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/InuxjoudB64', NULL, NULL, NULL, '', 'published', 2, NULL, 41, 0, NULL, '2026-06-20 08:41:03', '2026-06-20 09:05:57'),
(75, 3, 7, 'Sehemu ya 42', 'sehemu-ya-42', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/7CHGDmWShkI', NULL, NULL, NULL, '', 'published', 2, NULL, 42, 0, NULL, '2026-06-20 08:42:26', '2026-06-20 09:05:57'),
(76, 3, 7, 'Sehemu ya 43', 'sehemu-ya-43', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/IVY3lNLoL90', NULL, NULL, NULL, '', 'published', 2, NULL, 43, 0, NULL, '2026-06-20 08:43:46', '2026-06-20 09:05:51'),
(77, 3, 7, 'Sehemu ya 44', 'sehemu-ya-44', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/vT3Xce_0oig', NULL, NULL, NULL, '', 'published', 2, NULL, 44, 0, NULL, '2026-06-20 08:45:36', '2026-06-20 09:05:50'),
(78, 3, 7, 'sehemu Ya 45', 'sehemu-ya-45', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/PrSCVouOJjs', NULL, NULL, NULL, '', 'published', 2, NULL, 45, 0, NULL, '2026-06-20 08:47:49', '2026-06-20 09:05:50'),
(79, 3, 7, 'Sehemu Ya 46', 'sehemu-ya-46', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/8nWOo6JNZSM', NULL, NULL, NULL, '', 'published', 2, NULL, 46, 0, NULL, '2026-06-20 08:50:37', '2026-06-20 09:05:45'),
(80, 3, 7, 'Sehemu Ya 47', 'sehemu-ya-47', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/euc_ZxoAPWU', NULL, NULL, NULL, '', 'published', 2, NULL, 47, 0, NULL, '2026-06-20 08:54:13', '2026-06-20 09:05:44'),
(81, 3, 7, 'Sehemu Ya 48', 'sehemu-ya-48', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/vzSOe9owC3A', NULL, NULL, NULL, '', 'published', 2, NULL, 48, 0, NULL, '2026-06-20 08:55:56', '2026-06-20 09:05:40'),
(82, 3, 7, 'Sehemu Ya 49', 'sehemu-ya-49', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/kfWBFEiVe3A', NULL, NULL, NULL, '', 'published', 2, NULL, 49, 0, NULL, '2026-06-20 08:58:47', '2026-06-20 09:05:33'),
(83, 3, 7, 'Sehemu ya 50', 'sehemu-ya-50', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, 'https://www.youtube.com/embed/GHVEiB5R550', NULL, NULL, NULL, '', 'published', 2, NULL, 50, 0, NULL, '2026-06-20 09:01:16', '2026-06-20 09:05:27'),
(85, 1, 2, 'Harakaat Na Mitihani', 'harakaat-na-mitihani', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '', 'published', 2, 2, 2, 0, '2026-08-24 00:58:40', '2026-08-24 04:52:32', '2026-08-24 12:35:34'),
(86, 1, 2, 'Asili na Elimu yake', 'asili-na-elimu-yake', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '', 'published', 2, 2, 1, 0, '2026-08-24 08:54:04', '2026-08-24 12:50:46', '2026-08-24 12:55:36'),
(87, 1, 2, 'Athari Ya D’awah kazi  Yake', 'athari-ya-d-awah-kazi-yake', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '', 'published', 2, NULL, 3, 0, NULL, '2026-08-24 16:27:38', '2026-08-24 16:31:12'),
(88, 1, 2, 'Wanafunzi, Familia na Kifo chake', 'wanafunzi-familia-na-kifo-chake', NULL, 'Content divided into parts below.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '', 'published', 2, NULL, 4, 0, NULL, '2026-08-24 16:39:01', '2026-08-24 16:40:06');

-- --------------------------------------------------------

--
-- Table structure for table `content_parts`
--

CREATE TABLE `content_parts` (
  `id` int(11) NOT NULL,
  `content_id` bigint(20) NOT NULL,
  `part_number` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `arabic_body` longtext DEFAULT NULL,
  `arabic_body_html` text DEFAULT NULL,
  `swahili_body` longtext DEFAULT NULL,
  `swahili_body_html` text DEFAULT NULL,
  `section_order` enum('arabic_first','swahili_first') NOT NULL DEFAULT 'arabic_first',
  `audio_path` varchar(255) DEFAULT NULL,
  `video_path` varchar(255) DEFAULT NULL,
  `video_url` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `display_mode` enum('sections','combined') NOT NULL DEFAULT 'sections',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `content_parts`
--

INSERT INTO `content_parts` (`id`, `content_id`, `part_number`, `title`, `arabic_body`, `arabic_body_html`, `swahili_body`, `swahili_body_html`, `section_order`, `audio_path`, `video_path`, `video_url`, `sort_order`, `display_mode`, `created_at`) VALUES
(233, 22, 1, 'hadith ya pili', 'عَنْ أَبِي هُرَيْرَةَ رَضِيَ اللَّهُ عَنْهُ قَالَ: قَالَ رَسُولُ اللَّهِ ﷺ:«لَا يَقْبَلُ اللَّهُ صَلَاةَ أَحَدِكُمْ إِذَا أَحْدَثَ حَتَّى يَتَوَضَّأَ»', '<p>عَنْ أَبِي هُرَيْرَةَ رَضِيَ اللَّهُ عَنْهُ قَالَ: قَالَ رَسُولُ اللَّهِ ﷺ:</p><p>«لَا يَقْبَلُ اللَّهُ صَلَاةَ أَحَدِكُمْ إِذَا أَحْدَثَ حَتَّى يَتَوَضَّأَ»</p>', '🌙 𝑻𝒂𝒇𝒔𝒊𝒓𝒊 𝒚𝒂𝒌𝒆:Kutoka kwa Abu Hurayrah رضي الله عنه amesema: Mtume wa Allah ﷺ amesema:“Allah Hakubali swala ya mmoja wenu anapopatwa na hadathi mpaka atawadhe.” 🤍', '<p>🌙 𝑻𝒂𝒇𝒔𝒊𝒓𝒊 𝒚𝒂𝒌𝒆:<br>Kutoka kwa Abu Hurayrah رضي الله عنه amesema: Mtume wa Allah ﷺ amesema:</p><p>“Allah Hakubali swala ya mmoja wenu anapopatwa na hadathi mpaka atawadhe.” 🤍</p>', 'arabic_first', '', '', '', 1, 'sections', '2026-05-26 20:48:54'),
(237, 25, 1, 'hadithi ya tano', 'عَنْ أَبِي هُرَيْرَةَ رضي الله عنه، أَنَّ رَسُولَ اللَّهِ ﷺ قَالَ: «لَا يَبُولَنَّ أَحَدُكُمْ فِي الْمَاءِ الدَّائِمِ الَّذِي لَا يَجْرِي، ثُمَّ يَغْتَسِلُ مِنْهُ».وَلِمُسْلِمٍ: «لَا يَغْتَسِلْ أَحَدُكُمْ فِي الْمَاءِ الدَّائِمِ وَهُوَ جُنُبٌ».', '<p><strong>عَنْ أَبِي هُرَيْرَةَ رضي الله عنه، أَنَّ رَسُولَ اللَّهِ ﷺ قَالَ: «لَا يَبُولَنَّ أَحَدُكُمْ فِي الْمَاءِ الدَّائِمِ الَّذِي لَا يَجْرِي، ثُمَّ يَغْتَسِلُ مِنْهُ».</strong></p><p><strong>وَلِمُسْلِمٍ: «لَا يَغْتَسِلْ أَحَدُكُمْ فِي الْمَاءِ الدَّائِمِ وَهُوَ جُنُبٌ».</strong></p>', '🌙 𝑻𝒂𝒇𝒔𝒊𝒓𝒊 𝒚𝒂𝒌𝒆:Kutoka kwa Abu Hurayrah رضي الله عنه amesema: Mtume wa Allah ﷺ amesema:“Asikojoe mmoja wenu katika maji yaliyotuama yasiyotiririka, kisha akaoga humo.” 🤍📚 (Al-Bukhari namba 239, na Muslim namba 282)  🌙 𝑻𝒂𝒇𝒔𝒊𝒓𝒊 𝒚𝒂𝒌𝒆:“Na asioge mmoja wenu katika maji yaliyotuama hali ya kuwa ana janaba.” 🤍', '<p>🌙 𝑻𝒂𝒇𝒔𝒊𝒓𝒊 𝒚𝒂𝒌𝒆:<br>Kutoka kwa Abu Hurayrah رضي الله عنه amesema: Mtume wa Allah ﷺ amesema:</p><p>“Asikojoe mmoja wenu katika maji yaliyotuama yasiyotiririka, kisha akaoga humo.” 🤍</p><p>📚 (Al-Bukhari namba 239, na Muslim namba 282)<br> </p><p><br> </p><p>🌙 𝑻𝒂𝒇𝒔𝒊𝒓𝒊 𝒚𝒂𝒌𝒆:<br>“Na asioge mmoja wenu katika maji yaliyotuama hali ya kuwa ana janaba.” 🤍</p>', 'arabic_first', '', '', '', 1, 'sections', '2026-05-26 21:03:44'),
(239, 24, 1, 'hadithi ya nne', '■ الحديث الرابع ■{ ٤ } عَن أَبِي هُرَيْرَةَ ﷺ : أَنَّ رَسُولَ اللَّهِ ﷺ : قَالَ: «إِذَا تَوَضَأَ أَحَدُكُمْ فَلْيَجْعَلْ فِي أَنْفِه مَاءً، ثُمَّ لِيَسْتَنْثِرْ، وَمَنْ اسْتَجْمَرَ فَلْيُوتِرْ، وَإِذَا اسْتَيْقَظَ أَحَدُكُمْ مِنْ نَوْمِهِ فَلْيَغْسِلْ يَدَهُ قَبْلَ أَنْ يُدْخِلَهُمَا فِي وَضُوئِهِ، فَإِنَّ أَحَدَكُمْ لَا يَدْرِي أَيْنَ بَاتَتْ يَدُهُ» (٢).وفي لفظ لمسلم: «فَلْيَسْتَنْثِرْ بِمَنْخَرَيْهِ مِنَ الْمَاءِ». { ٢٣٧ / ٢١ }.وفي لفظ: «مَنْ تَوَضَّأَ فَلْيَسْتَنْثِرْ». { ١٦١ }، ومسلم رقم (٢١ / ٢٣٧).', '<p>■ الحديث الرابع ■</p><p>{ ٤ } عَن أَبِي هُرَيْرَةَ ﷺ : أَنَّ رَسُولَ اللَّهِ ﷺ : قَالَ: «إِذَا تَوَضَأَ أَحَدُكُمْ فَلْيَجْعَلْ فِي أَنْفِه مَاءً، ثُمَّ لِيَسْتَنْثِرْ، وَمَنْ اسْتَجْمَرَ فَلْيُوتِرْ، وَإِذَا اسْتَيْقَظَ أَحَدُكُمْ مِنْ نَوْمِهِ فَلْيَغْسِلْ يَدَهُ قَبْلَ أَنْ يُدْخِلَهُمَا فِي وَضُوئِهِ، فَإِنَّ أَحَدَكُمْ لَا يَدْرِي أَيْنَ بَاتَتْ يَدُهُ» (٢).</p><p>وفي لفظ لمسلم: «فَلْيَسْتَنْثِرْ بِمَنْخَرَيْهِ مِنَ الْمَاءِ». { ٢٣٧ / ٢١ }.</p><p>وفي لفظ: «مَنْ تَوَضَّأَ فَلْيَسْتَنْثِرْ». { ١٦١ }، ومسلم رقم (٢١ / ٢٣٧).</p><p> </p><p> </p><p> </p><p> </p><p> </p><p><br> </p>', '“Anapofanya udhu mmoja wenu, na aweke maji ndani ya pua yake, kisha ayatoe. Na anayejisafisha baada ya kujisaidia kwa mawe (au vinavyochukua nafasi yake), afanye hivyo kwa idadi isiyo ya jozi. Na anapoamka mmoja wenu kutoka usingizini, na aoshe mkono wake kabla ya kuuingiza katika chombo chake cha udhu, kwani mmoja wenu hajui mkono wake ulikuwa wapi usiku.”Na katika riwaya nyingine ya Sahih Muslim imekuja:“Na ayatoe maji hayo kwa kutumia matundu yake ya pua.”Na katika lafudhi nyingine imekuja:“Mwenye kufanya udhu, basi na ayatoe maji kutoka puani.”', '<blockquote><p>“Anapofanya udhu mmoja wenu, na aweke maji ndani ya pua yake, kisha ayatoe. Na anayejisafisha baada ya kujisaidia kwa mawe (au vinavyochukua nafasi yake), afanye hivyo kwa idadi isiyo ya jozi. Na anapoamka mmoja wenu kutoka usingizini, na aoshe mkono wake kabla ya kuuingiza katika chombo chake cha udhu, kwani mmoja wenu hajui mkono wake ulikuwa wapi usiku.”</p></blockquote><p>Na katika riwaya nyingine ya Sahih Muslim imekuja:</p><blockquote><p>“Na ayatoe maji hayo kwa kutumia matundu yake ya pua.”</p></blockquote><p>Na katika lafudhi nyingine imekuja:</p><blockquote><p>“Mwenye kufanya udhu, basi na ayatoe maji kutoka puani.”</p></blockquote>', 'arabic_first', '', '', '', 1, 'sections', '2026-05-29 20:46:11'),
(240, 23, 1, 'hadith ya tatu', 'عَنْ عَبْدِ اللَّهِ بْنِ عَمْرِو بْنِ الْعَاصِ، وَأَبِي هُرَيْرَةَ، وَعَائِشَةَ رضي الله عنهم، قَالُوا: قَالَ رَسُولُ اللَّهِ ﷺ: «وَيْلٌ لِلْأَعْقَابِ مِنَ النَّارِ».', '<p>عَنْ عَبْدِ اللَّهِ بْنِ عَمْرِو بْنِ الْعَاصِ، وَأَبِي هُرَيْرَةَ، وَعَائِشَةَ رضي الله عنهم، قَالُوا: قَالَ رَسُولُ اللَّهِ ﷺ: «وَيْلٌ لِلْأَعْقَابِ مِنَ النَّارِ».</p>', '🌙 𝑻𝒂𝒇𝒔𝒊𝒓𝒊 𝒚𝒂𝒌𝒆:Kutoka kwa Abdullah bin Umar, Abu Hurayrah, na Aisha رضي الله عنهم wamesema: Mtume wa Allah ﷺ amesema:“Ole wao wenye kuacha visigino vyao bila kufikiwa na maji ya udhu, kwani wataadhibiwa Motoni.” 🤍', '<p>🌙 𝑻𝒂𝒇𝒔𝒊𝒓𝒊 𝒚𝒂𝒌𝒆:<br>Kutoka kwa Abdullah bin Umar, Abu Hurayrah, na Aisha رضي الله عنهم wamesema: Mtume wa Allah ﷺ amesema:</p><p>“Ole wao wenye kuacha visigino vyao bila kufikiwa na maji ya udhu, kwani wataadhibiwa Motoni.” 🤍</p>', 'arabic_first', '', '', '', 1, 'sections', '2026-05-29 21:26:08'),
(329, 21, 1, 'الْحَدِيثُ الْأَوَّلُ', '', NULL, '', NULL, 'swahili_first', '', '', '', 1, 'combined', '2026-05-30 15:09:31'),
(330, 21, 2, 'ما يُؤخذ من الحديث', '', NULL, '', NULL, 'swahili_first', '', '', '', 2, 'sections', '2026-05-30 15:09:31'),
(331, 26, 1, 'hadithi ya sita', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-05-30 23:07:29'),
(334, 29, 1, 'hadithi ya saba', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-05-31 00:50:04'),
(335, 30, 1, 'hadithi ya nane', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-05-31 00:53:40'),
(336, 31, 1, 'mlango wa twahara', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-05-31 01:01:08'),
(337, 32, 1, 'Utangulizi wa kitabu', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-05-31 01:07:30'),
(338, 33, 1, '١- سَيِّدُنَا مُحَمَّدٌ', 'الدَّوْرُ الأَوَّلُمِنْ حَيَاةِ رَسُولِ اللهِ، صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ١- سَيِّدُنَا مُحَمَّدٌ١- هُوَ رَسُولُ اللهِ إِلَى النَّاسِ كَافَّةً، وَآخِرُ الأَنْبِيَاءِ، وَإِمَامُ الرُّسُلِ.٢- جَاءَ بِالدِّينِ الإِسْلَامِيِّ، الَّذِي لَا يَقْبَلُ اللهُ يَوْمَ الْقِيَامَةِ دِينًا غَيْرَهُ.٣- وَهُوَ مِنْ نَسْلِ سَادَاتِ قُرَيْشٍ، أَشْرَفِ قَبِيلَةٍ فِي مَكَّةَ الْمُكَرَّمَةِ.٤- وَيَتَّصِلُ نَسَبُهُ بِإِسْمَاعِيلَ بْنِ إِبْرَاهِيمَ، عَلَيْهِمَا السَّلَامُ.', '<h3>الدَّوْرُ الأَوَّلُ</h3><h4>مِنْ حَيَاةِ رَسُولِ اللهِ، صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ</h4><h3>١- سَيِّدُنَا مُحَمَّدٌ</h3><h4>١- هُوَ رَسُولُ اللهِ إِلَى النَّاسِ كَافَّةً، وَآخِرُ الأَنْبِيَاءِ، وَإِمَامُ الرُّسُلِ.</h4><h4>٢- جَاءَ بِالدِّينِ الإِسْلَامِيِّ، الَّذِي لَا يَقْبَلُ اللهُ يَوْمَ الْقِيَامَةِ دِينًا غَيْرَهُ.</h4><h4>٣- وَهُوَ مِنْ نَسْلِ سَادَاتِ قُرَيْشٍ، أَشْرَفِ قَبِيلَةٍ فِي مَكَّةَ الْمُكَرَّمَةِ.</h4><h4>٤- وَيَتَّصِلُ نَسَبُهُ بِإِسْمَاعِيلَ بْنِ إِبْرَاهِيمَ، عَلَيْهِمَا السَّلَامُ.</h4>', 'Somo la KwanzaKutoka katika Maisha ya Mtume wa Mwenyezi Mungu ﷺBwana Wetu Muhammad ﷺYeye ni Mtume wa Mwenyezi Mungu kwa watu wote, ni wa mwisho wa Manabii na kiongozi wa Mitume.Alikuja na dini ya Uislamu, ambayo Mwenyezi Mungu hatakubali dini nyingine isipokuwa hiyo Siku ya Kiyama.Anatokana na kizazi cha viongozi wa kabila la Maquraishi (Quraysh), ambalo lilikuwa kabila lenye heshima zaidi katika Makkah Tukufu.Nasaba yake inaungana na Nabii Ismail mwana wa Nabii Ibrahim, amani iwe juu yao.', '<h3>Somo la Kwanza</h3><h4>Kutoka katika Maisha ya Mtume wa Mwenyezi Mungu ﷺ</h4><h3>Bwana Wetu Muhammad ﷺ</h3><ol><li>Yeye ni Mtume wa Mwenyezi Mungu kwa watu wote, ni wa mwisho wa Manabii na kiongozi wa Mitume.</li><li>Alikuja na dini ya Uislamu, ambayo Mwenyezi Mungu hatakubali dini nyingine isipokuwa hiyo Siku ya Kiyama.</li><li>Anatokana na kizazi cha viongozi wa kabila la Maquraishi (Quraysh), ambalo lilikuwa kabila lenye heshima zaidi katika Makkah Tukufu.</li><li>Nasaba yake inaungana na Nabii Ismail mwana wa Nabii Ibrahim, amani iwe juu yao.</li></ol>', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:11:42'),
(339, 33, 2, '١- سَيِّدُنَا مُحَمَّدٌ', 'أَسْئِلَةٌمَنْ سَيِّدُنَا مُحَمَّدٌ؟بِمَاذَا جَاءَ؟مِنْ أَيِّ قَبِيلَةٍ أَبَوَاهُ؟بِمَنْ يَتَّصِلُ نَسَبُهُ؟الخُلَاصَةُسَيِّدُنَا مُحَمَّدٌ: هُوَ رَسُولُ اللهِ إِلَى النَّاسِ أَجْمَعِينَ، جَاءَ بِالدِّينِ الإِسْلَامِيِّ، وَهُوَ عَرَبِيٌّ، قُرَشِيٌّ، عَدْنَانِيٌّ', '<h3>أَسْئِلَةٌ</h3><h4>مَنْ سَيِّدُنَا مُحَمَّدٌ؟<br>بِمَاذَا جَاءَ؟<br>مِنْ أَيِّ قَبِيلَةٍ أَبَوَاهُ؟<br>بِمَنْ يَتَّصِلُ نَسَبُهُ؟</h4><h3>الخُلَاصَةُ</h3><h4>سَيِّدُنَا مُحَمَّدٌ: هُوَ رَسُولُ اللهِ إِلَى النَّاسِ أَجْمَعِينَ، جَاءَ بِالدِّينِ الإِسْلَامِيِّ، وَهُوَ عَرَبِيٌّ، قُرَشِيٌّ، عَدْنَانِيٌّ</h4>', 'MaswaliBwana wetu Muhammad ﷺ ni nani?Alikuja na nini?Wazazi wake walitoka katika kabila gani?Nasaba yake inaungana na nani?Muhtasari(ufupisho)Bwana wetu Muhammad ﷺ ni Mtume wa Mwenyezi Mungu kwa watu wote. Alikuja na dini ya Uislamu. Yeye ni Mwarabu, Mquraishi (wa kabila la Quraysh), na ni wa ukoo wa Adnani.', '<h4>Maswali</h4><ol><li>Bwana wetu Muhammad ﷺ ni nani?</li><li>Alikuja na nini?</li><li>Wazazi wake walitoka katika kabila gani?</li><li>Nasaba yake inaungana na nani?</li></ol><h3>Muhtasari(ufupisho)</h3><p>Bwana wetu Muhammad ﷺ ni Mtume wa Mwenyezi Mungu kwa watu wote. Alikuja na dini ya Uislamu. Yeye ni Mwarabu, Mquraishi (wa kabila la Quraysh), na ni wa ukoo wa Adnani.</p>', 'arabic_first', '', '', 'https://www.youtube.com/embed/uwRxJDbYzgQ', 2, 'sections', '2026-06-20 01:20:44'),
(340, 34, 1, 'Nasabu yake Mtume na Kufa Kwa baba yake', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:37:42'),
(341, 35, 1, 'Kuzaliwa kwake na kunyonyweshwa', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:44:14'),
(342, 36, 1, 'Kufa kwa mama yake na kulelewa kwake', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:46:32'),
(343, 37, 1, 'Kulelewa kwake na kufa Kwa Babu yake', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:49:05'),
(344, 38, 1, 'Kuchunga Kwake Mbuzi na Safari yake ya Kwanza sham', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:51:33'),
(345, 39, 1, 'Safari yake ya pili Kuelekea Sham.', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:53:51'),
(346, 40, 1, 'Kumuoa Kwake Bi. Khadijah', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:57:01'),
(348, 42, 1, 'Hukmu Yake Mtume Baina Ya Makuraish katika kuliweka Jiwe jeusi', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 01:59:14'),
(349, 43, 1, 'Kuibuka kwake Mtume.', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 02:02:59'),
(350, 44, 1, 'Maisha yake Mtume Kabla ya Utume', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 02:07:38'),
(351, 45, 1, 'Ufupisho wa Mzunguko Wa Kwanza', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 02:09:49'),
(352, 45, 2, 'Muendelezo ...', '', '', '', '', 'arabic_first', '', '', 'https://www.youtube.com/embed/6UREnidxDNg', 2, 'sections', '2026-06-20 02:13:15'),
(353, 46, 1, 'Kuanza Kuteremka Wahyi', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 07:50:37'),
(354, 47, 1, 'Hali ya Waarabu kabla ya Uislam', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 07:53:33'),
(355, 48, 1, 'Mlinganio Wa siri', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 07:56:18'),
(356, 49, 1, 'Waislam Wa Mwanzo', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 07:58:22'),
(357, 50, 1, 'Mlinganio Wa dhahiri', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:00:15'),
(358, 51, 1, 'Kuwakusanya kwake mtume Jamaa Zake', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:04:38'),
(359, 52, 1, 'Ugumu(Uzito) wa Makuraishi Juu ya Mtume s.a.w', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:06:19'),
(360, 53, 1, 'Kuja Kwa makuraishi kwa Abuu TWALIB mara ya pili', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:08:32'),
(361, 54, 1, 'Kuja Kwa Makuraishi kwa ami yake mtume (abuu Twalib) mara ya tatu', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:10:01'),
(362, 55, 1, 'Maudhi ya Makuraishi Kwa mtume s.a.w', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:11:35'),
(363, 56, 1, 'Maudhi ya Makuraishi Kwa Maswahaba wa Mtume', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:13:13'),
(364, 57, 1, 'Matakwa Ya Makuraishi Kwa Mtume S.a.w', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:14:48'),
(365, 58, 1, 'Kuhamia Habeshi Mara Ya Kwanza', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:16:32'),
(366, 59, 1, 'Kusilimu Kwa Hamza na Omari r.a', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:18:18'),
(367, 60, 1, 'Kizuizi Cha Mtume s.a.w NA Watu Wa Nyumbani Kwake', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:20:03'),
(368, 61, 1, 'Kuhamia Habeshi Mara ya pili', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:21:41'),
(369, 62, 1, 'Kusilimu Mfalme Wa Habeshi', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:23:12'),
(370, 63, 1, 'Kutoka Mtume s.a.a Katika Kizuizi', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:24:31'),
(371, 64, 1, 'Ujumbe Wa Najiran', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:25:52'),
(372, 65, 1, 'Kufa Kwa Bi. khadija , kumuoa Bi. Aisha na Bi. sauda r.a', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:28:01'),
(373, 66, 1, 'Kufa Kwa Ami Yake Mtume s.a.w', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:29:39'),
(374, 67, 1, 'Maudhi Ya Makuraishi Kwa Mtume s.a.w', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:31:26'),
(375, 68, 1, 'Kuhama Mtume s.a.w Kuelekea Katika Mji wa Taif', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:32:55'),
(376, 69, 1, 'Safari yake Ya Israa Na Miraaj', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:34:23'),
(377, 70, 1, 'Kulingania Makabila Katika dini', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:35:55'),
(378, 71, 1, 'Kuanza Uislamu Wa Answaar', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:37:10'),
(379, 72, 1, 'Ahadi Ya Mafungamano Ya Mwanzo', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:38:18'),
(380, 73, 1, 'Ahadi Ya Mafungamano Ya pili', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:39:46'),
(381, 74, 1, 'Kuhama Waislamu Kuelekea Mji Wa Madina', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:41:03'),
(382, 75, 1, 'Makubaliano Ya Makuraishi Juu Ya Kumuua Mtume s.aw', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:42:26'),
(383, 76, 1, 'Kuhama Kwa Mtume s.a.w', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:43:47'),
(384, 77, 1, 'Makuraishi Kumtafuta Mtume s.a.w', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:45:36'),
(385, 78, 1, 'Kutoka Kwa Mtume s.a.w ndani ya pango', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:47:49'),
(386, 79, 1, 'Kufika Mtume Katika Eneo la Kuba , Msikiti wa Mwanzo Alioujenga.', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:50:37'),
(387, 80, 1, 'Kuwasili Madina Ijumaa Ya Mwanzo Katika Uislam, Khutba Ya Mwanzo katika Uislam', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:54:13'),
(388, 81, 1, 'Kuingia Mtume s.a.w Katika MJi wa Madina', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:55:56'),
(389, 82, 1, 'Kuhama Kwa jamaa Zake Mtume, Kuzuiwa Wanyonge , Asili Ya Kunuti, humma ya madina', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 08:58:47'),
(390, 83, 1, 'Ufupisho - Mzunguko wa pili', '', '', '', '', 'arabic_first', '', '', '', 1, 'sections', '2026-06-20 09:01:16'),
(391, 83, 2, 'Ufupisho-Mzunguko Wa pili', '', '', '', '', 'arabic_first', '', '', 'https://www.youtube.com/embed/6d8k5MQer84', 2, 'sections', '2026-06-20 09:04:58'),
(392, 83, 3, 'Ufupisho- Mzunguko Wa pili', '', '', '', '', 'arabic_first', '', '', 'https://www.youtube.com/embed/zVlWyzd5v10', 3, 'sections', '2026-06-20 09:04:58'),
(394, 85, 1, 'Harakaat Na Mitihani', '', NULL, '', NULL, 'swahili_first', '', '', '', 1, 'combined', '2026-08-24 11:22:39'),
(395, 85, 2, 'Harakaat Na Mitihani(inaendelea....)', '', NULL, '', NULL, 'swahili_first', '', '', '', 2, 'combined', '2026-08-24 11:22:39'),
(396, 85, 3, 'Harakaat Na Mitihani(inaendelea...)', '', NULL, '', NULL, 'swahili_first', '', '', '', 3, 'sections', '2026-08-24 11:22:39'),
(397, 86, 1, 'Asili na Elimu yake', '', NULL, '', NULL, 'swahili_first', '', '', '', 1, 'sections', '2026-08-24 12:50:46'),
(398, 87, 1, 'Athari Ya D’awah kazi  Yake', '', NULL, '', NULL, 'swahili_first', '', '', '', 1, 'sections', '2026-08-24 16:27:38'),
(399, 88, 1, 'Wanafunzi, Familia na Kifo chake', '', NULL, '', NULL, 'swahili_first', '', '', '', 1, 'sections', '2026-08-24 16:39:01'),
(400, 21, 3, 'فائدة', '', NULL, '', NULL, 'swahili_first', '', '', '', 3, 'combined', '2026-08-25 08:27:58');

-- --------------------------------------------------------

--
-- Table structure for table `help_articles`
--

CREATE TABLE `help_articles` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `slug` varchar(255) NOT NULL,
  `view_count` int(11) NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `help_categories`
--

CREATE TABLE `help_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `icon` varchar(50) DEFAULT '❓',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leaderboard_stats`
--

CREATE TABLE `leaderboard_stats` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `mode` enum('lesson','competition') NOT NULL DEFAULT 'lesson',
  `total_points` decimal(10,2) DEFAULT 0.00,
  `quizzes_taken` int(11) DEFAULT 0,
  `quizzes_completed` int(11) DEFAULT 0,
  `total_correct_answers` int(11) DEFAULT 0,
  `total_wrong_answers` int(11) DEFAULT 0,
  `average_score` decimal(8,2) DEFAULT 0.00,
  `best_score` decimal(8,2) DEFAULT 0.00,
  `total_time_seconds` int(11) DEFAULT 0,
  `rank_position` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leaderboard_stats`
--

INSERT INTO `leaderboard_stats` (`id`, `user_id`, `mode`, `total_points`, `quizzes_taken`, `quizzes_completed`, `total_correct_answers`, `total_wrong_answers`, `average_score`, `best_score`, `total_time_seconds`, `rank_position`, `updated_at`) VALUES
(0, 12, '', '0.00', 1, 1, 0, 10, '0.00', '0.00', 64, NULL, '2026-08-01 21:35:32');

-- --------------------------------------------------------

--
-- Table structure for table `media`
--

CREATE TABLE `media` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `media_type` enum('image','video','audio','document') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `thumbnail_path` varchar(500) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL COMMENT 'in bytes',
  `duration` int(11) DEFAULT NULL COMMENT 'in seconds, for video/audio',
  `subject_id` int(11) DEFAULT NULL COMMENT 'optional link to subjects',
  `uploaded_by` int(11) NOT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'published',
  `view_count` int(11) NOT NULL DEFAULT 0,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `media`
--

INSERT INTO `media` (`id`, `title`, `slug`, `description`, `media_type`, `file_path`, `thumbnail_path`, `file_size`, `duration`, `subject_id`, `uploaded_by`, `status`, `view_count`, `download_count`, `created_at`, `updated_at`) VALUES
(0, 'Yaseer al dosar', 'yaseer-al-dosar', '', 'video', 'uploads/media/media_6a5698b60fb397.50756959.mp4', NULL, 4026257, NULL, NULL, 2, 'published', 0, 0, '2026-07-14 20:14:46', '2026-07-14 20:14:46'),
(1, 'Nasaha 1', 'nasaha-1', '', 'image', 'uploads/media/media_69e5a7faf38926.91247909.jpg', NULL, 100582, NULL, NULL, 1, 'published', 4, 0, '2026-04-20 04:13:47', '2026-05-13 14:50:47'),
(2, 'Nasaha 2', 'nasaha-2', '', 'image', 'uploads/media/media_69e5a81bd1bc19.79832514.jpg', NULL, 44607, NULL, NULL, 1, 'published', 3, 0, '2026-04-20 04:14:19', '2026-05-13 14:50:42'),
(3, 'Nasaha 3', 'nasaha-3', '', 'image', 'uploads/media/media_69e5a85722a791.20893014.jpg', NULL, 16653, NULL, NULL, 1, 'published', 2, 0, '2026-04-20 04:15:19', '2026-05-11 09:07:43'),
(4, 'Nasaha 4', 'nasaha-4', '', 'image', 'uploads/media/media_69e5a87091ad47.16373344.jpeg', NULL, 36457, NULL, NULL, 1, 'published', 9, 1, '2026-04-20 04:15:44', '2026-05-13 14:50:15'),
(5, 'Nasaha 5', 'nasaha-5', '', 'image', 'uploads/media/media_69e5a88cedcb60.99203579.jpeg', NULL, 112398, NULL, NULL, 1, 'published', 8, 0, '2026-04-20 04:16:13', '2026-05-13 14:50:09'),
(6, 'Nasaha 6', 'nasaha-6', '', 'image', 'uploads/media/media_69e5a8a190b569.72998892.jpeg', NULL, 102991, NULL, NULL, 1, 'published', 18, 0, '2026-04-20 04:16:33', '2026-05-13 14:49:59'),
(7, 'Nasaha 7', 'nasaha-7', '', 'image', 'uploads/media/media_69e5a8b489a3e9.05381293.jpg', NULL, 112692, NULL, NULL, 1, 'published', 23, 3, '2026-04-20 04:16:52', '2026-06-14 12:11:04');

-- --------------------------------------------------------

--
-- Table structure for table `media_categories`
--

CREATE TABLE `media_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `media_category_relations`
--

CREATE TABLE `media_category_relations` (
  `media_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `media_downloads`
--

CREATE TABLE `media_downloads` (
  `id` bigint(20) NOT NULL,
  `media_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `downloaded_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `media_downloads`
--

INSERT INTO `media_downloads` (`id`, `media_id`, `user_id`, `downloaded_at`) VALUES
(0, 7, 12, '2026-06-14 12:11:04');

-- --------------------------------------------------------

--
-- Table structure for table `media_tags`
--

CREATE TABLE `media_tags` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `slug` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `media_tag_relations`
--

CREATE TABLE `media_tag_relations` (
  `media_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `media_views`
--

CREATE TABLE `media_views` (
  `id` bigint(20) NOT NULL,
  `media_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `media_views`
--

INSERT INTO `media_views` (`id`, `media_id`, `user_id`, `ip_address`, `viewed_at`) VALUES
(0, 7, 12, '197.186.69.37', '2026-05-19 09:51:45'),
(1, 7, 6, '::1', '2026-05-06 04:04:03'),
(2, 3, 15, '197.186.72.76', '2026-05-11 09:07:43'),
(3, 1, 15, '197.186.72.76', '2026-05-11 09:08:20'),
(4, 2, 15, '197.186.72.76', '2026-05-11 09:08:45'),
(5, 7, 15, '197.186.72.76', '2026-05-11 09:08:51'),
(6, 6, 14, '197.186.1.52', '2026-05-13 14:49:59'),
(7, 5, 14, '197.186.1.52', '2026-05-13 14:50:09'),
(8, 4, 14, '197.186.1.52', '2026-05-13 14:50:15'),
(9, 2, 14, '197.186.1.52', '2026-05-13 14:50:42'),
(10, 1, 14, '197.186.1.52', '2026-05-13 14:50:47');

-- --------------------------------------------------------

--
-- Table structure for table `monthly_exams`
--

CREATE TABLE `monthly_exams` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `month_year` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `time_limit_minutes` int(11) DEFAULT NULL,
  `passing_score` int(11) NOT NULL DEFAULT 70,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 1,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `monthly_exams`
--

INSERT INTO `monthly_exams` (`id`, `title`, `slug`, `description`, `subject_id`, `month_year`, `start_date`, `end_date`, `time_limit_minutes`, `passing_score`, `total_points`, `max_attempts`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Jaribio la Muhtasari: Qur\'an, Mwanamke na Mirathi', 'muhtasari-quran-mwanamke-mirathi', 'Jaribio hili linajumuisha maswali kuhusu Qur\'an, nafasi ya mwanamke katika Qur\'an, na sheria za mirathi (urithi) katika Uislamu.', 1, '2026-04', '2026-04-21 05:04:00', '2026-08-03 20:15:00', 1, 70, 15, 1, 'published', 2, '2026-04-21 02:04:43', '2026-08-01 21:38:11');

-- --------------------------------------------------------

--
-- Table structure for table `monthly_exam_attempts`
--

CREATE TABLE `monthly_exam_attempts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `attempt_number` int(11) NOT NULL DEFAULT 1,
  `total_questions` int(11) NOT NULL DEFAULT 0,
  `correct_answers` int(11) NOT NULL DEFAULT 0,
  `score` int(11) NOT NULL DEFAULT 0,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `passed` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('started','completed','abandoned','terminated') NOT NULL DEFAULT 'started',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `answers_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `monthly_exam_attempts`
--

INSERT INTO `monthly_exam_attempts` (`id`, `user_id`, `exam_id`, `attempt_number`, `total_questions`, `correct_answers`, `score`, `total_points`, `percentage`, `passed`, `status`, `started_at`, `completed_at`, `answers_json`, `created_at`, `updated_at`) VALUES
(0, 12, 1, 1, 10, 0, 0, 15, '0.00', 0, 'completed', '2026-08-01 14:34:28', '2026-08-01 14:35:32', '[{\"question_id\":1,\"question_text\":\"Neno “Qur’an” lina maana gani?\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":2,\"correct_answer_text\":\"Kisomo kinachosomwa kwa sauti\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":2,\"question_text\":\"Ni aya ipi inaonyesha kuwa mwanamke ameumbwa kutoka nafsi moja?\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":6,\"correct_answer_text\":\"4:1\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":3,\"question_text\":\"Ni ipi kati ya hizi ni sifa ya Qur’an?\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":10,\"correct_answer_text\":\"Mwongozo\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":4,\"question_text\":\"Ni ipi hutekelezwa kabla ya kugawa urithi?\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":14,\"correct_answer_text\":\"Kulipa madeni\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":5,\"question_text\":\"Ni nani hupata mabaki ya urithi baada ya Fardh?\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":19,\"correct_answer_text\":\"‘Asabah\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":6,\"question_text\":\"Qur’an ina sura 114\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":21,\"correct_answer_text\":\"True\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":7,\"question_text\":\"Mwanamke hana usawa wa kiroho na mwanaume katika Qur’an\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":24,\"correct_answer_text\":\"False\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":8,\"question_text\":\"Mirathi hugawiwa kabla ya kulipa madeni\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":26,\"correct_answer_text\":\"False\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":9,\"question_text\":\"Mtu aliyemuua marehemu haruhusiwi kurithi\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":27,\"correct_answer_text\":\"True\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":10,\"question_text\":\"Qur’an hutumika kama mwongozo wa maisha\",\"user_answer\":null,\"user_answer_text\":null,\"correct_answer\":29,\"correct_answer_text\":\"True\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"\",\"feedback\":\"Incorrect.\"},{\"question_id\":11,\"question_text\":\"Eleza kwa ufupi maana ya Qur’an.\",\"user_answer\":\"\",\"user_answer_text\":\"\",\"correct_answer\":null,\"correct_answer_text\":\"Qur’an ni neno la Mwenyezi Mungu lilioteremshwa kwa Mtume Muhammad (s.a.w.) kwa lugha ya Kiarabu, kusomwa kwa ibada, na lililohifadhiwa kwa uthabiti.\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"Jibu linapaswa kutaja kwamba Qur’an ni Neno la Mungu, lilioteremshwa, ni muujiza, na ni mwongozo.\",\"feedback\":\"No answer provided.\"},{\"question_id\":12,\"question_text\":\"Eleza kwa kifupi jinsi Qur’an inavyomuelezea mwanamke kwa upande wa asili yake.\",\"user_answer\":\"\",\"user_answer_text\":\"\",\"correct_answer\":null,\"correct_answer_text\":\"Qur’an inaeleza kuwa mwanamke ameumbwa kutoka nafsi moja na mwanaume (Surah An-Nisa 4:1). Hivyo asili yake ni moja na mwanaume, si kiumbe wa daraja tofauti.\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"Jibu lazima litaje aya 4:1 na wazo la “nafsi moja”.\",\"feedback\":\"No answer provided.\"},{\"question_id\":13,\"question_text\":\"Eleza kwa ufupi umuhimu wa mirathi katika Uislamu.\",\"user_answer\":\"\",\"user_answer_text\":\"\",\"correct_answer\":null,\"correct_answer_text\":\"Mirathi ni mfumo wa haki unaohakikisha mali ya marehemu inasambazwa kwa warithi wake kwa mujibu wa Qur’an na Sunnah. Inazuia migogoro, inahifadhi haki za wanawake na watoto, na inaleta utulivu wa kijamii.\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"Jibu linapaswa kuelezea kwamba mirathi inalinda haki, inazuia ugomvi, na inafuata amri za Mwenyezi Mungu.\",\"feedback\":\"No answer provided.\"},{\"question_id\":14,\"question_text\":\"Eleza hatua zinazofanyika kabla ya kugawa urithi.\",\"user_answer\":\"\",\"user_answer_text\":\"\",\"correct_answer\":null,\"correct_answer_text\":\"Hatua ni: 1) kulipa gharama za mazishi, 2) kulipa madeni ya marehemu, 3) kutekeleza wasia (si zaidi ya 1/3 ya mali), kisha 4) kugawa urithi kwa warithi.\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"Jibu lazima liwe na madeni, wasia, kisha urithi.\",\"feedback\":\"No answer provided.\"},{\"question_id\":15,\"question_text\":\"Eleza tofauti kati ya Fardh na ‘Asabah katika mirathi.\",\"user_answer\":\"\",\"user_answer_text\":\"\",\"correct_answer\":null,\"correct_answer_text\":\"Fardh ni sehemu maalum iliyoamriwa na Mwenyezi Mungu kwa warithi fulani (mfano: mke, mume, wazazi). ‘Asabah ni jamaa wa upande wa baba wanaopokea mabaki ya urithi baada ya warithi wa fardh kuchukua sehemu zao.\",\"is_correct\":false,\"points_earned\":0,\"explanation\":\"Tofauti: Fardh ni sehemu maalum, ‘Asabah ni mabaki.\",\"feedback\":\"No answer provided.\"}]', '2026-08-01 21:34:28', '2026-08-01 21:35:32');

-- --------------------------------------------------------

--
-- Table structure for table `monthly_exam_options`
--

CREATE TABLE `monthly_exam_options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_text` varchar(500) NOT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `monthly_exam_questions`
--

CREATE TABLE `monthly_exam_questions` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('multiple_choice','true_false','explanation') NOT NULL,
  `points` int(11) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `image_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `monthly_exam_questions`
--

INSERT INTO `monthly_exam_questions` (`id`, `exam_id`, `question_text`, `question_type`, `points`, `sort_order`, `image_path`, `created_at`) VALUES
(1, 1, 'Neno “Qur’an” lina maana gani?', 'multiple_choice', 1, 1, NULL, '2026-04-21 02:04:43'),
(2, 1, 'Ni aya ipi inaonyesha kuwa mwanamke ameumbwa kutoka nafsi moja?', 'multiple_choice', 1, 2, NULL, '2026-04-21 02:04:43'),
(3, 1, 'Ni ipi kati ya hizi ni sifa ya Qur’an?', 'multiple_choice', 1, 3, NULL, '2026-04-21 02:04:43'),
(4, 1, 'Ni ipi hutekelezwa kabla ya kugawa urithi?', 'multiple_choice', 1, 4, NULL, '2026-04-21 02:04:43'),
(5, 1, 'Ni nani hupata mabaki ya urithi baada ya Fardh?', 'multiple_choice', 1, 5, NULL, '2026-04-21 02:04:43'),
(6, 1, 'Qur’an ina sura 114', 'true_false', 1, 6, NULL, '2026-04-21 02:04:43'),
(7, 1, 'Mwanamke hana usawa wa kiroho na mwanaume katika Qur’an', 'true_false', 1, 7, NULL, '2026-04-21 02:04:43'),
(8, 1, 'Mirathi hugawiwa kabla ya kulipa madeni', 'true_false', 1, 8, NULL, '2026-04-21 02:04:43'),
(9, 1, 'Mtu aliyemuua marehemu haruhusiwi kurithi', 'true_false', 1, 9, NULL, '2026-04-21 02:04:43'),
(10, 1, 'Qur’an hutumika kama mwongozo wa maisha', 'true_false', 1, 10, NULL, '2026-04-21 02:04:43'),
(11, 1, 'Eleza kwa ufupi maana ya Qur’an.', 'explanation', 1, 11, NULL, '2026-04-21 02:04:43'),
(12, 1, 'Eleza kwa kifupi jinsi Qur’an inavyomuelezea mwanamke kwa upande wa asili yake.', 'explanation', 1, 12, NULL, '2026-04-21 02:04:44'),
(13, 1, 'Eleza kwa ufupi umuhimu wa mirathi katika Uislamu.', 'explanation', 1, 13, NULL, '2026-04-21 02:04:44'),
(14, 1, 'Eleza hatua zinazofanyika kabla ya kugawa urithi.', 'explanation', 1, 14, NULL, '2026-04-21 02:04:44'),
(15, 1, 'Eleza tofauti kati ya Fardh na ‘Asabah katika mirathi.', 'explanation', 1, 15, NULL, '2026-04-21 02:04:44');

-- --------------------------------------------------------

--
-- Table structure for table `monthly_exam_question_explanations`
--

CREATE TABLE `monthly_exam_question_explanations` (
  `question_id` int(11) NOT NULL,
  `expected_answer` text NOT NULL,
  `explanation` text DEFAULT NULL,
  `keywords_json` text DEFAULT NULL COMMENT 'JSON array of keywords with optional weights'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `monthly_exam_question_explanations`
--

INSERT INTO `monthly_exam_question_explanations` (`question_id`, `expected_answer`, `explanation`, `keywords_json`) VALUES
(11, 'Qur’an ni neno la Mwenyezi Mungu lilioteremshwa kwa Mtume Muhammad (s.a.w.) kwa lugha ya Kiarabu, kusomwa kwa ibada, na lililohifadhiwa kwa uthabiti.', 'Jibu linapaswa kutaja kwamba Qur’an ni Neno la Mungu, lilioteremshwa, ni muujiza, na ni mwongozo.', '[{\"word\":\"neno\",\"weight\":2},{\"word\":\"mungu\",\"weight\":2},{\"word\":\"teremshwa\",\"weight\":2},{\"word\":\"muhammad\",\"weight\":2},{\"word\":\"mwongozo\",\"weight\":1}]'),
(12, 'Qur’an inaeleza kuwa mwanamke ameumbwa kutoka nafsi moja na mwanaume (Surah An-Nisa 4:1). Hivyo asili yake ni moja na mwanaume, si kiumbe wa daraja tofauti.', 'Jibu lazima litaje aya 4:1 na wazo la “nafsi moja”.', '[{\"word\":\"nafsi\",\"weight\":2},{\"word\":\"moja\",\"weight\":2},{\"word\":\"4:1\",\"weight\":2},{\"word\":\"asili\",\"weight\":1}]'),
(13, 'Mirathi ni mfumo wa haki unaohakikisha mali ya marehemu inasambazwa kwa warithi wake kwa mujibu wa Qur’an na Sunnah. Inazuia migogoro, inahifadhi haki za wanawake na watoto, na inaleta utulivu wa kijamii.', 'Jibu linapaswa kuelezea kwamba mirathi inalinda haki, inazuia ugomvi, na inafuata amri za Mwenyezi Mungu.', '[{\"word\":\"haki\",\"weight\":2},{\"word\":\"mali\",\"weight\":2},{\"word\":\"warithi\",\"weight\":2},{\"word\":\"quran\",\"weight\":2},{\"word\":\"amri\",\"weight\":1}]'),
(14, 'Hatua ni: 1) kulipa gharama za mazishi, 2) kulipa madeni ya marehemu, 3) kutekeleza wasia (si zaidi ya 1/3 ya mali), kisha 4) kugawa urithi kwa warithi.', 'Jibu lazima liwe na madeni, wasia, kisha urithi.', '[{\"word\":\"mazishi\",\"weight\":1},{\"word\":\"madeni\",\"weight\":3},{\"word\":\"wasia\",\"weight\":2},{\"word\":\"1/3\",\"weight\":2}]'),
(15, 'Fardh ni sehemu maalum iliyoamriwa na Mwenyezi Mungu kwa warithi fulani (mfano: mke, mume, wazazi). ‘Asabah ni jamaa wa upande wa baba wanaopokea mabaki ya urithi baada ya warithi wa fardh kuchukua sehemu zao.', 'Tofauti: Fardh ni sehemu maalum, ‘Asabah ni mabaki.', '[{\"word\":\"fardh\",\"weight\":2},{\"word\":\"asabah\",\"weight\":2},{\"word\":\"sehemu\",\"weight\":2},{\"word\":\"maalum\",\"weight\":2},{\"word\":\"mabaki\",\"weight\":2}]');

-- --------------------------------------------------------

--
-- Table structure for table `monthly_exam_question_options`
--

CREATE TABLE `monthly_exam_question_options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_text` varchar(500) NOT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `monthly_exam_question_options`
--

INSERT INTO `monthly_exam_question_options` (`id`, `question_id`, `option_text`, `image_path`, `is_correct`, `sort_order`) VALUES
(1, 1, 'Kitabu cha sheria', NULL, 0, 1),
(2, 1, 'Kisomo kinachosomwa kwa sauti', NULL, 1, 2),
(3, 1, 'Hadithi za kale', NULL, 0, 3),
(4, 1, 'Sheria za jamii', NULL, 0, 4),
(5, 2, '33:35', NULL, 0, 1),
(6, 2, '4:1', NULL, 1, 2),
(7, 2, '17:70', NULL, 0, 3),
(8, 2, '24:31', NULL, 0, 4),
(9, 3, 'Riwaya', NULL, 0, 1),
(10, 3, 'Mwongozo', NULL, 1, 2),
(11, 3, 'Historia tu', NULL, 0, 3),
(12, 3, 'Hadithi za kubuni', NULL, 0, 4),
(13, 4, 'Kugawa mali', NULL, 0, 1),
(14, 4, 'Kulipa madeni', NULL, 1, 2),
(15, 4, 'Kuwapa ndugu', NULL, 0, 3),
(16, 4, 'Kugawa kwa watoto', NULL, 0, 4),
(17, 5, 'Mke', NULL, 0, 1),
(18, 5, 'Rafiki', NULL, 0, 2),
(19, 5, '‘Asabah', NULL, 1, 3),
(20, 5, 'Jirani', NULL, 0, 4),
(21, 6, 'True', NULL, 1, 1),
(22, 6, 'False', NULL, 0, 2),
(23, 7, 'True', NULL, 0, 1),
(24, 7, 'False', NULL, 1, 2),
(25, 8, 'True', NULL, 0, 1),
(26, 8, 'False', NULL, 1, 2),
(27, 9, 'True', NULL, 1, 1),
(28, 9, 'False', NULL, 0, 2),
(29, 10, 'True', NULL, 1, 1),
(30, 10, 'False', NULL, 0, 2);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('leaderboard','content','quiz','exam','lesson','subject','multimedia','announcement','system','category','certificate') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `link`, `is_read`, `created_at`) VALUES
(0, 12, 'leaderboard', '🎉 Milestone Achieved!', 'You have completed 5 lessons. Great progress!', '/subjects.php', 1, '2026-05-22 21:39:46'),
(1, 1, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-04-23 11:59:37'),
(2, 2, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-04-23 11:59:37'),
(3, 3, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-04-23 11:59:37'),
(4, 5, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-04-23 11:59:37'),
(5, 6, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-04-23 11:59:37'),
(6, 7, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-04-23 11:59:37'),
(8, 1, 'lesson', 'New Lesson: content testing', 'A new lesson has been published. Check it out!', '/content.php?id=16', 0, '2026-04-23 12:43:59'),
(9, 2, 'lesson', 'New Lesson: content testing', 'A new lesson has been published. Check it out!', '/content.php?id=16', 0, '2026-04-23 12:43:59'),
(10, 3, 'lesson', 'New Lesson: content testing', 'A new lesson has been published. Check it out!', '/content.php?id=16', 0, '2026-04-23 12:43:59'),
(11, 5, 'lesson', 'New Lesson: content testing', 'A new lesson has been published. Check it out!', '/content.php?id=16', 0, '2026-04-23 12:43:59'),
(12, 6, 'lesson', 'New Lesson: content testing', 'A new lesson has been published. Check it out!', '/content.php?id=16', 1, '2026-04-23 12:43:59'),
(13, 7, 'lesson', 'New Lesson: content testing', 'A new lesson has been published. Check it out!', '/content.php?id=16', 0, '2026-04-23 12:43:59'),
(15, 6, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #1 on the Lesson leaderboard. Keep it up!', '/leaderboard.php?mode=lesson', 0, '2026-04-23 18:30:02'),
(16, 2, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #2 on the Lesson leaderboard. Keep it up!', '/leaderboard.php?mode=lesson', 0, '2026-04-24 02:37:45'),
(17, 2, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #1 on the Lesson leaderboard. Keep it up!', '/leaderboard.php?mode=lesson', 0, '2026-04-24 06:24:42'),
(18, 6, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #2 on the Lesson leaderboard. Keep it up!', '/leaderboard.php?mode=lesson', 0, '2026-04-24 06:32:20'),
(19, 6, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #1 on the Lesson leaderboard. Keep it up!', '/leaderboard.php?mode=lesson', 1, '2026-04-24 06:34:10'),
(20, 7, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #3 on the Lesson leaderboard. Keep it up!', '/leaderboard.php?mode=lesson', 0, '2026-04-27 04:52:43'),
(21, 7, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #2 on the Lesson leaderboard. Keep it up!', '/leaderboard.php?mode=lesson', 0, '2026-04-27 04:55:07'),
(22, 7, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #1 on the Lesson leaderboard. Keep it up!', '/leaderboard.php?mode=lesson', 1, '2026-04-27 04:57:07'),
(23, 2, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #1 on the Competition leaderboard. Keep it up!', '/leaderboard.php?mode=competition', 0, '2026-04-27 16:29:36'),
(24, 6, 'certificate', '🎓 Subject Certificate Earned', 'Congratulations! You have earned your certificate for QUR-AN. You can now view, verify, and print it.', 'certificate_view.php?code=CERT-B159400C30208EC8', 1, '2026-04-27 18:21:57'),
(25, 10, 'leaderboard', '🏆 Leaderboard Update!', 'You are now ranked #2 on the Competition leaderboard. Keep it up!', '/leaderboard.php?mode=competition', 0, '2026-05-06 04:18:34'),
(26, 7, 'certificate', '🎓 Subject Certificate Earned', 'Congratulations! You have earned your certificate for QUR-AN. You can now view, verify, and print it.', 'certificate_view.php?code=CERT-5192B9361A9BCDFF', 0, '2026-05-06 04:44:26'),
(27, 1, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(28, 2, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(29, 3, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(30, 5, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(31, 6, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(32, 7, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(33, 8, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(34, 9, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(35, 10, 'lesson', 'New Lesson: Ufunguzi wa Qur-an', 'A new lesson has been published. Check it out!', '/content.php?id=1', 0, '2026-05-07 03:29:15'),
(42, 1, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(43, 2, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(44, 3, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(45, 5, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(46, 6, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(47, 7, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(48, 8, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(49, 9, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(50, 10, 'lesson', 'New Lesson: media integration', 'A new lesson has been published. Check it out!', '/content.php?id=17', 0, '2026-05-08 14:52:30'),
(57, 1, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(58, 2, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(59, 3, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(60, 5, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(61, 6, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(62, 7, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(63, 8, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(64, 9, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(65, 10, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=18', 0, '2026-05-08 15:05:54'),
(72, 1, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 0, '2026-05-08 19:42:45'),
(73, 2, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 1, '2026-05-08 19:42:45'),
(74, 3, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 0, '2026-05-08 19:42:45'),
(75, 5, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 0, '2026-05-08 19:42:45'),
(76, 6, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 0, '2026-05-08 19:42:45'),
(77, 7, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 0, '2026-05-08 19:42:45'),
(78, 8, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 0, '2026-05-08 19:42:45'),
(79, 9, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 0, '2026-05-08 19:42:45'),
(80, 10, 'lesson', 'New Lesson: media', 'A new lesson has been published. Check it out!', '/content.php?id=19', 0, '2026-05-08 19:42:45'),
(81, 1, 'subject', 'Subject Activated: QUR-AN', 'The subject \'QUR-AN\' is now active.', '/subjects.php', 0, '2026-05-10 09:14:53'),
(82, 2, 'subject', 'Subject Activated: QUR-AN', 'The subject \'QUR-AN\' is now active.', '/subjects.php', 0, '2026-05-10 09:14:53'),
(83, 3, 'subject', 'Subject Activated: QUR-AN', 'The subject \'QUR-AN\' is now active.', '/subjects.php', 0, '2026-05-10 09:14:53'),
(84, 6, 'leaderboard', '🎉 Milestone Achieved!', 'You have completed 5 lessons. Great progress!', '/subjects.php', 0, '2026-05-10 13:26:08'),
(85, 1, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30'),
(86, 2, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30'),
(87, 3, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30'),
(88, 5, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30'),
(89, 6, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30'),
(90, 7, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30'),
(91, 8, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30'),
(92, 9, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30'),
(93, 10, 'lesson', 'New Lesson: external media', 'A new lesson has been published. Check it out!', '/content.php?id=20', 0, '2026-05-10 19:19:30');

-- --------------------------------------------------------

--
-- Table structure for table `part_sections`
--

CREATE TABLE `part_sections` (
  `id` int(11) NOT NULL,
  `part_id` int(11) NOT NULL,
  `section_number` int(11) NOT NULL DEFAULT 0,
  `content` longtext DEFAULT NULL,
  `content_html` longtext DEFAULT NULL,
  `language` varchar(20) NOT NULL DEFAULT 'swahili',
  `direction` varchar(10) NOT NULL DEFAULT 'ltr',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `text_color` varchar(20) DEFAULT NULL,
  `background_color` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `part_sections`
--

INSERT INTO `part_sections` (`id`, `part_id`, `section_number`, `content`, `content_html`, `language`, `direction`, `sort_order`, `text_color`, `background_color`, `created_at`, `updated_at`) VALUES
(16, 393, 1, 'Asili yakeJina lake ni Abul-Husayn Muhammad bin ‘Abdil-Wahhaab bin Sulaymaan bin ‘Aliy Musharrif Al-Wuhaybiy. Kabila lake ni Bani Tamiym. Amezaliwa mwaka 1115 H (1704 M) katika mji ya ‘Uyaynah kijiji cha Yamaamah ndani ya Najd, kaskazini Magharibi ya mji wa Riyadhw, Saudi Arabia. Ametoka katika familia ya Wanavyuoni kwani baba yake ‘Abdul-Wahhaab alikuwa ni Mwanachuoni maarufu wa Najd na Qaadhwi wa mji huo.', '<ol><li><strong>Asili yake</strong><br>Jina lake ni Abul-Husayn Muhammad bin ‘Abdil-Wahhaab bin Sulaymaan bin ‘Aliy Musharrif Al-Wuhaybiy. <br>Kabila lake ni Bani Tamiym. Amezaliwa mwaka 1115 H (1704 M) katika mji ya ‘Uyaynah <strong>kijiji cha Yamaamah ndani ya Najd, kaskazini Magharibi ya mji wa Riyadhw, </strong>Saudi Arabia. <br>Ametoka katika familia ya Wanavyuoni kwani baba yake ‘Abdul-Wahhaab alikuwa ni Mwanachuoni maarufu wa Najd na Qaadhwi wa mji huo.</li></ol>', 'swahili', 'ltr', 1, '#1f2937', '#ffffff', '2026-08-24 12:31:45', '2026-08-24 12:31:45'),
(17, 393, 2, '2.Elimu YakeAlijifunza kusoma Qur-aan alipokuwa na umri mdogo na akaihifadhi akiwa chini ya umri wa miaka kumi. Na Allaah (سبحانه وتعالى) Alimjaalia kuwa na uhodari na akili nzuri, wepesi wa kufahamu na kumaizi jambo au somo. Alipendezewa mno kusoma kazi za Shaykhul-Islaam Ibn Taymiyyah na mwanafunzi wake Ibnul-Qayyim (رحمهما الله).Alifanya bidii kwenye masomo yake na kuendelea kujifunza chini ya usimamizi wa baba yake. Akajifunza elimu ya Dini chini ya Wanavyuoni walioko mjini mwake wakiwemo baba yake na ‘ammiy yake. Alisafiri kutoka kwenda Madiynah kusoma chini ya Wanavyuoni wakubwa wa huko wakiwemo; Shaykh ‘Abdullaah bin Ibraahiym bin Ash-Shamariyyi, na Mwanachuoni maarufu wa India Shaykh Muhammad Hayaat Al-Sindi. Alikwenda Makkah pia na alitekeleza Hajj. Hatimaye akaelekea Baswrah (Kaskazini Iraq) kutafuta elimu zaidi akasoma chini ya Wanavyuoni wa huko akawa maarufu kwa mijadala baina yake na Wanavyuoni.', '<p><strong>2.Elimu Yake</strong><br>Alijifunza kusoma Qur-aan alipokuwa na umri mdogo na akaihifadhi akiwa chini ya umri wa miaka kumi. <br>Na Allaah (سبحانه وتعالى) Alimjaalia kuwa na uhodari na akili nzuri, wepesi wa kufahamu na kumaizi jambo au somo. <br>Alipendezewa mno kusoma kazi za Shaykhul-Islaam Ibn Taymiyyah na mwanafunzi wake Ibnul-Qayyim (رحمهما الله).</p><p>Alifanya bidii kwenye masomo yake na kuendelea kujifunza chini ya usimamizi wa baba yake.<br> Akajifunza elimu ya Dini chini ya Wanavyuoni walioko mjini mwake wakiwemo baba yake na ‘ammiy yake. <br>Alisafiri kutoka kwenda Madiynah kusoma chini ya Wanavyuoni wakubwa wa huko wakiwemo; Shaykh ‘Abdullaah bin Ibraahiym bin Ash-Shamariyyi, na Mwanachuoni maarufu wa India Shaykh Muhammad Hayaat Al-Sindi. <br>Alikwenda Makkah pia na alitekeleza Hajj. Hatimaye akaelekea Baswrah (Kaskazini Iraq) kutafuta elimu zaidi akasoma chini ya Wanavyuoni wa huko akawa maarufu kwa mijadala baina yake na Wanavyuoni.</p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-24 12:31:45', '2026-08-24 12:31:45'),
(39, 394, 1, 'Watu wa Najd walikuwa katika shirki na bid’ah (uzushi). Walikuwa wakiabudu miungu mingi na kuabudu makaburi, miti, mawe, mapango, majini na mashaytwaan, waja wema waliojulikana kama ni mawalii. Uchawi na unajimu (utabiri wa nyota) ulisambaa pia. Hakuna aliyekataza ‘iIbaadah potofu hizo kwani watu walikuwa katika kuchuma manufaa na starehe za dunia zaidi na wakakhofia kuyapoteza hayo. Hivyo Shaykh akaona umuhimu mkubwa uliohitajika kuwarudisha watu katika manhaj ya Qur-aan na Sunnah. Akaanza kuwalingania watu katika Tawhiyd - kumpwekesha Allaah (سبحانه وتعالى) bila ya kumshirikisha na lolote katika ‘ibaadah. Akaazimia kujitumikisha peke yake kwa uvumilivu katika konde. Akajua hakuna lolote kitachofaulu kufanyika ila jihaad katika njia ya Allaah.', '<p>Watu wa Najd walikuwa katika shirki na bid’ah (uzushi). <br>Walikuwa wakiabudu miungu mingi na kuabudu makaburi, miti, mawe, mapango, majini na mashaytwaan, waja wema waliojulikana kama ni mawalii. <br><br>Uchawi na unajimu (utabiri wa nyota) ulisambaa pia. Hakuna aliyekataza ‘iIbaadah potofu hizo kwani watu walikuwa katika kuchuma manufaa na starehe za dunia zaidi na wakakhofia kuyapoteza hayo. Hivyo Shaykh akaona umuhimu mkubwa uliohitajika kuwarudisha watu katika manhaj ya Qur-aan na Sunnah. <br><br>Akaanza kuwalingania watu katika Tawhiyd - kumpwekesha Allaah (<strong>سبحانه وتعالى</strong>) bila ya kumshirikisha na lolote katika ‘ibaadah. Akaazimia kujitumikisha peke yake kwa uvumilivu katika konde. Akajua hakuna lolote kitachofaulu kufanyika ila jihaad katika njia ya Allaah.</p>', 'swahili', 'ltr', 1, '#1f2937', '#ffffff', '2026-08-24 12:35:34', '2026-08-24 12:35:34'),
(40, 394, 2, 'Shaykh alikumbana na mitihani, misukosuko, na vitisho, lakini alikuwa ameshategemea hali hiyo kumfikia na alikuwa tayari kukabaliana nayo kwani alitambua kuwa hilo ni jambo lisiloepukika kwa kila mlinganiaji kwani ndio hali waliyokutana nayo Manabii wote, na Salafus-Swaalih (waja wema waliotangulia).', '<p>Shaykh alikumbana na mitihani, misukosuko, na vitisho, lakini alikuwa ameshategemea hali hiyo kumfikia na alikuwa tayari kukabaliana nayo kwani alitambua kuwa hilo ni jambo lisiloepukika kwa kila mlinganiaji kwani ndio hali waliyokutana nayo Manabii wote, na Salafus-Swaalih (waja wema waliotangulia).</p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-24 12:35:34', '2026-08-24 12:35:34'),
(41, 395, 1, 'Miongoni mwa mitihani aliyokumbana nayo ni kutokana na Wanavyuoni wadhaifu wasiokuwa na hoja ambao walimpinga na kumfanya akabiliane na misukosuko, vitisho na kukasirikiwa. Pia alikabiliana na misukosuko na mateso chini ya mikono ya madhalimu wa Huraymilaa. Na alipowashawishi watawala wahukumu madhalimu kwa Shariy’ah ya Kiislamu, ilisababisha baadhi ya watu kuyaweka maisha yake hatiani lakini Allaah (سبحانه وتعالى) Alimnusuru.', '<p><strong>Miongoni mwa mitihani aliyokumbana nayo ni kutokana na Wanavyuoni </strong>wadhaifu wasiokuwa na hoja ambao walimpinga na kumfanya akabiliane na misukosuko, vitisho na kukasirikiwa. <br><br>Pia alikabiliana na misukosuko na mateso chini ya mikono ya madhalimu wa Huraymilaa. Na alipowashawishi watawala wahukumu madhalimu kwa Shariy’ah ya Kiislamu, ilisababisha baadhi ya watu kuyaweka maisha yake hatiani lakini Allaah (<strong>سبحانه وتعالى</strong>) Alimnusuru.</p>', 'swahili', 'ltr', 1, '#1f2937', '#ffffff', '2026-08-24 12:35:34', '2026-08-24 12:35:34'),
(42, 395, 2, 'Aliamua kurudi kwao ‘Uyaynah, ambako kipindi hicho kulikuwa chini ya utawala wa mtoto wa Mfalme ‘Uthmaan bin Muhammad bin Mu’ammar, ambaye alimpokea Shaykh kwa ukarimu na kumuahidi kumuunga mkono na kumsaidia kuwaita watu katika Uislam. Akaendelea kufundisha na kutoa da’wah hatimaye akashawishika kidhati na kwa vitendo kuondoa dini ya miungu mingi pale alipoona baadhi ya watu ni wagumu kurudi katika Uislamu. Aliweza kumshawishi gavana wa mji huo kulivunja zege lililojengewa juu ya kaburi la Zayd bin Al-Khattwaab ambaye alikuwa ni kaka wa ‘Umar bin Al-Khattwaab (رضي الله عنهما). Akavunjavunja mangome mengineyo, mapango, miti n.k. Akaamrisha Shariy’ah ya Kiislamu itekelezwe kama kumpiga mawe hadi afe mwanamke aliyekiri uzinifu.', '<p>Aliamua kurudi kwao ‘Uyaynah, ambako kipindi hicho kulikuwa chini ya utawala wa mtoto wa Mfalme ‘Uthmaan bin Muhammad bin Mu’ammar, ambaye alimpokea Shaykh kwa ukarimu na kumuahidi kumuunga mkono na kumsaidia kuwaita watu katika Uislam. <br><br>Akaendelea kufundisha na kutoa da’wah hatimaye akashawishika kidhati na kwa vitendo kuondoa dini ya miungu mingi pale alipoona baadhi ya watu ni wagumu kurudi katika Uislamu. Aliweza kumshawishi gavana wa mji huo kulivunja zege lililojengewa juu ya kaburi la Zayd bin Al-Khattwaab ambaye alikuwa ni kaka wa ‘Umar bin Al-Khattwaab <strong>(رضي الله عنهما)</strong>. <br><br>Akavunjavunja mangome mengineyo, mapango, miti n.k. Akaamrisha Shariy’ah ya Kiislamu itekelezwe kama kumpiga mawe hadi afe mwanamke aliyekiri uzinifu.</p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-24 12:35:34', '2026-08-24 12:35:34'),
(43, 395, 3, 'Shaykh aliendelea na harakati zake za kauli na ‘amali ambazo zilimfanya azidi kuwa maarufu. Hatimaye akawa Jaji (Qaadhwiy) wa mji wa ‘Uyaynah. Lakini hakuweza kuendelea hapo kwani Mfalme alishawishiwa na viongozi wa miji ya jirani amuue Shaykh kwa vile hawakupendezewa na da’wah yake. Ikabidi afukuzwe katika mji huo.', '<p>Shaykh aliendelea na harakati zake za kauli na ‘amali ambazo zilimfanya azidi kuwa maarufu. <br><br>Hatimaye akawa Jaji (Qaadhwiy) wa mji wa ‘Uyaynah. <br><br>Lakini hakuweza kuendelea hapo kwani Mfalme alishawishiwa na viongozi wa miji ya jirani amuue Shaykh kwa vile hawakupendezewa na da’wah yake. Ikabidi afukuzwe katika mji huo.</p>', 'swahili', 'ltr', 3, '#1f2937', '#ffffff', '2026-08-24 12:35:34', '2026-08-24 12:35:34'),
(44, 396, 1, 'Akakaribishwa mji wa Ad-Dir’iyyah ambao ulikuwa ni jirani yake. Huko akakaribishwa ingawa kwanza alitiliwa shaka. Alifikia kwa mja mwema aliyempokea ila alimkhofia kutokukubaliwa na mtoto wa Mfalme Muhammad bin Sa’uwd.  Habari zikamfikia kwanza mke wa Ibn Sa’uwd ambaye alikuwa mkarimu na mwenye taqwa. Akamfahamisha mumewe kwa kumwambia, “Hii tawfiyq kubwa umeletewa kutoka kwa Allaah (سبحانه وتعالى) mtu ambaye anawaita watu katika Uislamu, anawaita katika Qur-aan na Sunnah za Rasuli wa Allaah (صلى الله عليه وآله وسلم). Tawfiyq iliyoje? Mkimbilie haraka na umuunge mkono wala usimpinge au kumkataza kwa hayo”. Muhammad Ibn Sa’uwd akakubali ushauri wa mkewe na akaenda kwa Shaykh na akafanya mkataba naye kwamba asiondoke nchini hapo. Ndipo Shaykh akaweka makazi yake hapo na kuendelea na da’wah. Akaendelea na harakati za da’awah hapo Dar’iyyah. Akaheshimika na kupendwa mno na akaungwa mkono na watu. Da’wah yake iliwaathiri na kuwapendezea mno watu. Ikaenea katika nchi za Kiislamu na nyinginezo. Wakimiminika watu kufika hapo Dar’iyyah kujifunza kwake.', '<p>Akakaribishwa mji wa Ad-Dir’iyyah ambao ulikuwa ni jirani yake. <br>Huko akakaribishwa ingawa kwanza alitiliwa shaka. Alifikia kwa mja mwema aliyempokea ila alimkhofia kutokukubaliwa na mtoto wa Mfalme Muhammad bin Sa’uwd.  <br><br>Habari zikamfikia kwanza mke wa Ibn Sa’uwd ambaye alikuwa mkarimu na mwenye taqwa. Akamfahamisha mumewe kwa kumwambia, “Hii tawfiyq kubwa umeletewa kutoka kwa Allaah (<strong>سبحانه وتعالى</strong>) mtu ambaye anawaita watu katika Uislamu, anawaita katika Qur-aan na Sunnah za Rasuli wa Allaah (<strong>صلى الله عليه وآله وسلم</strong>).</p><p><br> Tawfiyq iliyoje? Mkimbilie haraka na umuunge mkono wala usimpinge au kumkataza kwa hayo”. Muhammad Ibn Sa’uwd akakubali ushauri wa mkewe na akaenda kwa Shaykh na akafanya mkataba naye kwamba asiondoke nchini hapo.<br><br> Ndipo Shaykh akaweka makazi yake hapo na kuendelea na da’wah. A<strong>kaendelea na harakati za da’awah hapo Dar’iyyah. Akaheshimika na kupendwa mno na akaungwa mkono na watu. Da’wah yake iliwaathiri na kuwapendezea mno watu. Ikaenea katika nchi za Kiislamu na nyinginezo. Wakimiminika watu kufika hapo Dar’iyyah kujifunza kwake.</strong></p>', 'swahili', 'ltr', 1, '#1f2937', '#ffffff', '2026-08-24 12:35:34', '2026-08-24 12:35:34'),
(45, 396, 2, 'Kama ijulikanavyo kila jema halikosi mitihani. Wakatokeza wapinzani na waliokuwa na chuki naye. Kulikuweko waliompinga da’wah yake ya kuondosha shirki na bid’ah na kuwaita watu katika Tawhiyd. Na wengineo waliompinga kabisa ni kwa sababu ya kuhofu kufukuzwa katika nafasi au vyeo vyao vya kazi. Kwa hiyo wengine walimpinga kwa ajili ya Dini, na wengine walimpinga kisiasa. Wapinzani wake walimzushia ya kumzushia walifika hadi kudai kwamba Shaykh alikuwa ni mfuasi wa Khawaarij. Na mara nyingine wakimlaumu bure bila ya dalili na nje ya upeo wa elimu zao. Hivyo yakaweko malumbano na majibishano yaliyoendelea katika mijadala kadhaa. Shaykh aliwaandikia kuwajibu madai yao, na wao walimrudishia majibu na yeye akawa akiwathibitishia ufahamu wao mbaya wa shariy’ah na Dini kwa ujumla kwa dalili. Ndipo wingi wa maswali na majibu yakawajumuisha watu na wakazidi kuongezeka. Baadhi ya mijadala hiyo iliandikwa na kuchapishwa katika vitabu.', '<p><strong>Kama ijulikanavyo kila jema halikosi mitihani. Wakatokeza wapinzani na waliokuwa na chuki naye. Kulikuweko waliompinga da’wah yake ya kuondosha shirki na bid’ah na kuwaita watu katika Tawhiyd. </strong><br><br><strong>Na wengineo </strong>waliompinga kabisa ni kwa sababu ya kuhofu kufukuzwa katika nafasi au vyeo vyao vya kazi. Kwa hiyo wengine walimpinga kwa ajili ya Dini, na wengine walimpinga kisiasa. Wapinzani wake walimzushia ya kumzushia walifika hadi kudai kwamba Shaykh alikuwa ni mfuasi wa Khawaarij. <br><br>Na mara nyingine wakimlaumu bure bila ya dalili na nje ya upeo wa elimu zao. Hivyo yakaweko malumbano na majibishano yaliyoendelea katika mijadala kadhaa. Shaykh aliwaandikia kuwajibu madai yao, na wao walimrudishia majibu na yeye akawa akiwathibitishia ufahamu wao mbaya wa shariy’ah na Dini kwa ujumla kwa dalili.<br><br> Ndipo wingi wa maswali na majibu yakawajumuisha watu na wakazidi kuongezeka. Baadhi ya mijadala hiyo iliandikwa na kuchapishwa katika vitabu.</p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-24 12:35:34', '2026-08-24 12:35:34'),
(52, 397, 1, 'Asili YakeJina lake ni Abul-Husayn Muhammad bin ‘Abdil-Wahhaab bin Sulaymaan bin ‘Aliy Musharrif Al-Wuhaybiy. Kabila lake ni Bani Tamiym. Amezaliwa mwaka 1115 H (1704 M) katika mji ya ‘Uyaynah kijiji cha Yamaamah ndani ya Najd, kaskazini Magharibi ya mji wa Riyadhw, Saudi Arabia. Ametoka katika familia ya Wanavyuoni kwani baba yake ‘Abdul-Wahhaab alikuwa ni Mwanachuoni maarufu wa Najd na Qaadhwi wa mji huo.', '<p><strong>  Asili Yake</strong><br>Jina lake ni Abul-Husayn Muhammad bin ‘Abdil-Wahhaab bin Sulaymaan bin ‘Aliy Musharrif Al-Wuhaybiy. <br><br>Kabila lake ni Bani Tamiym. Amezaliwa mwaka 1115 H (1704 M) katika mji ya ‘Uyaynah <strong>kijiji cha Yamaamah ndani ya Najd, kaskazini Magharibi ya mji wa Riyadhw, </strong>Saudi Arabia. <br><br>Ametoka katika familia ya Wanavyuoni kwani baba yake ‘Abdul-Wahhaab alikuwa ni Mwanachuoni maarufu wa Najd na Qaadhwi wa mji huo.</p>', 'swahili', 'ltr', 1, '#1f2937', '#ffffff', '2026-08-24 12:55:36', '2026-08-24 12:55:36'),
(53, 397, 2, 'Elimu YakeAlijifunza kusoma Qur-aan alipokuwa na umri mdogo na akaihifadhi akiwa chini ya umri wa miaka kumi. Na Allaah (سبحانه وتعالى) Alimjaalia kuwa na uhodari na akili nzuri, wepesi wa kufahamu na kumaizi jambo au somo. Alipendezewa mno kusoma kazi za Shaykhul-Islaam Ibn Taymiyyah na mwanafunzi wake Ibnul-Qayyim (رحمهما الله).Alifanya bidii kwenye masomo yake na kuendelea kujifunza chini ya usimamizi wa baba yake. Akajifunza elimu ya Dini chini ya Wanavyuoni walioko mjini mwake wakiwemo baba yake na ‘ammiy yake. Alisafiri kutoka kwenda Madiynah kusoma chini ya Wanavyuoni wakubwa wa huko wakiwemo; Shaykh ‘Abdullaah bin Ibraahiym bin Ash-Shamariyyi, na Mwanachuoni maarufu wa India Shaykh Muhammad Hayaat Al-Sindi. Alikwenda Makkah pia na alitekeleza Hajj. Hatimaye akaelekea Baswrah (Kaskazini Iraq) kutafuta elimu zaidi akasoma chini ya Wanavyuoni wa huko akawa maarufu kwa mijadala baina yake na Wanavyuoni.', '<p><strong>  Elimu Yake</strong><br>Alijifunza kusoma Qur-aan alipokuwa na umri mdogo na akaihifadhi akiwa chini ya umri wa miaka kumi. Na Allaah (سبحانه وتعالى) Alimjaalia kuwa na uhodari na akili nzuri, wepesi wa kufahamu na kumaizi jambo au somo. <br><br>Alipendezewa mno kusoma kazi za Shaykhul-Islaam Ibn Taymiyyah na mwanafunzi wake Ibnul-Qayyim (رحمهما الله).</p><p>Alifanya bidii kwenye masomo yake na kuendelea kujifunza chini ya usimamizi wa baba yake. <br><br>Akajifunza elimu ya Dini chini ya Wanavyuoni walioko mjini mwake wakiwemo baba yake na ‘ammiy yake. Alisafiri kutoka kwenda Madiynah kusoma chini ya Wanavyuoni wakubwa wa huko wakiwemo; Shaykh ‘Abdullaah bin Ibraahiym bin Ash-Shamariyyi, na Mwanachuoni maarufu wa India Shaykh Muhammad Hayaat Al-Sindi. <br><br>Alikwenda Makkah pia na alitekeleza Hajj. Hatimaye akaelekea Baswrah (Kaskazini Iraq) kutafuta elimu zaidi akasoma chini ya Wanavyuoni wa huko akawa maarufu kwa mijadala baina yake na Wanavyuoni.</p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-24 12:55:36', '2026-08-24 12:55:36'),
(54, 398, 1, 'Athari Ya Da’wah yake Da’wah yake ya takriban miaka 50 imedhihirika na kuleta athari kubwa kwanza kwa Waislamu wa zama hizo. Watu waliacha kuabudu makaburi, miti, mawe, majini, waliacha utabiri wa nyota na kila aina ya shirki. Imewatoa pia watu katika ujinga wa kufuata mila za mababu bila ya dalili.  Maamrisho ya mema na makatazo ya maovu yakalinganiwa katika Misikiti. Na hapo msimamo wa Qur-aan na Sunnah ukahuishwa na shirki na bid’ah zikatoweka. Amani na utulivu ukawafunika watu kila mahali. Na mpaka sasa da’wah yake imekuwa na umuhimu mkubwa katika jamii ya Kiislamu. Vijana khasa kutoka nchi za Magharibi na zaidi wale wanaosilimu wamekuwa na hamasa na shauku kubwa ya kujifunza Dini iliyo sahihi na si ile iliyochanganywa na shirki na bid’ah. Kwa hiyo, mafundisho ya msimamo wake wa Ahlus-Sunnah wal-Jama’ah yamekuwa kipaumbele katika lengo la kutafuta elimu kwa Waislamu watakao na wapendao haki.', '<p><strong>Athari Ya Da’wah yake</strong><br> Da’wah yake ya takriban miaka 50 imedhihirika na kuleta athari kubwa kwanza kwa Waislamu wa zama hizo. Watu waliacha kuabudu makaburi, miti, mawe, majini, waliacha utabiri wa nyota na kila aina ya shirki.<br><br> Imewatoa pia watu katika ujinga wa kufuata mila za mababu bila ya dalili.  Maamrisho ya mema na makatazo ya maovu yakalinganiwa katika Misikiti. Na hapo msimamo wa Qur-aan na Sunnah ukahuishwa na shirki na bid’ah zikatoweka.<br><br> Amani na utulivu ukawafunika watu kila mahali. Na mpaka sasa da’wah yake imekuwa na umuhimu mkubwa katika jamii ya Kiislamu. Vijana khasa kutoka nchi za Magharibi na zaidi wale wanaosilimu wamekuwa na hamasa na shauku kubwa ya kujifunza Dini iliyo sahihi na si ile iliyochanganywa na shirki na bid’ah. <br><br>Kwa hiyo, mafundisho ya msimamo wake wa Ahlus-Sunnah wal-Jama’ah yamekuwa kipaumbele katika lengo la kutafuta elimu kwa Waislamu watakao na wapendao haki.</p>', 'swahili', 'ltr', 1, '#1f2937', '#ffffff', '2026-08-24 16:27:38', '2026-08-24 16:27:38');
INSERT INTO `part_sections` (`id`, `part_id`, `section_number`, `content`, `content_html`, `language`, `direction`, `sort_order`, `text_color`, `background_color`, `created_at`, `updated_at`) VALUES
(55, 398, 2, 'Kazi zakeAmeandika vitabu vingi vya mafunzo mbali mbali lakini alitilia mkazo zaidi katika somo la Tawhiyd na ‘Aqiydah. Na alitumia hikmah ya kuandika vitabu vidogo vidogo lakini vilivyokusanya nukta muhimu kabisa. Vilikuwa ni mukhtasari wa maudhui aliyotaka kuidhihirisha. Na vitabu hivyo vimekuja kufafanuliwa kwa kushereheshwa na ‘Ulamaa kadhaa na vimekuwa vina umuhimu mno katika vyuo vya Kiislamu vinavyofuata mwendo wa Ahlus-Sunnah wal-Jama’ah. Vifuatavyo ni baadhi ya vitabu vyake maarufu. Vinne vya mwanzo ni ambavyo mashuhuri zaidi. Na vinginevyo vimekusanywa na kujumuishwa katika ‘Maj’muw’at Mu-allafaatil-Imaam Muhammad bin ‘Abdil-Wahhaab’.1.      Kitaabut-Tawhiyd - kitabu hiki kimekuwa mashuhuri mno na kimeenea sana na somo lake limekuwa ni lenye kutoa mafunzo muhimu kuhusu kumpwekesha Allaah na kuepukana na shirki. 2.      Al-Uswuul Ath-Thalaathah – kitabu kitoacho mafunzo kuhusu asili au misingi ya Uislamu. 3.      Al-Qawaaid Al-Arba’ - kinatoa mafunzo ya kanuni za ufahamu wa shirki kwa ujumla. 4.      Kashf Ash-Shubuhaat - kinakanusha hoja zinazojulikana na watetezi wa shirki na bid’ah. 5.      Mukhtaswar Al-Inswaaf was-Sharh Al-Kabiyr 6.      Mukhtaswar Zaad Al-Ma’aad 7.      Mukhtaswar As-Siyrah 8.      Mukhtaswar al-Fat-h 9.       Masaail Al-Jaahiliyyah 10.   Adaab Al-Mashiy ilaa as-Swalaah 11.  Nawaaqidhw Al-Islaam', '<p><strong>Kazi zake</strong><br>Ameandika vitabu vingi vya mafunzo mbali mbali lakini alitilia mkazo zaidi katika somo la Tawhiyd na ‘Aqiydah. <br><br>Na alitumia hikmah ya kuandika vitabu vidogo vidogo lakini vilivyokusanya nukta muhimu kabisa. Vilikuwa ni mukhtasari wa maudhui aliyotaka kuidhihirisha. <br>Na vitabu hivyo vimekuja kufafanuliwa kwa kushereheshwa na ‘Ulamaa kadhaa na vimekuwa vina umuhimu mno katika vyuo vya Kiislamu vinavyofuata mwendo wa Ahlus-Sunnah wal-Jama’ah.<br> </p><p>Vifuatavyo ni baadhi ya vitabu vyake maarufu. Vinne vya mwanzo ni ambavyo mashuhuri zaidi. Na vinginevyo vimekusanywa na kujumuishwa katika <i>‘Maj’muw’at Mu-allafaatil-Imaam Muhammad bin ‘Abdil-Wahhaab’.</i></p><p>1.      <i>Kitaabut-Tawhiyd</i> - kitabu hiki kimekuwa mashuhuri mno na kimeenea sana na somo lake limekuwa ni lenye kutoa mafunzo muhimu kuhusu kumpwekesha Allaah na kuepukana na shirki.<br> </p><p>2.      <i>Al-Uswuul Ath-Thalaathah</i> – kitabu kitoacho mafunzo kuhusu asili au misingi ya Uislamu.<br> </p><p>3.      <i>Al-Qawaaid Al-Arba’</i> - kinatoa mafunzo ya kanuni za ufahamu wa shirki kwa ujumla.<br> </p><p>4.      <i>Kashf Ash-Shubuhaat</i> - kinakanusha hoja zinazojulikana na watetezi wa shirki na bid’ah.<br> </p><p><i>5.      Mukhtaswar Al-Inswaaf was-Sharh Al-Kabiyr</i><br> </p><p><i>6.      Mukhtaswar Zaad Al-Ma’aad</i><br> </p><p><i>7.      Mukhtaswar As-Siyrah</i><br> </p><p><i>8.      Mukhtaswar al-Fat-h</i><br> </p><p><i>9.      </i> <i>Masaail Al-Jaahiliyyah</i><br> </p><p><i>10.  </i> <i>Adaab Al-Mashiy ilaa as-Swalaah</i><br> </p><p><i>11.  Nawaaqidhw Al-Islaam</i></p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-24 16:27:38', '2026-08-24 16:27:38'),
(56, 399, 1, 'Wanafunzi wakeAlikuwa na wanafunzi wengi mno. Miongoni mwao ni watoto na wajukuu wake ambao waliendeleza kazi yake ya da’wah na Jihaad katika njia ya Allaah, walifungua vyuo karibu na kwao wakifunza watu masomo ya Dini. Kati ya watoto wake ni Husayn, ‘Aliy, ‘Abdullaah na Ibraahiym. Na kati ya wajukuu wake ni ‘Aliy bin Husayn, na pia ‘Abdur-Rahmaan bin Hasan aliyeandika kitabu cha ‘Fat-h Al-Majiyd Sharh Kitaab At-Tawhiyd’. Wanafunzi wake wengineo ni ‘Abdul-‘Aziyz bin Muhammad bin Sa’uwd, Hamad bin Naaswir bin Mu’ammar na ‘Abdul-‘Aziyz bin ‘Abdillaah bin Husayn.', '<p><strong>Wanafunzi wake</strong><br>Alikuwa na wanafunzi wengi mno. Miongoni mwao ni watoto na wajukuu wake ambao waliendeleza kazi yake ya da’wah na Jihaad katika njia ya Allaah, walifungua vyuo karibu na kwao wakifunza watu masomo ya Dini. Kati ya watoto wake ni Husayn, ‘Aliy, ‘Abdullaah na Ibraahiym.<br><br> Na kati ya wajukuu wake ni ‘Aliy bin Husayn, na pia ‘Abdur-Rahmaan bin Hasan aliyeandika kitabu cha <i>‘Fat-h Al-Majiyd Sharh Kitaab At-Tawhiyd’</i>. Wanafunzi wake wengineo ni ‘Abdul-‘Aziyz bin Muhammad bin Sa’uwd, Hamad bin Naaswir bin Mu’ammar na ‘Abdul-‘Aziyz bin ‘Abdillaah bin Husayn<strong>.</strong></p>', 'swahili', 'ltr', 1, '#1f2937', '#ffffff', '2026-08-24 16:39:01', '2026-08-24 16:39:01'),
(57, 399, 2, 'Familia YakeAlikuwa na watoto sita; Husayn, ‘Abdullaah, Hasan, ‘Aliy, Ibraahiym, ‘Abdul-‘Aziyz. Shaykh anatokana na kizazi kinachojulikana kwa ‘Aal-Shaykh’ ambacho kimetoa Wanavyuoni kadhaa akiwemo Shaykh Muhammad bin Ibraahiym Aal-Shaykh aliyekuwa Mufti mkuu wa awali Saudi Arabia na Shaykh ‘Abdul-‘Aziyz Aal-Shaykh  ambaye ni Mufti mkuu wa sasa.', '<p><strong>Familia Yake</strong><br>Alikuwa na watoto sita; Husayn, ‘Abdullaah, Hasan, ‘Aliy, Ibraahiym, ‘Abdul-‘Aziyz. Shaykh anatokana na kizazi kinachojulikana kwa ‘Aal-Shaykh’ ambacho kimetoa Wanavyuoni kadhaa akiwemo Shaykh Muhammad bin Ibraahiym Aal-Shaykh aliyekuwa Mufti mkuu wa awali Saudi Arabia na Shaykh ‘Abdul-‘Aziyz Aal-Shaykh  ambaye ni Mufti mkuu wa sasa<strong>.</strong></p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-24 16:39:01', '2026-08-24 16:39:01'),
(58, 399, 3, 'Kifo ChakeAlifariki mwaka 1206 H (1792 M) akiwa na umri wa miaka 91. Rahmah za Allaah ziwe juu yake na Allaah Amruzuku Jannah ya Firdaws. Aamiyn.', '<p><strong>Kifo Chake</strong><br>Alifariki mwaka 1206 H (1792 M) akiwa na umri wa miaka 91. Rahmah za Allaah ziwe juu yake na Allaah Amruzuku Jannah ya Firdaws. Aamiyn.</p><p><br> </p>', 'swahili', 'ltr', 3, '#1f2937', '#ffffff', '2026-08-24 16:39:01', '2026-08-24 16:39:01'),
(155, 329, 1, '﴿١﴾ عَنْ أَمِيرِ الْمُؤْمِنِينَ أَبِي حَفْصٍ عُمَرَ بْنِ الْخَطَّابِ رَضِيَ اللهُ عَنْهُ قَالَ: سَمِعْتُ رَسُولَ اللهِ صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ يَقُولُ:«إِنَّمَا الْأَعْمَالُ بِالنِّيَّاتِ، وَإِنَّمَا لِكُلِّ امْرِئٍ مَا نَوَى، فَمَنْ كَانَتْ هِجْرَتُهُ إِلَى اللهِ وَرَسُولِهِ فَهِجْرَتُهُ إِلَى اللهِ وَرَسُولِهِ، وَمَنْ كَانَتْ هِجْرَتُهُ لِدُنْيَا يُصِيبُهَا، أَوِ امْرَأَةٍ يَنْكِحُهَا فَهِجْرَتُهُ إِلَى مَا هَاجَرَ إِلَيْهِ.»', '<p>﴿١﴾ عَنْ أَمِيرِ الْمُؤْمِنِينَ أَبِي حَفْصٍ عُمَرَ بْنِ الْخَطَّابِ رَضِيَ اللهُ عَنْهُ قَالَ: سَمِعْتُ رَسُولَ اللهِ صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ يَقُولُ:<strong>«إِنَّمَا الْأَعْمَالُ بِالنِّيَّاتِ، وَإِنَّمَا لِكُلِّ امْرِئٍ مَا نَوَى، فَمَنْ كَانَتْ هِجْرَتُهُ إِلَى اللهِ وَرَسُولِهِ فَهِجْرَتُهُ إِلَى اللهِ وَرَسُولِهِ، وَمَنْ كَانَتْ هِجْرَتُهُ لِدُنْيَا يُصِيبُهَا، أَوِ امْرَأَةٍ يَنْكِحُهَا فَهِجْرَتُهُ إِلَى مَا هَاجَرَ إِلَيْهِ.»</strong></p>', 'arabic', 'rtl', 1, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(156, 329, 2, 'Kutoka kwa Amiri wa Waumini, Abu Hafs Umar bin Al-Khattāb — Mwenyezi Mungu amemridhia — alisema:Nilimsikia Rasuli wa Mwenyezi Mungu — Mwenyezi Mungu amrehemu na amsalimie — akisema:«Hakika matendo yote yanategemea nia (makusudi ya moyo), na hakika kila mtu atapata (malipo au matokeo) ya aliyonuiya. Basi mwenye kuhamia (kwa Hijra) kwake ikiwa ni kwa ajili ya Mwenyezi Mungu na Mtume Wake, basi uhamiaji wake ni kwa ajili ya Mwenyezi Mungu na Mtume Wake (atapewa thawabu ya Hijra). Na mwenye kuhamia kwake ikiwa ni kwa ajili ya dunia atakayoipata, au kwa ajili ya mwanamke atakayemuoa, basi uhamiaji wake ni kwa kile alichohamia kwa ajili yake (hatapewa thawabu ya Hijra, bali atapata kile alichokusudia tu).»', '<p> Kutoka kwa <strong>Amiri wa Waumini, Abu Hafs Umar bin Al-Khattāb</strong> — Mwenyezi Mungu amemridhia — alisema:</p><blockquote><p>Nilimsikia <strong>Rasuli wa Mwenyezi Mungu</strong> — Mwenyezi Mungu amrehemu na amsalimie — akisema:<br>«Hakika matendo yote yanategemea nia (makusudi ya moyo), na hakika kila mtu atapata (malipo au matokeo) ya aliyonuiya. Basi mwenye kuhamia (kwa Hijra) kwake ikiwa ni kwa ajili ya Mwenyezi Mungu na Mtume Wake, basi uhamiaji wake ni kwa ajili ya Mwenyezi Mungu na Mtume Wake (atapewa thawabu ya Hijra). Na mwenye kuhamia kwake ikiwa ni kwa ajili ya dunia atakayoipata, au kwa ajili ya mwanamke atakayemuoa, basi uhamiaji wake ni kwa kile alichohamia kwa ajili yake (hatapewa thawabu ya Hijra, bali atapata kile alichokusudia tu).»</p></blockquote>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(157, 330, 1, '١ - أن مدار الأعمال على النيات، صحة، وفسادًا، وكمالًا، ونقصًا، وطاعة ومعصية، فمن قصد بعمله الرياء أثم، ومن قصد بالجهاد مثلًا إعلاء كلمة الله تعالى فقط كمل ثوابه. ومن قصد ذلك والغنيمة معه نقص ثوابه. ومن قصد الغنيمة وحدها لم يأثم ولكنه لا يعطى أجر المجاهد. فالحديث مسوق لبيان أن كل عمل طاعة كان في الصورة أو معصية يختلف باختلاف النيات.', '<p>١ - أن مدار الأعمال على النيات، صحة، وفسادًا، وكمالًا، ونقصًا، وطاعة ومعصية، فمن قصد بعمله الرياء أثم، ومن قصد بالجهاد مثلًا إعلاء كلمة الله تعالى فقط كمل ثوابه. ومن قصد ذلك والغنيمة معه نقص ثوابه. ومن قصد الغنيمة وحدها لم يأثم ولكنه لا يعطى أجر المجاهد. فالحديث مسوق لبيان أن كل عمل طاعة كان في الصورة أو معصية يختلف باختلاف النيات.</p><p> </p>', 'arabic', 'rtl', 1, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(158, 330, 2, 'Hakika msingi wa matendo unatokana na nia.Kwa upande wa kuwa matendo ni sahihi au yenye kuharibika, kamili au pungufu, utiifu au maasi.Basi mwenye kukusudia kwa tendo lake kujionyesha (riyā), atapata dhambi.Na mwenye kukusudia katika jihadi, kwa mfano, kuliinua neno la Mwenyezi Mungu Aliyetukuka pekee, ujira wake unakuwa kamili.Na mwenye kukusudia hilo pamoja na kutaka ngawira, ujira wake unapungua.Na mwenye kukusudia ngawira pekee, hatapata dhambi, lakini hatapewa ujira wa mpiganaji/jihadi.Basi hadithi hii imekuja kueleza kwamba kila tendo la utiifu, au tendo ambalo kwa sura yake linaweza kuwa la utiifu au maasi, hutofautiana kulingana na tofauti za nia.', '<p>Hakika msingi wa matendo unatokana na nia.<br>Kwa upande wa kuwa matendo ni <strong>sahihi au yenye kuharibika, kamili au pungufu, utiifu au maasi.</strong><br><br>Basi mwenye kukusudia kwa tendo lake <strong>kujionyesha (riyā)</strong>, atapata dhambi.<br><br>Na mwenye kukusudia katika jihadi, kwa mfano, <strong>kuliinua neno la Mwenyezi Mungu Aliyetukuka pekee</strong>, ujira wake unakuwa kamili.Na mwenye kukusudia hilo <strong>pamoja na kutaka ngawira</strong>, ujira wake unapungua.<br><br>Na mwenye kukusudia <strong>ngawira pekee</strong>, hatapata dhambi, lakini hatapewa ujira wa mpiganaji/jihadi.<br><br>Basi hadithi hii imekuja <strong>kueleza kwamba kila tendo la utiifu, au tendo ambalo kwa sura yake linaweza kuwa la utiifu au maasi, hutofautiana kulingana na tofauti za nia.</strong><br><br> </p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(159, 330, 3, '٢ - أن النية شرط أساسي في العمل، ولكن بلا غلوّ في استحضارها يفسد على المتعبد عبادته. فإن مجرد قصد العمل يكون نية له بدون تكلف استحضارها وتحقيقها.', '<p>٢ - أن النية شرط أساسي في العمل، ولكن بلا غلوّ في استحضارها يفسد على المتعبد عبادته. فإن مجرد قصد العمل يكون نية له بدون تكلف استحضارها وتحقيقها.</p><p> </p>', 'arabic', 'rtl', 3, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(160, 330, 4, 'Hakika nia ni sharti la msingi katika tendo. Lakini haifai kupindukia katika kuiweka nia hiyo akilini kiasi cha kumharibia mwenye kufanya ibada ibada yake. Kwa hakika, kukusudia tu kufanya tendo kunatosha kuwa nia yake, bila kujilazimisha kuiweka nia hiyo akilini na kuitekeleza.', '<p>Hakika nia ni sharti la msingi katika tendo. Lakini haifai kupindukia katika kuiweka nia hiyo akilini kiasi cha kumharibia mwenye kufanya ibada ibada yake. Kwa hakika, kukusudia tu kufanya tendo kunatosha kuwa nia yake, bila kujilazimisha kuiweka nia hiyo akilini na kuitekeleza.</p><p> </p>', 'swahili', 'ltr', 4, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(161, 330, 5, '٣ - أن النية محلها القلب، واللفظ بها بدعة.', '<p>٣ - أن النية محلها القلب، واللفظ بها بدعة.</p>', 'arabic', 'rtl', 5, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(162, 330, 6, 'Hakika mahali pa nia ni moyoni, na kuitamka nia kwa ulimi ni bid‘ah.', '<p>Hakika mahali pa nia ni <strong>moyoni</strong>, na kuitamka nia kwa ulimi ni <strong>bid‘ah</strong>.</p>', 'swahili', 'ltr', 6, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(163, 330, 7, '٤ - وجوب الحذر من الرياء والسمعة، والعمل لأجل الدنيا، ما دام أن شيئًا من ذلك يفسد العبادة.', '<p>٤ - وجوب الحذر من الرياء والسمعة، والعمل لأجل الدنيا، ما دام أن شيئًا من ذلك يفسد العبادة.</p>', 'arabic', 'rtl', 7, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(164, 330, 8, 'Ni wajibu kujihadhari na riyā, kutafuta kusikika/kusifiwa na watu, na kufanya matendo kwa ajili ya dunia, maadamu chochote katika mambo hayo kinaharibu ibada', '<p>Ni wajibu kujihadhari na <strong>riyā, kutafuta kusikika/kusifiwa na watu, na kufanya matendo kwa ajili ya dunia</strong>, maadamu chochote katika mambo hayo kinaharibu ibada</p>', 'swahili', 'ltr', 8, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(165, 330, 9, '٥ - وجوب الاعتناء بأعمال القلوب ومراقبتها.', '<p>٥ - وجوب الاعتناء بأعمال القلوب ومراقبتها.</p>', 'arabic', 'rtl', 9, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(166, 330, 10, 'Ni wajibu kuzingatia matendo ya moyo na kuyachunguza/kuyafuatilia.', '<p>Ni wajibu <strong>kuzingatia matendo ya moyo na kuyachunguza/kuyafuatilia</strong>.</p>', 'swahili', 'ltr', 10, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(167, 330, 11, '٦- إن الهجرة من بلاد الشرك إلى بلاد الإسلام، من أفضل العبادات إذا قصد بها وجه الله تعالى.', '<p>٦- إن الهجرة من بلاد الشرك إلى بلاد الإسلام، من أفضل العبادات إذا قصد بها وجه الله تعالى.</p>', 'arabic', 'rtl', 11, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(168, 330, 12, '“Kuhama kutoka nchi ya shirk kwenda nchi ya Uislamu ni miongoni mwa ibada bora zaidi, ikiwa lengo lake ni kutafuta radhi za Mwenyezi Mungu.”', '<p>“Kuhama kutoka nchi ya shirk kwenda nchi ya Uislamu ni miongoni mwa ibada bora zaidi, ikiwa lengo lake ni kutafuta radhi za Mwenyezi Mungu.”</p>', 'swahili', 'ltr', 12, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(169, 400, 1, 'فائدة: ذكر ابن رجب أن العمل لغير الله على أقسام: فتارة يكون رياءً محضًا لا يقصد به سوى مراءاة المخلوقين لتحصيل غرض دنيوي، وهذا لا يكاد يصدر عن مؤمن، ولا شك في أنه يحبط العمل وأن صاحبه يستحق المقت من الله والعقوبة. وتارة يكون العمل لله وشريكه في العمل الرياء، فإن شاركه من أصله فإن النصوص الصحيحة تدل على بطلانه، وإن كان أصل العمل لله ثم طرأ عليه نية الرياء ودفعه صاحبه، فإن ذلك لا يضره بغير خلاف، وقد اختلف العلماء في الاسترسال في الرياء الطارئ: هل يحبط العمل أو لا يضر فاعله ويجري على أصل نيته؟ أم يتصرف', '<p>فائدة: ذكر ابن رجب أن العمل لغير الله على أقسام: فتارة يكون رياءً محضًا لا يقصد به سوى مراءاة المخلوقين لتحصيل غرض دنيوي، وهذا لا يكاد يصدر عن مؤمن، ولا شك في أنه يحبط العمل وأن صاحبه يستحق المقت من الله والعقوبة. وتارة يكون العمل لله وشريكه في العمل الرياء، فإن شاركه من أصله فإن النصوص الصحيحة تدل على بطلانه، وإن كان أصل العمل لله ثم طرأ عليه نية الرياء ودفعه صاحبه، فإن ذلك لا يضره بغير خلاف، وقد اختلف العلماء في الاسترسال في الرياء الطارئ: هل يحبط العمل أو لا يضر فاعله ويجري على أصل نيته؟ أم يتصرف</p>', 'arabic', 'rtl', 1, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26'),
(170, 400, 2, 'Faida: Ibn Rajab ametaja kwamba kufanya amali kwa ajili ya asiyekuwa Allah kuna sehemu mbalimbali: wakati mwingine huwa ni riyaa tupu, ambapo mtu hakusudii chochote isipokuwa kujionyesha kwa viumbe ili apate manufaa ya kidunia, na hali hii karibu haiwezi kutoka kwa Muumini. Wala hakuna shaka kwamba riyaa hiyo hubatilisha amali, na mwenye kufanya hivyo anastahili kuchukiwa na Allah pamoja na kuadhibiwa. Na wakati mwingine mtu hufanya amali kwa ajili ya Allah, lakini akamshirikisha mwingine katika amali hiyo kwa riyaa. Ikiwa riyaa hiyo imeingia tangu mwanzo wa amali, basi dalili sahihi zinaonyesha kwamba amali hiyo hubatilika. Lakini ikiwa msingi wa amali ulikuwa kwa ajili ya Allah, kisha baadaye likamjia nia ya riyaa na mwenyewe akaiondoa na kuipinga, basi hilo halimdhuru bila khilafu. Na wanazuoni wametofautiana kuhusu kuendelea na riyaa inayotokea baadaye: je, hiyo hubatilisha amali, au haimdhuru mwenye kuifanya na amali yake itaendelea kwa kufuata nia yake ya mwanzo? Na Allah ndiye Ajuaye zaidi.', '<p><strong>Faida:</strong> Ibn Rajab ametaja kwamba kufanya amali kwa ajili ya asiyekuwa Allah kuna sehemu mbalimbali: <br>wakati mwingine huwa ni riyaa tupu, ambapo mtu hakusudii chochote isipokuwa kujionyesha kwa viumbe ili apate manufaa ya kidunia, na hali hii karibu haiwezi kutoka kwa Muumini. Wala hakuna shaka kwamba riyaa hiyo hubatilisha amali, na mwenye kufanya hivyo anastahili kuchukiwa na Allah pamoja na kuadhibiwa. <br><br>Na wakati mwingine mtu hufanya amali kwa ajili ya Allah, lakini akamshirikisha mwingine katika amali hiyo kwa riyaa. Ikiwa riyaa hiyo imeingia tangu mwanzo wa amali, basi dalili sahihi zinaonyesha kwamba amali hiyo hubatilika.<br><br> Lakini ikiwa msingi wa amali ulikuwa kwa ajili ya Allah, kisha baadaye likamjia nia ya riyaa na mwenyewe akaiondoa na kuipinga, basi hilo halimdhuru bila khilafu. <br><br>Na wanazuoni wametofautiana kuhusu kuendelea na riyaa inayotokea baadaye: je, hiyo hubatilisha amali, au haimdhuru mwenye kuifanya na amali yake itaendelea kwa kufuata nia yake ya mwanzo? Na Allah ndiye Ajuaye zaidi.</p>', 'swahili', 'ltr', 2, '#1f2937', '#ffffff', '2026-08-25 08:53:26', '2026-08-25 08:53:26');

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `content_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `mode` enum('lesson','competition','self') NOT NULL DEFAULT 'lesson',
  `difficulty` enum('easy','medium','hard') NOT NULL DEFAULT 'easy',
  `passing_score` int(11) NOT NULL DEFAULT 70,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 1,
  `unlimited_attempts` tinyint(1) NOT NULL DEFAULT 0,
  `time_limit_minutes` int(11) DEFAULT NULL,
  `prize_description` varchar(255) DEFAULT NULL,
  `competition_start_at` datetime DEFAULT NULL,
  `competition_end_at` datetime DEFAULT NULL,
  `hints_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quizzes`
--

INSERT INTO `quizzes` (`id`, `subject_id`, `category_id`, `content_id`, `title`, `slug`, `description`, `mode`, `difficulty`, `passing_score`, `total_points`, `max_attempts`, `unlimited_attempts`, `time_limit_minutes`, `prize_description`, `competition_start_at`, `competition_end_at`, `hints_enabled`, `status`, `created_by`, `published_at`, `created_at`, `updated_at`) VALUES
(3, NULL, NULL, NULL, 'Welcome to MyIslam learning platform', 'welcome-to-myislam-learning-platform', 'this is just myislam juice!!!', 'competition', 'easy', 46, 10, 1, 0, 1, NULL, '2026-04-22 22:19:00', '2026-09-08 19:31:00', 0, 'published', 2, '2026-08-01 17:32:12', '2026-04-18 19:20:01', '2026-08-01 21:32:12'),
(5, 1, 3, NULL, 'Mirathi (Urithi) – Inheritance in Islam', 'mirathi-urithi-inheritance-quiz', 'Jaribio la kujaribu uelewa wako kuhusu sheria za mirathi katika Uislamu.', 'self', 'medium', 70, 15, 3, 0, NULL, NULL, NULL, NULL, 0, 'published', 2, '2026-04-19 09:57:26', '2026-04-19 06:57:26', '2026-04-19 06:57:26'),
(8, 1, NULL, NULL, 'Jaribio la Juu la Mirathi', 'mirathi-heavy-competition', 'Maswali ya kina kuhusu sheria za urithi katika Uislamu (Mirathi). Unatarajiwa kueleza kwa usahihi sehemu za warithi, kanuni za fardh na asabah, misingi ya ugawaji, na sheria maalum. Hakuna majibu ya kuchagua – andika jibu lako kwa maandishi.', 'competition', 'hard', 70, 100, 1, 0, 1, 'Nafasi ya heshima kwenye kibao cha viongozi wa Mirathi na cheti cha ushiriki.', '2026-04-22 10:17:00', '2026-08-29 20:15:00', 0, 'published', 2, '2026-07-29 16:49:04', '2026-04-19 07:17:13', '2026-07-29 20:49:04'),
(9, 3, 6, 33, 'Quiz-SEERAH->no.1', 'quiz-1', '١- سَيِّدُنَا مُحَمَّدٌ', 'lesson', 'easy', 100, 4, 3, 1, NULL, NULL, NULL, NULL, 0, 'published', 2, '2026-06-23 16:53:11', '2026-06-23 20:41:23', '2026-06-23 20:53:10');

-- --------------------------------------------------------

--
-- Table structure for table `quiz_attempts`
--

CREATE TABLE `quiz_attempts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `attempt_number` int(11) NOT NULL DEFAULT 1,
  `total_questions` int(11) NOT NULL DEFAULT 0,
  `correct_answers` int(11) NOT NULL DEFAULT 0,
  `score` int(11) NOT NULL DEFAULT 0,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `passed` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('started','completed','abandoned') NOT NULL DEFAULT 'started',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `answers_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quiz_attempts`
--

INSERT INTO `quiz_attempts` (`id`, `user_id`, `quiz_id`, `attempt_number`, `total_questions`, `correct_answers`, `score`, `total_points`, `percentage`, `passed`, `status`, `started_at`, `completed_at`, `answers_json`, `created_at`, `updated_at`) VALUES
(0, 12, 8, 1, 0, 0, 0, 100, '0.00', 0, 'started', '2026-08-01 14:31:32', NULL, NULL, '2026-08-01 21:31:32', '2026-08-01 21:31:32');

-- --------------------------------------------------------

--
-- Table structure for table `quiz_attempt_answers`
--

CREATE TABLE `quiz_attempt_answers` (
  `id` bigint(20) NOT NULL,
  `attempt_id` bigint(20) NOT NULL,
  `question_id` int(11) NOT NULL,
  `selected_option_id` int(11) DEFAULT NULL,
  `selected_answer_text` text DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT 0,
  `points_earned` decimal(8,2) DEFAULT 0.00,
  `points_possible` int(11) DEFAULT 1,
  `answered_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_options`
--

CREATE TABLE `quiz_options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_text` varchar(500) NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quiz_options`
--

INSERT INTO `quiz_options` (`id`, `question_id`, `option_text`, `image_path`, `is_correct`, `sort_order`, `created_at`, `updated_at`) VALUES
(129, 34, 'Umar', NULL, 0, 1, '2026-04-18 19:21:24', '2026-04-18 19:21:24'),
(130, 34, 'ali', NULL, 0, 2, '2026-04-18 19:21:24', '2026-04-18 19:21:24'),
(131, 34, 'abu bakr', NULL, 1, 3, '2026-04-18 19:21:24', '2026-04-18 19:21:24'),
(132, 34, 'Uthman', NULL, 0, 4, '2026-04-18 19:21:24', '2026-04-18 19:21:24'),
(133, 35, 'jibril', NULL, 0, 1, '2026-04-18 19:25:15', '2026-04-18 19:25:15'),
(134, 35, 'israfil', NULL, 1, 2, '2026-04-18 19:25:15', '2026-04-18 19:25:15'),
(135, 35, 'mikail', NULL, 0, 3, '2026-04-18 19:25:15', '2026-04-18 19:25:15'),
(136, 35, 'malik', NULL, 0, 4, '2026-04-18 19:25:15', '2026-04-18 19:25:15'),
(137, 36, 'farasi', NULL, 0, 1, '2026-04-18 19:26:17', '2026-04-18 19:26:17'),
(138, 36, 'ngamia', NULL, 0, 2, '2026-04-18 19:26:17', '2026-04-18 19:26:17'),
(139, 36, 'punda', NULL, 0, 3, '2026-04-18 19:26:17', '2026-04-18 19:26:17'),
(140, 36, 'buraq', NULL, 1, 4, '2026-04-18 19:26:17', '2026-04-18 19:26:17'),
(141, 37, 'True', NULL, 0, 1, '2026-04-18 19:28:39', '2026-04-18 19:28:39'),
(142, 37, 'False', NULL, 1, 2, '2026-04-18 19:28:39', '2026-04-18 19:28:39'),
(143, 38, 'True', NULL, 1, 1, '2026-04-18 19:31:19', '2026-04-18 19:31:19'),
(144, 38, 'False', NULL, 0, 2, '2026-04-18 19:31:19', '2026-04-18 19:31:19'),
(145, 39, 'True', NULL, 1, 1, '2026-04-18 19:33:29', '2026-04-18 19:33:29'),
(146, 39, 'False', NULL, 0, 2, '2026-04-18 19:33:29', '2026-04-18 19:33:29'),
(147, 44, 'Kugawa mali', NULL, 0, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(148, 44, 'Kulipa madeni', NULL, 1, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(149, 44, 'Kuwapa watoto', NULL, 0, 3, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(150, 44, 'Kugawa kwa ndugu', NULL, 0, 4, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(151, 45, 'Rafiki', NULL, 0, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(152, 45, 'Mke', NULL, 1, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(153, 45, 'Jirani', NULL, 0, 3, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(154, 45, 'Mfanyakazi', NULL, 0, 4, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(155, 46, 'Sehemu maalum', NULL, 0, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(156, 46, 'Mabaki ya urithi', NULL, 1, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(157, 46, 'Wasia', NULL, 0, 3, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(158, 46, 'Madeni', NULL, 0, 4, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(159, 47, 'Urafiki', NULL, 0, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(160, 47, 'Ujirani', NULL, 0, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(161, 47, 'Ukoo', NULL, 1, 3, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(162, 47, 'Kazi', NULL, 0, 4, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(163, 48, 'Mtoto', NULL, 0, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(164, 48, 'Baba', NULL, 0, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(165, 48, 'Muuaji wa marehemu', NULL, 1, 3, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(166, 48, 'Mke', NULL, 0, 4, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(167, 49, 'True', NULL, 1, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(168, 49, 'False', NULL, 0, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(169, 50, 'True', NULL, 0, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(170, 50, 'False', NULL, 1, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(171, 51, 'True', NULL, 1, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(172, 51, 'False', NULL, 0, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(173, 52, 'True', NULL, 0, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(174, 52, 'False', NULL, 1, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(175, 53, 'True', NULL, 0, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(176, 53, 'False', NULL, 1, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27'),
(507, 237, 'mussa', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(508, 237, 'issa', NULL, 1, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(509, 237, 'muhammad', NULL, 1, 2, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(510, 237, 'ibrahim', NULL, 1, 3, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(511, 238, 'Kitabu cha sheria', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(512, 238, 'Kisomo kinachosomwa kwa sauti', NULL, 1, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(513, 238, 'Hadithi za kale', NULL, 1, 2, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(514, 238, 'Sheria za jamii', NULL, 1, 3, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(515, 239, '100', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(516, 239, '112', NULL, 1, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(517, 239, '113', NULL, 1, 2, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(518, 239, '114', NULL, 1, 3, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(519, 240, 'Riwaya', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(520, 240, 'mwongozo', NULL, 1, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(521, 240, 'Hadithi za kubuni', NULL, 1, 2, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(522, 240, 'historia tu', NULL, 1, 3, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(523, 241, 'miaka 5', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(524, 241, 'miaka 10', NULL, 1, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(525, 241, 'miaka 22', NULL, 1, 2, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(526, 241, 'miaka 23', NULL, 1, 3, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(527, 242, 'True', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(528, 242, 'False', NULL, 0, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(529, 243, 'True', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(530, 243, 'False', NULL, 0, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(531, 244, 'True', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(532, 244, 'False', NULL, 0, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(533, 245, 'True', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(534, 245, 'False', NULL, 0, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(535, 246, 'True', NULL, 1, 0, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(536, 246, 'False', NULL, 0, 1, '2026-05-10 19:29:17', '2026-05-10 19:29:17'),
(537, 252, 'mussa', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(538, 252, 'issa', NULL, 1, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(539, 252, 'muhammad', NULL, 1, 2, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(540, 252, 'ibrahim', NULL, 1, 3, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(541, 253, 'Kitabu cha sheria', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(542, 253, 'Kisomo kinachosomwa kwa sauti', NULL, 1, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(543, 253, 'Hadithi za kale', NULL, 1, 2, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(544, 253, 'Sheria za jamii', NULL, 1, 3, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(545, 254, '100', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(546, 254, '112', NULL, 1, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(547, 254, '113', NULL, 1, 2, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(548, 254, '114', NULL, 1, 3, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(549, 255, 'Riwaya', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(550, 255, 'mwongozo', NULL, 1, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(551, 255, 'Hadithi za kubuni', NULL, 1, 2, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(552, 255, 'historia tu', NULL, 1, 3, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(553, 256, 'miaka 5', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(554, 256, 'miaka 10', NULL, 1, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(555, 256, 'miaka 22', NULL, 1, 2, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(556, 256, 'miaka 23', NULL, 1, 3, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(557, 257, 'True', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(558, 257, 'False', NULL, 0, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(559, 258, 'True', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(560, 258, 'False', NULL, 0, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(561, 259, 'True', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(562, 259, 'False', NULL, 0, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(563, 260, 'True', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(564, 260, 'False', NULL, 0, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(565, 261, 'True', NULL, 1, 0, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(566, 261, 'False', NULL, 0, 1, '2026-05-17 18:58:18', '2026-05-17 18:58:18'),
(567, 267, 'mussa', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(568, 267, 'issa', NULL, 1, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(569, 267, 'muhammad', NULL, 1, 2, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(570, 267, 'ibrahim', NULL, 1, 3, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(571, 268, 'Kitabu cha sheria', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(572, 268, 'Kisomo kinachosomwa kwa sauti', NULL, 1, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(573, 268, 'Hadithi za kale', NULL, 1, 2, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(574, 268, 'Sheria za jamii', NULL, 1, 3, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(575, 269, '100', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(576, 269, '112', NULL, 1, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(577, 269, '113', NULL, 1, 2, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(578, 269, '114', NULL, 1, 3, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(579, 270, 'Riwaya', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(580, 270, 'mwongozo', NULL, 1, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(581, 270, 'Hadithi za kubuni', NULL, 1, 2, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(582, 270, 'historia tu', NULL, 1, 3, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(583, 271, 'miaka 5', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(584, 271, 'miaka 10', NULL, 1, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(585, 271, 'miaka 22', NULL, 1, 2, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(586, 271, 'miaka 23', NULL, 1, 3, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(587, 272, 'True', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(588, 272, 'False', NULL, 0, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(589, 273, 'True', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(590, 273, 'False', NULL, 0, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(591, 274, 'True', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(592, 274, 'False', NULL, 0, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(593, 275, 'True', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(594, 275, 'False', NULL, 0, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(595, 276, 'True', NULL, 1, 0, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(596, 276, 'False', NULL, 0, 1, '2026-05-30 08:53:11', '2026-05-30 08:53:11'),
(597, 282, 'mussa', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(598, 282, 'issa', NULL, 1, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(599, 282, 'muhammad', NULL, 1, 2, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(600, 282, 'ibrahim', NULL, 1, 3, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(601, 283, 'Kitabu cha sheria', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(602, 283, 'Kisomo kinachosomwa kwa sauti', NULL, 1, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(603, 283, 'Hadithi za kale', NULL, 1, 2, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(604, 283, 'Sheria za jamii', NULL, 1, 3, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(605, 284, '100', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(606, 284, '112', NULL, 1, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(607, 284, '113', NULL, 1, 2, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(608, 284, '114', NULL, 1, 3, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(609, 285, 'Riwaya', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(610, 285, 'mwongozo', NULL, 1, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(611, 285, 'Hadithi za kubuni', NULL, 1, 2, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(612, 285, 'historia tu', NULL, 1, 3, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(613, 286, 'miaka 5', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(614, 286, 'miaka 10', NULL, 1, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(615, 286, 'miaka 22', NULL, 1, 2, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(616, 286, 'miaka 23', NULL, 1, 3, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(617, 287, 'True', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(618, 287, 'False', NULL, 0, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(619, 288, 'True', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(620, 288, 'False', NULL, 0, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(621, 289, 'True', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(622, 289, 'False', NULL, 0, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(623, 290, 'True', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(624, 290, 'False', NULL, 0, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(625, 291, 'True', NULL, 1, 0, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(626, 291, 'False', NULL, 0, 1, '2026-05-30 08:54:22', '2026-05-30 08:54:22'),
(627, 297, 'mussa', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(628, 297, 'issa', NULL, 1, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(629, 297, 'muhammad', NULL, 1, 2, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(630, 297, 'ibrahim', NULL, 1, 3, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(631, 298, 'Kitabu cha sheria', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(632, 298, 'Kisomo kinachosomwa kwa sauti', NULL, 1, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(633, 298, 'Hadithi za kale', NULL, 1, 2, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(634, 298, 'Sheria za jamii', NULL, 1, 3, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(635, 299, '100', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(636, 299, '112', NULL, 1, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(637, 299, '113', NULL, 1, 2, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(638, 299, '114', NULL, 1, 3, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(639, 300, 'Riwaya', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(640, 300, 'mwongozo', NULL, 1, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(641, 300, 'Hadithi za kubuni', NULL, 1, 2, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(642, 300, 'historia tu', NULL, 1, 3, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(643, 301, 'miaka 5', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(644, 301, 'miaka 10', NULL, 1, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(645, 301, 'miaka 22', NULL, 1, 2, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(646, 301, 'miaka 23', NULL, 1, 3, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(647, 302, 'True', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(648, 302, 'False', NULL, 0, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(649, 303, 'True', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(650, 303, 'False', NULL, 0, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(651, 304, 'True', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(652, 304, 'False', NULL, 0, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(653, 305, 'True', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(654, 305, 'False', NULL, 0, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(655, 306, 'True', NULL, 1, 0, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(656, 306, 'False', NULL, 0, 1, '2026-05-30 14:27:00', '2026-05-30 14:27:00'),
(657, 312, 'mussa', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(658, 312, 'issa', NULL, 1, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(659, 312, 'muhammad', NULL, 1, 2, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(660, 312, 'ibrahim', NULL, 1, 3, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(661, 313, 'Kitabu cha sheria', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(662, 313, 'Kisomo kinachosomwa kwa sauti', NULL, 1, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(663, 313, 'Hadithi za kale', NULL, 1, 2, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(664, 313, 'Sheria za jamii', NULL, 1, 3, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(665, 314, '100', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(666, 314, '112', NULL, 1, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(667, 314, '113', NULL, 1, 2, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(668, 314, '114', NULL, 1, 3, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(669, 315, 'Riwaya', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(670, 315, 'mwongozo', NULL, 1, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(671, 315, 'Hadithi za kubuni', NULL, 1, 2, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(672, 315, 'historia tu', NULL, 1, 3, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(673, 316, 'miaka 5', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(674, 316, 'miaka 10', NULL, 1, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(675, 316, 'miaka 22', NULL, 1, 2, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(676, 316, 'miaka 23', NULL, 1, 3, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(677, 317, 'True', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(678, 317, 'False', NULL, 0, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(679, 318, 'True', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(680, 318, 'False', NULL, 0, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(681, 319, 'True', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(682, 319, 'False', NULL, 0, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(683, 320, 'True', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(684, 320, 'False', NULL, 0, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(685, 321, 'True', NULL, 1, 0, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(686, 321, 'False', NULL, 0, 1, '2026-05-30 14:28:14', '2026-05-30 14:28:14'),
(687, 327, 'mussa', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(688, 327, 'issa', NULL, 1, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(689, 327, 'muhammad', NULL, 1, 2, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(690, 327, 'ibrahim', NULL, 1, 3, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(691, 328, 'Kitabu cha sheria', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(692, 328, 'Kisomo kinachosomwa kwa sauti', NULL, 1, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(693, 328, 'Hadithi za kale', NULL, 1, 2, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(694, 328, 'Sheria za jamii', NULL, 1, 3, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(695, 329, '100', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(696, 329, '112', NULL, 1, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(697, 329, '113', NULL, 1, 2, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(698, 329, '114', NULL, 1, 3, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(699, 330, 'Riwaya', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(700, 330, 'mwongozo', NULL, 1, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(701, 330, 'Hadithi za kubuni', NULL, 1, 2, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(702, 330, 'historia tu', NULL, 1, 3, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(703, 331, 'miaka 5', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(704, 331, 'miaka 10', NULL, 1, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(705, 331, 'miaka 22', NULL, 1, 2, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(706, 331, 'miaka 23', NULL, 1, 3, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(707, 332, 'True', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(708, 332, 'False', NULL, 0, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38');
INSERT INTO `quiz_options` (`id`, `question_id`, `option_text`, `image_path`, `is_correct`, `sort_order`, `created_at`, `updated_at`) VALUES
(709, 333, 'True', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(710, 333, 'False', NULL, 0, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(711, 334, 'True', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(712, 334, 'False', NULL, 0, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(713, 335, 'True', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(714, 335, 'False', NULL, 0, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(715, 336, 'True', NULL, 1, 0, '2026-06-19 07:47:38', '2026-06-19 07:47:38'),
(716, 336, 'False', NULL, 0, 1, '2026-06-19 07:47:38', '2026-06-19 07:47:38');

-- --------------------------------------------------------

--
-- Table structure for table `quiz_questions`
--

CREATE TABLE `quiz_questions` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `monthly_exam_id` int(11) DEFAULT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('multiple_choice','true_false','explanation') NOT NULL DEFAULT 'multiple_choice',
  `points` int(11) NOT NULL DEFAULT 1,
  `negative_points` int(11) NOT NULL DEFAULT 0,
  `difficulty` enum('easy','medium','hard') NOT NULL DEFAULT 'medium',
  `explanation` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expected_answer` text DEFAULT NULL COMMENT 'Model answer for explanation questions',
  `keywords_json` text DEFAULT NULL COMMENT 'JSON array of keywords with optional weights'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quiz_questions`
--

INSERT INTO `quiz_questions` (`id`, `quiz_id`, `monthly_exam_id`, `question_text`, `question_type`, `points`, `negative_points`, `difficulty`, `explanation`, `image_path`, `sort_order`, `created_at`, `updated_at`, `expected_answer`, `keywords_json`) VALUES
(34, 3, NULL, 'Ni nani alikuwa Khalifa wa kwanza katika Uislamu?', 'multiple_choice', 1, 0, 'easy', NULL, NULL, 0, '2026-04-18 19:21:24', '2026-04-18 19:21:24', NULL, NULL),
(35, 3, NULL, 'Ni malaika gani anahusika na kupuliza parapanda siku ya Qiyama?', 'multiple_choice', 1, 0, 'easy', NULL, NULL, 2, '2026-04-18 19:25:15', '2026-04-18 19:25:15', NULL, NULL),
(36, 3, NULL, 'Ni mnyama gani alitumika kama chombo cha safari ya Mtume katika Israa na Mi’raj?', 'multiple_choice', 1, 0, 'easy', NULL, NULL, 0, '2026-04-18 19:26:17', '2026-04-18 19:26:17', NULL, NULL),
(37, 3, NULL, 'Waislamu huswali kuelekea Mashariki', 'true_false', 1, 0, 'easy', NULL, NULL, 4, '2026-04-18 19:28:39', '2026-04-18 19:28:39', NULL, NULL),
(38, 3, NULL, 'Hijja ni wajibu kwa mwenye uwezo', 'true_false', 1, 0, 'easy', NULL, NULL, 5, '2026-04-18 19:31:19', '2026-04-18 19:31:19', NULL, NULL),
(39, 3, NULL, 'Swala tano ni wajibu kwa kila Muislamu?', 'true_false', 1, 0, 'easy', NULL, NULL, 6, '2026-04-18 19:33:29', '2026-04-18 19:33:29', NULL, NULL),
(40, 3, NULL, 'Ni nani Mtume wa mwisho katika Uislamu?', 'explanation', 1, 0, 'easy', NULL, NULL, 7, '2026-04-18 19:34:35', '2026-04-18 20:00:03', 'muhammad', '[{\"word\":\"muhammad\",\"weight\":1}]'),
(41, 3, NULL, 'Taja jina la huu msikiti', 'explanation', 1, 0, 'easy', NULL, 'uploads/questions/qimg_69e3de61762b78.91740213.png', 8, '2026-04-18 19:41:21', '2026-04-18 19:41:21', 'aqsa', '[{\"word\":\"al.aqsa\",\"weight\":1}]'),
(42, 3, NULL, 'Taja jina la msikiti huu', 'explanation', 1, 0, 'medium', NULL, 'uploads/questions/qimg_69e3e1a09d72b0.20330526.png', 9, '2026-04-18 19:53:01', '2026-04-18 19:55:12', 'al-haram', '[{\"word\":\"al\",\"weight\":1},{\"word\":\"haram\",\"weight\":1}]'),
(43, 3, NULL, 'Taja jina la msikiti huu.', 'explanation', 1, 0, 'medium', NULL, 'uploads/questions/qimg_69e3e25493e3a1.14377391.png', 10, '2026-04-18 19:58:12', '2026-04-18 19:58:12', 'an-nabawi', '[{\"word\":\"an\",\"weight\":1},{\"word\":\"nabawi\",\"weight\":1}]'),
(44, 5, NULL, 'Ni ipi hutangulia kabla ya kugawa urithi?', 'multiple_choice', 1, 0, 'easy', 'Kabla ya kugawa urithi, ni lazima kulipa madeni ya marehemu na kutekeleza wasia wake.', NULL, 1, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(45, 5, NULL, 'Ni nani kati ya hawa hupata Fardh?', 'multiple_choice', 1, 0, 'easy', 'Fardh ni sehemu ya lazima ya urithi. Mke ni mmoja wa warithi wa fardh (anapata sehemu maalum).', NULL, 2, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(46, 5, NULL, '‘Asabah ni nini?', 'multiple_choice', 1, 0, 'medium', 'Asabah ni jamaa wa upande wa baba ambao hupokea mabaki ya urithi baada ya warithi wenye sehemu maalum (fardh) kuchukua sehemu zao.', NULL, 3, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(47, 5, NULL, 'Ni ipi kati ya hizi ni sababu ya kurithi?', 'multiple_choice', 1, 0, 'easy', 'Sababu za kurithi ni pamoja na: ukoo (nasaba), ndoa, na wala (ukaribu wa kisheria). Ukoo ni msingi.', NULL, 4, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(48, 5, NULL, 'Ni nani huzuiwa kurithi?', 'multiple_choice', 1, 0, 'easy', 'Muuaji wa marehemu huzuiwa kurithi kwa mujibu wa Sheria ya Kiislamu. Pia mtu wa dini tofauti au mtumwa (kihistoria) huzuiwa.', NULL, 5, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(49, 5, NULL, 'Mirathi hugawiwa baada ya kulipa madeni', 'true_false', 1, 0, 'easy', 'Ndiyo, ni wajibu kulipa madeni ya marehemu kabla ya kugawa urithi.', NULL, 6, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(50, 5, NULL, 'Mke hupata 1/4 kama kuna watoto', 'true_false', 1, 0, 'medium', 'Hii ni sahihi. Mke hupata 1/8 ya urithi ikiwa marehemu ana watoto, na 1/4 ikiwa hana watoto. Taarifa inasema \"kama kuna watoto\" – basi anapata 1/8, si 1/4. Hivyo ni False.', NULL, 7, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(51, 5, NULL, 'Mtoto wa kiume hupata mara mbili ya mtoto wa kike', 'true_false', 1, 0, 'easy', 'Ndiyo, kanuni ya Qur\'an (Surah An-Nisa 4:11) inasema sehemu ya mwanaume ni sawa na sehemu ya wanawake wawili.', NULL, 8, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(52, 5, NULL, 'Ukoo si sababu ya kurithi', 'true_false', 1, 0, 'easy', 'Ukoo (nasaba) ni mojawapo ya sababu kuu za kurithi. Hivyo kauli hii ni si sahihi.', NULL, 9, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(53, 5, NULL, 'Wasia unaweza kuzidi 1/3 ya mali', 'true_false', 1, 0, 'medium', 'Hairuhusiwi. Wasia hauwezi kuzidi theluthi moja (1/3) ya mali isipokuwa kwa ridhaa ya warithi wote baada ya kifo.', NULL, 10, '2026-04-19 06:57:27', '2026-04-19 06:57:27', NULL, NULL),
(54, 5, NULL, 'Mke hupata sehemu gani kama kuna watoto?', 'explanation', 1, 0, 'medium', 'Mke hupata 1/8 (themuni) ya urithi ikiwa marehemu ana watoto.', NULL, 11, '2026-04-19 06:57:27', '2026-04-19 06:57:27', '1/8', '[{\"word\":\"1/8\",\"weight\":2},{\"word\":\"themune\",\"weight\":1},{\"word\":\"themuna\",\"weight\":1},{\"word\":\"themuni\",\"weight\":1}]'),
(55, 5, NULL, 'Taja sababu moja ya kurithi.', 'explanation', 1, 0, 'easy', 'Sababu za kurithi ni: ukoo (nasaba), ndoa, au wala (ukaribu wa kisheria).', NULL, 12, '2026-04-19 06:57:27', '2026-04-19 06:57:27', 'ukoo', '[{\"word\":\"ukoo\",\"weight\":2},{\"word\":\"nasaba\",\"weight\":2},{\"word\":\"ndoa\",\"weight\":2},{\"word\":\"wala\",\"weight\":2},{\"word\":\"uzazi\",\"weight\":1}]'),
(56, 5, NULL, 'Ni nani hupata mabaki ya urithi?', 'explanation', 1, 0, 'medium', 'Asabah (jamaa wa upande wa baba) ndiye hupokea mabaki ya urithi baada ya warithi wenye sehemu maalum.', NULL, 13, '2026-04-19 06:57:27', '2026-04-19 06:57:27', 'asabah', '[{\"word\":\"asabah\",\"weight\":3},{\"word\":\"asaba\",\"weight\":2},{\"word\":\"mabaki\",\"weight\":1},{\"word\":\"jamaa\",\"weight\":1}]'),
(57, 5, NULL, 'Ni kitu gani hulipwa baada ya mazishi kabla ya kugawa urithi?', 'explanation', 1, 0, 'easy', 'Madeni ya marehemu hulipwa kwanza kabla ya kugawa urithi.', NULL, 14, '2026-04-19 06:57:27', '2026-04-19 06:57:27', 'madeni', '[{\"word\":\"madeni\",\"weight\":3},{\"word\":\"deni\",\"weight\":2},{\"word\":\"mikopo\",\"weight\":1}]'),
(58, 5, NULL, 'Ni neno gani la Kiarabu linalomaanisha sehemu maalum ya urithi?', 'explanation', 1, 0, 'medium', 'Neno “Fardh” (pia “Faraidh”) linamaanisha sehemu maalum iliyoamriwa na Mwenyezi Mungu kwa warithi fulani.', NULL, 15, '2026-04-19 06:57:27', '2026-04-19 06:57:27', 'fardh', '[{\"word\":\"fardh\",\"weight\":3},{\"word\":\"faraidh\",\"weight\":2},{\"word\":\"faridha\",\"weight\":2}]'),
(77, 8, NULL, 'Taja warithi wote wa kike wanaopata sehemu maalum (fardh) katika mirathi, na ueleze sehemu zao katika hali tofauti (kwa mfano, mke anapata nini ikiwa hana watoto, na nini ikiwa ana watoto).', 'explanation', 10, 0, 'hard', 'Warithi wa kike wenye fardh ni: mke (1/4 ikiwa hana watoto, 1/8 ikiwa ana watoto), binti (1/2 ikiwa peke yake, 2/3 ikiwa wawili au zaidi), baba mkubwa (mama wa baba) – 1/6, mama (1/6 ikiwa ana watoto au kaka, 1/3 ikiwa hana watoto wala kaka), dada mzazi (1/2 ikiwa peke yake, 2/3 ikiwa wawili au zaidi), dada wa baba (1/2 ikiwa peke yake, 2/3 ikiwa wawili au zaidi) lakini wanazuiwa na kuwepo kwa binti au baba mkubwa, na mke na mume hupata fardh pia.', NULL, 1, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Mke (1/4 au 1/8), binti (1/2 au 2/3), mama (1/6 au 1/3), dada mzazi (1/2 au 2/3), dada wa baba (1/2 au 2/3), na baba mkubwa (1/6).', '[{\"word\":\"mke\",\"weight\":2},{\"word\":\"binti\",\"weight\":2},{\"word\":\"mama\",\"weight\":2},{\"word\":\"dada\",\"weight\":2},{\"word\":\"fardh\",\"weight\":3},{\"word\":\"1/4\",\"weight\":2},{\"word\":\"1/8\",\"weight\":2},{\"word\":\"1/2\",\"weight\":2},{\"word\":\"2/3\",\"weight\":2}]'),
(78, 8, NULL, 'Eleza maana ya “Asabah” na orodhesha warithi wa kiume wanaorithi kwa njia ya asabah (mabaki). Toa mfano wa hesabu ya ugawaji kwa mfano wa marehemu aliyeacha mke, binti, na baba.', 'explanation', 10, 0, 'hard', 'Asabah ni jamaa wa upande wa baba wanaopokea mabaki baada ya warithi wa fardh kuchukua sehemu zao. Warithi wa kiume asabah: mtoto wa kiume, baba, babu (baba wa baba), kaka mzazi, kaka wa baba, mjomba wa baba, na wana wa kaka. Mfano: marehemu acha mke (1/8), binti (1/2), na baba (asabah). Jumla ya fardh = 1/8+1/2 = 5/8. Mabaki = 3/8 anapata baba.', NULL, 2, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Asabah ni warithi wa kiume wanaopokea mabaki. Mfano: baba anapata mabaki baada ya mke na binti.', '[{\"word\":\"asabah\",\"weight\":3},{\"word\":\"mabaki\",\"weight\":2},{\"word\":\"baba\",\"weight\":2},{\"word\":\"mtoto\",\"weight\":2},{\"word\":\"kaka\",\"weight\":2}]'),
(79, 8, NULL, 'Taja sababu kuu tatu za kurithi katika Uislamu, na ueleze vizuizi vitatu vinavyomzuia mtu kurithi.', 'explanation', 10, 0, 'hard', 'Sababu: (1) Ukoo (nasaba) – kwa kuzaliwa. (2) Ndoa – mke na mume hurithiana. (3) Wala (ukaribu wa kisheria) – kwa mfano, mtoto wa kulea si hurithi ila kwa wasia. Vizuizi: (1) Utofauti wa dini – Muislamu hana urithi na asiye Muislamu. (2) Uuaji – muuaji wa marehemu hazuiliwi kurithi. (3) Utumwa (kihistoria) – mtumwa hawezi kurithi.', NULL, 3, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Sababu: ukoo, ndoa, wala. Vizuizi: tofauti ya dini, uuaji, utumwa.', '[{\"word\":\"ukoo\",\"weight\":2},{\"word\":\"ndoa\",\"weight\":2},{\"word\":\"wala\",\"weight\":2},{\"word\":\"dini\",\"weight\":2},{\"word\":\"uuaji\",\"weight\":2}]'),
(80, 8, NULL, 'Eleza kanuni ya Qur’an kuhusu urithi wa watoto: “Mwanamume anapata sawa na sehemu ya wanawake wawili.” Toa mfano wa hesabu ikiwa marehemu ana watoto wawili wa kiume na watatu wa kike, na hana mke wala wazazi.', 'explanation', 10, 0, 'hard', 'Kanuni: sehemu ya mwanaume ni mara mbili ya sehemu ya mwanamke. Mfano: watoto wote ni warithi pekee. Jumla ya sehemu: kwa kila mwanaume hesabu kama 2, na kwa kila mwanamke hesabu kama 1. Hivyo: wanaume wawili = 2×2=4, wanawake watatu = 3×1=3, jumla = 7. Kila mwanaume anapata 2/7 ya mali, na kila mwanamke anapata 1/7.', NULL, 4, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Mwanaume anapata mara mbili ya mwanamke. Kwa mfano: wanaume wawili wanapata 2/7 kila mmoja, wanawake watatu wanapata 1/7 kila mmoja.', '[{\"word\":\"mara mbili\",\"weight\":3},{\"word\":\"mwanaume\",\"weight\":2},{\"word\":\"mwanamke\",\"weight\":2},{\"word\":\"2/7\",\"weight\":2},{\"word\":\"1/7\",\"weight\":2}]'),
(81, 8, NULL, 'Eleza tofauti ya urithi wa mke na mume. Mke anapata nini ikiwa hana watoto? Anapata nini ikiwa ana watoto? Mume anapata nini ikiwa hana watoto? Na anapata nini ikiwa ana watoto?', 'explanation', 10, 0, 'hard', 'Mke: 1/4 ikiwa marehemu hana watoto, 1/8 ikiwa ana watoto. Mume: 1/2 ikiwa mke wake hana watoto, 1/4 ikiwa ana watoto (au watoto wa mume kutoka mke mwingine). Ikiwa marehemu ni mwanamke na mume wake ana watoto (kwa mke mwingine), basi mume anapata 1/4.', NULL, 5, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Mke: 1/4 (hakuna watoto), 1/8 (kuna watoto). Mume: 1/2 (hakuna watoto), 1/4 (kuna watoto).', '[{\"word\":\"mke\",\"weight\":2},{\"word\":\"mume\",\"weight\":2},{\"word\":\"1/4\",\"weight\":2},{\"word\":\"1/8\",\"weight\":2},{\"word\":\"1/2\",\"weight\":2}]'),
(82, 8, NULL, 'Wazazi wa marehemu hurithi vipi? Eleza hali mbalimbali: ikiwa marehemu ana watoto, ikiwa hana watoto, na ikiwa ana kaka au dada.', 'explanation', 10, 0, 'hard', 'Baba: kama kuna mtoto wa kiume, baba hupata asabah (mabaki); kama hakuna mtoto wa kiume, baba anapata 1/6 pamoja na asabah. Mama: 1/6 ikiwa marehemu ana watoto au kaka; 1/3 ikiwa hana watoto wala kaka. Ikiwa marehemu ana baba na mama pekee (hakuna mtoto wala mke), mama anapata 1/3, baba anapata 2/3.', NULL, 6, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Baba: asabah au 1/6. Mama: 1/6 (kuna watoto/kaka), 1/3 (hakuna).', '[{\"word\":\"baba\",\"weight\":2},{\"word\":\"mama\",\"weight\":2},{\"word\":\"1/6\",\"weight\":2},{\"word\":\"1/3\",\"weight\":2},{\"word\":\"asabah\",\"weight\":2}]'),
(83, 8, NULL, 'Eleza dhana ya “Al-‘awl” katika mirathi. Toa mfano wa hali ambapo ‘awl hutumika na uonyeshe hesabu.', 'explanation', 10, 0, 'hard', 'Al-‘awl ni wakati jumla ya sehemu za fardh zinazidi 1 (asilimia 100). Kwa mfano, marehemu ana mke (1/8), binti (1/2), na baba (1/6). Jumla = 1/8+1/2+1/6 = (3+12+4)/24 = 19/24, haizidi. Lakini mfano halisi: mke (1/4), binti wawili (2/3), baba (1/6), mama (1/6). Jumla = 1/4+2/3+1/6+1/6 = (3+8+2+2)/12 = 15/12 = 1.25. Hivyo, sehemu hupunguzwa kwa uwiano: kila sehemu inazidishwa na 12/15 = 4/5. Mfano: mke anapata 1/4 × 4/5 = 1/5.', NULL, 7, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Al-‘awl ni marekebisho wakati jumla ya fardh inazidi 1; sehemu hupunguzwa kwa uwiano.', '[{\"word\":\"awl\",\"weight\":3},{\"word\":\"fardh\",\"weight\":2},{\"word\":\"uwiano\",\"weight\":2},{\"word\":\"1/4\",\"weight\":2},{\"word\":\"2/3\",\"weight\":2}]'),
(84, 8, NULL, 'Eleza dhana ya “Al-rad” (kurudisha ziada) katika mirathi. Ni katika hali gani inatumika? Toa mfano.', 'explanation', 10, 0, 'hard', 'Al-rad hutumika wakati baada ya kugawa sehemu za fardh, kuna mabaki (ziada) lakini hakuna asabah wa kuyapokea. Mabaki hurudishwa kwa warithi wa fardh kwa uwiano wa sehemu zao. Kwa mfano, marehemu anabaki na binti pekee (anapata 1/2), na mke (1/4) – lakini mke hana asabah. Jumla = 1/2+1/4=3/4, mabaki=1/4. Mabaki yanarudishwa kwa binti na mke kwa uwiano 2:1 (binti 2/3, mke 1/3). Hivyo binti anapata 1/2 + (2/3×1/4)= 2/3, mke anapata 1/4+(1/3×1/4)=1/3.', NULL, 8, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Al-rad ni kurudisha mabaki kwa warithi wa fardh wakati hakuna asabah. Mfano: binti na mke.', '[{\"word\":\"rad\",\"weight\":3},{\"word\":\"mabaki\",\"weight\":2},{\"word\":\"asabah\",\"weight\":2},{\"word\":\"uwiano\",\"weight\":2}]'),
(85, 8, NULL, 'Ni katika hali gani binti au dada huzuiwa kurithi (au kupunguziwa sehemu) licha ya kuwepo? Eleza kwa mifano.', 'explanation', 10, 0, 'hard', 'Binti huzuiwa kabisa kurithi kama kuna mtoto wa kiume? La, binti hajazuwi bali anapata 1/2 au 2/3 na mwanaume asabah. Lakini dada mzazi huzuiwa na kuwepo kwa baba au mtoto wa kiume. Dada wa baba huzuiwa na kuwepo kwa baba, kaka mzazi, au binti. Kwa mfano, marehemu ana baba na dada mzazi – dada mzazi hana urithi (anazuiliwa na baba).', NULL, 9, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Binti hajazuwi kabisa; dada huzuiwa na baba au mtoto wa kiume. Mfano: dada anazuiliwa na baba.', '[{\"word\":\"binti\",\"weight\":2},{\"word\":\"dada\",\"weight\":2},{\"word\":\"zuio\",\"weight\":3},{\"word\":\"baba\",\"weight\":2}]'),
(86, 8, NULL, 'Je, wasia unaathiri vipi ugawaji wa mirathi? Eleza kikomo cha wasia (kiasi cha juu) na utaratibu wa utekelezaji wake kabla ya kugawa urithi.', 'explanation', 10, 0, 'hard', 'Wasia hauwezi kuzidi theluthi moja (1/3) ya mali isipokuwa kwa ridhaa ya warithi wote. Wasia hutekelezwa kabla ya kugawa urithi, lakini baada ya kulipa madeni na gharama za mazishi. Ikiwa wasia unazidi 1/3, unapunguzwa hadi 1/3 isipokuwa warithi wakubali. Wasia hauwezi kumpa mrithi ambaye tayari anarithi (kwa mfano, mtoto) isipokuwa warithi wengine wakubali.', NULL, 10, '2026-04-19 07:17:13', '2026-04-19 07:17:13', 'Wasia ni kikomo cha 1/3 ya mali, hutekelezwa baada ya madeni na mazishi, kabla ya mirathi.', '[{\"word\":\"wasia\",\"weight\":3},{\"word\":\"1/3\",\"weight\":3},{\"word\":\"madeni\",\"weight\":2},{\"word\":\"warithi\",\"weight\":2}]'),
(357, 9, NULL, '. مَنْ سَيِّدُنَا مُحَمَّدٌ ﷺ؟', 'explanation', 1, 0, 'easy', 'Bwana wetu Muhammad ﷺ ni Mtume wa Mwenyezi Mungu kwa watu wote.', NULL, 1, '2026-06-23 20:45:07', '2026-06-23 20:45:07', 'Bwana wetu Muhammad ﷺ ni Mtume wa Mwenyezi Mungu kwa watu wote.', '[{\"word\":\"muhammad\",\"weight\":1},{\"word\":\"Muhammad\",\"weight\":1},{\"word\":\"Bwana\",\"weight\":1},{\"word\":\"wetu\",\"weight\":1},{\"word\":\"mtumem\",\"weight\":1},{\"word\":\"Mtume\",\"weight\":1},{\"word\":\"Mwenyezi\",\"weight\":1},{\"word\":\"Mungu\",\"weight\":1},{\"word\":\"MwenyeziMungu\",\"weight\":1},{\"word\":\"watu\",\"weight\":1},{\"word\":\"kwa\",\"weight\":1},{\"word\":\"wote\",\"weight\":1}]'),
(358, 9, NULL, '. بِمَاذَا جَاءَ سَيِّدُنَا مُحَمَّدٌ ﷺ؟', 'explanation', 1, 0, 'easy', 'Alikuja na dini ya Uislamu', NULL, 2, '2026-06-23 20:46:40', '2026-06-23 20:54:11', 'Alikuja na dini ya Uislamu', '[{\"word\":\"alikuja\",\"weight\":1},{\"word\":\"dini\",\"weight\":1},{\"word\":\"ya\",\"weight\":1},{\"word\":\"uislam\",\"weight\":1},{\"word\":\"kufundisha\",\"weight\":1}]'),
(359, 9, NULL, '. مِنْ أَيِّ قَبِيلَةٍ كَانَ سَيِّدُنَا مُحَمَّدٌ ﷺ؟', 'explanation', 1, 0, 'easy', 'Wazazi wake walikuwa wa kabila la Quraysh.', NULL, 6, '2026-06-23 20:48:44', '2026-06-23 20:48:44', 'Wazazi wake walikuwa wa kabila la Quraysh.', '[{\"word\":\"wazazi\",\"weight\":1},{\"word\":\"wake\",\"weight\":1},{\"word\":\"walikuwa\",\"weight\":1},{\"word\":\"walitokana\",\"weight\":1},{\"word\":\"wa\",\"weight\":1},{\"word\":\"kabila\",\"weight\":1},{\"word\":\"quraysh\",\"weight\":1},{\"word\":\"kuraishi\",\"weight\":1},{\"word\":\"quraishi\",\"weight\":1},{\"word\":\"kuraysh\",\"weight\":1}]'),
(360, 9, NULL, '. بِمَنْ يَتَّصِلُ نَسَبُ سَيِّدِنَا مُحَمَّدٍ ﷺ؟', 'explanation', 1, 0, 'easy', 'Nasaba yake inaungana na Nabii Ismail mwana wa Nabii Ibrahim, amani iwe juu yao.', NULL, 4, '2026-06-23 20:50:28', '2026-06-23 20:50:28', 'Nasaba yake inaungana na Nabii Ismail mwana wa Nabii Ibrahim, amani iwe juu yao.', '[{\"word\":\"nasaba\",\"weight\":1},{\"word\":\"yake\",\"weight\":1},{\"word\":\"inaungana\",\"weight\":1},{\"word\":\"na\",\"weight\":1},{\"word\":\"nabii\",\"weight\":1},{\"word\":\"ismail\",\"weight\":1},{\"word\":\"mwana\",\"weight\":1},{\"word\":\"wa\",\"weight\":1},{\"word\":\"nabii\",\"weight\":1},{\"word\":\"ibrahim\",\"weight\":1},{\"word\":\"amani\",\"weight\":1},{\"word\":\"iwe\",\"weight\":1},{\"word\":\"juu\",\"weight\":1},{\"word\":\"yao\",\"weight\":1}]');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `name`, `slug`, `description`, `icon`, `image_path`, `sort_order`, `is_active`, `created_at`, `image`) VALUES
(1, 'الأُصُولُ ألثَّلاَثَة', 'usuli', 'AL-USWUWL ATH-THALAATHAH\r\n(MISINGI MITATU)', '📚', '/myislam/uploads/subjects/subject_6a8adfd180854.jpg', 1, 1, '2026-04-18 09:27:38', NULL),
(2, 'عُمْدَةُ الأَحْكَامِ', 'umdatul-ahkaam', 'تَيْسِيرُ الْعَلَّمِ شَرْحُ   عُمْدَةِ   الْأَحْكَامِ', '📚', '/myislam/uploads/subjects/subject_6a0c72041d940.png', 2, 1, '2026-05-17 09:13:59', NULL),
(3, 'خُلَاصَةُ نُورِ الْيَقِينِ', 'nuurul-yaqeen', 'خُلَاصَةُ نُورِ الْيَقِينِ فِي سِيرَةِ سَيِّدِنَا مُحَمَّدٍ', '📚', '/myislam/uploads/subjects/subject_6a8af46a40e31.jpeg', 3, 1, '2026-06-19 09:07:52', NULL),
(4, 'كِتَابُ التَّوْحِيدِ', 'tawheed', 'تَأْلِيف: لِلشَّيْخِ مُحَمَّدِ بْنِ عَبْدِ الْوَهَّابِ', '📚', '/myislam/uploads/subjects/subject_6a8af91768f83.jpeg', 4, 1, '2026-06-19 09:12:48', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `admin_response` text DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('owner','admin','sheikh','user') NOT NULL DEFAULT 'user',
  `bio` text DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_verified` tinyint(1) DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `email`, `whatsapp`, `password`, `role`, `bio`, `profile_image`, `is_active`, `is_verified`, `last_login_at`, `created_at`) VALUES
(1, 'luten canal', NULL, 'sharifurashidi44@gmail.com', NULL, '$2y$10$N8IUHVAXZmcNgV1BQZceTeXKajfa9/yCWtPpjL.n0S.scuiSFWdSm', 'admin', NULL, 'uploads/profiles/user_1_1776858939.png', 1, 1, '2026-04-22 17:48:39', '2026-03-26 14:47:03'),
(2, 'System Admin', NULL, 'admin@myislam.com', NULL, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NULL, NULL, 1, 1, '2026-08-25 22:17:19', '2026-03-27 00:15:55'),
(3, 'Sheikh Mohammed', NULL, 'sheikh@myislam.com', NULL, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'sheikh', NULL, NULL, 1, 1, NULL, '2026-03-27 00:15:55'),
(4, 'sania twaha', NULL, 'sania@my.wife', NULL, '$2y$10$9TQIC5YdQXcU8nmJUd4yaONL9A0UV/OOFIrFDd/8aTWj3.Wf5ogl2', 'user', NULL, NULL, 1, 1, '2026-04-22 19:43:08', '2026-03-28 23:55:20'),
(5, 'juma maji', NULL, 'juma@maji.com', NULL, '$2y$10$GqVeu6hsTsGqMjKnlRk3quZNSwr0k6nSsULzmX1Xu1OAqQJ6R9cDG', 'user', NULL, NULL, 1, 1, '2026-04-22 19:40:40', '2026-03-29 11:49:55'),
(6, 'myrath', NULL, 'myrath@gmail.com', NULL, '$2y$10$2me562ly9LctoHD8SSMSTu9fJVPmPSNzbw.KIx0z6vlv/zEEyedyS', 'user', NULL, NULL, 1, 1, '2026-04-22 22:05:34', '2026-04-12 17:27:27'),
(7, 'sania athumani', NULL, 'saniatwaha44@gmail.com', NULL, '$2y$10$85XS7PZ1rPCLTslTCqXsDu4PzWzxLE6q3utJhSaa6mNN.1x01EpNm', 'user', NULL, NULL, 1, 1, NULL, '2026-04-14 19:33:29'),
(10, 'Hussein', 'Hussein', 'dottoibrahim2@gmail.com', '+255713685246', '$2y$10$f9qUmJ6/2NdzeT2uYwbaBu.wCS5fJ/7c4izG16FVgjmJYbN6p3AN.', '', NULL, NULL, 1, 1, '2026-04-22 22:36:16', '2026-04-23 05:35:12'),
(11, 'Yusra Hassan', 'Ilham', 'yusralassan150@gmail.com', '2556554321804', '$2y$10$6nerAkrYaW9yOr08nAIDN.ic/U4CtJBOOehK7xNSO74GwFJAkuWA6', 'user', NULL, NULL, 1, 1, NULL, '2026-04-23 17:38:33'),
(12, 'myrath shareef hussein', 'myrath', 'myrathshareef@gmail.com', '233433365555', '$2y$10$wilc7NBKx/ABzj9PIYeXAOmATYjAsifCvvwwy9exmLSPZ05YgTsKG', 'user', NULL, NULL, 1, 1, NULL, '2026-04-27 20:17:54'),
(13, 'Sania Twaha Athumani', 'SaniaAthumani', 'saniatwahaathumani@gmail.com', '0680384136', '$2y$10$kL7HYnyT5E5HX5qXkR4wLe4s3dr1jNvDDUbZ.wm89a8pZDAF47vxW', 'user', NULL, 'uploads/profiles/user_13_1777904454.jpg', 1, 1, NULL, '2026-04-27 21:24:15'),
(14, 'MHITA, IDDI Z', 'Abuuahmad', 'iddimhita@gmail.com', '0717099485', '$2y$10$TVQKONBC4dPKsSfSDSflVuxEiISyQyYHhQc.b5zDey10qCVgCxrea', 'user', NULL, NULL, 1, 1, NULL, '2026-05-06 12:27:05'),
(15, 'TASLIMA, KAMALU MANSOOR', 'TASLIMA', 'kamalumansoor7@gmail.com', '255773490210', '$2y$10$cVls64l4e6iPN42Zv6EM3.PddNQzIMeNsU6p6yxhkPTPe9YFz.0vy', 'user', NULL, NULL, 1, 1, NULL, '2026-05-31 16:14:25'),
(16, 'Bases Rashid', 'BasesRashid', 'abuabdillahrashid12@gmail.com', 'BasesRashid', '$2y$10$VhrOMLdAjfiH9aqwChMolOmK/onEi.3wuaTUWV7GYzpjaTWQUOOwO', 'user', NULL, NULL, 1, 1, NULL, '2026-06-01 20:27:12'),
(17, 'Sudeis', 'Mustapha', 'sudeismustpha283@gmail.com', '676636434', '$2y$10$uNNzIojV.EvsgWwBFl0vR.7vm2AdaOEc7TSooFsqaaALXJnMXXwoi', 'user', NULL, NULL, 1, 1, NULL, '2026-06-02 19:08:36'),
(18, 'OMARY RAJABU BACHIE', 'UMAR', 'pembafashion5@gmail.com', '0793347628', '$2y$10$OUnKLm8e6yl1ohHqhNbEF.OP0CCpakHqkDXtMr.F3al6OnubDUszC', 'user', NULL, NULL, 1, 1, NULL, '2026-06-14 11:08:53');

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_logs`
--

CREATE TABLE `user_activity_logs` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_activity_logs`
--

INSERT INTO `user_activity_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(0, 12, 'logout', 'User logged out', '197.186.2.205', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-16 21:08:48'),
(1, 2, 'login', 'User logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-22 18:19:30'),
(2, 2, 'admin_login', 'Admin logged in', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/149.0', '2026-04-23 06:50:55'),
(3, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-23 06:53:08'),
(4, 1, 'login', 'User logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-23 06:53:17'),
(5, 6, 'login', 'User logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-23 11:55:36'),
(6, 2, 'admin_login', 'Admin logged in', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/149.0', '2026-04-23 17:08:46'),
(7, 2, 'logout', 'User logged out', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/149.0', '2026-04-23 17:09:38'),
(8, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-23 17:13:28'),
(9, 2, 'admin_login', 'Admin logged in', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/149.0', '2026-04-23 17:16:09'),
(10, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-23 17:24:41'),
(11, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-23 17:25:16'),
(12, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-24 06:04:24'),
(13, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 06:17:36'),
(14, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-24 06:31:04'),
(15, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-24 13:14:30'),
(16, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-25 01:31:06'),
(17, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-25 04:09:21'),
(18, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-25 04:30:33'),
(19, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-25 04:50:45'),
(20, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-25 05:00:16'),
(21, 10, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-25 05:13:53'),
(22, 10, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-25 05:14:42'),
(23, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-25 05:43:03'),
(24, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-26 08:28:48'),
(25, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-26 16:52:58'),
(26, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-27 04:40:00'),
(27, 7, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-27 05:12:06'),
(28, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-27 05:14:15'),
(29, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-27 08:27:42'),
(30, 7, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-04-27 08:44:36'),
(31, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-04-27 08:45:49'),
(32, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-04-27 09:34:47'),
(33, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-27 09:46:24'),
(34, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-04-27 09:55:50'),
(35, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-27 16:30:21'),
(36, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-27 18:13:01'),
(37, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-27 18:15:20'),
(38, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-27 19:04:39'),
(39, 7, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-27 19:06:38'),
(40, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-04 11:01:51'),
(41, 2, 'impersonate', 'Admin impersonated user ID 9 (De-coder057)', '::1', NULL, '2026-05-04 11:02:12'),
(42, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-06 04:05:52'),
(43, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 04:06:39'),
(44, 2, 'admin_logout', 'Admin logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-06 04:15:05'),
(45, 2, 'admin_login', 'Admin logged in', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:150.0) Gecko/20100101 Firefox/150.0', '2026-05-06 04:20:14'),
(46, 7, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-06 08:29:40'),
(47, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-06 08:36:24'),
(48, 10, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Linux; Android 16; Xiaomi 12) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.6778.260 Mobile Safari/537.36', '2026-05-06 09:22:59'),
(49, 6, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Linux; Android 16; Xiaomi 12) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.6778.260 Mobile Safari/537.36', '2026-05-06 09:23:18'),
(50, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-07 03:28:59'),
(51, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-07 04:43:54'),
(52, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 11:37:16'),
(53, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 19:52:44'),
(54, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-08 20:26:27'),
(55, 2, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-09 12:45:14'),
(56, 2, 'admin_login', 'Admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:04:05'),
(57, 7, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-10 04:16:17'),
(58, 7, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-10 06:43:17'),
(59, 7, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-10 09:46:23'),
(60, 6, 'logout', 'User logged out', '197.186.0.229', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 12:01:58'),
(61, 6, 'logout', 'User logged out', '197.186.0.229', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 12:28:33'),
(62, 6, 'logout', 'User logged out', '197.186.0.229', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', '2026-05-10 12:47:01'),
(63, 6, 'logout', 'User logged out', '197.186.0.229', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-10 13:33:44'),
(64, 6, 'logout', 'User logged out', '197.186.0.229', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-10 19:04:03'),
(65, 2, 'admin_login', 'Admin logged in', '197.186.0.229', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 19:08:34'),
(66, 6, 'logout', 'User logged out', '197.186.3.28', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-11 06:08:58'),
(67, 11, 'logout', 'User logged out', '154.74.192.108', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-11 07:20:23'),
(68, 6, 'logout', 'User logged out', '197.186.3.28', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-11 12:46:43'),
(69, 6, 'logout', 'User logged out', '197.186.3.28', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-11 14:09:44'),
(70, 6, 'logout', 'User logged out', '197.186.3.28', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-12 14:01:08'),
(71, 2, 'admin_login', 'Admin logged in', '197.186.3.28', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-12 14:09:44'),
(72, 2, 'admin_login', 'Admin logged in', '197.186.3.28', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:150.0) Gecko/20100101 Firefox/150.0', '2026-05-12 14:16:49'),
(73, 2, 'impersonate', 'Admin impersonated user ID 10 (salim ahmed)', '197.186.3.28', NULL, '2026-05-12 14:23:04'),
(74, 2, 'impersonate', 'Admin impersonated user ID 10 (salim ahmed)', '197.186.3.28', NULL, '2026-05-12 14:24:26'),
(75, 2, 'admin_login', 'Admin logged in', '197.186.3.28', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:150.0) Gecko/20100101 Firefox/150.0', '2026-05-12 14:24:44'),
(76, 2, 'impersonate', 'Admin impersonated user ID 10 (salim ahmed)', '197.186.3.28', NULL, '2026-05-12 14:25:03'),
(77, 2, 'admin_login', 'Admin logged in', '197.186.3.28', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-12 14:26:16'),
(78, 2, 'logout', 'User logged out', '197.186.3.28', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-12 14:29:45'),
(79, 2, 'admin_login', 'Admin logged in', '197.186.1.52', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-13 14:39:21'),
(80, 14, 'logout', 'User logged out', '197.186.1.52', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-13 14:39:34'),
(81, 2, 'logout', 'User logged out', '197.186.1.52', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-13 14:40:02');

-- --------------------------------------------------------

--
-- Table structure for table `user_content_progress`
--

CREATE TABLE `user_content_progress` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `content_id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `is_started` tinyint(1) NOT NULL DEFAULT 0,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `current_part` int(11) NOT NULL DEFAULT 1,
  `progress_percent` int(11) NOT NULL DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `last_accessed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `time_spent_seconds` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_content_progress`
--

INSERT INTO `user_content_progress` (`id`, `user_id`, `content_id`, `subject_id`, `is_started`, `is_completed`, `current_part`, `progress_percent`, `started_at`, `last_accessed_at`, `completed_at`, `created_at`, `updated_at`, `time_spent_seconds`) VALUES
(0, 12, 32, 2, 1, 0, 1, 0, '2026-08-03 13:54:55', '2026-08-03 13:54:55', NULL, '2026-08-03 20:54:55', '2026-08-03 20:54:55', 0),
(0, 12, 33, 3, 1, 0, 2, 0, '2026-08-12 12:27:27', '2026-08-12 12:42:37', NULL, '2026-08-12 19:27:27', '2026-08-12 19:42:37', 0),
(0, 12, 31, 2, 1, 1, 1, 100, '2026-08-12 12:28:33', '2026-08-23 01:19:07', '2026-08-12 12:29:12', '2026-08-12 19:28:33', '2026-08-23 08:19:07', 0),
(0, 12, 21, 2, 1, 1, 1, 100, '2026-08-12 12:29:33', '2026-08-25 04:54:36', '2026-08-21 16:34:18', '2026-08-12 19:29:33', '2026-08-25 11:54:36', 0),
(1, 12, 22, 2, 1, 1, 1, 100, '2026-08-23 01:19:17', '2026-08-23 01:19:21', '2026-08-23 01:19:21', '2026-08-23 08:19:17', '2026-08-23 08:19:21', 0),
(2, 12, 23, 2, 1, 1, 1, 100, '2026-08-23 01:19:23', '2026-08-23 01:19:26', '2026-08-23 01:19:26', '2026-08-23 08:19:23', '2026-08-23 08:19:26', 0),
(3, 12, 24, 2, 1, 1, 1, 100, '2026-08-23 01:19:27', '2026-08-23 01:19:30', '2026-08-23 01:19:30', '2026-08-23 08:19:27', '2026-08-23 08:19:30', 0),
(4, 12, 25, 2, 1, 1, 1, 100, '2026-08-23 01:19:32', '2026-08-23 01:19:35', '2026-08-23 01:19:35', '2026-08-23 08:19:32', '2026-08-23 08:19:35', 0),
(5, 12, 26, 2, 1, 0, 1, 0, '2026-08-23 01:19:36', '2026-08-23 01:40:19', NULL, '2026-08-23 08:19:36', '2026-08-23 08:40:19', 0),
(6, 12, 85, 1, 1, 1, 1, 100, '2026-08-23 21:53:14', '2026-08-24 09:40:18', '2026-08-24 09:32:29', '2026-08-24 04:53:14', '2026-08-24 16:40:18', 0),
(7, 12, 86, 1, 1, 1, 1, 100, '2026-08-24 05:51:56', '2026-08-24 11:26:31', '2026-08-24 05:56:30', '2026-08-24 12:51:56', '2026-08-24 18:26:31', 0),
(8, 12, 87, 1, 1, 1, 1, 100, '2026-08-24 09:32:31', '2026-08-24 09:40:10', '2026-08-24 09:39:44', '2026-08-24 16:32:31', '2026-08-24 16:40:10', 0),
(9, 12, 88, 1, 1, 0, 1, 0, '2026-08-24 09:40:22', '2026-08-24 11:25:48', NULL, '2026-08-24 16:40:22', '2026-08-24 18:25:48', 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_material_progress`
--

CREATE TABLE `user_material_progress` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `last_viewed_at` datetime DEFAULT NULL,
  `view_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_material_progress`
--

INSERT INTO `user_material_progress` (`id`, `user_id`, `material_id`, `last_viewed_at`, `view_count`, `created_at`, `updated_at`) VALUES
(1, 11, 1, '2026-05-16 15:49:12', 13, '2026-05-16 21:14:41', '2026-05-16 22:49:12'),
(2, 12, 1, '2026-05-16 15:55:33', 12, '2026-05-16 21:15:45', '2026-05-16 22:55:33'),
(26, 12, 2, '2026-05-16 16:20:15', 81, '2026-05-16 22:57:01', '2026-05-16 23:20:15'),
(107, 11, 2, '2026-05-16 16:21:02', 1, '2026-05-16 23:21:02', '2026-05-16 23:21:02'),
(108, 12, 3, '2026-05-16 16:27:41', 2, '2026-05-16 23:24:19', '2026-05-16 23:27:41'),
(110, 12, 4, '2026-05-16 16:31:31', 2, '2026-05-16 23:29:24', '2026-05-16 23:31:31'),
(112, 12, 5, '2026-05-16 18:13:48', 16, '2026-05-16 23:35:18', '2026-05-17 01:13:48'),
(128, 11, 5, '2026-05-16 18:22:32', 24, '2026-05-17 01:15:03', '2026-05-17 01:22:32'),
(151, 12, 6, '2026-08-23 07:22:18', 19, '2026-05-17 01:17:08', '2026-08-23 14:22:18'),
(161, 11, 6, '2026-05-18 16:59:47', 4, '2026-05-17 01:22:42', '2026-05-18 23:59:47'),
(167, 12, 7, '2026-08-23 20:39:03', 5, '2026-05-17 01:31:55', '2026-08-24 03:39:03'),
(173, 12, 8, '2026-05-17 19:03:07', 6, '2026-05-17 15:05:01', '2026-05-18 02:03:07'),
(183, 12, 9, '2026-05-30 18:01:52', 4, '2026-05-19 00:51:19', '2026-05-31 01:01:52'),
(186, 12, 10, '2026-07-24 09:25:42', 16, '2026-05-19 14:34:21', '2026-07-24 16:25:42'),
(199, 15, 10, '2026-05-31 09:19:47', 1, '2026-05-31 16:19:47', '2026-05-31 16:19:47'),
(203, 12, 11, '2026-06-28 00:51:13', 3, '2026-06-20 07:43:43', '2026-06-28 07:51:13'),
(210, 12, 14, '2026-08-24 11:33:37', 3, '2026-08-24 03:48:02', '2026-08-24 18:33:37'),
(211, 12, 17, '2026-08-24 09:57:49', 2, '2026-08-24 04:05:08', '2026-08-24 16:57:49');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `preferred_language` varchar(10) DEFAULT 'EN',
  `theme_preference` varchar(20) DEFAULT 'light',
  `email_notifications` tinyint(1) DEFAULT 1,
  `reminder_notifications` tinyint(1) DEFAULT 1,
  `leaderboard_visibility` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`id`, `user_id`, `preferred_language`, `theme_preference`, `email_notifications`, `reminder_notifications`, `leaderboard_visibility`, `updated_at`) VALUES
(1, 1, 'EN', 'light', 1, 1, 1, '2026-04-23 11:42:02'),
(3, 6, 'EN', 'light', 1, 1, 1, '2026-04-27 09:34:41'),
(4, 7, 'EN', 'light', 1, 1, 1, '2026-05-09 13:39:48');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_settings`
--
ALTER TABLE `admin_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `is_published` (`is_published`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `actor_user_id` (`actor_user_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `category_materials`
--
ALTER TABLE `category_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `certificate_code` (`certificate_code`),
  ADD UNIQUE KEY `uniq_user_certificate` (`user_id`,`certification_type`,`subject_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_subject_id` (`subject_id`),
  ADD KEY `idx_certificate_code` (`certificate_code`);

--
-- Indexes for table `certification_exams`
--
ALTER TABLE `certification_exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `certification_exam_attempts`
--
ALTER TABLE `certification_exam_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `exam_id` (`exam_id`);

--
-- Indexes for table `certification_exam_questions`
--
ALTER TABLE `certification_exam_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`);

--
-- Indexes for table `certification_exam_question_options`
--
ALTER TABLE `certification_exam_question_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `contents`
--
ALTER TABLE `contents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `content_parts`
--
ALTER TABLE `content_parts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_part` (`content_id`,`part_number`),
  ADD KEY `content_id` (`content_id`);

--
-- Indexes for table `help_articles`
--
ALTER TABLE `help_articles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `help_categories`
--
ALTER TABLE `help_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `leaderboard_stats`
--
ALTER TABLE `leaderboard_stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_mode` (`user_id`,`mode`),
  ADD KEY `idx_mode_rank` (`mode`,`rank_position`),
  ADD KEY `idx_mode_points` (`mode`,`total_points`);

--
-- Indexes for table `media`
--
ALTER TABLE `media`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `media_type` (`media_type`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `media_categories`
--
ALTER TABLE `media_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `media_category_relations`
--
ALTER TABLE `media_category_relations`
  ADD PRIMARY KEY (`media_id`,`category_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `media_downloads`
--
ALTER TABLE `media_downloads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `media_id` (`media_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `media_tags`
--
ALTER TABLE `media_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `media_tag_relations`
--
ALTER TABLE `media_tag_relations`
  ADD PRIMARY KEY (`media_id`,`tag_id`),
  ADD KEY `tag_id` (`tag_id`);

--
-- Indexes for table `media_views`
--
ALTER TABLE `media_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `media_id` (`media_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `monthly_exams`
--
ALTER TABLE `monthly_exams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `monthly_exam_attempts`
--
ALTER TABLE `monthly_exam_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_exam_attempt_user_exam_attemptno` (`user_id`,`exam_id`,`attempt_number`),
  ADD KEY `exam_id` (`exam_id`);

--
-- Indexes for table `monthly_exam_options`
--
ALTER TABLE `monthly_exam_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `monthly_exam_questions`
--
ALTER TABLE `monthly_exam_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`);

--
-- Indexes for table `monthly_exam_question_explanations`
--
ALTER TABLE `monthly_exam_question_explanations`
  ADD PRIMARY KEY (`question_id`);

--
-- Indexes for table `monthly_exam_question_options`
--
ALTER TABLE `monthly_exam_question_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `type` (`type`);

--
-- Indexes for table `part_sections`
--
ALTER TABLE `part_sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `part_id` (`part_id`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_quizzes_slug` (`slug`),
  ADD KEY `idx_quizzes_subject_id` (`subject_id`),
  ADD KEY `idx_quizzes_category_id` (`category_id`),
  ADD KEY `idx_quizzes_content_id` (`content_id`),
  ADD KEY `idx_quizzes_mode` (`mode`),
  ADD KEY `idx_quizzes_status` (`status`);

--
-- Indexes for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_quiz_attempt_user_quiz_attemptno` (`user_id`,`quiz_id`,`attempt_number`),
  ADD KEY `idx_quiz_attempts_user_id` (`user_id`),
  ADD KEY `idx_quiz_attempts_quiz_id` (`quiz_id`),
  ADD KEY `idx_quiz_attempts_status` (`status`),
  ADD KEY `idx_quiz_attempts_completed_at` (`completed_at`);

--
-- Indexes for table `quiz_attempt_answers`
--
ALTER TABLE `quiz_attempt_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `attempt_id` (`attempt_id`),
  ADD KEY `question_id` (`question_id`),
  ADD KEY `is_correct` (`is_correct`);

--
-- Indexes for table `quiz_options`
--
ALTER TABLE `quiz_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_quiz_options_question_id` (`question_id`),
  ADD KEY `idx_quiz_options_is_correct` (`is_correct`),
  ADD KEY `idx_quiz_options_sort_order` (`sort_order`);

--
-- Indexes for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_quiz_questions_quiz_id` (`quiz_id`),
  ADD KEY `idx_quiz_questions_sort_order` (`sort_order`),
  ADD KEY `monthly_exam_id` (`monthly_exam_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_material_progress`
--
ALTER TABLE `user_material_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_material_unique` (`user_id`,`material_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `material_id` (`material_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `category_materials`
--
ALTER TABLE `category_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `contents`
--
ALTER TABLE `contents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=89;

--
-- AUTO_INCREMENT for table `content_parts`
--
ALTER TABLE `content_parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=401;

--
-- AUTO_INCREMENT for table `part_sections`
--
ALTER TABLE `part_sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=171;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `quiz_options`
--
ALTER TABLE `quiz_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=747;

--
-- AUTO_INCREMENT for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=361;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `user_material_progress`
--
ALTER TABLE `user_material_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=215;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

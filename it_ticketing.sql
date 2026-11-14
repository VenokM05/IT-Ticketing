-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 09, 2026 at 05:13 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `it_ticketing`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `actor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `event` varchar(255) NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) UNSIGNED DEFAULT NULL,
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `actor_id`, `event`, `subject_type`, `subject_id`, `properties`, `created_at`) VALUES
(1, 1, 'branding.title_updated', NULL, NULL, '{\"title\":\"Laravels\"}', '2026-10-27 02:02:36'),
(2, 1, 'branding.title_updated', NULL, NULL, '{\"title\":\"IT Helpdesk\"}', '2026-10-27 02:02:54'),
(3, 1, 'ticket.status_changed', 'App\\Models\\Ticket', 2, '{\"from\":\"assigned\",\"to\":\"in_progress\",\"remark\":null}', '2026-10-29 22:28:35'),
(4, 1, 'ticket.status_changed', 'App\\Models\\Ticket', 2, '{\"from\":\"in_progress\",\"to\":\"resolved\",\"remark\":null}', '2026-10-29 22:28:43');

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cache`
--

INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('356a192b7913b04c54574d18c28d46e6395428ab', 'i:1;', 1793341609),
('356a192b7913b04c54574d18c28d46e6395428ab:timer', 'i:1793341609;', 1793341609),
('424f74a6a7ed4d4ed4761507ebcd209a6ef0937b', 'i:1;', 1793331066),
('424f74a6a7ed4d4ed4761507ebcd209a6ef0937b:timer', 'i:1793331066;', 1793331066),
('5c785c036466adea360111aa28563bfd556b5fba', 'i:2;', 1793153164),
('5c785c036466adea360111aa28563bfd556b5fba:timer', 'i:1793153164;', 1793153164),
('problem-tally:9e957681ae4e6dc2f50235b8b13a897e', 'a:8:{s:5:\"weeks\";i:8;s:5:\"start\";s:10:\"2026-09-14\";s:3:\"end\";s:10:\"2026-11-02\";s:6:\"totals\";a:4:{s:7:\"tickets\";i:1;s:10:\"categories\";i:1;s:9:\"recurring\";i:0;s:11:\"repeat_rate\";d:0;}s:10:\"categories\";a:1:{i:0;a:13:{s:3:\"key\";s:7:\"network\";s:5:\"label\";s:7:\"Network\";s:5:\"count\";i:1;s:4:\"open\";i:1;s:8:\"resolved\";i:1;s:5:\"share\";d:100;s:9:\"this_week\";i:0;s:9:\"last_week\";i:1;s:5:\"delta\";i:-1;s:5:\"trend\";a:8:{i:0;a:2:{s:4:\"week\";s:10:\"2026-09-14\";s:5:\"count\";i:0;}i:1;a:2:{s:4:\"week\";s:10:\"2026-09-21\";s:5:\"count\";i:0;}i:2;a:2:{s:4:\"week\";s:10:\"2026-09-28\";s:5:\"count\";i:0;}i:3;a:2:{s:4:\"week\";s:10:\"2026-10-05\";s:5:\"count\";i:0;}i:4;a:2:{s:4:\"week\";s:10:\"2026-10-12\";s:5:\"count\";i:0;}i:5;a:2:{s:4:\"week\";s:10:\"2026-10-19\";s:5:\"count\";i:0;}i:6;a:2:{s:4:\"week\";s:10:\"2026-10-26\";s:5:\"count\";i:1;}i:7;a:2:{s:4:\"week\";s:10:\"2026-11-02\";s:5:\"count\";i:0;}}s:11:\"by_priority\";a:1:{i:0;a:3:{s:3:\"key\";s:6:\"medium\";s:5:\"label\";s:6:\"Medium\";s:5:\"count\";i:1;}}s:12:\"top_priority\";a:3:{s:3:\"key\";s:6:\"medium\";s:5:\"label\";s:6:\"Medium\";s:5:\"count\";i:1;}s:22:\"avg_resolution_minutes\";d:1;}}s:10:\"priorities\";a:1:{i:0;a:6:{s:3:\"key\";s:6:\"medium\";s:5:\"label\";s:6:\"Medium\";s:5:\"count\";i:1;s:4:\"open\";i:1;s:12:\"top_category\";s:7:\"Network\";s:22:\"avg_resolution_minutes\";d:1;}}s:9:\"recurring\";a:0:{}s:7:\"related\";a:0:{}}', 1793673928),
('problem-tally:a1bfcf9ab4dbc3b3e9cc9303d028e011', 'a:8:{s:5:\"weeks\";i:8;s:5:\"start\";s:10:\"2026-09-14\";s:3:\"end\";s:10:\"2026-11-02\";s:6:\"totals\";a:4:{s:7:\"tickets\";i:1;s:10:\"categories\";i:1;s:9:\"recurring\";i:0;s:11:\"repeat_rate\";d:0;}s:10:\"categories\";a:1:{i:0;a:13:{s:3:\"key\";s:7:\"network\";s:5:\"label\";s:7:\"Network\";s:5:\"count\";i:1;s:4:\"open\";i:1;s:8:\"resolved\";i:1;s:5:\"share\";d:100;s:9:\"this_week\";i:0;s:9:\"last_week\";i:1;s:5:\"delta\";i:-1;s:5:\"trend\";a:8:{i:0;a:2:{s:4:\"week\";s:10:\"2026-09-14\";s:5:\"count\";i:0;}i:1;a:2:{s:4:\"week\";s:10:\"2026-09-21\";s:5:\"count\";i:0;}i:2;a:2:{s:4:\"week\";s:10:\"2026-09-28\";s:5:\"count\";i:0;}i:3;a:2:{s:4:\"week\";s:10:\"2026-10-05\";s:5:\"count\";i:0;}i:4;a:2:{s:4:\"week\";s:10:\"2026-10-12\";s:5:\"count\";i:0;}i:5;a:2:{s:4:\"week\";s:10:\"2026-10-19\";s:5:\"count\";i:0;}i:6;a:2:{s:4:\"week\";s:10:\"2026-10-26\";s:5:\"count\";i:1;}i:7;a:2:{s:4:\"week\";s:10:\"2026-11-02\";s:5:\"count\";i:0;}}s:11:\"by_priority\";a:1:{i:0;a:3:{s:3:\"key\";s:6:\"medium\";s:5:\"label\";s:6:\"Medium\";s:5:\"count\";i:1;}}s:12:\"top_priority\";a:3:{s:3:\"key\";s:6:\"medium\";s:5:\"label\";s:6:\"Medium\";s:5:\"count\";i:1;}s:22:\"avg_resolution_minutes\";d:1;}}s:10:\"priorities\";a:1:{i:0;a:6:{s:3:\"key\";s:6:\"medium\";s:5:\"label\";s:6:\"Medium\";s:5:\"count\";i:1;s:4:\"open\";i:1;s:12:\"top_category\";s:7:\"Network\";s:22:\"avg_resolution_minutes\";d:1;}}s:9:\"recurring\";a:0:{}s:7:\"related\";a:0:{}}', 1793840806),
('public-ticket:::1', 'i:1;', 1793341721),
('public-ticket:::1:timer', 'i:1793341721;', 1793341721);

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `canned_responses`
--

CREATE TABLE `canned_responses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `category_key` varchar(40) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `key`, `label`, `is_active`, `sort`, `created_at`, `updated_at`) VALUES
(1, 'hardware', 'Hardware', 1, 1, NULL, NULL),
(2, 'software', 'Software', 1, 2, NULL, NULL),
(3, 'network', 'Network', 1, 3, NULL, NULL),
(4, 'printer', 'Printer', 1, 4, NULL, NULL),
(5, 'access', 'Access/Permissions', 1, 5, NULL, NULL),
(6, 'general', 'General IT Support', 1, 6, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(16) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `name`, `code`, `email`, `location`, `logo_path`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'One Source Facility Services Corporation', 'OSFSC', 'it@onesourcefacility.example', 'Cebu City', NULL, 1, '2026-10-26 22:37:16', '2026-10-26 22:37:16'),
(2, 'Staunch Workforce Corporation', 'SWC', 'it@staunchworkforce.example', 'Cebu City', NULL, 1, '2026-10-26 22:37:16', '2026-10-26 22:37:16'),
(3, 'Sendiva Logistics Corporation', 'SLC', 'it@sendivalogistics.example', 'Cebu City', NULL, 1, '2026-10-26 22:37:17', '2026-10-26 22:37:17');

-- --------------------------------------------------------

--
-- Table structure for table `dashboard_notifications`
--

CREATE TABLE `dashboard_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `ticket_id` bigint(20) UNSIGNED DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `ref_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dashboard_notifications`
--

INSERT INTO `dashboard_notifications` (`id`, `user_id`, `ticket_id`, `type`, `ref_id`, `title`, `message`, `data`, `read_at`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, 'new_ticket', NULL, 'New ticket IT-2026-000001', 'Throwaway receipt render check', NULL, '2026-10-29 21:58:10', '2026-10-27 18:06:15', '2026-10-29 21:58:10'),
(2, 1, 2, 'new_ticket', NULL, 'New ticket IT-2026-000002', 'Network Problem', NULL, NULL, '2026-10-29 22:27:41', '2026-10-29 22:27:41'),
(3, 2, 2, 'ticket_assigned', NULL, 'Assigned: IT-2026-000002', 'Network Problem', NULL, NULL, '2026-10-29 22:27:42', '2026-10-29 22:27:42'),
(4, 1, 2, 'status_changed', 3, 'IT-2026-000002 → In Progress', 'Status changed to In Progress', '{\"from\":\"assigned\",\"to\":\"in_progress\"}', NULL, '2026-10-29 22:28:34', '2026-10-29 22:28:34'),
(5, 1, 2, 'status_changed', 4, 'IT-2026-000002 → Resolved', 'Status changed to Resolved', '{\"from\":\"in_progress\",\"to\":\"resolved\"}', NULL, '2026-10-29 22:28:43', '2026-10-29 22:28:43');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `whatsapp_number` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `department_id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_tokens`
--

CREATE TABLE `employee_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `token` varchar(64) NOT NULL,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employee_tokens`
--

INSERT INTO `employee_tokens` (`id`, `token`, `ticket_id`, `expires_at`, `last_used_at`, `created_at`, `updated_at`) VALUES
(1, '9c50872d6142551f1f3d1cb33a98bb25a208589ed21b568a9c2f9c3447e34971', 2, '2026-11-28 22:27:42', NULL, '2026-10-29 22:27:42', '2026-10-29 22:27:42');

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kb_articles`
--

CREATE TABLE `kb_articles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `category_key` varchar(40) DEFAULT NULL,
  `published` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_10_26_000001_create_departments_table', 1),
(5, '2026_10_26_000002_add_role_to_users_table', 1),
(6, '2026_10_26_000003_create_employees_table', 1),
(7, '2026_10_26_000004_create_ticket_number_sequences_table', 1),
(8, '2026_10_26_000005_create_tickets_table', 1),
(9, '2026_10_26_000006_create_ticket_messages_table', 1),
(10, '2026_10_26_000007_create_ticket_attachments_table', 1),
(11, '2026_10_26_000008_create_ticket_progress_updates_table', 1),
(12, '2026_10_26_000009_create_ticket_progress_reports_table', 1),
(13, '2026_10_26_000010_create_ticket_status_histories_table', 1),
(14, '2026_10_26_000011_create_dashboard_notifications_table', 1),
(15, '2026_10_26_000012_create_whatsapp_webhook_events_table', 1),
(16, '2026_10_26_000013_create_whatsapp_templates_table', 1),
(17, '2026_10_26_000014_create_relink_requests_table', 1),
(18, '2026_10_27_000001_create_companies_table', 1),
(19, '2026_10_27_000002_add_company_support', 1),
(20, '2026_10_27_000003_allow_public_ticket_requests', 1),
(21, '2026_10_27_000004_add_anydesk_id_to_tickets_table', 1),
(22, '2026_10_27_000005_create_system_settings_table', 1),
(23, '2026_11_01_000001_add_sla_deadlines_to_tickets_table', 2),
(24, '2026_11_02_000001_add_is_on_call_to_users_table', 3),
(25, '2026_11_02_000002_create_technician_skills_table', 3),
(26, '2026_11_03_000001_create_canned_responses_table', 4),
(27, '2026_11_05_000001_create_saved_filters_table', 5),
(28, '2026_11_06_000001_add_csat_to_tickets_table', 6),
(29, '2026_11_07_000001_add_notify_preferences_to_users_table', 7),
(30, '2026_11_08_000001_create_activity_log_table', 8),
(31, '2026_11_09_000001_create_categories_and_priorities_tables', 9),
(32, '2026_11_09_000002_add_lookup_ids_to_tickets_table', 9),
(33, '2026_11_10_000001_create_kb_articles_table', 9),
(34, '2026_11_10_000002_create_employee_tokens_table', 9),
(35, '2026_11_11_000001_add_logo_to_companies_table', 10),
(36, '2026_11_05_000001_add_requester_email_to_tickets_table', 11);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `priorities`
--

CREATE TABLE `priorities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `sla_response_hours` int(10) UNSIGNED DEFAULT NULL,
  `sla_resolve_hours` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `priorities`
--

INSERT INTO `priorities` (`id`, `key`, `label`, `sla_response_hours`, `sla_resolve_hours`, `is_active`, `sort`, `created_at`, `updated_at`) VALUES
(1, 'critical', 'Critical', 1, 4, 1, 1, NULL, NULL),
(2, 'high', 'High', 4, 8, 1, 2, NULL, NULL),
(3, 'medium', 'Medium', 8, 24, 1, 3, NULL, NULL),
(4, 'low', 'Low', 24, 72, 1, 4, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `relink_requests`
--

CREATE TABLE `relink_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `requested_number` varchar(255) NOT NULL,
  `employee_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `decided_by` bigint(20) UNSIGNED DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `source_message_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saved_filters`
--

CREATE TABLE `saved_filters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('IR6W12HcM07DboFbFjrMADz69WZp6fEKgTDCyVhN', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', 'YTo1OntzOjY6Il90b2tlbiI7czo0MDoiSU9FaHZ5ZExQNjJsMHBmdmM5WWVHQUczQ3c2Vm1sdG1yRVpoOTJjZyI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6NTA6Imh0dHA6Ly9sb2NhbGhvc3QvaXQtdGlja2V0aW5nL2FwcC9wdWJsaWMvZGFzaGJvYXJkIjtzOjU6InJvdXRlIjtzOjk6ImRhc2hib2FyZCI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fXM6NTA6ImxvZ2luX3dlYl81OWJhMzZhZGRjMmIyZjk0MDE1ODBmMDE0YzdmNThlYTRlMzA5ODlkIjtpOjE7czoxNzoicGFzc3dvcmRfaGFzaF93ZWIiO3M6NjQ6ImU5ZGE0ZWMyMTkyN2VmNmUyYTdiNTNhNWE0MjIzMGY2Yjg2YzMzYTlkOWEzMGQyZDI5NGY3YjhiNzhkMDEwMjAiO30=', 1793843571);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `key`, `value`, `created_at`, `updated_at`) VALUES
(1, 'branding.title', 'eyJpdiI6IkpoWk5pZWtwdlhlWi9NbkVkU1Qvcmc9PSIsInZhbHVlIjoiS3dGVC9oMGU3N05pYUI1S1E1OUtoZz09IiwibWFjIjoiOGMyN2FkOWQ2ZGEzNzE0OTIxMTEwNmQ4ODAzYmUwNDY1ODNiZWM5ZjllNjVjOTljMWMzMzYxZjA0YmE2ZGU0NiIsInRhZyI6IiJ9', '2026-10-27 02:02:36', '2026-10-27 02:02:54');

-- --------------------------------------------------------

--
-- Table structure for table `technician_skills`
--

CREATE TABLE `technician_skills` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `category_key` varchar(40) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ticket_number` varchar(255) NOT NULL,
  `employee_id` bigint(20) UNSIGNED DEFAULT NULL,
  `requester_name` varchar(255) DEFAULT NULL,
  `requester_contact` varchar(255) DEFAULT NULL,
  `requester_company` varchar(255) DEFAULT NULL,
  `requester_department` varchar(255) DEFAULT NULL,
  `requester_position` varchar(255) DEFAULT NULL,
  `requester_email` varchar(255) DEFAULT NULL,
  `anydesk_id` varchar(60) DEFAULT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_to` bigint(20) UNSIGNED DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `priority_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'new',
  `first_response_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `response_due_at` timestamp NULL DEFAULT NULL,
  `resolve_due_at` timestamp NULL DEFAULT NULL,
  `rating` tinyint(3) UNSIGNED DEFAULT NULL,
  `rating_comment` text DEFAULT NULL,
  `rated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `ticket_number`, `employee_id`, `requester_name`, `requester_contact`, `requester_company`, `requester_department`, `requester_position`, `requester_email`, `anydesk_id`, `company_id`, `assigned_to`, `subject`, `description`, `category_id`, `priority_id`, `status`, `first_response_at`, `started_at`, `resolved_at`, `closed_at`, `response_due_at`, `resolve_due_at`, `rating`, `rating_comment`, `rated_at`, `created_at`, `updated_at`) VALUES
(2, 'IT-2026-000002', NULL, 'Elvin Manuel', '09244426231', NULL, 'IT', 'IT Admin', NULL, NULL, 1, 2, 'Network Problem', 'Sample', 3, 3, 'resolved', '2026-10-29 22:28:34', '2026-10-29 22:28:34', '2026-10-29 22:28:43', NULL, '2026-10-30 06:27:41', '2026-10-30 22:27:41', NULL, NULL, NULL, '2026-10-29 22:27:41', '2026-10-29 22:28:43');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_attachments`
--

CREATE TABLE `ticket_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `ticket_message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `uploaded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT NULL,
  `storage_path` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_messages`
--

CREATE TABLE `ticket_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `direction` varchar(255) NOT NULL,
  `channel` varchar(255) NOT NULL DEFAULT 'whatsapp',
  `message_type` varchar(255) NOT NULL DEFAULT 'text',
  `body` text DEFAULT NULL,
  `whatsapp_message_id` varchar(255) DEFAULT NULL,
  `delivery_status` varchar(255) DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_number_sequences`
--

CREATE TABLE `ticket_number_sequences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `year` smallint(5) UNSIGNED NOT NULL,
  `next_value` bigint(20) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_number_sequences`
--

INSERT INTO `ticket_number_sequences` (`id`, `year`, `next_value`, `created_at`, `updated_at`) VALUES
(1, 2026, 3, '2026-10-27 18:06:14', '2026-10-29 22:27:41');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_progress_reports`
--

CREATE TABLE `ticket_progress_reports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `prepared_by` bigint(20) UNSIGNED DEFAULT NULL,
  `report_number` varchar(255) NOT NULL,
  `report_type` varchar(255) NOT NULL DEFAULT 'initial',
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `title` varchar(255) DEFAULT NULL,
  `problem_summary` text DEFAULT NULL,
  `work_performed` text DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `next_steps` text DEFAULT NULL,
  `reported_at` timestamp NULL DEFAULT NULL,
  `finalized_at` timestamp NULL DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_progress_reports`
--

INSERT INTO `ticket_progress_reports` (`id`, `ticket_id`, `prepared_by`, `report_number`, `report_type`, `status`, `title`, `problem_summary`, `work_performed`, `findings`, `next_steps`, `reported_at`, `finalized_at`, `document_path`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 'INIT-IT-2026-000002', 'initial', 'final', 'Network Problem', 'Sample', NULL, 'Network cable unplug', 'Plug to Network Port', NULL, '2026-10-29 22:46:49', NULL, '2026-10-29 22:28:34', '2026-10-29 22:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_progress_updates`
--

CREATE TABLE `ticket_progress_updates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `work_performed` text DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `update_text` text DEFAULT NULL,
  `next_action` varchar(255) DEFAULT NULL,
  `minutes_spent` int(10) UNSIGNED DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_progress_updates`
--

INSERT INTO `ticket_progress_updates` (`id`, `ticket_id`, `user_id`, `work_performed`, `findings`, `update_text`, `next_action`, `minutes_spent`, `is_internal`, `created_at`, `updated_at`) VALUES
(1, 2, 1, NULL, 'Network cable unplug', NULL, 'Plug to Network Port', 2, 0, '2026-10-29 22:46:49', '2026-10-29 22:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_status_histories`
--

CREATE TABLE `ticket_status_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `changed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `from_status` varchar(255) DEFAULT NULL,
  `to_status` varchar(255) NOT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_status_histories`
--

INSERT INTO `ticket_status_histories` (`id`, `ticket_id`, `changed_by`, `from_status`, `to_status`, `remark`, `created_at`, `updated_at`) VALUES
(2, 2, NULL, NULL, 'assigned', 'Ticket created and auto-assigned to One Source IT', '2026-10-29 22:27:41', '2026-10-29 22:27:41'),
(3, 2, 1, 'assigned', 'in_progress', NULL, '2026-10-29 22:28:34', '2026-10-29 22:28:34'),
(4, 2, 1, 'in_progress', 'resolved', NULL, '2026-10-29 22:28:43', '2026-10-29 22:28:43');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'technician',
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_on_call` tinyint(1) NOT NULL DEFAULT 0,
  `notify_email` tinyint(1) NOT NULL DEFAULT 1,
  `notify_whatsapp` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `role`, `company_id`, `is_active`, `is_on_call`, `notify_email`, `notify_whatsapp`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'IT Administrator', 'admin@example.com', '2026-10-26 22:37:15', '$2y$12$p.uPEaYxaA2kD0DrLUr/au9ITbdbY/dRlFbi3kprO8bd3CCSbYZ.u', 'admin', NULL, 1, 0, 1, 1, NULL, '2026-10-26 22:37:16', '2026-10-26 22:37:16'),
(2, 'One Source IT', 'it.admin@onesourcefacility.ph', '2026-10-26 22:37:16', '$2y$12$el.gt/LiOqM5xBcZrnWQ2uJDGbLxA2cUdo/KgrDotdtTpdJvRlVTW', 'technician', 1, 1, 0, 1, 1, NULL, '2026-10-26 22:37:16', '2026-10-26 22:37:16'),
(3, 'Staunch IT', 'it.admin@staunchworkforce.com.ph', '2026-10-26 22:37:16', '$2y$12$xR5qMMdc0yCnLC4rdq84V.ps58LW.3KPs9MrStVHNeJmI.15sIRKe', 'technician', 2, 1, 0, 1, 1, NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17'),
(4, 'Sendiva IT', 'it@sendivalogistics.com', '2026-10-26 22:37:17', '$2y$12$0dvZyvY7sNhXD4v6smSitubBeuj71i9zNlcgKqY5CdjOLgtdCT1J.', 'technician', 3, 1, 0, 1, 1, NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17');

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_templates`
--

CREATE TABLE `whatsapp_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `language` varchar(255) NOT NULL DEFAULT 'en',
  `category` varchar(255) NOT NULL DEFAULT 'utility',
  `approval_status` varchar(255) NOT NULL DEFAULT 'pending',
  `body` text DEFAULT NULL,
  `notification_type` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `whatsapp_templates`
--

INSERT INTO `whatsapp_templates` (`id`, `name`, `language`, `category`, `approval_status`, `body`, `notification_type`, `created_at`, `updated_at`) VALUES
(1, 'ticket_acknowledgment', 'en', 'utility', 'approved', 'Ticket {{1}} received: {{2}}', NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17'),
(2, 'unregistered_guidance', 'en', 'utility', 'approved', 'This number is not registered. Contact IT to enroll.', NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17'),
(3, 'ticket_assigned', 'en', 'utility', 'pending', 'Ticket {{1}} assigned to {{2}}', NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17'),
(4, 'ticket_in_progress', 'en', 'utility', 'pending', 'Work started on ticket {{1}}', NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17'),
(5, 'ticket_pending', 'en', 'utility', 'pending', 'Ticket {{1}} is pending more info', NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17'),
(6, 'ticket_resolved', 'en', 'utility', 'pending', 'Ticket {{1}} resolved', NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17'),
(7, 'ticket_closed', 'en', 'utility', 'pending', 'Ticket {{1}} closed', NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17'),
(8, 'ticket_status_reply', 'en', 'utility', 'pending', 'Ticket {{1}} is {{2}}', NULL, '2026-10-26 22:37:17', '2026-10-26 22:37:17');

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_webhook_events`
--

CREATE TABLE `whatsapp_webhook_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_key` varchar(255) NOT NULL,
  `event_type` varchar(255) NOT NULL,
  `processing_status` varchar(255) NOT NULL DEFAULT 'pending',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `error_message` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_log_actor_id_foreign` (`actor_id`),
  ADD KEY `activity_log_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  ADD KEY `activity_log_event_index` (`event`),
  ADD KEY `activity_log_created_at_index` (`created_at`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `canned_responses`
--
ALTER TABLE `canned_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `canned_responses_created_by_foreign` (`created_by`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `categories_key_unique` (`key`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `companies_name_unique` (`name`),
  ADD UNIQUE KEY `companies_code_unique` (`code`);

--
-- Indexes for table `dashboard_notifications`
--
ALTER TABLE `dashboard_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dashboard_notifications_user_id_type_ticket_id_ref_id_unique` (`user_id`,`type`,`ticket_id`,`ref_id`),
  ADD KEY `dashboard_notifications_ticket_id_foreign` (`ticket_id`),
  ADD KEY `dashboard_notifications_user_id_read_at_index` (`user_id`,`read_at`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `departments_name_unique` (`name`),
  ADD KEY `departments_company_id_foreign` (`company_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employees_department_id_foreign` (`department_id`),
  ADD KEY `employees_whatsapp_number_index` (`whatsapp_number`),
  ADD KEY `employees_company_id_foreign` (`company_id`);

--
-- Indexes for table `employee_tokens`
--
ALTER TABLE `employee_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_tokens_token_unique` (`token`),
  ADD KEY `employee_tokens_ticket_id_foreign` (`ticket_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `kb_articles`
--
ALTER TABLE `kb_articles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kb_articles_created_by_foreign` (`created_by`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `priorities`
--
ALTER TABLE `priorities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `priorities_key_unique` (`key`);

--
-- Indexes for table `relink_requests`
--
ALTER TABLE `relink_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `relink_requests_employee_id_foreign` (`employee_id`),
  ADD KEY `relink_requests_decided_by_foreign` (`decided_by`),
  ADD KEY `relink_requests_status_index` (`status`),
  ADD KEY `relink_requests_requested_number_index` (`requested_number`);

--
-- Indexes for table `saved_filters`
--
ALTER TABLE `saved_filters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `saved_filters_user_id_name_unique` (`user_id`,`name`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `system_settings_key_unique` (`key`);

--
-- Indexes for table `technician_skills`
--
ALTER TABLE `technician_skills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `technician_skills_user_id_category_key_unique` (`user_id`,`category_key`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tickets_ticket_number_unique` (`ticket_number`),
  ADD KEY `tickets_employee_id_foreign` (`employee_id`),
  ADD KEY `tickets_status_index` (`status`),
  ADD KEY `tickets_assigned_to_index` (`assigned_to`),
  ADD KEY `tickets_created_at_index` (`created_at`),
  ADD KEY `tickets_company_id_index` (`company_id`),
  ADD KEY `tickets_category_id_index` (`category_id`),
  ADD KEY `tickets_priority_id_index` (`priority_id`);

--
-- Indexes for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_attachments_ticket_message_id_foreign` (`ticket_message_id`),
  ADD KEY `ticket_attachments_uploaded_by_foreign` (`uploaded_by`),
  ADD KEY `ticket_attachments_ticket_id_index` (`ticket_id`);

--
-- Indexes for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_messages_user_id_foreign` (`user_id`),
  ADD KEY `ticket_messages_ticket_id_index` (`ticket_id`),
  ADD KEY `ticket_messages_whatsapp_message_id_index` (`whatsapp_message_id`);

--
-- Indexes for table `ticket_number_sequences`
--
ALTER TABLE `ticket_number_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_number_sequences_year_unique` (`year`);

--
-- Indexes for table `ticket_progress_reports`
--
ALTER TABLE `ticket_progress_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_progress_reports_ticket_id_report_number_unique` (`ticket_id`,`report_number`),
  ADD KEY `ticket_progress_reports_prepared_by_foreign` (`prepared_by`);

--
-- Indexes for table `ticket_progress_updates`
--
ALTER TABLE `ticket_progress_updates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_progress_updates_user_id_foreign` (`user_id`),
  ADD KEY `ticket_progress_updates_ticket_id_created_at_index` (`ticket_id`,`created_at`);

--
-- Indexes for table `ticket_status_histories`
--
ALTER TABLE `ticket_status_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_status_histories_changed_by_foreign` (`changed_by`),
  ADD KEY `ticket_status_histories_ticket_id_created_at_index` (`ticket_id`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD KEY `users_company_id_foreign` (`company_id`);

--
-- Indexes for table `whatsapp_templates`
--
ALTER TABLE `whatsapp_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `whatsapp_templates_name_language_unique` (`name`,`language`);

--
-- Indexes for table `whatsapp_webhook_events`
--
ALTER TABLE `whatsapp_webhook_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `whatsapp_webhook_events_event_key_unique` (`event_key`),
  ADD KEY `whatsapp_webhook_events_processing_status_index` (`processing_status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `canned_responses`
--
ALTER TABLE `canned_responses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `dashboard_notifications`
--
ALTER TABLE `dashboard_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_tokens`
--
ALTER TABLE `employee_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kb_articles`
--
ALTER TABLE `kb_articles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `priorities`
--
ALTER TABLE `priorities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `relink_requests`
--
ALTER TABLE `relink_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `saved_filters`
--
ALTER TABLE `saved_filters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `technician_skills`
--
ALTER TABLE `technician_skills`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_number_sequences`
--
ALTER TABLE `ticket_number_sequences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `ticket_progress_reports`
--
ALTER TABLE `ticket_progress_reports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `ticket_progress_updates`
--
ALTER TABLE `ticket_progress_updates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `ticket_status_histories`
--
ALTER TABLE `ticket_status_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `whatsapp_templates`
--
ALTER TABLE `whatsapp_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `whatsapp_webhook_events`
--
ALTER TABLE `whatsapp_webhook_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `canned_responses`
--
ALTER TABLE `canned_responses`
  ADD CONSTRAINT `canned_responses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `dashboard_notifications`
--
ALTER TABLE `dashboard_notifications`
  ADD CONSTRAINT `dashboard_notifications_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `dashboard_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `employees_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employees_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_tokens`
--
ALTER TABLE `employee_tokens`
  ADD CONSTRAINT `employee_tokens_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kb_articles`
--
ALTER TABLE `kb_articles`
  ADD CONSTRAINT `kb_articles_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `relink_requests`
--
ALTER TABLE `relink_requests`
  ADD CONSTRAINT `relink_requests_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `relink_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `saved_filters`
--
ALTER TABLE `saved_filters`
  ADD CONSTRAINT `saved_filters_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `technician_skills`
--
ALTER TABLE `technician_skills`
  ADD CONSTRAINT `technician_skills_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tickets_priority_id_foreign` FOREIGN KEY (`priority_id`) REFERENCES `priorities` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD CONSTRAINT `ticket_attachments_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_attachments_ticket_message_id_foreign` FOREIGN KEY (`ticket_message_id`) REFERENCES `ticket_messages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ticket_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  ADD CONSTRAINT `ticket_messages_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ticket_progress_reports`
--
ALTER TABLE `ticket_progress_reports`
  ADD CONSTRAINT `ticket_progress_reports_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ticket_progress_reports_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_progress_updates`
--
ALTER TABLE `ticket_progress_updates`
  ADD CONSTRAINT `ticket_progress_updates_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_progress_updates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_status_histories`
--
ALTER TABLE `ticket_status_histories`
  ADD CONSTRAINT `ticket_status_histories_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ticket_status_histories_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

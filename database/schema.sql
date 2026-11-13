-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- PHP Version: 8.4.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `nymix_hardware_management_system`
--

DELIMITER $$
--
-- Procedures
--
$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `account_reviews`
--

CREATE TABLE `account_reviews` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `user_id` int NOT NULL,
  `status` enum('open','meeting','upheld','cleared') NOT NULL DEFAULT 'open',
  `flags` longtext,
  `evidence` longtext,
  `ai_summary` text,
  `scan_from` datetime DEFAULT NULL,
  `manager_note` text,
  `meeting_at` datetime DEFAULT NULL,
  `meeting_location` varchar(160) DEFAULT NULL,
  `staff_response` text,
  `staff_response_at` datetime DEFAULT NULL,
  `decided_by` int DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `account_review_log`
--

CREATE TABLE `account_review_log` (
  `id` int NOT NULL,
  `review_id` int NOT NULL,
  `actor_id` int DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `branch_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `record_id` int UNSIGNED DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `admin_activity_log`
--

CREATE TABLE `admin_activity_log` (
  `id` int UNSIGNED NOT NULL,
  `admin_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_broadcast_log`
--

CREATE TABLE `admin_broadcast_log` (
  `id` int NOT NULL,
  `sender_name` varchar(120) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text,
  `priority` varchar(20) DEFAULT 'normal',
  `recipient_type` varchar(20) DEFAULT 'all',
  `client_id` int DEFAULT NULL,
  `recipient_count` int DEFAULT '0',
  `sent_count` int DEFAULT '0',
  `failed_count` int DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_sessions`
--

CREATE TABLE `admin_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `session_token` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_id` int UNSIGNED NOT NULL,
  `admin_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `admin_role` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_address` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_active` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `ag_batches`
--

CREATE TABLE `ag_batches` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `product_id` int NOT NULL,
  `batch_no` varchar(60) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `qty_received` decimal(12,3) NOT NULL,
  `qty_on_hand` decimal(12,3) NOT NULL,
  `buying_price` decimal(12,2) DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `po_id` int DEFAULT NULL,
  `status` enum('active','quarantined','expired','depleted') NOT NULL DEFAULT 'active',
  `received_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ag_credit_promises`
--

CREATE TABLE `ag_credit_promises` (
  `id` int NOT NULL,
  `customer_id` int NOT NULL,
  `sale_id` int DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `promise_date` date NOT NULL,
  `status` enum('open','paid','broken') NOT NULL DEFAULT 'open',
  `reminded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ag_farmer_profiles`
--

CREATE TABLE `ag_farmer_profiles` (
  `customer_id` int NOT NULL,
  `client_id` int NOT NULL,
  `village` varchar(80) DEFAULT NULL,
  `ward` varchar(80) DEFAULT NULL,
  `farm_size` varchar(40) DEFAULT NULL,
  `livestock` text,
  `crops` text,
  `payment_cycle` varchar(40) DEFAULT NULL,
  `preferred_lang` enum('en','sw') DEFAULT 'sw',
  `assigned_agent_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ag_preorders`
--

CREATE TABLE `ag_preorders` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `product_id` int NOT NULL,
  `qty` decimal(12,3) NOT NULL,
  `total` decimal(12,2) NOT NULL,
  `deposit_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `expected_date` date DEFAULT NULL,
  `status` enum('open','ready','collected','cancelled') DEFAULT 'open',
  `sale_id` int DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ag_product_info`
--

CREATE TABLE `ag_product_info` (
  `product_id` int NOT NULL,
  `client_id` int NOT NULL,
  `is_restricted` tinyint(1) NOT NULL DEFAULT '0',
  `track_batches` tinyint(1) NOT NULL DEFAULT '1',
  `withdrawal_days` int DEFAULT NULL,
  `active_ingredient` varchar(160) DEFAULT NULL,
  `usage_notes` text,
  `storage_note` varchar(160) DEFAULT NULL,
  `pack_size` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ag_product_targets`
--

CREATE TABLE `ag_product_targets` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `target_type` enum('animal','crop') NOT NULL,
  `target` varchar(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ag_restricted_sales`
--

CREATE TABLE `ag_restricted_sales` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `sale_id` int DEFAULT NULL,
  `product_id` int NOT NULL,
  `batch_id` int DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL,
  `customer_id` int DEFAULT NULL,
  `animal_or_crop` varchar(60) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `approved_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ag_unit_conversions`
--

CREATE TABLE `ag_unit_conversions` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `unit_name` varchar(20) NOT NULL,
  `factor_to_base` decimal(12,4) NOT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ag_vet_pins`
--

CREATE TABLE `ag_vet_pins` (
  `user_id` int NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_insights`
--

CREATE TABLE `ai_insights` (
  `id` bigint UNSIGNED NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL DEFAULT '0',
  `kind` varchar(40) NOT NULL DEFAULT '',
  `content` mediumtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_usage`
--

CREATE TABLE `ai_usage` (
  `id` bigint UNSIGNED NOT NULL,
  `client_id` int NOT NULL,
  `feature` varchar(40) NOT NULL,
  `tokens` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `appointed_staff`
--

CREATE TABLE `appointed_staff` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `national_id` varchar(20) NOT NULL,
  `expected_role_id` int NOT NULL,
  `added_by` int DEFAULT NULL,
  `status` enum('pending','registered') NOT NULL DEFAULT 'pending',
  `registered_user_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `card_no` varchar(40) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `pay_type` enum('fixed','hourly') NOT NULL DEFAULT 'fixed',
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `fixed_salary` decimal(12,2) DEFAULT NULL,
  `pay_method` varchar(20) NOT NULL DEFAULT 'bank'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint UNSIGNED NOT NULL,
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_role` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` int UNSIGNED DEFAULT NULL,
  `entity_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details` json DEFAULT NULL,
  `ip_address` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `branch_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_type` enum('hardware','wholesale','supermarket','agrovet','pharmacy','electronics') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hardware',
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `etims_enabled` tinyint(1) DEFAULT '0',
  `etims_pin` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etims_branch_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etims_device_serial` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etims_env` enum('sandbox','live') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'sandbox',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `branches_backup`
--

CREATE TABLE `branches_backup` (
  `id` int UNSIGNED NOT NULL DEFAULT '0',
  `client_id` int UNSIGNED DEFAULT NULL,
  `branch_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_main` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `broadcast_log`
--

CREATE TABLE `broadcast_log` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `sender_name` varchar(150) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text,
  `priority` varchar(20) DEFAULT 'normal',
  `recipient_type` varchar(20) DEFAULT NULL,
  `recipient_count` int DEFAULT '0',
  `sent_count` int DEFAULT '0',
  `failed_count` int DEFAULT '0',
  `status` varchar(20) DEFAULT 'queued',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `broadcast_queue`
--

CREATE TABLE `broadcast_queue` (
  `id` int NOT NULL,
  `broadcast_log_id` int NOT NULL,
  `recipient_name` varchar(150) DEFAULT NULL,
  `recipient_email` varchar(150) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `html_body` mediumtext,
  `plain_body` text,
  `status` varchar(20) DEFAULT 'pending',
  `attempts` int DEFAULT '0',
  `error_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int UNSIGNED NOT NULL,
  `business_name` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kra_pin` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','read_only','suspended','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `etims_pin` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etims_branch_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etims_device_serial` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etims_env` enum('sandbox','live') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'sandbox',
  `otp_code` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `business_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hardware'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `client_portal_users`
--

CREATE TABLE `client_portal_users` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `otp_code` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `otp_attempts` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `company_policies`
--

CREATE TABLE `company_policies` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `title` varchar(160) NOT NULL,
  `original_text` text NOT NULL,
  `metric` varchar(40) NOT NULL DEFAULT 'none',
  `window_days` int NOT NULL DEFAULT '7',
  `threshold` decimal(12,2) NOT NULL DEFAULT '0.00',
  `severity` enum('low','medium','critical') NOT NULL DEFAULT 'medium',
  `action` enum('review','suspend','terminate') NOT NULL DEFAULT 'review',
  `supported` tinyint(1) NOT NULL DEFAULT '0',
  `ai_note` varchar(600) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `credit_limit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `branch_id` int DEFAULT NULL,
  `total_purchased` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_score` decimal(4,2) NOT NULL DEFAULT '1.00',
  `tokens` int DEFAULT '0',
  `lifetime_tokens` int DEFAULT '0',
  `tier` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Bronze'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `customer_payments`
--

CREATE TABLE `customer_payments` (
  `id` int UNSIGNED NOT NULL,
  `customer_id` int UNSIGNED NOT NULL,
  `sale_id` int UNSIGNED DEFAULT NULL,
  `received_by` int UNSIGNED DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_method` enum('cash','mpesa','bank') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `mpesa_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `error_log`
--

CREATE TABLE `error_log` (
  `id` int UNSIGNED NOT NULL,
  `level` enum('info','warning','error','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'error',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line` int UNSIGNED DEFAULT NULL,
  `method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `etims_queue`
--

CREATE TABLE `etims_queue` (
  `id` int UNSIGNED NOT NULL,
  `sale_id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `attempts` tinyint DEFAULT '0',
  `last_attempt` datetime DEFAULT NULL,
  `status` enum('pending','failed','done') DEFAULT 'pending',
  `error` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `category_id` smallint UNSIGNED DEFAULT NULL,
  `recorded_by` int UNSIGNED DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expense_date` date NOT NULL,
  `receipt_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` smallint UNSIGNED NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `guidelines`
--

CREATE TABLE `guidelines` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `version` int NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` mediumtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `guideline_signatures`
--

CREATE TABLE `guideline_signatures` (
  `id` int NOT NULL,
  `guideline_id` int NOT NULL,
  `user_id` int NOT NULL,
  `typed_name` varchar(160) NOT NULL,
  `signature_path` varchar(255) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `signed_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `hourly_payouts`
--

CREATE TABLE `hourly_payouts` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `staff_user_id` int NOT NULL,
  `hours` decimal(8,2) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `pay_method` varchar(20) NOT NULL DEFAULT 'bank',
  `disburse_at` datetime NOT NULL,
  `status` enum('scheduled','released','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `subscription_id` int UNSIGNED DEFAULT NULL,
  `invoice_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','sent','unpaid','paid','overdue','void') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `jb_applications`
--

CREATE TABLE `jb_applications` (
  `id` int UNSIGNED NOT NULL,
  `job_id` int UNSIGNED NOT NULL,
  `candidate_id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `cover_letter` text,
  `status` varchar(20) NOT NULL DEFAULT 'submitted',
  `match_score` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `match_detail` text,
  `flags` text,
  `ai_analysis` text,
  `auto_rejected` tinyint(1) NOT NULL DEFAULT '0',
  `reject_reason` varchar(500) DEFAULT NULL,
  `decided_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `matched` text,
  `missing` text,
  `ai_result` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `jb_app_log`
--

CREATE TABLE `jb_app_log` (
  `id` int UNSIGNED NOT NULL,
  `application_id` int UNSIGNED NOT NULL,
  `status` varchar(20) NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `actor` varchar(80) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `jb_blocks`
--

CREATE TABLE `jb_blocks` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `candidate_id` int UNSIGNED NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `blocked_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jb_candidates`
--

CREATE TABLE `jb_candidates` (
  `id` int UNSIGNED NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','banned') NOT NULL DEFAULT 'active',
  `otp_hash` varchar(255) DEFAULT NULL,
  `otp_expires` datetime DEFAULT NULL,
  `otp_attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `otp_sent_at` datetime DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `location` varchar(120) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `headline` varchar(160) DEFAULT NULL,
  `bio` text,
  `skills` text,
  `interests` text,
  `education` text,
  `certifications` text,
  `work_history` text,
  `experience_years` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `links` text,
  `open_to_work` tinyint(1) NOT NULL DEFAULT '1',
  `cv_path` varchar(255) DEFAULT NULL,
  `cv_name` varchar(190) DEFAULT NULL,
  `cv_text` mediumtext,
  `cv_hash` char(40) DEFAULT NULL,
  `ai_profile` text,
  `ai_coach_day` date DEFAULT NULL,
  `ai_calls` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `ai_day` date DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `jb_jobs`
--

CREATE TABLE `jb_jobs` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` mediumtext NOT NULL,
  `requirements` text,
  `skills` text,
  `location` varchar(120) DEFAULT NULL,
  `job_type` varchar(20) NOT NULL DEFAULT 'full_time',
  `min_exp` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `salary_min` int UNSIGNED DEFAULT NULL,
  `salary_max` int UNSIGNED DEFAULT NULL,
  `show_salary` tinyint(1) NOT NULL DEFAULT '0',
  `openings` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `deadline` date DEFAULT NULL,
  `status` enum('open','closed','draft') NOT NULL DEFAULT 'open',
  `ai_generated` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `isp` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `failure_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_customers`
--

CREATE TABLE `mp_customers` (
  `id` int NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `address` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `otp_hash` varchar(255) DEFAULT NULL,
  `otp_expires` datetime DEFAULT NULL,
  `otp_attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `otp_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_listings`
--

CREATE TABLE `mp_listings` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `requested_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_messages`
--

CREATE TABLE `mp_messages` (
  `id` int NOT NULL,
  `thread_id` int NOT NULL,
  `sender_type` enum('customer','staff') NOT NULL,
  `sender_id` int NOT NULL,
  `body` varchar(2000) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_orders`
--

CREATE TABLE `mp_orders` (
  `id` int NOT NULL,
  `order_no` varchar(30) NOT NULL,
  `customer_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `client_id` int NOT NULL,
  `fulfil` enum('delivery','pickup') DEFAULT 'delivery',
  `delivery_address` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(30) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `delivery_fee` decimal(10,2) DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL,
  `commission_rate` decimal(5,2) DEFAULT '0.00',
  `commission_amount` decimal(12,2) DEFAULT '0.00',
  `payment_method` enum('mpesa','cod') DEFAULT 'mpesa',
  `payment_status` enum('unpaid','pending','paid','failed') DEFAULT 'unpaid',
  `checkout_id` varchar(80) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `status` enum('placed','confirmed','packed','dispatched','out_for_delivery','delivered','cancelled') DEFAULT 'placed',
  `eta_at` datetime DEFAULT NULL,
  `vehicle_id` int DEFAULT NULL,
  `assigned_support_id` int DEFAULT NULL,
  `stock_deducted` tinyint DEFAULT '0',
  `sale_id` int DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_order_items`
--

CREATE TABLE `mp_order_items` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `product_id` int NOT NULL,
  `name` varchar(160) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_order_tracking`
--

CREATE TABLE `mp_order_tracking` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `status` varchar(30) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `location_text` varchar(160) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_reviews`
--

CREATE TABLE `mp_reviews` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `client_id` int NOT NULL,
  `support_user_id` int DEFAULT NULL,
  `rating` tinyint NOT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_store_photos`
--

CREATE TABLE `mp_store_photos` (
  `id` int NOT NULL,
  `branch_id` int NOT NULL,
  `kind` enum('store','staff','delivery') NOT NULL DEFAULT 'store',
  `path` varchar(255) NOT NULL,
  `caption` varchar(140) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mp_store_profiles`
--

CREATE TABLE `mp_store_profiles` (
  `branch_id` int NOT NULL,
  `client_id` int NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `tagline` varchar(160) DEFAULT NULL,
  `description` text,
  `categories` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `banner_path` varchar(255) DEFAULT NULL,
  `accent` varchar(9) DEFAULT '#1E9E52',
  `avg_delivery_mins` int DEFAULT '60',
  `delivery_fee` decimal(10,2) DEFAULT '200.00',
  `min_order` decimal(10,2) DEFAULT '0.00',
  `delivery_enabled` tinyint DEFAULT '1',
  `pickup_enabled` tinyint DEFAULT '1',
  `is_published` tinyint DEFAULT '0',
  `nymix_verified` tinyint DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_threads`
--

CREATE TABLE `mp_threads` (
  `id` int NOT NULL,
  `customer_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `client_id` int NOT NULL,
  `last_message_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `staff_read_id` int DEFAULT '0',
  `cust_read_id` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `mp_vehicles`
--

CREATE TABLE `mp_vehicles` (
  `id` int NOT NULL,
  `branch_id` int NOT NULL,
  `label` varchar(80) NOT NULL,
  `plate` varchar(20) DEFAULT NULL,
  `vtype` varchar(30) DEFAULT NULL,
  `driver_name` varchar(80) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `msg_messages`
--

CREATE TABLE `msg_messages` (
  `id` int NOT NULL,
  `thread_id` int NOT NULL,
  `sender_id` int NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `msg_reads`
--

CREATE TABLE `msg_reads` (
  `thread_id` int NOT NULL,
  `user_id` int NOT NULL,
  `last_read_id` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `msg_settings`
--

CREATE TABLE `msg_settings` (
  `user_id` int NOT NULL,
  `show_last_seen` tinyint NOT NULL DEFAULT '1',
  `show_online` tinyint NOT NULL DEFAULT '1',
  `read_receipts` tinyint NOT NULL DEFAULT '1',
  `enter_to_send` tinyint NOT NULL DEFAULT '1',
  `sound` tinyint NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `msg_threads`
--

CREATE TABLE `msg_threads` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `created_by` int NOT NULL,
  `subject` varchar(160) NOT NULL,
  `category` enum('general','product','payout','other') NOT NULL DEFAULT 'general',
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `last_message_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `to_user_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `nx_alert_mail_log`
--

CREATE TABLE `nx_alert_mail_log` (
  `id` int NOT NULL,
  `fingerprint` varchar(190) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `nx_alert_state`
--

CREATE TABLE `nx_alert_state` (
  `k` varchar(40) NOT NULL,
  `last_run` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `nx_auto_log`
--

CREATE TABLE `nx_auto_log` (
  `id` int NOT NULL,
  `kind` varchar(40) NOT NULL,
  `ref` varchar(120) NOT NULL DEFAULT '',
  `payload` mediumtext,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `nx_login_watch`
--

CREATE TABLE `nx_login_watch` (
  `id` int NOT NULL,
  `email` varchar(190) NOT NULL,
  `ip` varchar(64) NOT NULL,
  `ok` tinyint(1) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `onboarding_requests`
--

CREATE TABLE `onboarding_requests` (
  `id` int NOT NULL,
  `business_name` varchar(160) DEFAULT NULL,
  `owner_name` varchar(120) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `kra_pin` varchar(30) DEFAULT NULL,
  `business_type` varchar(30) DEFAULT NULL,
  `branch_count` int DEFAULT '1',
  `location` varchar(160) DEFAULT NULL,
  `notes` text,
  `transcript` mediumtext,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `code` varchar(20) DEFAULT NULL,
  `code_expires_at` datetime DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` datetime DEFAULT NULL,
  `client_id` int DEFAULT NULL,
  `ai_decision` varchar(20) DEFAULT NULL,
  `ai_note` text,
  `reviewed_by` varchar(80) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `email_sent_at` datetime DEFAULT NULL,
  `no_kra` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `payslips`
--

CREATE TABLE `payslips` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `user_id` int NOT NULL,
  `period` char(7) NOT NULL,
  `payslip_no` varchar(30) NOT NULL,
  `gross` decimal(12,2) NOT NULL,
  `total_deductions` decimal(12,2) NOT NULL,
  `net` decimal(12,2) NOT NULL,
  `data` mediumtext NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED DEFAULT NULL,
  `supplier_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barcode` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `unit` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pcs',
  `cost_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `selling_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reorder_level` int UNSIGNED NOT NULL DEFAULT '5',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `unit_id` int DEFAULT NULL,
  `buying_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `client_unit_base` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int UNSIGNED NOT NULL,
  `purchase_order_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `quantity_ordered` decimal(12,3) NOT NULL,
  `quantity_received` decimal(12,3) NOT NULL DEFAULT '0.000',
  `buying_price` decimal(12,2) NOT NULL,
  `subtotal` decimal(14,2) GENERATED ALWAYS AS ((`quantity_received` * `buying_price`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `supplier_id` int UNSIGNED NOT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `lpo_number` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','sent','partially_received','received','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `total_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(14,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('unpaid','partial','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `expected_date` date DEFAULT NULL,
  `received_date` date DEFAULT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` tinyint UNSIGNED NOT NULL,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `salary_allocations`
--

CREATE TABLE `salary_allocations` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `period` enum('daily','monthly') NOT NULL DEFAULT 'monthly',
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `salary_allocation_requests`
--

CREATE TABLE `salary_allocation_requests` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `requested_by` int NOT NULL,
  `month` varchar(7) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `decided_by` int DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `salary_disbursements`
--

CREATE TABLE `salary_disbursements` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `staff_user_id` int NOT NULL,
  `disbursed_by` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` enum('bank','mpesa','cash') DEFAULT 'bank',
  `note` text,
  `status` enum('pending_otp','confirmed','cancelled') DEFAULT 'pending_otp',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `salary_requests`
--

CREATE TABLE `salary_requests` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `requested_by` int DEFAULT NULL,
  `staff_name` varchar(150) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `period` enum('daily','monthly') NOT NULL DEFAULT 'monthly',
  `note` text,
  `status` enum('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
  `decided_by` int DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `sale_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sale_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(12,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','mpesa','card','cheque','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `change_given` decimal(12,2) NOT NULL DEFAULT '0.00',
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `voided` tinyint(1) NOT NULL DEFAULT '0',
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `receipt_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `served_by` int DEFAULT NULL,
  `balance_due` decimal(10,2) NOT NULL DEFAULT '0.00',
  `mpesa_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paid',
  `etims_status` enum('pending','submitted','failed','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'skipped',
  `etims_invoice_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `etims_qr_code` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `etims_submitted_at` datetime DEFAULT NULL,
  `etims_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `etims_retry_count` tinyint DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int UNSIGNED NOT NULL,
  `sale_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `batch_id` int DEFAULT NULL,
  `sold_unit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sold_qty` decimal(12,3) DEFAULT NULL,
  `base_qty` decimal(12,3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `security_alerts`
--

CREATE TABLE `security_alerts` (
  `id` bigint UNSIGNED NOT NULL,
  `alert_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `severity` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `meta` json DEFAULT NULL,
  `is_resolved` tinyint(1) NOT NULL DEFAULT '0',
  `resolved_by` int UNSIGNED DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `staff_ai_profiles`
--

CREATE TABLE `staff_ai_profiles` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `appointed_id` int DEFAULT NULL,
  `source` enum('cv','role') NOT NULL DEFAULT 'role',
  `headline` varchar(200) DEFAULT NULL,
  `summary` text,
  `skills` text,
  `duties` text,
  `strengths` text,
  `gaps` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `staff_attendance`
--

CREATE TABLE `staff_attendance` (
  `user_id` int NOT NULL,
  `att_date` date NOT NULL,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `staff_finance`
--

CREATE TABLE `staff_finance` (
  `user_id` int NOT NULL,
  `kra_pin` varchar(20) DEFAULT NULL,
  `nssf_no` varchar(30) DEFAULT NULL,
  `shif_no` varchar(30) DEFAULT NULL,
  `bank_name` varchar(80) DEFAULT NULL,
  `bank_account` varchar(40) DEFAULT NULL,
  `mpesa_phone` varchar(20) DEFAULT NULL,
  `paypal_email` varchar(120) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `staff_leave`
--

CREATE TABLE `staff_leave` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `user_id` int NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `leave_type` varchar(30) NOT NULL DEFAULT 'annual',
  `reason` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `staff_notifications`
--

CREATE TABLE `staff_notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(150) NOT NULL,
  `body` text,
  `is_read` tinyint NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `staff_payroll`
--

CREATE TABLE `staff_payroll` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `id_number` varchar(30) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `fixed_salary` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `pay_type` enum('fixed','hourly') NOT NULL DEFAULT 'fixed',
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `pay_method` varchar(20) NOT NULL DEFAULT 'bank',
  `allowances` decimal(12,2) NOT NULL DEFAULT '0.00',
  `insurance_premium` decimal(12,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `staff_time_logs`
--

CREATE TABLE `staff_time_logs` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL,
  `payout_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `stock`
--

CREATE TABLE `stock` (
  `id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `type` enum('sale','purchase','adjustment','transfer_in','transfer_out','void_return') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `reference_id` int UNSIGNED DEFAULT NULL,
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `stock_notifications`
--

CREATE TABLE `stock_notifications` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `branch_id` int DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `branch_name` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,3) DEFAULT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `note` text,
  `done_by` varchar(100) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfers`
--

CREATE TABLE `stock_transfers` (
  `id` int UNSIGNED NOT NULL,
  `from_branch` int UNSIGNED NOT NULL,
  `to_branch` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `requested_by` int UNSIGNED DEFAULT NULL,
  `approved_by` int UNSIGNED DEFAULT NULL,
  `status` enum('pending','approved','rejected','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `plan_id` int UNSIGNED DEFAULT NULL,
  `plan_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_count` int UNSIGNED NOT NULL DEFAULT '1',
  `base_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `addon_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_monthly` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `billing_cycle` enum('monthly','quarterly','annual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `grace_end_date` date DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `auto_renew` tinyint(1) NOT NULL DEFAULT '1',
  `user_id` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `subscription_payments`
--

CREATE TABLE `subscription_payments` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED DEFAULT NULL,
  `received_by` int UNSIGNED DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paystack',
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_tracking_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_date` date NOT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `confirmed_by` int UNSIGNED DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `provider` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paystack',
  `receipt_no` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paystack_id` bigint DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `raw_response` mediumtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE `subscription_plans` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `branch_addon_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `max_users` int UNSIGNED DEFAULT NULL,
  `max_products` int UNSIGNED DEFAULT NULL,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `super_admins`
--

CREATE TABLE `super_admins` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('superadmin','billing','support') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'support',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `invite_token_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invite_expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` int UNSIGNED NOT NULL,
  `supplier_id` int UNSIGNED NOT NULL,
  `purchase_order_id` int UNSIGNED DEFAULT NULL,
  `paid_by` int UNSIGNED DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_method` enum('cash','mpesa','bank','cheque') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `assigned_to` int UNSIGNED DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` enum('low','medium','high','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `status` enum('open','in_progress','resolved','closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `system_health`
--

CREATE TABLE `system_health` (
  `id` int UNSIGNED NOT NULL,
  `server_load` decimal(5,2) NOT NULL DEFAULT '0.00',
  `memory_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `memory_used_mb` decimal(10,2) NOT NULL DEFAULT '0.00',
  `memory_total_mb` decimal(10,2) NOT NULL DEFAULT '0.00',
  `disk_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `disk_free_gb` decimal(10,2) NOT NULL DEFAULT '0.00',
  `disk_total_gb` decimal(10,2) NOT NULL DEFAULT '0.00',
  `db_size_mb` decimal(10,2) NOT NULL DEFAULT '0.00',
  `db_connections` int UNSIGNED NOT NULL DEFAULT '0',
  `db_slow_queries` int UNSIGNED NOT NULL DEFAULT '0',
  `db_uptime_secs` bigint NOT NULL DEFAULT '0',
  `php_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active_clients` int UNSIGNED NOT NULL DEFAULT '0',
  `suspended_clients` int UNSIGNED NOT NULL DEFAULT '0',
  `active_subs` int UNSIGNED NOT NULL DEFAULT '0',
  `unpaid_invoices` int UNSIGNED NOT NULL DEFAULT '0',
  `open_tickets` int UNSIGNED NOT NULL DEFAULT '0',
  `errors_24h` int UNSIGNED NOT NULL DEFAULT '0',
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `ticket_replies`
--

CREATE TABLE `ticket_replies` (
  `id` int UNSIGNED NOT NULL,
  `ticket_id` int UNSIGNED NOT NULL,
  `sender_type` enum('admin','client') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `sender_id` int UNSIGNED NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_voice` tinyint(1) NOT NULL DEFAULT '0',
  `voice_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `ticket_typing`
--

CREATE TABLE `ticket_typing` (
  `ticket_id` int NOT NULL,
  `sender_type` enum('admin','client') NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` smallint UNSIGNED NOT NULL,
  `name` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED NOT NULL,
  `full_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `age` int DEFAULT NULL,
  `gender` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_of_kin_relationship` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `national_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `insurance_provider` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `insurance_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cv_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_doc_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `appointment_letter_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_status` enum('pending','verified','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'verified',
  `role_id` tinyint NOT NULL DEFAULT '3',
  `role` enum('manager','cashier','stock_clerk') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cashier',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `otp_code` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `otp_attempts` int NOT NULL DEFAULT '0',
  `id_verified` tinyint(1) NOT NULL DEFAULT '0',
  `id_verified_at` datetime DEFAULT NULL,
  `id_verify_country` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_verify_attempts` int NOT NULL DEFAULT '0',
  `id_verify_attempt_day` date DEFAULT NULL,
  `card_no` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_issued_at` datetime DEFAULT NULL,
  `card_required` tinyint(1) NOT NULL DEFAULT '0',
  `last_seen` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_client_subscriptions`
-- (See below for the actual view)
--
CREATE TABLE `v_client_subscriptions` (
`account_status` enum('active','read_only','suspended','cancelled')
,`billing_cycle` enum('monthly','quarterly','annual')
,`branch_count` int unsigned
,`business_name` varchar(180)
,`client_id` int unsigned
,`days_remaining` int
,`end_date` date
,`grace_end_date` date
,`phone` varchar(30)
,`plan` varchar(80)
,`start_date` date
,`subscription_status` varchar(20)
,`total_monthly` decimal(12,2)
,`total_paid_lifetime` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_expiring_soon`
-- (See below for the actual view)
--
CREATE TABLE `v_expiring_soon` (
`business_name` varchar(180)
,`days_left` int
,`end_date` date
,`id` int unsigned
,`plan` varchar(80)
,`total_monthly` decimal(12,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_failed_logins_24h`
-- (See below for the actual view)
--
CREATE TABLE `v_failed_logins_24h` (
`attempt_count` bigint
,`countries` text
,`distinct_emails` bigint
,`first_seen` datetime
,`ip_address` varchar(64)
,`last_seen` datetime
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_monthly_revenue`
-- (See below for the actual view)
--
CREATE TABLE `v_monthly_revenue` (
`month` varchar(7)
,`payment_count` bigint
,`total_revenue` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_soc_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_soc_summary` (
`active_sessions` bigint
,`audit_events_24h` bigint
,`critical_alerts` bigint
,`failures_24h` bigint
,`logins_24h` bigint
,`open_alerts` bigint
,`successes_24h` bigint
,`suspicious_ips` bigint
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_system_overview`
-- (See below for the actual view)
--
CREATE TABLE `v_system_overview` (
`active_clients` bigint
,`errors_24h` bigint
,`open_tickets` bigint
,`readonly_clients` bigint
,`revenue_today` int
,`sales_today` int
,`sub_revenue_this_month` decimal(34,2)
,`suspended_clients` bigint
,`total_branches` bigint
,`total_products` int
,`total_users` bigint
);

-- --------------------------------------------------------

--
-- Table structure for table `wh_audit_log`
--

CREATE TABLE `wh_audit_log` (
  `id` bigint UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `warehouse_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. grn_created, batch_released, order_dispatched',
  `entity_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'grn, batch, outbound_order, production_order…',
  `entity_id` int UNSIGNED DEFAULT NULL,
  `old_value` json DEFAULT NULL,
  `new_value` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_batches`
--

CREATE TABLE `wh_batches` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id (HMS)',
  `batch_number` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_lot` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Supplier''s own lot/batch reference',
  `manufacture_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `qty_received` decimal(12,3) NOT NULL DEFAULT '0.000',
  `qty_available` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Current available qty in this batch',
  `qty_reserved` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Qty allocated to pick orders not yet dispatched',
  `qty_consumed` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Used in production',
  `qty_rejected` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Failed QC',
  `status` enum('quarantine','available','reserved','consumed','expired','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quarantine',
  `grn_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_grn.id — which GRN created this batch',
  `location_id` int UNSIGNED DEFAULT NULL COMMENT 'Primary storage location',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_batch_locations`
--

CREATE TABLE `wh_batch_locations` (
  `id` int UNSIGNED NOT NULL,
  `batch_id` int UNSIGNED NOT NULL,
  `location_id` int UNSIGNED NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT '0.000',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_bom`
--

CREATE TABLE `wh_bom` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `finished_product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id — what we produce',
  `bom_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v1',
  `qty_produced` decimal(12,3) NOT NULL DEFAULT '1.000' COMMENT 'Units of finished product per run',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_bom_items`
--

CREATE TABLE `wh_bom_items` (
  `id` int UNSIGNED NOT NULL,
  `bom_id` int UNSIGNED NOT NULL,
  `input_product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id — raw material / component',
  `qty_required` decimal(12,4) NOT NULL COMMENT 'Qty of input per BOM run',
  `unit` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `waste_pct` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'Expected waste %',
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_delivery_schedule`
--

CREATE TABLE `wh_delivery_schedule` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `supplier_id` int UNSIGNED DEFAULT NULL COMMENT 'FK suppliers.id (HMS)',
  `po_id` int UNSIGNED DEFAULT NULL COMMENT 'FK purchase_orders.id (HMS)',
  `scheduled_date` date NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `status` enum('scheduled','confirmed','arrived','receiving','completed','missed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `vehicle_reg` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dock_door` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Which loading dock to assign',
  `grn_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_grn.id — created when delivery arrives',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_grn`
--

CREATE TABLE `wh_grn` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `grn_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Auto-generated e.g. GRN-20250627-0001',
  `po_id` int UNSIGNED DEFAULT NULL COMMENT 'FK purchase_orders.id (HMS) — optional link',
  `supplier_id` int UNSIGNED DEFAULT NULL COMMENT 'FK suppliers.id (HMS)',
  `supplier_invoice` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_do` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Supplier delivery order number',
  `delivery_date` date NOT NULL,
  `received_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `qc_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id — who did QC inspection',
  `vehicle_reg` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Delivery vehicle registration',
  `driver_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','receiving','qc_pending','qc_passed','qc_failed','partially_accepted','accepted','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `total_items` int UNSIGNED NOT NULL DEFAULT '0',
  `total_qty_ordered` decimal(12,3) NOT NULL DEFAULT '0.000',
  `total_qty_received` decimal(12,3) NOT NULL DEFAULT '0.000',
  `total_qty_accepted` decimal(12,3) NOT NULL DEFAULT '0.000',
  `total_qty_rejected` decimal(12,3) NOT NULL DEFAULT '0.000',
  `rejection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `qc_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_grn_items`
--

CREATE TABLE `wh_grn_items` (
  `id` int UNSIGNED NOT NULL,
  `grn_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id (HMS)',
  `po_item_id` int UNSIGNED DEFAULT NULL COMMENT 'FK purchase_items.id (HMS)',
  `qty_ordered` decimal(12,3) NOT NULL DEFAULT '0.000',
  `qty_received` decimal(12,3) NOT NULL DEFAULT '0.000',
  `qty_accepted` decimal(12,3) NOT NULL DEFAULT '0.000',
  `qty_rejected` decimal(12,3) NOT NULL DEFAULT '0.000',
  `unit_cost` decimal(12,4) DEFAULT NULL,
  `batch_number` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Batch assigned during receiving',
  `expiry_date` date DEFAULT NULL,
  `location_id` int UNSIGNED DEFAULT NULL COMMENT 'Putaway location assigned during receiving',
  `qc_status` enum('pending','passed','failed','partial') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `qc_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rejection_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_locations`
--

CREATE TABLE `wh_locations` (
  `id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `zone_id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `aisle` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. A, B, C',
  `rack` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. 01, 02',
  `shelf` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. 01, 02, 03',
  `bin` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. 01, 02',
  `label` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Full address e.g. RM-A-03-07',
  `barcode` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Printable barcode for bin label',
  `capacity` decimal(12,3) DEFAULT NULL COMMENT 'Max units this bin can hold',
  `current_qty` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Running total for quick capacity check',
  `location_type` enum('bin','shelf','rack','floor','bulk') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bin',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_outbound_orders`
--

CREATE TABLE `wh_outbound_orders` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `order_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. OB-20250627-0001',
  `order_type` enum('branch_replenishment','customer_order','production_supply','inter_warehouse','return_to_supplier') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'branch_replenishment',
  `reference_id` int UNSIGNED DEFAULT NULL COMMENT 'FK to sale, transfer, or production order depending on type',
  `dest_branch_id` int UNSIGNED DEFAULT NULL COMMENT 'FK branches.id (HMS) for replenishment',
  `dest_warehouse_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_warehouses.id for inter-warehouse',
  `customer_id` int UNSIGNED DEFAULT NULL COMMENT 'FK customers.id (HMS) for customer orders',
  `requested_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `assigned_picker` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `assigned_packer` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `priority` enum('low','normal','high','urgent') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `status` enum('draft','confirmed','picking','picked','packing','packed','dispatched','delivered','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `required_date` date DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `dispatch_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `total_items` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_pack_slips`
--

CREATE TABLE `wh_pack_slips` (
  `id` int UNSIGNED NOT NULL,
  `outbound_id` int UNSIGNED NOT NULL,
  `slip_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `packed_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `packed_at` timestamp NULL DEFAULT NULL,
  `box_count` int UNSIGNED NOT NULL DEFAULT '1',
  `total_weight_kg` decimal(8,3) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('packing','packed','dispatched') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'packing',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_pack_slip_items`
--

CREATE TABLE `wh_pack_slip_items` (
  `id` int UNSIGNED NOT NULL,
  `slip_id` int UNSIGNED NOT NULL,
  `pick_item_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `batch_id` int UNSIGNED DEFAULT NULL,
  `qty_packed` decimal(12,3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_pick_items`
--

CREATE TABLE `wh_pick_items` (
  `id` int UNSIGNED NOT NULL,
  `outbound_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id (HMS)',
  `batch_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_batches.id — FEFO assigned batch',
  `location_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_locations.id — where to pick from',
  `qty_requested` decimal(12,3) NOT NULL,
  `qty_picked` decimal(12,3) NOT NULL DEFAULT '0.000',
  `qty_short` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Qty not available',
  `status` enum('pending','partial','picked','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `picked_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `picked_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_production_inputs`
--

CREATE TABLE `wh_production_inputs` (
  `id` int UNSIGNED NOT NULL,
  `production_id` int UNSIGNED NOT NULL,
  `bom_item_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_bom_items.id',
  `product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id — the raw material',
  `batch_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_batches.id — which batch consumed',
  `location_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_locations.id — picked from where',
  `qty_planned` decimal(12,4) NOT NULL,
  `qty_actual` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `qty_wasted` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `issued_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `issued_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wh_production_orders`
--

CREATE TABLE `wh_production_orders` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `order_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. PRD-20250627-0001',
  `bom_id` int UNSIGNED NOT NULL COMMENT 'FK wh_bom.id',
  `finished_product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id',
  `qty_planned` decimal(12,3) NOT NULL COMMENT 'How many finished units to produce',
  `qty_produced` decimal(12,3) NOT NULL DEFAULT '0.000',
  `qty_rejected` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Failed QC at output',
  `status` enum('draft','materials_check','ready','in_progress','completed','partially_completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `planned_start` date DEFAULT NULL,
  `planned_end` date DEFAULT NULL,
  `actual_start` timestamp NULL DEFAULT NULL,
  `actual_end` timestamp NULL DEFAULT NULL,
  `output_batch` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Batch number assigned to finished goods',
  `output_location_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_locations.id — where to put finished goods',
  `assigned_to` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id — production supervisor',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wh_stock_counts`
--

CREATE TABLE `wh_stock_counts` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `count_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. CNT-20250627-001',
  `count_type` enum('full','cycle','zone','product') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full',
  `zone_id` int UNSIGNED DEFAULT NULL COMMENT 'For zone counts',
  `product_id` int UNSIGNED DEFAULT NULL COMMENT 'For product counts',
  `status` enum('draft','in_progress','completed','approved','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `count_date` date NOT NULL,
  `approved_by` int UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_stock_count_items`
--

CREATE TABLE `wh_stock_count_items` (
  `id` int UNSIGNED NOT NULL,
  `count_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id',
  `batch_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_batches.id',
  `location_id` int UNSIGNED NOT NULL COMMENT 'FK wh_locations.id',
  `system_qty` decimal(12,3) NOT NULL COMMENT 'What the system says',
  `counted_qty` decimal(12,3) DEFAULT NULL COMMENT 'What was physically counted',
  `variance` decimal(12,3) GENERATED ALWAYS AS ((`counted_qty` - `system_qty`)) STORED,
  `variance_value` decimal(14,4) DEFAULT NULL COMMENT 'Variance × unit cost',
  `counted_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `counted_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','counted','approved','adjusted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wh_stock_ledger`
--

CREATE TABLE `wh_stock_ledger` (
  `id` bigint UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id (HMS)',
  `batch_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_batches.id',
  `location_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_locations.id',
  `movement_type` enum('grn_receive','putaway','pick','pack','dispatch','production_issue','production_return','production_output','transfer_out','transfer_in','bin_transfer','qc_quarantine','qc_release','qc_reject','adjustment_add','adjustment_remove','expiry_writeoff') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty_in` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Stock entering',
  `qty_out` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Stock leaving',
  `qty_balance` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'Running balance at this location',
  `reference_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'grn, outbound_order, production_order, adjustment',
  `reference_id` int UNSIGNED DEFAULT NULL COMMENT 'ID in the reference table',
  `unit_cost` decimal(12,4) DEFAULT NULL,
  `performed_by` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_transfers`
--

CREATE TABLE `wh_transfers` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `transfer_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. IWT-20250627-001',
  `from_warehouse_id` int UNSIGNED NOT NULL,
  `to_warehouse_id` int UNSIGNED NOT NULL,
  `status` enum('draft','approved','in_transit','received','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `requested_by` int UNSIGNED DEFAULT NULL,
  `approved_by` int UNSIGNED DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wh_transfer_items`
--

CREATE TABLE `wh_transfer_items` (
  `id` int UNSIGNED NOT NULL,
  `transfer_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL COMMENT 'FK products.id',
  `batch_id` int UNSIGNED DEFAULT NULL COMMENT 'FK wh_batches.id',
  `from_location_id` int UNSIGNED DEFAULT NULL,
  `to_location_id` int UNSIGNED DEFAULT NULL,
  `qty_requested` decimal(12,3) NOT NULL,
  `qty_dispatched` decimal(12,3) NOT NULL DEFAULT '0.000',
  `qty_received` decimal(12,3) NOT NULL DEFAULT '0.000'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wh_user_settings`
--

CREATE TABLE `wh_user_settings` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL COMMENT 'FK users.id (HMS)',
  `default_warehouse_id` int UNSIGNED DEFAULT NULL,
  `scan_mode` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = barcode scan mode on by default',
  `items_per_page` smallint NOT NULL DEFAULT '25',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_user_warehouse`
--

CREATE TABLE `wh_user_warehouse` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_warehouses`
--

CREATE TABLE `wh_warehouses` (
  `id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `branch_id` int UNSIGNED DEFAULT NULL COMMENT 'Linked HMS branch if applicable',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Short code e.g. WH-NBI-01',
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_id` int UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `wh_zones`
--

CREATE TABLE `wh_zones` (
  `id` int UNSIGNED NOT NULL,
  `warehouse_id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. RM, FG, QT, CS',
  `type` enum('raw_material','work_in_progress','finished_goods','quarantine','dispatch','returns','cold_store','general') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_reviews`
--
ALTER TABLE `account_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_client_status` (`client_id`,`status`),
  ADD KEY `idx_branch_status` (`branch_id`,`status`);

--
-- Indexes for table `account_review_log`
--
ALTER TABLE `account_review_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_review` (`review_id`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_log_user` (`user_id`),
  ADD KEY `fk_log_branch` (`branch_id`);

--
-- Indexes for table `admin_activity_log`
--
ALTER TABLE `admin_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_aal_admin` (`admin_id`);

--
-- Indexes for table `admin_broadcast_log`
--
ALTER TABLE `admin_broadcast_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `idx_token` (`session_token`),
  ADD KEY `idx_admin` (`admin_id`),
  ADD KEY `idx_active` (`ended_at`,`last_active`);

--
-- Indexes for table `ag_batches`
--
ALTER TABLE `ag_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fefo` (`branch_id`,`product_id`,`status`,`expiry_date`),
  ADD KEY `expiry_date` (`expiry_date`);

--
-- Indexes for table `ag_credit_promises`
--
ALTER TABLE `ag_credit_promises`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `promise_date` (`promise_date`,`status`);

--
-- Indexes for table `ag_farmer_profiles`
--
ALTER TABLE `ag_farmer_profiles`
  ADD PRIMARY KEY (`customer_id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `ag_preorders`
--
ALTER TABLE `ag_preorders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`,`status`,`expected_date`);

--
-- Indexes for table `ag_product_info`
--
ALTER TABLE `ag_product_info`
  ADD PRIMARY KEY (`product_id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `ag_product_targets`
--
ALTER TABLE `ag_product_targets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `target` (`target`);

--
-- Indexes for table `ag_restricted_sales`
--
ALTER TABLE `ag_restricted_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`,`created_at`);

--
-- Indexes for table `ag_unit_conversions`
--
ALTER TABLE `ag_unit_conversions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_id` (`product_id`,`unit_name`);

--
-- Indexes for table `ag_vet_pins`
--
ALTER TABLE `ag_vet_pins`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `ai_insights`
--
ALTER TABLE `ai_insights`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lookup` (`client_id`,`branch_id`,`kind`,`created_at`);

--
-- Indexes for table `ai_usage`
--
ALTER TABLE `ai_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_day` (`client_id`,`created_at`);

--
-- Indexes for table `appointed_staff`
--
ALTER TABLE `appointed_staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_client_natid` (`client_id`,`national_id`),
  ADD UNIQUE KEY `card_no` (`card_no`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin` (`admin_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_br_client` (`client_id`);

--
-- Indexes for table `broadcast_log`
--
ALTER TABLE `broadcast_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `broadcast_queue`
--
ALTER TABLE `broadcast_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `broadcast_log_id` (`broadcast_log_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cat_client` (`client_id`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `client_portal_users`
--
ALTER TABLE `client_portal_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `client_id` (`client_id`);

--
-- Indexes for table `company_policies`
--
ALTER TABLE `company_policies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`,`is_active`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cust_client` (`client_id`);

--
-- Indexes for table `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cp_customer` (`customer_id`),
  ADD KEY `fk_cp_sale` (`sale_id`);

--
-- Indexes for table `error_log`
--
ALTER TABLE `error_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `etims_queue`
--
ALTER TABLE `etims_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_exp_branch` (`branch_id`),
  ADD KEY `fk_exp_category` (`category_id`),
  ADD KEY `idx_expenses_branch_date` (`branch_id`,`expense_date`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_exp_cat_name` (`name`);

--
-- Indexes for table `guidelines`
--
ALTER TABLE `guidelines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`,`is_active`);

--
-- Indexes for table `guideline_signatures`
--
ALTER TABLE `guideline_signatures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `guideline_id` (`guideline_id`,`user_id`);

--
-- Indexes for table `hourly_payouts`
--
ALTER TABLE `hourly_payouts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`,`disburse_at`),
  ADD KEY `staff_user_id` (`staff_user_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `fk_inv_client` (`client_id`),
  ADD KEY `fk_inv_sub` (`subscription_id`);

--
-- Indexes for table `jb_applications`
--
ALTER TABLE `jb_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_job_cand` (`job_id`,`candidate_id`),
  ADD KEY `branch_id` (`branch_id`,`status`),
  ADD KEY `candidate_id` (`candidate_id`);

--
-- Indexes for table `jb_app_log`
--
ALTER TABLE `jb_app_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `jb_blocks`
--
ALTER TABLE `jb_blocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_blk` (`client_id`,`candidate_id`);

--
-- Indexes for table `jb_candidates`
--
ALTER TABLE `jb_candidates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `cv_hash` (`cv_hash`),
  ADD KEY `phone` (`phone`);

--
-- Indexes for table `jb_jobs`
--
ALTER TABLE `jb_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`,`status`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_result` (`result`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `mp_customers`
--
ALTER TABLE `mp_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `mp_listings`
--
ALTER TABLE `mp_listings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prod_branch` (`product_id`,`branch_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `mp_messages`
--
ALTER TABLE `mp_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`);

--
-- Indexes for table `mp_orders`
--
ALTER TABLE `mp_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_no` (`order_no`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `mp_order_items`
--
ALTER TABLE `mp_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `mp_order_tracking`
--
ALTER TABLE `mp_order_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `mp_reviews`
--
ALTER TABLE `mp_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `support_user_id` (`support_user_id`);

--
-- Indexes for table `mp_store_photos`
--
ALTER TABLE `mp_store_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `mp_store_profiles`
--
ALTER TABLE `mp_store_profiles`
  ADD PRIMARY KEY (`branch_id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `mp_threads`
--
ALTER TABLE `mp_threads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cust_branch` (`customer_id`,`branch_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `mp_vehicles`
--
ALTER TABLE `mp_vehicles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `msg_messages`
--
ALTER TABLE `msg_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`);

--
-- Indexes for table `msg_reads`
--
ALTER TABLE `msg_reads`
  ADD PRIMARY KEY (`thread_id`,`user_id`);

--
-- Indexes for table `msg_settings`
--
ALTER TABLE `msg_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `msg_threads`
--
ALTER TABLE `msg_threads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`,`status`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `to_user_id` (`to_user_id`);

--
-- Indexes for table `nx_alert_mail_log`
--
ALTER TABLE `nx_alert_mail_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fingerprint` (`fingerprint`,`created_at`);

--
-- Indexes for table `nx_alert_state`
--
ALTER TABLE `nx_alert_state`
  ADD PRIMARY KEY (`k`);

--
-- Indexes for table `nx_auto_log`
--
ALTER TABLE `nx_auto_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kind` (`kind`,`ref`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `nx_login_watch`
--
ALTER TABLE `nx_login_watch`
  ADD PRIMARY KEY (`id`),
  ADD KEY `k1` (`email`,`ok`,`ip`),
  ADD KEY `k2` (`ip`,`ok`,`created_at`);

--
-- Indexes for table `onboarding_requests`
--
ALTER TABLE `onboarding_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_code` (`code`);

--
-- Indexes for table `payslips`
--
ALTER TABLE `payslips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_period` (`user_id`,`period`),
  ADD KEY `idx_branch` (`client_id`,`branch_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_prod_client` (`client_id`),
  ADD KEY `fk_prod_cat` (`category_id`),
  ADD KEY `fk_prod_sup` (`supplier_id`),
  ADD KEY `idx_products_client` (`client_id`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pi_po` (`purchase_order_id`),
  ADD KEY `fk_pi_product` (`product_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lpo_number` (`lpo_number`),
  ADD KEY `fk_po_branch` (`branch_id`),
  ADD KEY `fk_po_supplier` (`supplier_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_role_name` (`name`);

--
-- Indexes for table `salary_allocations`
--
ALTER TABLE `salary_allocations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_branch` (`branch_id`);

--
-- Indexes for table `salary_allocation_requests`
--
ALTER TABLE `salary_allocation_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `salary_disbursements`
--
ALTER TABLE `salary_disbursements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `salary_requests`
--
ALTER TABLE `salary_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sale_no` (`sale_no`),
  ADD KEY `fk_sale_branch` (`branch_id`),
  ADD KEY `fk_sale_user` (`user_id`),
  ADD KEY `fk_sale_customer` (`customer_id`),
  ADD KEY `idx_sales_branch_date` (`branch_id`,`voided`,`sale_date`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_si_sale` (`sale_id`),
  ADD KEY `fk_si_product` (`product_id`);

--
-- Indexes for table `security_alerts`
--
ALTER TABLE `security_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type` (`alert_type`),
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_resolved` (`is_resolved`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `staff_ai_profiles`
--
ALTER TABLE `staff_ai_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user` (`user_id`),
  ADD UNIQUE KEY `uq_appointed` (`appointed_id`),
  ADD KEY `idx_client` (`client_id`);

--
-- Indexes for table `staff_attendance`
--
ALTER TABLE `staff_attendance`
  ADD PRIMARY KEY (`user_id`,`att_date`);

--
-- Indexes for table `staff_finance`
--
ALTER TABLE `staff_finance`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `staff_leave`
--
ALTER TABLE `staff_leave`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`,`start_date`,`end_date`);

--
-- Indexes for table `staff_notifications`
--
ALTER TABLE `staff_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`,`is_read`);

--
-- Indexes for table `staff_payroll`
--
ALTER TABLE `staff_payroll`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user` (`user_id`);

--
-- Indexes for table `staff_time_logs`
--
ALTER TABLE `staff_time_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`,`clock_in`),
  ADD KEY `payout_id` (`payout_id`);

--
-- Indexes for table `stock`
--
ALTER TABLE `stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stock` (`product_id`,`branch_id`),
  ADD KEY `fk_stk_branch` (`branch_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sm_branch` (`branch_id`),
  ADD KEY `fk_sm_product` (`product_id`),
  ADD KEY `fk_sm_user` (`user_id`);

--
-- Indexes for table `stock_notifications`
--
ALTER TABLE `stock_notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_st_from` (`from_branch`),
  ADD KEY `fk_st_to` (`to_branch`),
  ADD KEY `fk_st_product` (`product_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sub_client` (`client_id`),
  ADD KEY `fk_sub_plan` (`plan_id`);

--
-- Indexes for table `subscription_payments`
--
ALTER TABLE `subscription_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sp_receipt` (`receipt_no`),
  ADD KEY `fk_pay_client` (`client_id`),
  ADD KEY `fk_pay_inv` (`invoice_id`),
  ADD KEY `fk_pay_recv` (`received_by`),
  ADD KEY `idx_sp_reference` (`reference`);

--
-- Indexes for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `super_admins`
--
ALTER TABLE `super_admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_sa_invite` (`invite_token_hash`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sup_client` (`client_id`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sp_supplier` (`supplier_id`),
  ADD KEY `fk_sp_po` (`purchase_order_id`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ticket_client` (`client_id`),
  ADD KEY `fk_ticket_admin` (`assigned_to`);

--
-- Indexes for table `system_health`
--
ALTER TABLE `system_health`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_reply_ticket` (`ticket_id`);

--
-- Indexes for table `ticket_typing`
--
ALTER TABLE `ticket_typing`
  ADD PRIMARY KEY (`ticket_id`,`sender_type`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_unit_name` (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uq_users_card_no` (`card_no`),
  ADD KEY `fk_usr_branch` (`branch_id`);

--
-- Indexes for table `wh_audit_log`
--
ALTER TABLE `wh_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_client` (`client_id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_audit_date` (`created_at`);

--
-- Indexes for table `wh_batches`
--
ALTER TABLE `wh_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_batch_no` (`warehouse_id`,`product_id`,`batch_number`),
  ADD KEY `idx_batch_client` (`client_id`),
  ADD KEY `idx_batch_product` (`product_id`),
  ADD KEY `idx_batch_status` (`status`),
  ADD KEY `idx_batch_expiry` (`expiry_date`);

--
-- Indexes for table `wh_batch_locations`
--
ALTER TABLE `wh_batch_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bl` (`batch_id`,`location_id`),
  ADD KEY `idx_bl_batch` (`batch_id`),
  ADD KEY `idx_bl_location` (`location_id`);

--
-- Indexes for table `wh_bom`
--
ALTER TABLE `wh_bom`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bom` (`client_id`,`finished_product_id`,`version`),
  ADD KEY `idx_bom_client` (`client_id`),
  ADD KEY `idx_bom_product` (`finished_product_id`);

--
-- Indexes for table `wh_bom_items`
--
ALTER TABLE `wh_bom_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bom_item_bom` (`bom_id`),
  ADD KEY `idx_bom_item_product` (`input_product_id`);

--
-- Indexes for table `wh_delivery_schedule`
--
ALTER TABLE `wh_delivery_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ds_client` (`client_id`),
  ADD KEY `idx_ds_wh` (`warehouse_id`),
  ADD KEY `idx_ds_date` (`scheduled_date`),
  ADD KEY `idx_ds_status` (`status`);

--
-- Indexes for table `wh_grn`
--
ALTER TABLE `wh_grn`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_grn_number` (`warehouse_id`,`grn_number`),
  ADD KEY `idx_grn_client` (`client_id`),
  ADD KEY `idx_grn_wh` (`warehouse_id`),
  ADD KEY `idx_grn_po` (`po_id`),
  ADD KEY `idx_grn_supplier` (`supplier_id`),
  ADD KEY `idx_grn_date` (`delivery_date`);

--
-- Indexes for table `wh_grn_items`
--
ALTER TABLE `wh_grn_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_grn_item_grn` (`grn_id`),
  ADD KEY `idx_grn_item_product` (`product_id`);

--
-- Indexes for table `wh_locations`
--
ALTER TABLE `wh_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_loc_label` (`warehouse_id`,`label`),
  ADD KEY `idx_loc_wh` (`warehouse_id`),
  ADD KEY `idx_loc_zone` (`zone_id`),
  ADD KEY `idx_loc_client` (`client_id`);

--
-- Indexes for table `wh_outbound_orders`
--
ALTER TABLE `wh_outbound_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ob_number` (`warehouse_id`,`order_number`),
  ADD KEY `idx_ob_client` (`client_id`),
  ADD KEY `idx_ob_wh` (`warehouse_id`),
  ADD KEY `idx_ob_status` (`status`),
  ADD KEY `idx_ob_picker` (`assigned_picker`);

--
-- Indexes for table `wh_pack_slips`
--
ALTER TABLE `wh_pack_slips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ps_slip` (`slip_number`),
  ADD KEY `idx_ps_outbound` (`outbound_id`);

--
-- Indexes for table `wh_pack_slip_items`
--
ALTER TABLE `wh_pack_slip_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_psi_slip` (`slip_id`),
  ADD KEY `idx_psi_pick` (`pick_item_id`);

--
-- Indexes for table `wh_pick_items`
--
ALTER TABLE `wh_pick_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pi_outbound` (`outbound_id`),
  ADD KEY `idx_pi_product` (`product_id`),
  ADD KEY `idx_pi_batch` (`batch_id`),
  ADD KEY `idx_pi_location` (`location_id`);

--
-- Indexes for table `wh_production_inputs`
--
ALTER TABLE `wh_production_inputs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pi_prod` (`production_id`),
  ADD KEY `idx_pi_product` (`product_id`),
  ADD KEY `idx_pi_batch` (`batch_id`);

--
-- Indexes for table `wh_production_orders`
--
ALTER TABLE `wh_production_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prod_number` (`warehouse_id`,`order_number`),
  ADD KEY `idx_prod_client` (`client_id`),
  ADD KEY `idx_prod_wh` (`warehouse_id`),
  ADD KEY `idx_prod_status` (`status`),
  ADD KEY `idx_prod_bom` (`bom_id`);

--
-- Indexes for table `wh_stock_counts`
--
ALTER TABLE `wh_stock_counts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cnt_number` (`warehouse_id`,`count_number`),
  ADD KEY `idx_cnt_client` (`client_id`),
  ADD KEY `idx_cnt_wh` (`warehouse_id`);

--
-- Indexes for table `wh_stock_count_items`
--
ALTER TABLE `wh_stock_count_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sci_count` (`count_id`),
  ADD KEY `idx_sci_product` (`product_id`),
  ADD KEY `idx_sci_location` (`location_id`);

--
-- Indexes for table `wh_stock_ledger`
--
ALTER TABLE `wh_stock_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ledger_client` (`client_id`),
  ADD KEY `idx_ledger_wh` (`warehouse_id`),
  ADD KEY `idx_ledger_product` (`product_id`),
  ADD KEY `idx_ledger_batch` (`batch_id`),
  ADD KEY `idx_ledger_location` (`location_id`),
  ADD KEY `idx_ledger_type` (`movement_type`),
  ADD KEY `idx_ledger_date` (`created_at`),
  ADD KEY `idx_ledger_ref` (`reference_type`,`reference_id`);

--
-- Indexes for table `wh_transfers`
--
ALTER TABLE `wh_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_iwt_number` (`client_id`,`transfer_number`),
  ADD KEY `idx_iwt_client` (`client_id`),
  ADD KEY `idx_iwt_from` (`from_warehouse_id`),
  ADD KEY `idx_iwt_to` (`to_warehouse_id`);

--
-- Indexes for table `wh_transfer_items`
--
ALTER TABLE `wh_transfer_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_iwti_transfer` (`transfer_id`),
  ADD KEY `idx_iwti_product` (`product_id`);

--
-- Indexes for table `wh_user_settings`
--
ALTER TABLE `wh_user_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wus_user` (`user_id`);

--
-- Indexes for table `wh_user_warehouse`
--
ALTER TABLE `wh_user_warehouse`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_uw` (`user_id`,`warehouse_id`),
  ADD KEY `idx_uw_user` (`user_id`),
  ADD KEY `idx_uw_wh` (`warehouse_id`);

--
-- Indexes for table `wh_warehouses`
--
ALTER TABLE `wh_warehouses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wh_code` (`client_id`,`code`),
  ADD KEY `idx_wh_client` (`client_id`),
  ADD KEY `idx_wh_branch` (`branch_id`);

--
-- Indexes for table `wh_zones`
--
ALTER TABLE `wh_zones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_zone_code` (`warehouse_id`,`code`),
  ADD KEY `idx_zone_wh` (`warehouse_id`),
  ADD KEY `idx_zone_client` (`client_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_reviews`
--
ALTER TABLE `account_reviews`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `account_review_log`
--
ALTER TABLE `account_review_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_activity_log`
--
ALTER TABLE `admin_activity_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `admin_broadcast_log`
--
ALTER TABLE `admin_broadcast_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `ag_batches`
--
ALTER TABLE `ag_batches`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ag_credit_promises`
--
ALTER TABLE `ag_credit_promises`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ag_preorders`
--
ALTER TABLE `ag_preorders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ag_product_targets`
--
ALTER TABLE `ag_product_targets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ag_restricted_sales`
--
ALTER TABLE `ag_restricted_sales`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ag_unit_conversions`
--
ALTER TABLE `ag_unit_conversions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_insights`
--
ALTER TABLE `ai_insights`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `ai_usage`
--
ALTER TABLE `ai_usage`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `appointed_staff`
--
ALTER TABLE `appointed_staff`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=166;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `broadcast_log`
--
ALTER TABLE `broadcast_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `broadcast_queue`
--
ALTER TABLE `broadcast_queue`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `client_portal_users`
--
ALTER TABLE `client_portal_users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `company_policies`
--
ALTER TABLE `company_policies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `customer_payments`
--
ALTER TABLE `customer_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `error_log`
--
ALTER TABLE `error_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `etims_queue`
--
ALTER TABLE `etims_queue`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `guidelines`
--
ALTER TABLE `guidelines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `guideline_signatures`
--
ALTER TABLE `guideline_signatures`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `hourly_payouts`
--
ALTER TABLE `hourly_payouts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=920;

--
-- AUTO_INCREMENT for table `jb_applications`
--
ALTER TABLE `jb_applications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `jb_app_log`
--
ALTER TABLE `jb_app_log`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `jb_blocks`
--
ALTER TABLE `jb_blocks`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jb_candidates`
--
ALTER TABLE `jb_candidates`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `jb_jobs`
--
ALTER TABLE `jb_jobs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `mp_customers`
--
ALTER TABLE `mp_customers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `mp_listings`
--
ALTER TABLE `mp_listings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `mp_messages`
--
ALTER TABLE `mp_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `mp_orders`
--
ALTER TABLE `mp_orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `mp_order_items`
--
ALTER TABLE `mp_order_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `mp_order_tracking`
--
ALTER TABLE `mp_order_tracking`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `mp_reviews`
--
ALTER TABLE `mp_reviews`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `mp_store_photos`
--
ALTER TABLE `mp_store_photos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mp_threads`
--
ALTER TABLE `mp_threads`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `mp_vehicles`
--
ALTER TABLE `mp_vehicles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `msg_messages`
--
ALTER TABLE `msg_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `msg_threads`
--
ALTER TABLE `msg_threads`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `nx_alert_mail_log`
--
ALTER TABLE `nx_alert_mail_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `nx_auto_log`
--
ALTER TABLE `nx_auto_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `nx_login_watch`
--
ALTER TABLE `nx_login_watch`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `onboarding_requests`
--
ALTER TABLE `onboarding_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payslips`
--
ALTER TABLE `payslips`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `salary_allocations`
--
ALTER TABLE `salary_allocations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `salary_allocation_requests`
--
ALTER TABLE `salary_allocation_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `salary_disbursements`
--
ALTER TABLE `salary_disbursements`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `salary_requests`
--
ALTER TABLE `salary_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=250;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=579;

--
-- AUTO_INCREMENT for table `security_alerts`
--
ALTER TABLE `security_alerts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `staff_ai_profiles`
--
ALTER TABLE `staff_ai_profiles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `staff_leave`
--
ALTER TABLE `staff_leave`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `staff_notifications`
--
ALTER TABLE `staff_notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `staff_payroll`
--
ALTER TABLE `staff_payroll`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `staff_time_logs`
--
ALTER TABLE `staff_time_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=356;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=336;

--
-- AUTO_INCREMENT for table `stock_notifications`
--
ALTER TABLE `stock_notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=920;

--
-- AUTO_INCREMENT for table `subscription_payments`
--
ALTER TABLE `subscription_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `super_admins`
--
ALTER TABLE `super_admins`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `system_health`
--
ALTER TABLE `system_health`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `wh_audit_log`
--
ALTER TABLE `wh_audit_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `wh_batches`
--
ALTER TABLE `wh_batches`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wh_batch_locations`
--
ALTER TABLE `wh_batch_locations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wh_bom`
--
ALTER TABLE `wh_bom`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wh_bom_items`
--
ALTER TABLE `wh_bom_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wh_delivery_schedule`
--
ALTER TABLE `wh_delivery_schedule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `wh_grn`
--
ALTER TABLE `wh_grn`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wh_grn_items`
--
ALTER TABLE `wh_grn_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `wh_locations`
--
ALTER TABLE `wh_locations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `wh_outbound_orders`
--
ALTER TABLE `wh_outbound_orders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wh_pack_slips`
--
ALTER TABLE `wh_pack_slips`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wh_pack_slip_items`
--
ALTER TABLE `wh_pack_slip_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wh_pick_items`
--
ALTER TABLE `wh_pick_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wh_production_inputs`
--
ALTER TABLE `wh_production_inputs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wh_production_orders`
--
ALTER TABLE `wh_production_orders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wh_stock_counts`
--
ALTER TABLE `wh_stock_counts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `wh_stock_count_items`
--
ALTER TABLE `wh_stock_count_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wh_stock_ledger`
--
ALTER TABLE `wh_stock_ledger`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `wh_transfers`
--
ALTER TABLE `wh_transfers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wh_transfer_items`
--
ALTER TABLE `wh_transfer_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wh_user_settings`
--
ALTER TABLE `wh_user_settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wh_user_warehouse`
--
ALTER TABLE `wh_user_warehouse`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `wh_warehouses`
--
ALTER TABLE `wh_warehouses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wh_zones`
--
ALTER TABLE `wh_zones`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

-- --------------------------------------------------------

--
-- Structure for view `v_client_subscriptions`
--
DROP TABLE IF EXISTS `v_client_subscriptions`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_client_subscriptions`  AS SELECT `c`.`id` AS `client_id`, `c`.`business_name` AS `business_name`, `c`.`phone` AS `phone`, `c`.`status` AS `account_status`, `sp`.`name` AS `plan`, `s`.`branch_count` AS `branch_count`, `s`.`total_monthly` AS `total_monthly`, `s`.`billing_cycle` AS `billing_cycle`, `s`.`start_date` AS `start_date`, `s`.`end_date` AS `end_date`, `s`.`grace_end_date` AS `grace_end_date`, `s`.`status` AS `subscription_status`, (to_days(`s`.`end_date`) - to_days(curdate())) AS `days_remaining`, (select sum(`p`.`amount`) from `subscription_payments` `p` where (`p`.`client_id` = `c`.`id`)) AS `total_paid_lifetime` FROM ((`clients` `c` join `subscriptions` `s` on((`s`.`client_id` = `c`.`id`))) join `subscription_plans` `sp` on((`sp`.`id` = `s`.`plan_id`))) WHERE (`s`.`id` = (select max(`s2`.`id`) from `subscriptions` `s2` where (`s2`.`client_id` = `c`.`id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_expiring_soon`
--
DROP TABLE IF EXISTS `v_expiring_soon`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_expiring_soon`  AS SELECT `c`.`id` AS `id`, `c`.`business_name` AS `business_name`, `sp`.`name` AS `plan`, `s`.`total_monthly` AS `total_monthly`, `s`.`end_date` AS `end_date`, (to_days(`s`.`end_date`) - to_days(curdate())) AS `days_left` FROM ((`clients` `c` join `subscriptions` `s` on((`s`.`id` = (select max(`s2`.`id`) from `subscriptions` `s2` where (`s2`.`client_id` = `c`.`id`))))) left join `subscription_plans` `sp` on((`sp`.`id` = `s`.`plan_id`))) WHERE ((`s`.`status` in ('active','grace')) AND ((to_days(`s`.`end_date`) - to_days(curdate())) between 0 and 7)) ORDER BY (to_days(`s`.`end_date`) - to_days(curdate())) ASC ;

-- --------------------------------------------------------

--
-- Structure for view `v_failed_logins_24h`
--
DROP TABLE IF EXISTS `v_failed_logins_24h`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_failed_logins_24h`  AS SELECT `login_attempts`.`ip_address` AS `ip_address`, count(0) AS `attempt_count`, count(distinct `login_attempts`.`email`) AS `distinct_emails`, min(`login_attempts`.`created_at`) AS `first_seen`, max(`login_attempts`.`created_at`) AS `last_seen`, group_concat(distinct `login_attempts`.`country` order by `login_attempts`.`country` ASC separator ', ') AS `countries` FROM `login_attempts` WHERE ((`login_attempts`.`result` = 'failure') AND (`login_attempts`.`created_at` >= (now() - interval 24 hour))) GROUP BY `login_attempts`.`ip_address` ORDER BY `attempt_count` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_monthly_revenue`
--
DROP TABLE IF EXISTS `v_monthly_revenue`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_monthly_revenue`  AS SELECT date_format(`subscription_payments`.`payment_date`,'%Y-%m') AS `month`, coalesce(sum(`subscription_payments`.`amount`),0) AS `total_revenue`, count(0) AS `payment_count` FROM `subscription_payments` WHERE (`subscription_payments`.`confirmed` = 1) GROUP BY date_format(`subscription_payments`.`payment_date`,'%Y-%m') ORDER BY `month` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `v_soc_summary`
--
DROP TABLE IF EXISTS `v_soc_summary`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_soc_summary`  AS SELECT (select count(0) from `login_attempts` where (`login_attempts`.`created_at` >= (now() - interval 24 hour))) AS `logins_24h`, (select count(0) from `login_attempts` where ((`login_attempts`.`result` = 'failure') and (`login_attempts`.`created_at` >= (now() - interval 24 hour)))) AS `failures_24h`, (select count(0) from `login_attempts` where ((`login_attempts`.`result` = 'success') and (`login_attempts`.`created_at` >= (now() - interval 24 hour)))) AS `successes_24h`, (select count(0) from `admin_sessions` where ((`admin_sessions`.`ended_at` is null) and (`admin_sessions`.`last_active` >= (now() - interval 1 hour)))) AS `active_sessions`, (select count(0) from `security_alerts` where (`security_alerts`.`is_resolved` = 0)) AS `open_alerts`, (select count(0) from `security_alerts` where ((`security_alerts`.`is_resolved` = 0) and (`security_alerts`.`severity` in ('high','critical')))) AS `critical_alerts`, (select count(0) from `audit_log` where (`audit_log`.`created_at` >= (now() - interval 24 hour))) AS `audit_events_24h`, (select count(distinct `login_attempts`.`ip_address`) from `login_attempts` where ((`login_attempts`.`result` = 'failure') and (`login_attempts`.`created_at` >= (now() - interval 24 hour)))) AS `suspicious_ips` ;

-- --------------------------------------------------------

--
-- Structure for view `v_system_overview`
--
DROP TABLE IF EXISTS `v_system_overview`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_system_overview`  AS SELECT (select count(0) from `clients` where (`clients`.`status` = 'active')) AS `active_clients`, (select count(0) from `clients` where (`clients`.`status` = 'read_only')) AS `readonly_clients`, (select count(0) from `clients` where (`clients`.`status` = 'suspended')) AS `suspended_clients`, (select count(0) from `branches` where (`branches`.`is_active` = 1)) AS `total_branches`, (select count(0) from `users` where (`users`.`is_active` = 1)) AS `total_users`, (select coalesce(sum(`sp`.`total_monthly`),0) from `subscriptions` `sp` where ((`sp`.`status` = 'active') and (month(`sp`.`start_date`) = month(curdate())) and (year(`sp`.`start_date`) = year(curdate())))) AS `sub_revenue_this_month`, (select count(0) from `support_tickets` where (`support_tickets`.`status` in ('open','in_progress'))) AS `open_tickets`, (select count(0) from `error_log` where ((`error_log`.`level` in ('error','critical')) and (`error_log`.`created_at` >= (now() - interval 24 hour)))) AS `errors_24h`, 0 AS `sales_today`, 0 AS `revenue_today`, 0 AS `total_products` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `branches`
--
ALTER TABLE `branches`
  ADD CONSTRAINT `fk_br_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `fk_cat_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `client_portal_users`
--
ALTER TABLE `client_portal_users`
  ADD CONSTRAINT `fk_cpu_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_cust_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD CONSTRAINT `fk_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_cp_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `etims_queue`
--
ALTER TABLE `etims_queue`
  ADD CONSTRAINT `etims_queue_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  ADD CONSTRAINT `etims_queue_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`);

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_exp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_exp_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_inv_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inv_sub` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_prod_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_prod_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_prod_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `fk_pi_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sale_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sale_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_si_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock`
--
ALTER TABLE `stock`
  ADD CONSTRAINT `fk_stk_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stk_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_sm_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_sm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_sm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD CONSTRAINT `fk_st_from` FOREIGN KEY (`from_branch`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fk_st_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_st_to` FOREIGN KEY (`to_branch`) REFERENCES `branches` (`id`);

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `fk_sub_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `subscription_payments`
--
ALTER TABLE `subscription_payments`
  ADD CONSTRAINT `fk_pay_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pay_inv` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pay_recv` FOREIGN KEY (`received_by`) REFERENCES `super_admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `fk_sup_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD CONSTRAINT `fk_sp_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `fk_ticket_admin` FOREIGN KEY (`assigned_to`) REFERENCES `super_admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ticket_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD CONSTRAINT `fk_reply_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_usr_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

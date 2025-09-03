-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 03, 2025 at 10:55 AM
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
-- Database: `it_repair_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED NOT NULL,
  `status` enum('Draft','Finalized','Cancelled') NOT NULL DEFAULT 'Draft',
  `tax_rate` decimal(5,4) NOT NULL DEFAULT 0.1300,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `quote_status` varchar(50) DEFAULT 'Pending',
  `approved_by` int(11) DEFAULT NULL,
  `payment_status` enum('Unpaid','Paid','Failed') NOT NULL DEFAULT 'Unpaid',
  `payment_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `item` varchar(160) NOT NULL,
  `unit` varchar(40) DEFAULT 'pcs',
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `knowledge_base`
--

CREATE TABLE `knowledge_base` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` text NOT NULL,
  `category` enum('General','Repair','Billing','Account','Warranty','Shipping') DEFAULT 'General',
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `knowledge_base`
--

INSERT INTO `knowledge_base` (`id`, `title`, `content`, `category`, `is_published`, `created_at`, `updated_at`) VALUES
(1, 'How long does a repair take?', 'Most repairs are completed within 3–5 business days. Complex issues may take 5–7 days. You’ll receive email updates at every stage.', 'Repair', 1, '2025-08-22 16:07:07', '2025-08-22 16:07:07'),
(2, 'Do you offer pickup and delivery?', 'Yes! We offer free pickup and delivery within Kathmandu Valley. Select \"Pickup\" or \"On-site\" when submitting your request.', 'General', 1, '2025-08-22 16:07:07', '2025-08-22 16:07:07'),
(3, 'Is my device insured during repair?', 'Yes. All devices are covered under our care, custody, and control policy for accidental damage, theft, or loss.', 'General', 1, '2025-08-22 16:07:07', '2025-08-22 16:07:07'),
(4, 'What if my device can’t be repaired?', 'If a repair isn’t possible, we’ll provide a diagnostic report and discuss options: replacement, data recovery, or disposal. No charges apply unless you approve.', 'Repair', 1, '2025-08-22 16:07:07', '2025-08-22 16:07:07'),
(5, 'Can I get a quote before repair?', 'Yes. After diagnosis, we’ll send a detailed quote. You can approve, request changes, or cancel — no fees apply until you confirm.', 'Billing', 1, '2025-08-22 16:07:07', '2025-08-22 16:07:07'),
(6, 'Do you repair water-damaged devices?', 'Yes. We specialize in liquid damage recovery. Bring your device in as soon as possible — we’ll clean, dry, and test components.', 'Repair', 1, '2025-08-22 16:07:07', '2025-08-22 16:07:07'),
(7, 'How do I track my repair?', 'Log in to your dashboard and go to \"My Requests\". Each repair shows real-time status and timeline updates.', 'General', 1, '2025-08-22 16:07:07', '2025-08-22 16:07:07'),
(8, 'What payment methods do you accept?', 'We accept credit/debit cards, bank transfer, and mobile wallets. Invoices can be paid online via the dashboard.', 'Billing', 1, '2025-08-22 16:07:07', '2025-08-22 16:07:07');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `body`, `is_read`, `created_at`) VALUES
(11, 5, 'Request Received', 'Ticket 6E0D85BF has been created.', 0, '2025-08-22 05:17:30'),
(12, 5, 'Request Received', 'Ticket 437E5973 has been created.', 0, '2025-08-22 08:44:44'),
(13, 4, 'Request Received', 'Ticket 761EA345 has been created.', 0, '2025-08-22 15:01:48'),
(14, 6, 'Request Received', 'Ticket 1C57FC97 has been created.', 0, '2025-08-23 08:22:58'),
(15, 6, 'Request Received', 'Ticket 005BD7A7 has been created.', 0, '2025-08-23 08:23:34'),
(16, 6, 'Request Received', 'Ticket 1B7CB45C has been created.', 0, '2025-08-23 08:30:28'),
(17, 7, 'Request Received', 'Ticket A3505A6A has been created.', 0, '2025-08-24 00:42:21'),
(18, 7, 'Request Received', 'Ticket 250824DTP194B has been created.', 0, '2025-08-24 01:23:37'),
(19, 7, 'Request Received', 'Ticket 250824DTPBE48 has been created.', 0, '2025-08-24 01:27:59'),
(20, 7, 'Request Received', 'Ticket 250824TBD001 has been created.', 0, '2025-08-24 01:29:45'),
(21, 6, 'Request Received', 'Ticket 250824TBO001 has been created.', 0, '2025-08-24 04:38:32'),
(22, 8, 'Request Received', 'Ticket 250824LTP001 has been created.', 0, '2025-08-24 09:10:42'),
(23, 5, 'Request Received', 'Ticket 250825TBD001 has been created.', 0, '2025-08-25 03:58:08'),
(24, 5, 'Request Received', 'Ticket 250825PRD001 has been created.', 0, '2025-08-25 04:03:25'),
(25, 5, 'Request Received', 'Ticket 250825PRD002 has been created.', 0, '2025-08-25 04:06:57'),
(26, 5, 'Request Received', 'Ticket 250825DTD001 has been created.', 0, '2025-08-25 04:10:27'),
(27, 5, 'Request Received', 'Ticket 250825DTD002 has been created.', 0, '2025-08-25 07:19:32'),
(28, 5, 'Request Received', 'Ticket 250825TBD002 has been created.', 0, '2025-08-25 08:08:41'),
(29, 9, 'Request Received', 'Ticket 250825PHP001 has been created.', 0, '2025-08-25 08:23:07'),
(30, 5, 'Request Received', 'Ticket 250903DTD001 has been created.', 0, '2025-09-03 06:59:46');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `repair_requests`
--

CREATE TABLE `repair_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_code` varchar(32) NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `device_type` varchar(60) NOT NULL,
  `brand` varchar(80) DEFAULT NULL,
  `model` varchar(120) DEFAULT NULL,
  `serial_no` varchar(120) DEFAULT NULL,
  `issue_description` text NOT NULL,
  `service_type` enum('dropoff','pickup','onsite') NOT NULL DEFAULT 'dropoff',
  `preferred_contact` enum('phone','email') NOT NULL DEFAULT 'phone',
  `accessories` text DEFAULT NULL,
  `warranty_status` enum('in_warranty','out_of_warranty','unknown') DEFAULT 'unknown',
  `priority` enum('low','normal','high') DEFAULT 'normal',
  `status` enum('Received','Pickup In Progress','Device Received','At Warehouse','In Repair','Onsite In Progress','Onsite Repair Started','Onsite Completed','Billed','Shipped','Delivered','Rejected','Cancelled') NOT NULL DEFAULT 'Received',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `repair_requests`
--

INSERT INTO `repair_requests` (`id`, `ticket_code`, `customer_id`, `device_type`, `brand`, `model`, `serial_no`, `issue_description`, `service_type`, `preferred_contact`, `accessories`, `warranty_status`, `priority`, `status`, `created_at`, `address`, `city`, `postal_code`, `updated_at`) VALUES
(11, '6E0D85BF', 5, 'Laptop', 'dell', 'latitude', '12345679', 'Keyboard/trackpad not working', 'pickup', '', 'Charger', 'unknown', 'normal', 'In Repair', '2025-08-22 05:17:30', 'chandragiri', 'Kathmandu', '4500', '2025-08-22 05:18:15'),
(12, '437E5973', 5, 'Desktop', 'acer', 'vestro', '123456', 'Slow performance', 'pickup', '', 'Keyboard, Monitor, Mouse', 'unknown', 'normal', 'In Repair', '2025-08-22 08:44:44', 'chandragiri', 'Kathmandu', '4500', '2025-08-22 08:45:15'),
(13, '761EA345', 4, 'Laptop', 'dell', 'vestro', '123', 'Slow performance; Keyboard issue; Overheating', 'dropoff', 'email', 'charger, backup drive', 'unknown', 'high', 'Received', '2025-08-22 15:01:48', NULL, NULL, NULL, '2025-08-22 15:01:48'),
(14, '1C57FC97', 6, 'Desktop', 'Hp', 'pavilion', '12345', 'Slow performance; Disk failure', 'pickup', 'phone', '-', 'unknown', 'normal', 'Received', '2025-08-23 08:22:58', 'chandragiri-14', 'Kathmandu', '4500', '2025-08-23 08:22:58'),
(15, '005BD7A7', 6, 'Desktop', 'dell', NULL, NULL, 'Slow performance', 'dropoff', 'phone', NULL, 'unknown', 'normal', 'Received', '2025-08-23 08:23:34', NULL, NULL, NULL, '2025-08-23 08:23:34'),
(16, '1B7CB45C', 6, 'Tablet', 'xiaomi', 'x50', NULL, 'Cracked screen', 'dropoff', 'phone', NULL, 'unknown', 'normal', 'Received', '2025-08-23 08:30:28', NULL, NULL, NULL, '2025-08-23 08:30:28'),
(17, 'A3505A6A', 7, 'Other', 'Automobilee', 'JCB123', '12134567890', 'Remote control not working', 'pickup', 'phone', '-', 'unknown', 'normal', 'Received', '2025-08-24 00:42:21', 'chandragiri-13', 'kathmandu', '4500', '2025-08-24 00:42:21'),
(18, '250824DTP194B', 7, 'Desktop', 'dell', 'vestro', '12345', 'Slow performance; No display', 'pickup', 'phone', 'datacable, powercable', 'unknown', 'normal', 'Received', '2025-08-24 01:23:37', 'chandragiri-14', 'Kathmandu', '4500', '2025-08-24 01:23:37'),
(19, '250824DTPBE48', 7, 'Desktop', 'dell', 'vestro', '12345', 'Slow performance; No display', 'pickup', 'phone', 'datacable, powercable', 'unknown', 'normal', 'Received', '2025-08-24 01:27:59', 'chandragiri-14', 'Kathmandu', '4500', '2025-08-24 01:27:59'),
(20, '250824TBD001', 7, 'Tablet', 'SAMSUNG', 'tab5', '123456', 'Touch unresponsive; Wi-Fi not working', 'dropoff', 'phone', '-', 'unknown', 'normal', 'Received', '2025-08-24 01:29:45', NULL, NULL, NULL, '2025-08-24 01:29:45'),
(21, '250824TBO001', 6, 'Tablet', 'dell', 'vestro', '1234567890', 'Connection issues; Button failure; Not detected; Wi-Fi not working; Touch unresponsive; Battery issue; Cracked screen', 'onsite', 'phone', NULL, 'unknown', 'normal', 'Received', '2025-08-24 04:38:32', 'chandragiri-13', 'Kathmandu', '4500', '2025-08-24 04:38:32'),
(22, '250824LTP001', 8, 'Laptop', 'Acer', 'Aspire', '1212333343333', 'Display; Blue screen', 'pickup', 'email', 'fsadfs', 'out_of_warranty', 'high', 'In Repair', '2025-08-24 09:10:42', 'Tripureshwore', 'Kathmandu', '8080', '2025-08-24 09:16:08'),
(23, '250825TBD001', 5, 'Tablet', 'oneplus', 'pad5', '12345', 'Battery issue; Touch unresponsive', 'dropoff', 'phone', 'charger', 'unknown', 'normal', 'Received', '2025-08-25 03:58:08', NULL, NULL, NULL, '2025-08-25 03:58:08'),
(24, '250825PRD001', 5, 'Printer', 'Canon', 'LBP2900', '123456', 'Not printing; Paper jam', 'dropoff', 'phone', 'Cables', 'unknown', 'normal', 'Received', '2025-08-25 04:03:25', NULL, NULL, NULL, '2025-08-25 04:03:25'),
(25, '250825PRD002', 5, 'Printer', 'Canon', 'LBP2900', '1234567890', 'Ink issue; Connectivity problem', 'dropoff', 'phone', NULL, 'unknown', 'normal', 'Received', '2025-08-25 04:06:57', NULL, NULL, NULL, '2025-08-25 04:06:57'),
(26, '250825DTD001', 5, 'Desktop', 'dell', 'vestro', '123456', 'Slow performance; No display', 'dropoff', 'phone', 'datacable, powercable', 'unknown', 'normal', 'Received', '2025-08-25 04:10:27', NULL, NULL, NULL, '2025-08-25 04:10:27'),
(27, '250825DTD002', 5, 'Desktop', 'dell', 'Optiplex 7010', '123456', 'No power', 'dropoff', 'phone', 'datacable, powercable', 'unknown', 'normal', 'Received', '2025-08-25 07:19:32', NULL, NULL, NULL, '2025-08-25 07:19:32'),
(28, '250825TBD002', 5, 'Tablet', 'apple', 'ipad', '123456', 'Battery issue; Touch unresponsive', 'dropoff', 'phone', 'sadfasdf', 'unknown', 'normal', 'Received', '2025-08-25 08:08:41', NULL, NULL, NULL, '2025-08-25 08:08:41'),
(29, '250825PHP001', 9, 'Phone', 'iphone', 'iphoone pro max', '2898329823232', 'Cracked screen with low battery problem', 'pickup', 'phone', 'sd card, sim', 'out_of_warranty', 'high', 'In Repair', '2025-08-25 08:23:07', 'M8JG+82W, Bangalamukhi Rd, Lalitpur 44600', 'Kathmandu', '+97798', '2025-08-25 08:24:39'),
(30, '250903DTD001', 5, 'Desktop', 'desktop1', 'desktop2', 'desktop3', 'No power; No display; Disk failure', 'dropoff', 'phone', NULL, 'unknown', 'normal', 'Received', '2025-09-03 06:59:46', NULL, NULL, NULL, '2025-09-03 06:59:46');

-- --------------------------------------------------------

--
-- Table structure for table `request_assignments`
--

CREATE TABLE `request_assignments` (
  `id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED NOT NULL,
  `desk` enum('Registration','Repair','Billing','Shipping') NOT NULL,
  `assigned_to` int(10) UNSIGNED NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `request_assignments`
--

INSERT INTO `request_assignments` (`id`, `request_id`, `desk`, `assigned_to`, `assigned_at`) VALUES
(6, 11, 'Repair', 3, '2025-08-22 05:18:15'),
(7, 12, 'Repair', 3, '2025-08-22 08:45:15'),
(8, 22, 'Repair', 3, '2025-08-24 09:16:08'),
(9, 29, 'Repair', 3, '2025-08-25 08:24:39');

-- --------------------------------------------------------

--
-- Table structure for table `request_attachments`
--

CREATE TABLE `request_attachments` (
  `id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(20) NOT NULL,
  `uploaded_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `request_attachments`
--

INSERT INTO `request_attachments` (`id`, `request_id`, `file_path`, `file_type`, `uploaded_at`) VALUES
(7, 27, 'uploads/requests/27/att_68ac0e846cef54.95918882.jpg', 'jpg', '2025-08-25 13:04:32'),
(8, 28, 'uploads/requests/28/att_68ac1a096df914.32670523.jpg', 'jpg', '2025-08-25 13:53:41'),
(9, 30, 'uploads/requests/30/att_68b7e7627b6b02.06102743.png', 'png', '2025-09-03 12:44:46');

-- --------------------------------------------------------

--
-- Table structure for table `request_parts`
--

CREATE TABLE `request_parts` (
  `id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED NOT NULL,
  `item` varchar(160) NOT NULL,
  `unit` varchar(40) DEFAULT 'pcs',
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `remarks` varchar(255) DEFAULT NULL,
  `added_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `request_parts`
--

INSERT INTO `request_parts` (`id`, `request_id`, `item`, `unit`, `qty`, `remarks`, `added_by`, `created_at`) VALUES
(2, 29, 'screen replaced', 'pcs', 1.00, '', 3, '2025-08-25 08:26:14');

-- --------------------------------------------------------

--
-- Table structure for table `request_status_history`
--

CREATE TABLE `request_status_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED NOT NULL,
  `status` enum('Received','In Repair','Billed','Shipped','Delivered','Rejected','Cancelled') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `changed_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `request_status_history`
--

INSERT INTO `request_status_history` (`id`, `request_id`, `status`, `note`, `changed_by`, `created_at`, `changed_at`) VALUES
(30, 11, 'Received', 'Request submitted by customer', NULL, '2025-08-22 05:17:30', '2025-08-22 05:17:30'),
(31, 11, '', 'Staff going for pickup', 3, '2025-08-22 05:18:05', '2025-08-22 05:18:05'),
(32, 11, '', 'Device collected from customer', 3, '2025-08-22 05:18:09', '2025-08-22 05:18:09'),
(33, 11, '', 'Device entered warehouse', 3, '2025-08-22 05:18:12', '2025-08-22 05:18:12'),
(34, 11, 'In Repair', 'Forwarded to Repair desk', 3, '2025-08-22 05:18:15', '2025-08-22 05:18:15'),
(35, 12, 'Received', 'Request submitted by customer', NULL, '2025-08-22 08:44:44', '2025-08-22 08:44:44'),
(36, 12, '', 'Staff going for pickup', 3, '2025-08-22 08:44:53', '2025-08-22 08:44:53'),
(37, 12, '', 'Device collected from customer', 3, '2025-08-22 08:45:11', '2025-08-22 08:45:11'),
(38, 12, '', 'Device entered warehouse', 3, '2025-08-22 08:45:13', '2025-08-22 08:45:13'),
(39, 12, 'In Repair', 'Forwarded to Repair desk', 3, '2025-08-22 08:45:15', '2025-08-22 08:45:15'),
(40, 13, 'Received', 'Request submitted by customer', NULL, '2025-08-22 15:01:48', '2025-08-22 15:01:48'),
(41, 14, 'Received', 'Request submitted by customer', NULL, '2025-08-23 08:22:58', '2025-08-23 08:22:58'),
(42, 15, 'Received', 'Request submitted by customer', NULL, '2025-08-23 08:23:34', '2025-08-23 08:23:34'),
(43, 16, 'Received', 'Request submitted by customer', NULL, '2025-08-23 08:30:28', '2025-08-23 08:30:28'),
(44, 17, 'Received', 'Request submitted by customer', NULL, '2025-08-24 00:42:21', '2025-08-24 00:42:21'),
(45, 18, 'Received', 'Request submitted by customer', NULL, '2025-08-24 01:23:37', '2025-08-24 01:23:37'),
(46, 19, 'Received', 'Request submitted by customer', NULL, '2025-08-24 01:27:59', '2025-08-24 01:27:59'),
(47, 20, 'Received', 'Request submitted by customer', NULL, '2025-08-24 01:29:45', '2025-08-24 01:29:45'),
(48, 21, 'Received', 'Request submitted by customer', NULL, '2025-08-24 04:38:32', '2025-08-24 04:38:32'),
(49, 22, 'Received', 'Request submitted by customer', NULL, '2025-08-24 09:10:42', '2025-08-24 09:10:42'),
(50, 22, '', 'Staff going for pickup', 3, '2025-08-24 09:15:59', '2025-08-24 09:15:59'),
(51, 22, '', 'Device collected from customer', 3, '2025-08-24 09:16:02', '2025-08-24 09:16:02'),
(52, 22, '', 'Device entered warehouse', 3, '2025-08-24 09:16:05', '2025-08-24 09:16:05'),
(53, 22, 'In Repair', 'Forwarded to Repair desk', 3, '2025-08-24 09:16:08', '2025-08-24 09:16:08'),
(54, 23, 'Received', 'Request submitted by customer', NULL, '2025-08-25 03:58:08', '2025-08-25 03:58:08'),
(55, 24, 'Received', 'Request submitted by customer', NULL, '2025-08-25 04:03:25', '2025-08-25 04:03:25'),
(56, 25, 'Received', 'Request submitted by customer', NULL, '2025-08-25 04:06:57', '2025-08-25 04:06:57'),
(57, 26, 'Received', 'Request submitted by customer', NULL, '2025-08-25 04:10:27', '2025-08-25 04:10:27'),
(58, 27, 'Received', 'Request submitted by customer', NULL, '2025-08-25 07:19:32', '2025-08-25 07:19:32'),
(59, 28, 'Received', 'Request submitted by customer', NULL, '2025-08-25 08:08:41', '2025-08-25 08:08:41'),
(60, 29, 'Received', 'Request submitted by customer', NULL, '2025-08-25 08:23:07', '2025-08-25 08:23:07'),
(61, 29, '', 'Staff going for pickup', 3, '2025-08-25 08:23:58', '2025-08-25 08:23:58'),
(62, 29, '', 'Device collected from customer', 3, '2025-08-25 08:24:19', '2025-08-25 08:24:19'),
(63, 29, '', 'Device entered warehouse', 3, '2025-08-25 08:24:30', '2025-08-25 08:24:30'),
(64, 29, 'In Repair', 'Forwarded to Repair desk', 3, '2025-08-25 08:24:39', '2025-08-25 08:24:39'),
(65, 30, 'Received', 'Request submitted by customer', NULL, '2025-09-03 06:59:46', '2025-09-03 06:59:46');

-- --------------------------------------------------------

--
-- Table structure for table `service_bookings`
--

CREATE TABLE `service_bookings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `slot_id` varchar(255) NOT NULL COMMENT 'Dynamic slot identifier (YYYY-MM-DD_HH:MM) or link to service_slots.id if structure changes back',
  `request_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK to repair_requests.id (nullable until request is created in some flows)',
  `user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK to users.id (customer who booked)',
  `status` enum('booked','cancelled','no_show') NOT NULL DEFAULT 'booked',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cancelled_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_bookings`
--

INSERT INTO `service_bookings` (`id`, `slot_id`, `request_id`, `user_id`, `status`, `notes`, `created_at`, `cancelled_at`) VALUES
(1, '2', 13, 4, 'booked', 'Customer booked during request creation', '2025-08-22 15:01:48', NULL),
(2, '2', 14, 6, 'booked', 'Customer booked during request creation', '2025-08-23 08:22:58', NULL),
(3, '5', 17, 7, 'booked', 'Customer booked during request creation', '2025-08-24 00:42:21', NULL),
(4, '5', 18, 7, 'booked', 'Customer booked during request creation', '2025-08-24 01:23:37', NULL),
(5, '5', 19, 7, 'booked', 'Customer booked during request creation', '2025-08-24 01:27:59', NULL),
(6, '6', 20, 7, 'booked', 'Customer booked during request creation', '2025-08-24 01:29:45', NULL),
(7, '8', 21, 6, 'booked', 'Customer booked during request creation', '2025-08-24 04:38:32', NULL),
(8, '6', 22, 8, 'booked', 'Customer booked during request creation', '2025-08-24 09:10:42', NULL),
(9, '2025-08-26_11:30', 23, 5, 'booked', 'Customer booked during request creation', '2025-08-25 03:58:08', NULL),
(10, '2025-08-25_11:30', 24, 5, 'booked', 'Customer booked during request creation', '2025-08-25 04:03:25', NULL),
(11, '2025-08-25_11:30', 25, 5, 'booked', 'Customer booked during request creation', '2025-08-25 04:06:57', NULL),
(12, '2025-08-25_11:30', 26, 5, 'booked', 'Customer booked during request creation', '2025-08-25 04:10:27', NULL),
(13, '2025-08-25_11:30', 27, 5, 'booked', 'Customer booked during request creation', '2025-08-25 07:19:32', NULL),
(14, '2025-08-25_11:30', 28, 5, 'booked', 'Customer booked during request creation', '2025-08-25 08:08:41', NULL),
(15, '2025-08-25_09:00', 29, 9, 'booked', 'Customer booked during request creation', '2025-08-25 08:23:07', NULL),
(16, '2025-09-11_afternoon', 30, 5, 'booked', 'Customer booked date/time period during request creation', '2025-09-03 06:59:46', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `service_slots`
--

CREATE TABLE `service_slots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `service_center_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Optional - links to a center/branch id if you have multiple locations',
  `slot_date` date NOT NULL COMMENT 'Date of the slot (YYYY-MM-DD)',
  `start_time` time NOT NULL COMMENT 'Start time (local server timezone)',
  `end_time` time NOT NULL,
  `capacity` int(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'How many bookings allowed in this slot',
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = inactive, 1 = active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_slots`
--

INSERT INTO `service_slots` (`id`, `service_center_id`, `slot_date`, `start_time`, `end_time`, `capacity`, `active`, `created_at`, `updated_at`) VALUES
(1, 1, '2025-08-23', '09:00:00', '11:00:00', 50, 1, '2025-08-22 11:56:49', '2025-08-24 01:30:36'),
(2, 1, '2025-08-23', '11:30:00', '13:30:00', 50, 1, '2025-08-22 11:56:49', '2025-08-24 01:30:49'),
(3, 1, '2025-08-23', '15:00:00', '17:00:00', 50, 1, '2025-08-22 11:56:49', '2025-08-24 01:30:55'),
(4, 1, '2025-08-24', '09:00:00', '11:00:00', 50, 1, '2025-08-22 11:56:49', '2025-08-24 01:31:04'),
(5, 1, '2025-08-24', '11:30:00', '13:30:00', 50, 1, '2025-08-22 11:56:49', '2025-08-24 01:31:07'),
(6, 1, '2025-08-24', '15:00:00', '17:00:00', 60, 1, '2025-08-22 11:56:49', '2025-08-24 01:31:11'),
(7, 1, '2025-08-25', '09:00:00', '11:00:00', 5, 1, '2025-08-22 11:56:49', '2025-08-24 01:31:33'),
(8, 1, '2025-08-25', '11:30:00', '13:30:00', 50, 1, '2025-08-22 11:56:49', '2025-08-24 01:31:35'),
(9, 1, '2025-08-25', '15:00:00', '17:00:00', 50, 1, '2025-08-22 11:56:49', '2025-08-24 01:31:39'),
(10, 1, '2025-08-26', '09:00:00', '11:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(11, 1, '2025-08-26', '11:30:00', '13:30:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(12, 1, '2025-08-26', '15:00:00', '17:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(13, 1, '2025-08-27', '09:00:00', '11:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(14, 1, '2025-08-27', '11:30:00', '13:30:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(15, 1, '2025-08-27', '15:00:00', '17:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(16, 1, '2025-08-28', '09:00:00', '11:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(17, 1, '2025-08-28', '11:30:00', '13:30:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(18, 1, '2025-08-28', '15:00:00', '17:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(19, 1, '2025-08-29', '09:00:00', '11:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(20, 1, '2025-08-29', '11:30:00', '13:30:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(21, 1, '2025-08-29', '15:00:00', '17:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(22, 1, '2025-08-30', '09:00:00', '11:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(23, 1, '2025-08-30', '11:30:00', '13:30:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(24, 1, '2025-08-30', '15:00:00', '17:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(25, 1, '2025-08-31', '09:00:00', '11:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(26, 1, '2025-08-31', '11:30:00', '13:30:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(27, 1, '2025-08-31', '15:00:00', '17:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(28, 1, '2025-09-01', '09:00:00', '11:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(29, 1, '2025-09-01', '11:30:00', '13:30:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11'),
(30, 1, '2025-09-01', '15:00:00', '17:00:00', 5, 1, '2025-08-24 04:46:11', '2025-08-24 04:46:11');

-- --------------------------------------------------------

--
-- Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED NOT NULL,
  `delivery_note_no` varchar(40) NOT NULL,
  `ship_address` varchar(255) DEFAULT NULL,
  `receiver_name` varchar(160) DEFAULT NULL,
  `status` enum('Ready','Shipped','Delivered') NOT NULL DEFAULT 'Ready',
  `shipped_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message` text NOT NULL,
  `status` varchar(20) DEFAULT 'Open',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_sequences`
--

CREATE TABLE `ticket_sequences` (
  `seq_key` varchar(255) NOT NULL,
  `next_value` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_sequences`
--

INSERT INTO `ticket_sequences` (`seq_key`, `next_value`) VALUES
('ticket_seq_250824_LT_P', 2),
('ticket_seq_250824_TB_D', 2),
('ticket_seq_250824_TB_O', 2),
('ticket_seq_250825_DT_D', 3),
('ticket_seq_250825_PH_P', 2),
('ticket_seq_250825_PR_D', 3),
('ticket_seq_250825_TB_D', 3),
('ticket_seq_250903_DT_D', 2);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `role` enum('customer','staff','admin') NOT NULL DEFAULT 'customer',
  `name` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `department` enum('Registration','Repair','Billing','Shipping') DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role`, `name`, `email`, `phone`, `password_hash`, `created_at`, `department`, `profile_picture`) VALUES
(1, 'customer', 'test', 'test@example.com', '9869666566', '$2y$10$aUmIB6ETkkhyzqqadm8vjeiA50Dy8uGjlk8O9qgtsetkXliUU0W5a', '2025-08-19 06:19:32', NULL, NULL),
(2, 'admin', 'System Admin', 'admin@nexusfix.local', NULL, '$2y$10$q2KE7o7Y3h1FmdXobct2ceL499l2vGfsJBXU.cuNXmLCuooyl5ApG', '2025-08-19 06:27:43', NULL, NULL),
(3, 'staff', 'recept', 'recept@example.com', NULL, '$2y$10$aOd7vgK80/TCtcWkySkODe9sxi7tNd55W.S5wbJVc/qM69Aht3Uy6', '2025-08-19 07:03:25', NULL, NULL),
(4, 'customer', 'suman', 'suman@example.com', '9869666566', '$2y$10$Fqpn9sG8WxLOTeteALtxpOWQFWRKFjxqKgEq1ZFrP.mJfbxIh5sia', '2025-08-20 09:07:44', NULL, NULL),
(5, 'customer', 'test2', 'test2@example.com', '1234567890', '$2y$10$Bgt9pL.M2OAjCgjAmD/UTOrJ65blgNNJfBc.Zs6tPxh1VP022jSai', '2025-08-20 09:08:17', NULL, 'it_repair/uploads/profile_pictures/profile_68b7ebd98eac70.15449189.png'),
(6, 'customer', 'new', 'new@example.com', '9812345678', '$2y$10$y8FiSsMk0ao.ioqpSIpj8epMmon48uadUev6mypS3vzGJiNscGYIi', '2025-08-23 08:14:20', NULL, NULL),
(7, 'customer', 'Ishan Basnet', 'ishan@example.com', '9869666566', '$2y$10$nkLTJIh9nCO6CcYDe937Yus6nzn8EMCyr0PFAEamb/iEM037Vu0wK', '2025-08-24 00:37:48', NULL, NULL),
(8, 'customer', 'Manish Rai', 'sambewamanish@gmail.com', '9840731919', '$2y$10$JJM.Tv1Qr6VXb.7V9oqwY.Q51Ga8VLEqFoR9Ar5Rd14J5BYB4FGq.', '2025-08-24 09:08:51', NULL, NULL),
(9, 'customer', 'John Doe', 'john@example.com', '+9779843800000', '$2y$10$ovI9HPRGEOsIVypRtNy7AOdlV5jhFdNI5cD30i.2gsxQV7B.jbHxm', '2025-08-25 08:16:39', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_id` (`request_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_inv_request_id` (`request_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`);

--
-- Indexes for table `knowledge_base`
--
ALTER TABLE `knowledge_base`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_title` (`title`);
ALTER TABLE `knowledge_base` ADD FULLTEXT KEY `idx_search` (`title`,`content`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `repair_requests`
--
ALTER TABLE `repair_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_code` (`ticket_code`),
  ADD KEY `idx_requests_customer` (`customer_id`);

--
-- Indexes for table `request_assignments`
--
ALTER TABLE `request_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `request_attachments`
--
ALTER TABLE `request_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_request_attachment_request` (`request_id`);

--
-- Indexes for table `request_parts`
--
ALTER TABLE `request_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `added_by` (`added_by`),
  ADD KEY `idx_rp_request_id` (`request_id`);

--
-- Indexes for table `request_status_history`
--
ALTER TABLE `request_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rsh_request_id` (`request_id`);

--
-- Indexes for table `service_bookings`
--
ALTER TABLE `service_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `u_slot_request` (`slot_id`,`request_id`),
  ADD KEY `idx_slot_status` (`slot_id`,`status`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `service_slots`
--
ALTER TABLE `service_slots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date_center` (`slot_date`,`service_center_id`),
  ADD KEY `idx_center_active` (`service_center_id`,`active`);

--
-- Indexes for table `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_id` (`request_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_ship_request_id` (`request_id`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `ticket_sequences`
--
ALTER TABLE `ticket_sequences`
  ADD PRIMARY KEY (`seq_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `knowledge_base`
--
ALTER TABLE `knowledge_base`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `repair_requests`
--
ALTER TABLE `repair_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `request_assignments`
--
ALTER TABLE `request_assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `request_attachments`
--
ALTER TABLE `request_attachments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `request_parts`
--
ALTER TABLE `request_parts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `request_status_history`
--
ALTER TABLE `request_status_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `service_bookings`
--
ALTER TABLE `service_bookings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `service_slots`
--
ALTER TABLE `service_slots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `shipments`
--
ALTER TABLE `shipments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `repair_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `repair_requests`
--
ALTER TABLE `repair_requests`
  ADD CONSTRAINT `repair_requests_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `request_assignments`
--
ALTER TABLE `request_assignments`
  ADD CONSTRAINT `request_assignments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `repair_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `request_assignments_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `request_attachments`
--
ALTER TABLE `request_attachments`
  ADD CONSTRAINT `fk_request_attachment_request` FOREIGN KEY (`request_id`) REFERENCES `repair_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `request_parts`
--
ALTER TABLE `request_parts`
  ADD CONSTRAINT `request_parts_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `repair_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `request_parts_ibfk_2` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `request_status_history`
--
ALTER TABLE `request_status_history`
  ADD CONSTRAINT `request_status_history_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `repair_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `shipments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `repair_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shipments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

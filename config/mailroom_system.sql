-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Apr 02, 2026 at 12:02 PM
-- Server version: 10.11.14-MariaDB-0ubuntu0.24.04.1
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `mailroom_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `distribution`
--

CREATE TABLE `distribution` (
  `id` int(11) NOT NULL,
  `newspaper_id` int(11) DEFAULT NULL,
  `distributed_to` varchar(200) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `copies` int(11) DEFAULT 1,
  `date_distributed` date NOT NULL,
  `distributed_by` varchar(100) DEFAULT NULL,
  `newspaper_ids` text DEFAULT NULL,
  `newspapers_list` text DEFAULT NULL,
  `categories_list` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `distribution`
--

INSERT INTO `distribution` (`id`, `newspaper_id`, `distributed_to`, `department`, `copies`, `date_distributed`, `distributed_by`, `newspaper_ids`, `newspapers_list`, `categories_list`) VALUES
(2, 2, 'Hollali Kelvin', 'IT Department', 1, '2026-03-08', 'Nadjat', NULL, NULL, NULL),
(5, 2, 'Mr John', 'HR', 1, '2026-03-08', 'Nadjat', NULL, NULL, NULL),
(6, 3, 'Mr John', 'HR', 1, '2026-03-08', 'Nadjat', NULL, NULL, NULL),
(7, 7, 'Mr Calvin', 'IT Department', 1, '2026-03-18', 'Nadjat', NULL, NULL, NULL),
(8, 4, 'Mr Calvin', 'IT Department', 1, '2026-03-18', 'Nadjat', NULL, NULL, NULL),
(9, 3, 'Mr Calvin', 'IT Department', 1, '2026-03-18', 'Nadjat', NULL, NULL, NULL),
(10, 7, 'Hollali Kelvin', 'IT Department', 1, '2026-03-24', 'Nadjat', NULL, NULL, NULL),
(11, 3, 'Hollali Kelvin', 'IT Department', 1, '2026-03-24', 'Nadjat', NULL, NULL, NULL),
(12, 3, 'Mr Michael Williams', 'Procurement', 1, '2026-03-24', 'Nadia', NULL, NULL, NULL),
(15, 2, 'Mr Michael Williams', 'Procurement', 2, '2026-03-31', 'Nadjat', '2,4', 'Daily Guide (Daily Guide) - Issue: DAI-20260308-015|Ghanaian Times (Ghanaian Times) - Issue: GHA-20260308-923', NULL),
(16, 7, 'Mrs Sarah Johnson', 'Administration', 4, '2026-03-31', 'Hollali', '7,2,4,3', 'Business & Financial Times (Business & Financial Times) - Issue: BUS-20260318-367|Daily Guide (Daily Guide) - Issue: DAI-20260308-015|Ghanaian Times (Ghanaian Times) - Issue: GHA-20260308-923|The Chronicle (The Chronicle) - Issue: THE-20260308-519', NULL),
(17, 9, 'Mr John', 'HR Department', 1, '2026-04-01', 'Hollali', '9', 'The Chronicle (The Chronicle) - Issue: THE-20260401-381', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `document_name` varchar(200) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `type_id` int(11) DEFAULT NULL,
  `origin` varchar(200) DEFAULT NULL,
  `copies_received` int(11) DEFAULT NULL,
  `date_received` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `document_name`, `type`, `type_id`, `origin`, `copies_received`, `date_received`, `created_at`) VALUES
(1, 'Bill', 'Legislative Documents', 1, 'Ministry of Education', 2, '2026-03-07', '2026-03-07 09:00:00'),
(2, 'Education Reform Bill 2026', NULL, 1, 'Minsitry of Education', 0, '2026-03-30', '2026-03-30 15:08:00');

-- --------------------------------------------------------

--
-- Table structure for table `document_distribution`
--

CREATE TABLE `document_distribution` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `number_received` int(11) NOT NULL,
  `number_distributed` int(11) NOT NULL,
  `date_distributed` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'distributed'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `document_distribution`
--

INSERT INTO `document_distribution` (`id`, `document_id`, `number_received`, `number_distributed`, `date_distributed`, `created_at`) VALUES
(1, 1, 1, 1, '2026-03-18', '2026-03-18 11:34:49'),
(2, 2, 275, 275, '2026-03-30', '2026-03-30 15:12:12'),
(3, 1, 1, 1, '2026-04-02', '2026-04-02 11:28:02');

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `id` int(11) NOT NULL,
  `type_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`id`, `type_name`, `description`, `created_at`) VALUES
(1, 'Legislative Documents', 'Official legislative documents including bills, acts, and parliamentary papers', '2026-03-08 03:56:46'),
(2, 'Administrative Documents', 'Internal administrative memos and correspondence', '2026-03-08 03:56:46'),
(3, 'Financial Documents', 'Budget statements, financial reports, and audit documents', '2026-03-08 03:56:46'),
(4, 'Policy Documents', 'Policy frameworks, guidelines, and strategic plans', '2026-03-08 03:56:46'),
(5, 'Reports', 'Annual reports, progress reports, and evaluation reports', '2026-03-08 03:56:46'),
(6, 'Correspondence', 'Official letters and correspondence', '2026-03-08 03:56:46'),
(7, 'Contracts', 'Contracts, MOUs, and agreements', '2026-03-08 03:56:46'),
(8, 'Personnel Records', 'Staff-related documents and records', '2026-03-08 03:56:46'),
(9, 'Project Documents', 'Project proposals, implementation plans, and project reports', '2026-03-08 03:56:46');

-- --------------------------------------------------------

--
-- Table structure for table `newspapers`
--

CREATE TABLE `newspapers` (
  `id` int(11) NOT NULL,
  `newspaper_name` varchar(100) DEFAULT NULL,
  `newspaper_number` varchar(50) DEFAULT NULL,
  `date_received` date DEFAULT NULL,
  `received_by` varchar(100) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `distributed_to` varchar(200) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `date_distributed` date DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `total_copies` int(11) DEFAULT 1,
  `available_copies` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `newspapers`
--

INSERT INTO `newspapers` (`id`, `newspaper_name`, `newspaper_number`, `date_received`, `received_by`, `status`, `distributed_to`, `department`, `date_distributed`, `category_id`, `total_copies`, `available_copies`) VALUES
(2, 'Daily Guide', 'DAI-20260308-015', '2026-03-08', 'Doreenda Abbey', 'available', NULL, NULL, NULL, 4, 1, 7),
(3, 'The Chronicle', 'THE-20260308-519', '2026-03-08', 'Doreenda Abbey', 'partial', NULL, NULL, NULL, 5, 1, 14),
(4, 'Ghanaian Times', 'GHA-20260308-923', '2026-03-08', 'Doreenda Abbey', 'partial', NULL, NULL, NULL, 2, 1, 6),
(7, 'Business & Financial Times', 'BUS-20260318-367', '2026-03-18', 'Nadjat', 'available', NULL, NULL, NULL, 3, 1, 8),
(9, 'The Chronicle', 'THE-20260401-381', '2026-04-01', 'Nadjat', 'partial', NULL, NULL, NULL, 5, 1, 9);

-- --------------------------------------------------------

--
-- Table structure for table `newspaper_categories`
--

CREATE TABLE `newspaper_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(150) NOT NULL,
  `newspaper_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `newspaper_categories`
--

INSERT INTO `newspaper_categories` (`id`, `category_name`, `newspaper_id`, `description`, `created_at`) VALUES
(1, 'Daily Graphic', NULL, NULL, '2026-03-07 18:32:15'),
(2, 'Ghanaian Times', NULL, NULL, '2026-03-07 18:32:15'),
(3, 'Business & Financial Times', NULL, NULL, '2026-03-07 18:32:15'),
(4, 'Daily Guide', NULL, NULL, '2026-03-07 18:32:15'),
(5, 'The Chronicle', NULL, NULL, '2026-03-07 18:32:15');

-- --------------------------------------------------------

--
-- Table structure for table `parcels_pickup`
--

CREATE TABLE `parcels_pickup` (
  `id` int(11) NOT NULL,
  `parcel_id` int(11) DEFAULT NULL,
  `picked_by` varchar(200) DEFAULT NULL,
  `phone_number` varchar(50) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `date_picked` date DEFAULT NULL,
  `picked_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `parcels_pickup`
--

INSERT INTO `parcels_pickup` (`id`, `parcel_id`, `picked_by`, `phone_number`, `designation`, `date_picked`, `picked_at`) VALUES
(1, 1, 'Doreenda Abbey', '0505306932', 'PVC', '2026-03-08', '2026-03-08 10:15:00'),
(2, 2, 'Nadjat', '0505306932', 'IT Department', '2026-03-18', '2026-03-18 14:20:00');

-- --------------------------------------------------------

--
-- Table structure for table `parcels_received`
--

CREATE TABLE `parcels_received` (
  `id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `sender` varchar(200) DEFAULT NULL,
  `addressed_to` varchar(200) DEFAULT NULL,
  `date_received` date DEFAULT NULL,
  `received_by` varchar(100) DEFAULT NULL,
  `tracking_id` varchar(50) DEFAULT NULL,
  `received_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `parcels_received`
--

INSERT INTO `parcels_received` (`id`, `description`, `sender`, `addressed_to`, `date_received`, `received_by`, `tracking_id`, `received_at`) VALUES
(1, 'A gift', 'Hollali Kelvin', 'Mr Kelvin Hollali', '2026-03-07', 'Doreenda Abbey', 'PRCL-20260307-9B102A', '2026-03-07 08:30:00'),
(2, 'An Egonomic Chair', 'Archiver Asare', 'Mr Kelvin Hollali', '2026-03-08', 'Doreenda Abbey', 'PRCL-20260308-7D19FA', '2026-03-08 13:45:00'),
(3, 'item', 'Ben', 'Mr Kobby', '2026-03-18', 'Salma', 'PRCL-20260318-9F3156', '2026-03-18 09:10:00');

-- --------------------------------------------------------

--
-- Table structure for table `recipients`
--

CREATE TABLE `recipients` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `recipients`
--

INSERT INTO `recipients` (`id`, `name`, `is_active`, `created_at`) VALUES
(1, 'Hollali Kelvin - IT Department', 1, '2026-03-24 03:53:23'),
(2, 'Mr John - HR Department', 1, '2026-03-24 03:53:23'),
(3, 'Doreenda Abbey - PVC Office', 1, '2026-03-24 03:53:23'),
(4, 'Mrs Sarah Johnson - Administration', 1, '2026-03-24 03:53:23'),
(5, 'Mr Michael Williams - Procurement', 1, '2026-03-24 03:53:23'),
(7, 'Hollali', 1, '2026-04-02 10:31:27');

-- --------------------------------------------------------

--
-- Table structure for table `recipient_category_subscriptions`
--

CREATE TABLE `recipient_category_subscriptions` (
  `id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `recipient_category_subscriptions`
--

INSERT INTO `recipient_category_subscriptions` (`id`, `recipient_id`, `category_id`, `created_at`) VALUES
(1, 2, 3, '2026-04-02 11:50:46'),
(2, 1, 3, '2026-04-02 11:51:28'),
(3, 1, 4, '2026-04-02 11:51:28'),
(4, 2, 5, '2026-04-02 11:51:28'),
(5, 4, 4, '2026-04-02 11:51:28'),
(6, 4, 2, '2026-04-02 11:51:28'),
(7, 5, 3, '2026-04-02 11:51:28');

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_distribution_by_category`
-- (See below for the actual view)
--
CREATE TABLE `view_distribution_by_category` (
`id` int(11)
,`distributed_to` varchar(200)
,`department` varchar(100)
,`date_distributed` date
,`distributed_by` varchar(100)
,`copies` int(11)
,`categories_list` text
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_recipient_subscriptions`
-- (See below for the actual view)
--
CREATE TABLE `view_recipient_subscriptions` (
`id` int(11)
,`recipient_id` int(11)
,`recipient_name` varchar(200)
,`category_id` int(11)
,`category_name` varchar(150)
,`subscribed_on` timestamp
,`total_newspapers_in_category` bigint(21)
);

-- --------------------------------------------------------

--
-- Structure for view `view_distribution_by_category`
--
DROP TABLE IF EXISTS `view_distribution_by_category`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_distribution_by_category`  AS SELECT `d`.`id` AS `id`, `d`.`distributed_to` AS `distributed_to`, `d`.`department` AS `department`, `d`.`date_distributed` AS `date_distributed`, `d`.`distributed_by` AS `distributed_by`, `d`.`copies` AS `copies`, `d`.`categories_list` AS `categories_list` FROM `distribution` AS `d` WHERE `d`.`categories_list` is not null ORDER BY `d`.`date_distributed` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `view_recipient_subscriptions`
--
DROP TABLE IF EXISTS `view_recipient_subscriptions`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_recipient_subscriptions`  AS SELECT `rcs`.`id` AS `id`, `r`.`id` AS `recipient_id`, `r`.`name` AS `recipient_name`, `nc`.`id` AS `category_id`, `nc`.`category_name` AS `category_name`, `rcs`.`created_at` AS `subscribed_on`, count(distinct `n`.`id`) AS `total_newspapers_in_category` FROM (((`recipient_category_subscriptions` `rcs` join `recipients` `r` on(`rcs`.`recipient_id` = `r`.`id`)) join `newspaper_categories` `nc` on(`rcs`.`category_id` = `nc`.`id`)) left join `newspapers` `n` on(`n`.`category_id` = `nc`.`id`)) WHERE `r`.`is_active` = 1 GROUP BY `rcs`.`id`, `r`.`id`, `r`.`name`, `nc`.`id`, `nc`.`category_name`, `rcs`.`created_at` ORDER BY `r`.`name` ASC, `nc`.`category_name` ASC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `distribution`
--
ALTER TABLE `distribution`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_newspaper_id` (`newspaper_id`),
  ADD KEY `idx_date_distributed` (`date_distributed`),
  ADD KEY `idx_categories_list` (`categories_list`(100));

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date_received` (`date_received`),
  ADD KEY `idx_document_name` (`document_name`),
  ADD KEY `fk_document_type` (`type_id`);

--
-- Indexes for table `document_distribution`
--
ALTER TABLE `document_distribution`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_type_name` (`type_name`);

--
-- Indexes for table `newspapers`
--
ALTER TABLE `newspapers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_newspaper_category` (`category_id`);

--
-- Indexes for table `newspaper_categories`
--
ALTER TABLE `newspaper_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_newspaper_id` (`newspaper_id`);

--
-- Indexes for table `parcels_pickup`
--
ALTER TABLE `parcels_pickup`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `parcels_received`
--
ALTER TABLE `parcels_received`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tracking_id` (`tracking_id`),
  ADD KEY `idx_date_received` (`date_received`);

--
-- Indexes for table `recipients`
--
ALTER TABLE `recipients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_name` (`name`);

--
-- Indexes for table `recipient_category_subscriptions`
--
ALTER TABLE `recipient_category_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_recipient_category` (`recipient_id`,`category_id`),
  ADD KEY `fk_sub_recipient` (`recipient_id`),
  ADD KEY `fk_sub_category` (`category_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `distribution`
--
ALTER TABLE `distribution`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `document_distribution`
--
ALTER TABLE `document_distribution`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `newspapers`
--
ALTER TABLE `newspapers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `newspaper_categories`
--
ALTER TABLE `newspaper_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `parcels_pickup`
--
ALTER TABLE `parcels_pickup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `parcels_received`
--
ALTER TABLE `parcels_received`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `recipients`
--
ALTER TABLE `recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `recipient_category_subscriptions`
--
ALTER TABLE `recipient_category_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `distribution`
--
ALTER TABLE `distribution`
  ADD CONSTRAINT `fk_distribution_newspaper` FOREIGN KEY (`newspaper_id`) REFERENCES `newspapers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_document_type` FOREIGN KEY (`type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `newspapers`
--
ALTER TABLE `newspapers`
  ADD CONSTRAINT `fk_newspaper_category` FOREIGN KEY (`category_id`) REFERENCES `newspaper_categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `recipient_category_subscriptions`
--
ALTER TABLE `recipient_category_subscriptions`
  ADD CONSTRAINT `fk_sub_category` FOREIGN KEY (`category_id`) REFERENCES `newspaper_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sub_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `recipients` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 13, 2026 at 08:07 AM
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
-- Database: `children`
--

-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `barangay_id` int(11) NOT NULL,
  `barangay_name` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL,
  `province` varchar(100) NOT NULL,
  `total_population` int(11) DEFAULT NULL,
  `estimated_children_measured` int(11) DEFAULT NULL,
  `psgc` char(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

INSERT INTO `barangays` (`barangay_id`, `barangay_name`, `city`, `province`, `total_population`, `estimated_children_measured`, `psgc`) VALUES
(1, 'TABON', 'BISLIG CITY', 'SURIGAO DEL SUR', 10000, 8900, '9021021902');

-- --------------------------------------------------------

--
-- Table structure for table `category_inventory`
--

CREATE TABLE `category_inventory` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `children`
--

CREATE TABLE `children` (
  `child_id` int(11) NOT NULL,
  `address` varchar(150) DEFAULT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `guardian_id` int(11) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `birthdate` date NOT NULL,
  `age_in_months` int(11) DEFAULT NULL,
  `status_date` datetime DEFAULT current_timestamp(),
  `is_ip` enum('Yes','No') NOT NULL DEFAULT 'No',
  `status` enum('Active','Archive','Disease','OverAge') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `children`
--

INSERT INTO `children` (`child_id`, `address`, `barangay_id`, `guardian_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `sex`, `birthdate`, `age_in_months`, `status_date`, `is_ip`, `status`) VALUES
(1, 'PUROK 1', 1, 1, 'JONAH', '', 'CUANAN', '', 'Female', '2025-09-12', 8, '2026-05-12 14:01:28', 'No', 'Active'),
(2, 'PUROK 3', 1, 2, 'CLAIRE', '', 'EGYPTO', '', 'Male', '2025-11-12', 6, '2026-05-12 14:02:44', 'No', 'Active'),
(3, 'PUROK 1', 1, 3, 'CLAIRE', '', 'EGYPTOO', '', 'Female', '2025-11-12', 6, '2026-05-12 00:00:00', 'No', 'Disease'),
(4, 'PUROK 1', 1, 4, 'DD,', '', ',D', '', 'Female', '2026-05-13', 0, '2026-05-13 03:24:42', 'No', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `growth_records`
--

CREATE TABLE `growth_records` (
  `record_id` int(11) NOT NULL,
  `child_id` int(11) NOT NULL,
  `measurement_date` date NOT NULL,
  `weight` decimal(5,2) NOT NULL,
  `height` decimal(5,2) NOT NULL,
  `weight_id` int(11) DEFAULT NULL,
  `height_id` int(11) DEFAULT NULL,
  `wfl_id` int(11) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `is_muac_only` tinyint(1) NOT NULL DEFAULT 0,
  `muac_id` int(11) DEFAULT NULL,
  `muac_measurement` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `growth_records`
--

INSERT INTO `growth_records` (`record_id`, `child_id`, `measurement_date`, `weight`, `height`, `weight_id`, `height_id`, `wfl_id`, `recorded_by`, `muac_id`, `muac_measurement`) VALUES
(1, 1, '2026-05-12', 2.00, 90.00, 18, 8, 222, 123456, 1, 9.00),
(2, 1, '2026-05-12', 5.00, 90.00, 18, 8, 222, 123456, 1, 9.00),
(3, 2, '2026-05-12', 12.00, 90.00, 13, 65, 91, 123456, NULL, NULL),
(4, 2, '2026-05-12', 15.00, 90.00, 13, 65, 91, 123456, 1, 12.00),
(5, 3, '2026-05-12', 11.90, 89.90, 14, 6, 222, 123456, 1, 90.00),
(6, 3, '2026-05-12', 12.00, 89.90, 14, 6, 222, 123456, 1, 91.00),
(7, 4, '2026-05-13', 90.00, 90.00, 2, 1, 222, 361806, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `guardians`
--

CREATE TABLE `guardians` (
  `guardian_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `relationship_to_child` enum('Mother','Father','Guardian') DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `guardians`
--

INSERT INTO `guardians` (`guardian_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `relationship_to_child`, `contact_number`) VALUES
(1, 'JONAH', '', 'JONAH', '', 'Mother', '091202109'),
(2, 'OKWOSKSW', 'JKJKSD', 'JSJKSW', '', 'Father', '9023903202'),
(3, 'JONAH', '', 'CUNAAN', '', 'Mother', '09090909'),
(4, 'SWA', '', 'DWXS', '', 'Mother', '90290');

-- --------------------------------------------------------

--
-- Table structure for table `height_for_age`
--

CREATE TABLE `height_for_age` (
  `height_id` int(11) NOT NULL,
  `age_month` int(11) NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `severely_stunted` decimal(4,1) DEFAULT NULL,
  `stunted_from` decimal(4,1) DEFAULT NULL,
  `stunted_to` decimal(4,1) DEFAULT NULL,
  `normal_from` decimal(4,1) DEFAULT NULL,
  `normal_to` decimal(4,1) DEFAULT NULL,
  `tall` decimal(4,1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `height_for_age`
--

INSERT INTO `height_for_age` (`height_id`, `age_month`, `sex`, `severely_stunted`, `stunted_from`, `stunted_to`, `normal_from`, `normal_to`, `tall`) VALUES
(1, 1, 'Female', 47.7, 47.8, 49.7, 49.8, 57.6, 57.7),
(2, 2, 'Female', 50.9, 51.0, 52.9, 53.0, 61.1, 61.2),
(3, 3, 'Female', 53.4, 53.5, 55.5, 55.6, 64.0, 64.1),
(4, 4, 'Female', 55.5, 55.6, 57.7, 57.8, 66.4, 66.5),
(5, 5, 'Female', 57.3, 57.4, 59.5, 59.6, 68.5, 68.6),
(6, 6, 'Female', 58.8, 58.9, 61.1, 61.2, 70.3, 70.4),
(7, 7, 'Female', 60.2, 60.3, 62.6, 62.7, 71.9, 72.0),
(8, 8, 'Female', 61.6, 61.7, 63.9, 64.0, 73.5, 73.6),
(9, 9, 'Female', 62.8, 62.9, 65.2, 65.3, 75.0, 75.1),
(10, 10, 'Female', 64.0, 64.1, 66.4, 66.5, 76.4, 76.5),
(11, 11, 'Female', 65.1, 65.2, 67.6, 67.7, 77.8, 77.9),
(12, 12, 'Female', 66.2, 66.3, 68.8, 68.9, 79.2, 79.3),
(13, 13, 'Female', 67.2, 67.3, 69.9, 70.0, 80.5, 80.6),
(14, 14, 'Female', 68.2, 68.3, 70.9, 71.0, 81.7, 81.8),
(15, 15, 'Female', 69.2, 69.3, 71.9, 72.0, 83.0, 83.1),
(16, 16, 'Female', 70.1, 70.2, 72.9, 73.0, 84.2, 84.3),
(17, 17, 'Female', 71.0, 71.1, 73.9, 74.0, 85.4, 85.5),
(18, 18, 'Female', 71.9, 72.0, 74.8, 74.9, 86.5, 86.6),
(19, 19, 'Female', 72.7, 72.8, 75.7, 75.8, 87.6, 87.7),
(20, 20, 'Female', 73.6, 73.7, 76.6, 76.7, 88.7, 88.8),
(21, 21, 'Female', 74.4, 74.5, 77.4, 77.5, 89.8, 89.9),
(22, 22, 'Female', 75.1, 75.2, 78.3, 78.4, 90.8, 90.9),
(23, 23, 'Female', 75.9, 76.0, 79.1, 79.2, 91.9, 92.0),
(24, 24, 'Female', 75.9, 76.0, 79.2, 79.3, 92.2, 92.3),
(25, 25, 'Female', 76.7, 76.8, 79.9, 80.0, 93.1, 93.2),
(26, 26, 'Female', 77.4, 77.5, 80.7, 80.8, 94.1, 94.2),
(27, 27, 'Female', 78.0, 78.1, 81.4, 81.5, 95.0, 95.1),
(28, 28, 'Female', 78.7, 78.8, 82.1, 82.2, 96.0, 96.1),
(29, 29, 'Female', 79.4, 79.5, 82.8, 82.9, 96.9, 97.0),
(30, 30, 'Female', 80.0, 80.1, 83.5, 83.6, 97.7, 97.8),
(31, 31, 'Female', 80.6, 80.7, 84.2, 84.3, 98.6, 98.7),
(32, 32, 'Female', 81.2, 81.3, 84.8, 84.9, 99.4, 99.5),
(33, 33, 'Female', 81.8, 81.9, 85.5, 85.6, 100.3, 100.4),
(34, 34, 'Female', 82.4, 82.5, 86.1, 86.2, 101.1, 101.2),
(35, 35, 'Female', 83.0, 83.1, 86.7, 86.8, 101.9, 102.0),
(36, 36, 'Female', 83.5, 83.6, 87.3, 87.4, 102.7, 102.8),
(37, 37, 'Female', 84.1, 84.2, 87.9, 88.0, 103.4, 103.5),
(38, 38, 'Female', 84.6, 84.7, 88.5, 88.6, 104.2, 104.3),
(39, 39, 'Female', 85.2, 85.3, 89.1, 89.2, 105.0, 105.1),
(40, 40, 'Female', 85.7, 85.8, 89.7, 89.8, 105.7, 105.8),
(41, 41, 'Female', 86.2, 86.3, 90.3, 90.4, 106.4, 106.5),
(42, 42, 'Female', 86.7, 86.8, 90.8, 90.9, 107.2, 107.3),
(43, 43, 'Female', 87.3, 87.4, 91.4, 91.5, 107.9, 108.0),
(44, 44, 'Female', 87.8, 87.9, 91.9, 92.0, 108.6, 108.7),
(45, 45, 'Female', 88.3, 88.4, 92.4, 92.5, 109.3, 109.4),
(46, 46, 'Female', 88.8, 88.9, 93.0, 93.1, 110.0, 110.1),
(47, 47, 'Female', 89.2, 89.3, 93.5, 93.6, 110.7, 110.8),
(48, 48, 'Female', 89.7, 89.8, 94.0, 94.1, 111.3, 111.4),
(49, 49, 'Female', 90.2, 90.3, 94.5, 94.6, 112.0, 112.1),
(50, 50, 'Female', 90.6, 90.7, 95.0, 95.1, 112.7, 112.8),
(51, 51, 'Female', 91.1, 91.2, 95.5, 95.6, 113.3, 113.4),
(52, 52, 'Female', 91.6, 91.7, 96.0, 96.1, 114.0, 114.1),
(53, 53, 'Female', 92.0, 92.1, 96.5, 96.6, 114.6, 114.7),
(54, 54, 'Female', 92.5, 92.6, 97.0, 97.1, 115.2, 115.3),
(55, 55, 'Female', 92.9, 93.0, 97.5, 97.6, 115.9, 116.0),
(56, 56, 'Female', 93.3, 93.4, 98.0, 98.1, 116.5, 116.6),
(57, 57, 'Female', 93.8, 93.9, 98.4, 98.5, 117.1, 117.2),
(58, 58, 'Female', 94.2, 94.3, 98.9, 99.0, 117.7, 117.8),
(59, 59, 'Female', 94.6, 94.7, 99.3, 99.4, 118.3, 118.4),
(60, 1, 'Male', 48.8, 48.9, 50.7, 50.8, 58.6, 58.7),
(61, 2, 'Male', 52.3, 52.4, 54.3, 54.4, 62.4, 62.5),
(62, 3, 'Male', 55.2, 55.3, 57.2, 57.3, 65.5, 65.6),
(63, 4, 'Male', 57.5, 57.6, 59.6, 59.7, 68.0, 68.1),
(64, 5, 'Male', 59.5, 59.6, 61.6, 61.7, 70.1, 70.2),
(65, 6, 'Male', 61.1, 61.2, 63.2, 63.3, 71.9, 72.0),
(66, 7, 'Male', 62.6, 62.7, 64.7, 64.8, 73.5, 73.6),
(67, 8, 'Male', 63.9, 64.0, 66.1, 66.2, 75.0, 75.1),
(68, 9, 'Male', 65.1, 65.2, 67.4, 67.5, 76.5, 76.6),
(69, 10, 'Male', 66.3, 66.4, 68.6, 68.7, 77.9, 78.0),
(70, 11, 'Male', 67.5, 67.6, 69.8, 69.9, 79.2, 79.3),
(71, 12, 'Male', 68.5, 68.6, 70.9, 71.0, 80.5, 80.6),
(72, 13, 'Male', 69.5, 69.6, 72.0, 72.1, 81.8, 81.9),
(73, 14, 'Male', 70.5, 70.6, 73.0, 73.1, 83.0, 83.1),
(74, 15, 'Male', 71.5, 71.6, 74.0, 74.1, 84.2, 84.3),
(75, 16, 'Male', 72.4, 72.5, 74.9, 75.0, 85.4, 85.5),
(76, 17, 'Male', 73.2, 73.3, 75.9, 76.0, 86.5, 86.6),
(77, 18, 'Male', 74.1, 74.2, 76.8, 76.9, 87.7, 87.8),
(78, 19, 'Male', 74.9, 75.0, 77.6, 77.7, 88.8, 88.9),
(79, 20, 'Male', 75.7, 75.8, 78.5, 78.6, 89.8, 89.9),
(80, 21, 'Male', 76.4, 76.5, 79.3, 79.4, 90.9, 91.0),
(81, 22, 'Male', 77.1, 77.2, 80.1, 80.2, 91.9, 92.0),
(82, 23, 'Male', 77.9, 78.0, 80.9, 81.0, 92.9, 93.0),
(83, 24, 'Male', 77.9, 78.0, 80.9, 81.0, 93.2, 93.3),
(84, 25, 'Male', 78.5, 78.6, 81.6, 81.7, 94.2, 94.3),
(85, 26, 'Male', 79.2, 79.3, 82.4, 82.5, 95.2, 95.3),
(86, 27, 'Male', 79.8, 79.9, 83.0, 83.1, 96.1, 96.2),
(87, 28, 'Male', 80.4, 80.5, 83.7, 83.8, 97.0, 97.1),
(88, 29, 'Male', 81.0, 81.1, 84.4, 84.5, 97.9, 98.0),
(89, 30, 'Male', 81.6, 81.7, 85.0, 85.1, 98.7, 98.8),
(90, 31, 'Male', 82.2, 82.3, 85.6, 85.7, 99.6, 99.7),
(91, 32, 'Male', 82.7, 82.8, 86.3, 86.4, 100.4, 100.5),
(92, 33, 'Male', 83.3, 83.4, 86.8, 86.9, 101.2, 101.3),
(93, 34, 'Male', 83.8, 83.9, 87.4, 87.5, 102.0, 102.1),
(94, 35, 'Male', 84.3, 84.4, 88.0, 88.1, 102.7, 102.8),
(95, 36, 'Male', 84.9, 85.0, 88.6, 88.7, 103.5, 103.6),
(96, 37, 'Male', 85.4, 85.5, 89.1, 89.2, 104.2, 104.3),
(97, 38, 'Male', 85.9, 86.0, 89.7, 89.8, 105.0, 105.1),
(98, 39, 'Male', 86.4, 86.5, 90.2, 90.3, 105.7, 105.8),
(99, 40, 'Male', 86.9, 87.0, 90.8, 90.9, 106.4, 106.5),
(100, 41, 'Male', 87.4, 87.5, 91.3, 91.4, 107.1, 107.2),
(101, 42, 'Male', 87.9, 88.0, 91.8, 91.9, 107.8, 107.9),
(102, 43, 'Male', 88.3, 88.4, 92.3, 92.4, 108.5, 108.6),
(103, 44, 'Male', 88.8, 88.9, 92.9, 93.0, 109.1, 109.2),
(104, 45, 'Male', 89.3, 89.4, 93.4, 93.5, 109.8, 109.9),
(105, 46, 'Male', 89.7, 89.8, 93.9, 94.0, 110.4, 110.5),
(106, 47, 'Male', 90.2, 90.3, 94.3, 94.4, 111.1, 111.2),
(107, 48, 'Male', 90.6, 90.7, 94.8, 94.9, 111.7, 111.8),
(108, 49, 'Male', 91.1, 91.2, 95.3, 95.4, 112.4, 112.5),
(109, 50, 'Male', 91.5, 91.6, 95.8, 95.9, 113.0, 113.1),
(110, 51, 'Male', 92.0, 92.1, 96.3, 96.4, 113.6, 113.7),
(111, 52, 'Male', 92.4, 92.5, 96.8, 96.9, 114.2, 114.3),
(112, 53, 'Male', 92.9, 93.0, 97.3, 97.4, 114.9, 115.0),
(113, 54, 'Male', 93.3, 93.4, 97.7, 97.8, 115.5, 115.6),
(114, 55, 'Male', 93.8, 93.9, 98.2, 98.3, 116.1, 116.2),
(115, 56, 'Male', 94.2, 94.3, 98.7, 98.8, 116.7, 116.8),
(116, 57, 'Male', 94.6, 94.7, 99.2, 99.3, 117.4, 117.5),
(117, 58, 'Male', 95.1, 95.2, 99.6, 99.7, 118.0, 118.1),
(118, 59, 'Male', 95.5, 95.6, 100.1, 100.2, 118.6, 118.7);

-- --------------------------------------------------------

--
-- Table structure for table `interventions`
--

CREATE TABLE `interventions` (
  `intervention_id` int(11) NOT NULL,
  `child_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `intervention_date` date NOT NULL DEFAULT current_timestamp(),
  `type_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `intervention_items`
--

CREATE TABLE `intervention_items` (
  `item_id` int(11) NOT NULL,
  `intervention_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `quantity_given` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `intervention_types`
--

CREATE TABLE `intervention_types` (
  `type_id` int(11) NOT NULL,
  `type_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `intervention_types`
--

INSERT INTO `intervention_types` (`type_id`, `type_name`) VALUES
(1, 'Give Out');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `item_name` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(20) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `muac`
--

CREATE TABLE `muac` (
  `muac_id` int(11) NOT NULL,
  `severely_wasted` decimal(4,1) NOT NULL,
  `moderately_wasted` decimal(4,1) NOT NULL,
  `normal_status` decimal(4,1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `muac`
--

INSERT INTO `muac` (`muac_id`, `severely_wasted`, `moderately_wasted`, `normal_status`) VALUES
(1, 5.5, 11.5, 12.5);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `role` enum('Admin','Health Worker','Barangay Nutrition Scholars','Staff') NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `date_created` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `barangay_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `username`, `password`, `role`, `contact_number`, `email`, `status`, `date_created`) VALUES
(123456, NULL, 'Jonah', 'Garcia', 'Cuanan', NULL, '123456', '$2y$10$X/WDq0LwLa7jGhvTkdqgXeuwSbiP3j78IWZoWXCWBp7fYVx.38qq.', 'Admin', '09123456789', 'jonahcuanan15@gmail.com', 'Active', '2026-05-12 13:59:29'),
(361806, 1, 'CLAIRE', '', 'EGYPTOQ', '', '361806', '$2y$10$pJk9tnx5x1FF.200nE/YMevnatdizO/rUJvQqUKSxRwL30nRQVQ/G', 'Barangay Nutrition Scholars', '0900', 'claireegypto1021@gmail.com', 'Active', '2026-05-12 14:23:28'),
(738310, NULL, 'JONAH', 'GARCIA', 'CUANANAA', '', '738310', '$2y$10$twEJ5136YFxdjqXixG7OAe546j89v9zWr36r5iNv9JvQPh.ALL7Au', 'Staff', '289181298', 'jonahcuanan15@gmail.com', 'Active', '2026-05-13 03:37:02'),
(882017, 1, 'JONAS', 'GARCIA', 'CUANAN', '', '882017', '$2y$10$V/VABFt3HG8R2g2fChAyVezMOPRTKa/EZBpEbIBxp9AiJIBWF9cUe', 'Health Worker', '090909', 'claireegypto1021@gmail.com', 'Active', '2026-05-12 14:21:49');

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_log`
--

CREATE TABLE `user_activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `details` varchar(500) DEFAULT NULL,
  `activity_time` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_activity_log`
--

INSERT INTO `user_activity_log` (`id`, `user_id`, `activity_type`, `details`, `activity_time`) VALUES
(1, 123456, 'generate_report', 'Generated Summary (OPT Form 1) report for Brgy. TABON, January 2026', '2026-05-12 11:53:17'),
(2, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, January 2026', '2026-05-12 11:53:39'),
(3, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, February 2025', '2026-05-12 11:54:04'),
(4, 123456, 'add_profile', 'Added profile for BARRY BAYOT MAOT', '2026-05-12 12:08:59'),
(5, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, May 2026', '2026-05-12 12:09:49'),
(6, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, All months 2026', '2026-05-12 12:10:24'),
(7, 123456, 'add_profile', 'Added profile for DAVE S. SACAL', '2026-05-12 12:14:34'),
(8, 123456, 'generate_report', 'Generated OPT Form 1A report for Brgy. TABON, All months 2026', '2026-05-12 12:14:55'),
(9, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, All months 2026', '2026-05-12 12:15:06'),
(10, 123456, 'edit_profile', 'Child: DAVE S. SACAL | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, Age 13 mo', '2026-05-12 12:23:17'),
(11, 123456, 'add_profile', 'Added profile for SIMON LIM PETER', '2026-05-12 12:26:41'),
(12, 123456, 'archive_child', 'Archived child profile (Status: Disease, Child: BARRY BAYOT MAOT)', '2026-05-12 12:32:42'),
(13, 123456, 'edit_profile', 'Child: SIMON LIM PETER | Measurement: 2026-05-12, H 75.0 cm, W 12.0 kg, Age 10 mo', '2026-05-12 12:37:00'),
(14, 123456, 'login', '', '2026-05-12 12:39:36'),
(15, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, All months 2026', '2026-05-12 12:43:57'),
(16, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, February to March 2026', '2026-05-12 12:44:41'),
(17, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, May 2026', '2026-05-12 12:44:59'),
(18, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, February to May 2026', '2026-05-12 12:45:12'),
(19, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, January to February 2026', '2026-05-12 12:45:21'),
(20, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, All months 2026', '2026-05-12 12:47:51'),
(21, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, January 2026', '2026-05-12 12:48:02'),
(22, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, January 2026', '2026-05-12 12:48:14'),
(23, 123456, 'edit_profile', 'Child: SIMON LIM PETER | Measurement: 2026-05-12, H 75.0 cm, W 12.0 kg, MUAC 90.0 cm, Age 10 mo', '2026-05-12 12:54:50'),
(24, 123456, 'edit_profile', 'Child: SIMON LIM PETER | Measurement: 2026-05-12, H 75.0 cm, W 12.0 kg, MUAC 12.0 cm, Age 10 mo', '2026-05-12 12:54:59'),
(25, 123456, 'add_profile', 'Added profile for JONAH HATDOG', '2026-05-12 12:56:07'),
(26, 123456, 'edit_profile', 'Child: JONAH HATDOG | Measurement: 2026-05-12, H 91.0 cm, W 90.0 kg, MUAC 12.0 cm, Age 6 mo', '2026-05-12 12:56:19'),
(27, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, All months 2026', '2026-05-12 12:57:16'),
(28, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, January 2026', '2026-05-12 12:57:26'),
(29, 123456, 'auto_logout', '', '2026-05-12 12:57:50'),
(30, 123456, 'edit_profile', 'Child: DAVE S. SACAL | Measurement: 2026-05-12, H 0.0 cm, W 0.0 kg, MUAC 90.0 cm, Age 13 mo', '2026-05-12 12:59:09'),
(31, 123456, 'inventory_add_item', 'Item: VITAMIN A | Qty: 50 PCS | Category: SUPPLEMENTS | Expiry: 2026-09-12', '2026-05-12 13:00:52'),
(32, 123456, 'intervention_add', 'Type: Give Out | Date: 2026-05-12 | Children: 3 | Give out: VITAMIN A — 3 pcs total (PCS)', '2026-05-12 13:01:42'),
(33, 123456, 'edit_profile', 'Child: DAVE S. SACAL | Measurement: 2026-05-12, H 0.0 cm, W 0.0 kg, MUAC 12.0 cm, Age 13 mo', '2026-05-12 13:02:23'),
(34, 123456, 'add_profile', 'Added profile for OKAY OKAT', '2026-05-12 13:03:15'),
(35, 123456, 'edit_profile', 'Child: OKAY OKAT | Measurement: 2026-05-12, H 0.0 cm, W 0.0 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 13:03:23'),
(36, 123456, 'edit_profile', 'Child: OKAY OKAT | Measurement: 2026-05-12, H 12.0 cm, W 12.0 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 13:04:31'),
(37, 123456, 'add_profile', 'Added profile for DWD SW', '2026-05-12 13:06:22'),
(38, 123456, 'edit_profile', 'Child: DWD SW | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, MUAC 12.0 cm, Age 6 mo', '2026-05-12 13:06:29'),
(39, 123456, 'edit_profile', 'Child: DWD SW | Measurement: 2026-05-12, H 12.0 cm, W 12.0 kg, MUAC 12.0 cm, Age 6 mo', '2026-05-12 13:06:44'),
(40, 123456, 'edit_profile', 'Child: DWD SW | Measurement: 2026-05-12, H 12.0 cm, W 12.0 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 13:07:04'),
(41, 123456, 'add_profile', 'Added profile for DWDW DWDW', '2026-05-12 13:08:52'),
(42, 123456, 'edit_profile', 'Child: DWDW DWDW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 13:09:01'),
(43, 123456, 'edit_profile', 'Child: DWDW DWDW | Measurement: 2026-05-12, H 100.0 cm, W 15.0 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 13:09:21'),
(44, 123456, 'add_profile', 'Added profile for WSWQ DDW WDSW', '2026-05-12 13:10:49'),
(45, 123456, 'edit_profile', 'Child: WSWQ DDW WDSW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, Age 6 mo', '2026-05-12 13:11:06'),
(46, 123456, 'edit_profile', 'Child: WSWQ DDW WDSW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 13:11:12'),
(47, 123456, 'edit_profile', 'Child: WSWQ DDW WDSW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 100.0 cm, Age 6 mo', '2026-05-12 13:11:26'),
(48, 123456, 'edit_profile', 'Child: WSWQ DDW WDSW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 200.0 cm, Age 6 mo', '2026-05-12 13:11:39'),
(49, 123456, 'archive_child', 'Archived child profile (Status: Archive, Child: DAVE S. SACAL)', '2026-05-12 13:12:00'),
(50, 123456, 'archive_child', 'Archived child profile (Status: Archive, Child: SIMON LIM PETER)', '2026-05-12 13:12:24'),
(51, 123456, 'archive_child', 'Archived child profile (Status: Disease, Child: JONAH HATDOG)', '2026-05-12 13:12:30'),
(52, 123456, 'archive_child', 'Archived child profile (Status: Archive, Child: OKAY OKAT)', '2026-05-12 13:12:38'),
(53, 123456, 'archive_child', 'Archived child profile (Status: Archive, Child: DWD SW)', '2026-05-12 13:12:45'),
(54, 123456, 'archive_child', 'Archived child profile (Status: Archive, Child: DWDW DWDW)', '2026-05-12 13:12:55'),
(55, 123456, 'edit_profile', 'Child: WSWQ DDW WDSW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 201.0 cm, Age 6 mo', '2026-05-12 13:13:51'),
(56, 123456, 'edit_profile', 'Child: WSWQ DDW WDSW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 202.0 cm, Age 6 mo', '2026-05-12 13:13:57'),
(57, 123456, 'edit_profile', 'Child: WSWQ DDW WDSW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 200,000.0 cm, Age 6 mo', '2026-05-12 13:14:55'),
(58, 123456, 'edit_profile', 'Child: WSWQ DDW WDSW | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 12.0 cm, Age 6 mo', '2026-05-12 13:15:11'),
(59, 123456, 'add_profile', 'Added profile for JOSWKJ JDEJKDWJ NJDNJDE', '2026-05-12 13:16:12'),
(60, 123456, 'edit_profile', 'Child: JOSWKJ JDEJKDWJ NJDNJDE | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, Age 8 mo', '2026-05-12 13:16:53'),
(61, 123456, 'edit_profile', 'Child: JOSWKJ JDEJKDWJ NJDNJDE | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, MUAC 90.0 cm, Age 8 mo', '2026-05-12 13:18:48'),
(62, 123456, 'edit_profile', 'Child: JOSWKJ JDEJKDWJ NJDNJDE | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, MUAC 12.0 cm, Age 8 mo', '2026-05-12 13:19:01'),
(63, 123456, 'edit_profile', 'Child: JOSWKJ JDEJKDWJ NJDNJDE | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, MUAC 13.0 cm, Age 8 mo', '2026-05-12 13:19:15'),
(64, 123456, 'edit_profile', 'Child: JOSWKJ JDEJKDWJ NJDNJDE | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, MUAC 12.0 cm, Age 8 mo', '2026-05-12 13:22:07'),
(65, 123456, 'edit_profile', 'Child: JOSWKJ JDEJKDWJ NJDNJDE | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, MUAC 30.0 cm, Age 8 mo', '2026-05-12 13:22:13'),
(66, 123456, 'add_profile', 'Added profile for DW JDHJ NJNJ', '2026-05-12 13:24:27'),
(67, 123456, 'edit_profile', 'Child: DW JDHJ NJNJ | Measurement: 2026-05-12, H 90.0 cm, W 2.0 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 13:24:34'),
(68, 123456, 'edit_profile', 'Child: DW JDHJ NJNJ | Measurement: 2026-05-12, H 12.0 cm, W 12.0 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 13:24:42'),
(69, 123456, 'Delete Children', 'Automatically removed 7 records archived for over 1 year.', '2027-05-12 13:28:35'),
(70, 123456, 'edit_profile', 'Child: JOSWKJ JDEJKDWJ NJDNJDE | Measurement: 2026-05-12, H 90.0 cm, W 90.0 kg, MUAC 12.0 cm, Age 8 mo', '2026-05-12 13:29:13'),
(71, 123456, 'add_profile', 'Added profile for JKSDK NKSAK NKDS', '2026-05-12 13:30:22'),
(72, 123456, 'edit_profile', 'Child: JKSDK NKSAK NKDS | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 90.0 cm, Age 7 mo', '2026-05-12 13:30:30'),
(73, 123456, 'edit_profile', 'Child: JKSDK NKSAK NKDS | Measurement: 2026-05-12, H 90.0 cm, W 16.0 kg, MUAC 90.0 cm, Age 7 mo', '2026-05-12 13:30:45'),
(74, 123456, 'archive_child', 'Archived child profile (Status: Disease, Child: JKSDK NKSAK NKDS)', '2026-05-12 13:33:18'),
(75, 123456, 'archive_child', 'Archived child profile (Status: Disease, Child: DW JDHJ NJNJ)', '2026-05-12 13:33:24'),
(76, 123456, 'archive_child', 'Archived child profile (Status: Disease, Child: JOSWKJ JDEJKDWJ NJDNJDE)', '2026-05-12 13:33:30'),
(77, 123456, 'archive_child', 'Archived child profile (Status: Archive, Child: WSWQ DDW WDSW)', '2026-05-12 13:33:36'),
(78, 123456, 'add_profile', 'Added profile for JDISJSDW JKNCJ NKCDNC', '2027-05-12 13:34:34'),
(79, 123456, 'edit_profile', 'Child: JDISJSDW JKNCJ NKCDNC | Measurement: 2027-05-12, H 90.0 cm, W 30.0 kg, Age 6 mo', '2027-05-12 13:34:44'),
(80, 123456, 'edit_profile', 'Child: JDISJSDW JKNCJ NKCDNC | Measurement: 2027-05-12, H 90.0 cm, W 30.0 kg, MUAC 12.0 cm, Age 6 mo', '2027-05-12 13:34:51'),
(81, 123456, 'edit_profile', 'Child: JDISJSDW JKNCJ NKCDNC | Measurement: 2027-05-12, H 90.0 cm, W 30.0 kg, MUAC 14.0 cm, Age 6 mo', '2027-05-12 13:34:58'),
(82, 123456, 'login', '', '2027-05-12 13:35:34'),
(83, 123456, 'archive_child', 'Archived child profile (Status: Disease, Child: JDISJSDW JKNCJ NKCDNC)', '2026-05-12 13:36:29'),
(84, 123456, 'add_profile', 'Added profile for JONAH JSDKJKSW NJSDNJD', '2026-05-12 13:37:35'),
(85, 123456, 'login', '', '2026-05-12 13:42:41'),
(86, 123456, 'edit_profile', 'Child: JONAH JSDKJKSW NJSDNJD | Measurement: 2026-05-12, H 90.0 cm, W 12.0 kg, MUAC 90.0 cm, Age 7 mo', '2026-05-12 13:44:06'),
(87, 123456, 'edit_profile', 'Child: JONAH JSDKJKSW NJSDNJD | Measurement: 2026-05-12, H 90.0 cm, W 15.0 kg, MUAC 90.0 cm, Age 7 mo', '2026-05-12 13:44:21'),
(88, 123456, 'auto_logout', '', '2026-05-12 13:47:36'),
(89, 123456, 'auto_logout', '', '2026-05-12 13:53:55'),
(90, 124510, 'login', '', '2026-05-12 13:56:27'),
(91, 123456, 'login', '', '2026-05-12 13:59:38'),
(92, 123456, 'barangay_add', 'Added new barangay: TABON', '2026-05-12 14:00:33'),
(93, 123456, 'add_profile', 'Added profile for JONAH CUANAN', '2026-05-12 14:01:28'),
(94, 123456, 'edit_profile', 'Child: JONAH CUANAN | Measurement: 2026-05-12, H 90.0 cm, W 2.0 kg, MUAC 9.0 cm, Age 8 mo', '2026-05-12 14:01:36'),
(95, 123456, 'edit_profile', 'Child: JONAH CUANAN | Measurement: 2026-05-12, H 90.0 cm, W 5.0 kg, MUAC 9.0 cm, Age 8 mo', '2026-05-12 14:01:49'),
(96, 123456, 'add_profile', 'Added profile for CLAIRE EGYPTO', '2026-05-12 14:02:44'),
(97, 123456, 'edit_profile', 'Child: CLAIRE EGYPTO | Measurement: 2026-05-12, H 90.0 cm, W 15.0 kg, Age 6 mo', '2026-05-12 14:02:53'),
(98, 123456, 'edit_profile', 'Child: CLAIRE EGYPTO | Measurement: 2026-05-12, H 90.0 cm, W 15.0 kg, MUAC 12.0 cm, Age 6 mo', '2026-05-12 14:02:59'),
(100, 123456, 'generate_report', 'Generated Nut Status Report report for Brgy. TABON, All months 2026', '2026-05-12 14:06:30'),
(101, 123456, 'add_profile', 'Added profile for CLAIRE EGYPTOO', '2026-05-12 14:17:01'),
(102, 123456, 'edit_profile', 'Child: CLAIRE EGYPTOO | Measurement: 2026-05-12, H 89.9 cm, W 11.9 kg, MUAC 90.0 cm, Age 6 mo', '2026-05-12 14:18:59'),
(103, 123456, 'edit_profile', 'Child: CLAIRE EGYPTOO | Measurement: 2026-05-12, H 89.9 cm, W 12.0 kg, Age 6 mo', '2026-05-12 14:19:20'),
(104, 123456, 'edit_profile', 'Child: CLAIRE EGYPTOO | Measurement: 2026-05-12, H 89.9 cm, W 12.0 kg, MUAC 91.0 cm, Age 6 mo', '2026-05-12 14:19:32'),
(105, 123456, 'logout', '', '2026-05-12 14:20:02'),
(106, 123456, 'login', '', '2026-05-12 14:20:16'),
(107, 882017, 'login', '', '2026-05-12 14:22:11'),
(108, 882017, 'logout', '', '2026-05-12 14:23:35'),
(109, 361806, 'login', '', '2026-05-12 14:23:48'),
(110, 123456, 'archive_child', 'Archived child profile (Status: Disease, Child: CLAIRE EGYPTOO)', '2026-05-12 14:24:30'),
(111, 123456, 'auto_logout', '', '2026-05-12 14:55:25'),
(112, 361806, 'auto_logout', '', '2026-05-12 14:57:33'),
(113, 123456, 'login', '', '2026-05-12 14:59:07'),
(114, 123456, 'auto_logout', '', '2026-05-12 15:10:50'),
(115, 123456, 'login', '', '2026-05-12 15:11:38'),
(116, 123456, 'auto_logout', '', '2026-05-12 15:42:50'),
(117, 123456, 'login', '', '2026-05-12 15:45:32'),
(118, 123456, 'auto_logout', '', '2026-05-12 15:53:40'),
(119, 123456, 'login', '', '2026-05-12 15:55:05'),
(120, 123456, 'auto_logout', '', '2026-05-12 16:06:50'),
(121, 123456, 'login', '', '2026-05-12 18:11:33'),
(122, 123456, 'auto_logout', '', '2026-05-12 18:23:11'),
(123, 123456, 'login', '', '2026-05-12 18:23:20'),
(124, 123456, 'login', '', '2026-05-12 19:24:04'),
(125, 123456, 'edit_profile', 'Child: CLAIRE EGYPTO', '2026-05-12 19:24:19'),
(126, 123456, 'auto_logout', '', '2026-05-12 19:35:20'),
(127, 123456, 'login', '', '2026-05-12 19:45:18'),
(128, 123456, 'auto_logout', '', '2026-05-12 19:56:04'),
(129, 123456, 'login', '', '2026-05-12 19:57:35'),
(130, 123456, 'auto_logout', '', '2026-06-12 20:18:32'),
(131, 123456, 'login', '', '2026-05-13 03:23:39'),
(132, 123456, 'logout', '', '2026-05-13 03:23:48'),
(133, 361806, 'login', '', '2026-05-13 03:23:53'),
(134, 361806, 'add_profile', 'Added profile for DD, ,D', '2026-05-13 03:24:42'),
(135, 361806, 'auto_logout', '', '2026-05-13 03:27:52'),
(136, 123456, 'login', '', '2026-05-13 03:36:27'),
(137, 123456, 'logout', '', '2026-05-13 03:37:21'),
(138, 738310, 'login', '', '2026-05-13 03:37:30'),
(139, 738310, 'auto_logout', '', '2026-05-13 03:41:28');

-- --------------------------------------------------------

--
-- Table structure for table `weight_for_age`
--

CREATE TABLE `weight_for_age` (
  `weight_id` int(11) NOT NULL,
  `age_month` int(11) NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `severely_underweight_max` decimal(4,1) NOT NULL,
  `underweight_min` decimal(4,1) NOT NULL,
  `underweight_max` decimal(4,1) NOT NULL,
  `normal_min` decimal(4,1) NOT NULL,
  `normal_max` decimal(4,1) NOT NULL,
  `overweight` decimal(4,1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `weight_for_age`
--

INSERT INTO `weight_for_age` (`weight_id`, `age_month`, `sex`, `severely_underweight_max`, `underweight_min`, `underweight_max`, `normal_min`, `normal_max`, `overweight`) VALUES
(1, 0, 'Male', 2.1, 2.2, 2.4, 2.5, 4.4, 4.5),
(2, 0, 'Female', 2.0, 2.1, 2.3, 2.4, 4.2, 4.3),
(3, 1, 'Male', 2.9, 3.0, 3.3, 3.4, 5.8, 5.9),
(4, 1, 'Female', 2.7, 2.8, 3.1, 3.2, 5.5, 5.6),
(5, 2, 'Male', 3.8, 3.9, 4.2, 4.3, 7.1, 7.2),
(6, 2, 'Female', 3.4, 3.5, 3.8, 3.9, 6.6, 6.7),
(7, 3, 'Male', 4.4, 4.5, 4.9, 5.0, 8.0, 8.1),
(8, 3, 'Female', 4.0, 4.1, 4.4, 4.5, 7.5, 7.6),
(9, 4, 'Male', 4.9, 5.0, 5.5, 5.6, 8.7, 8.8),
(10, 4, 'Female', 4.4, 4.5, 4.9, 5.0, 8.2, 8.3),
(11, 5, 'Male', 5.3, 5.4, 5.9, 6.0, 9.3, 9.4),
(12, 5, 'Female', 4.8, 4.9, 5.3, 5.4, 8.8, 8.9),
(13, 6, 'Male', 5.7, 5.8, 6.3, 6.4, 9.8, 9.9),
(14, 6, 'Female', 5.1, 5.2, 5.6, 5.7, 9.3, 9.4),
(15, 7, 'Male', 5.9, 6.0, 6.6, 6.7, 10.3, 10.4),
(16, 7, 'Female', 5.3, 5.4, 5.9, 6.0, 9.8, 9.9),
(17, 8, 'Male', 6.2, 6.3, 6.8, 6.9, 10.7, 10.8),
(18, 8, 'Female', 5.6, 5.7, 6.2, 6.3, 10.2, 10.3),
(19, 9, 'Male', 6.4, 6.5, 7.0, 7.1, 11.0, 11.1),
(20, 9, 'Female', 5.8, 5.9, 6.4, 6.5, 10.5, 10.6),
(21, 10, 'Male', 6.6, 6.7, 7.3, 7.4, 11.4, 11.5),
(22, 10, 'Female', 5.9, 6.0, 6.6, 6.7, 10.9, 11.0),
(23, 11, 'Male', 6.8, 6.9, 7.5, 7.6, 11.7, 11.8),
(24, 11, 'Female', 6.1, 6.2, 6.8, 6.9, 11.2, 11.3),
(25, 12, 'Male', 6.9, 7.0, 7.6, 7.7, 12.0, 12.1),
(26, 12, 'Female', 6.3, 6.4, 6.9, 7.0, 11.5, 11.6),
(27, 13, 'Male', 7.1, 7.2, 7.8, 7.9, 12.3, 12.4),
(28, 13, 'Female', 6.4, 6.5, 7.1, 7.2, 11.8, 11.9),
(29, 14, 'Male', 7.2, 7.3, 8.0, 8.1, 12.6, 12.7),
(30, 14, 'Female', 6.6, 6.7, 7.3, 7.4, 12.1, 12.2),
(31, 15, 'Male', 7.4, 7.5, 8.2, 8.3, 12.8, 12.9),
(32, 15, 'Female', 6.7, 6.8, 7.5, 7.6, 12.4, 0.0),
(33, 16, 'Female', 6.9, 7.0, 7.6, 7.7, 12.6, 12.7),
(34, 17, 'Female', 7.0, 7.1, 7.8, 7.9, 12.9, 13.0),
(35, 18, 'Female', 7.2, 7.3, 8.0, 8.1, 13.2, 13.3),
(36, 19, 'Female', 7.3, 7.4, 8.1, 8.2, 13.5, 13.6),
(37, 20, 'Female', 7.5, 7.6, 8.3, 8.4, 13.7, 13.8),
(38, 21, 'Female', 7.6, 7.7, 8.5, 8.6, 14.0, 14.1),
(39, 22, 'Female', 7.8, 7.9, 8.6, 8.7, 14.3, 14.4),
(40, 23, 'Female', 7.9, 8.0, 8.8, 8.9, 14.6, 14.7),
(41, 24, 'Female', 8.1, 8.2, 8.9, 9.0, 14.8, 14.9),
(42, 25, 'Female', 8.2, 8.3, 9.1, 9.2, 15.1, 15.2),
(43, 26, 'Female', 8.4, 8.5, 9.3, 9.4, 15.4, 15.5),
(44, 27, 'Female', 8.5, 8.6, 9.4, 9.5, 15.7, 15.8),
(45, 28, 'Female', 8.6, 8.7, 9.6, 9.7, 16.0, 16.1),
(46, 29, 'Female', 8.8, 8.9, 9.7, 9.8, 16.2, 16.3),
(47, 30, 'Female', 8.9, 9.0, 9.9, 10.0, 16.5, 16.6),
(48, 31, 'Female', 9.0, 9.1, 10.0, 10.1, 16.8, 16.9),
(49, 32, 'Female', 9.1, 9.2, 10.2, 10.3, 17.1, 17.2),
(50, 33, 'Female', 9.3, 9.4, 10.3, 10.4, 17.3, 17.4),
(51, 34, 'Female', 9.4, 9.5, 10.4, 10.5, 17.6, 17.7),
(52, 35, 'Female', 9.5, 9.6, 10.6, 10.7, 17.9, 18.0),
(53, 36, 'Female', 9.6, 9.7, 10.7, 10.8, 18.1, 18.2),
(54, 37, 'Female', 9.7, 9.8, 10.8, 10.9, 18.4, 18.5),
(55, 38, 'Female', 9.8, 9.9, 11.0, 11.1, 18.7, 18.8),
(56, 39, 'Female', 9.9, 10.0, 11.1, 11.2, 19.0, 19.1),
(57, 40, 'Female', 10.1, 10.2, 11.2, 11.3, 19.2, 19.3),
(58, 41, 'Female', 10.2, 10.3, 11.4, 11.5, 19.5, 19.6),
(59, 42, 'Female', 10.3, 10.4, 11.5, 11.6, 19.8, 19.9),
(60, 43, 'Female', 10.4, 10.5, 11.6, 11.7, 20.1, 20.2),
(61, 44, 'Female', 10.5, 10.6, 11.7, 11.8, 20.4, 20.5),
(62, 45, 'Female', 10.6, 10.7, 11.9, 12.0, 20.7, 20.8),
(63, 46, 'Female', 10.7, 10.8, 12.0, 12.1, 20.9, 21.0),
(64, 47, 'Female', 10.8, 10.9, 12.1, 12.2, 21.2, 21.3),
(65, 48, 'Female', 10.9, 11.0, 12.2, 12.3, 21.5, 21.6),
(66, 49, 'Female', 11.0, 11.1, 12.3, 12.4, 21.8, 21.9),
(67, 50, 'Female', 11.1, 11.2, 12.4, 12.5, 22.1, 22.2),
(68, 51, 'Female', 11.2, 11.3, 12.6, 12.7, 22.4, 22.5),
(69, 52, 'Female', 11.3, 11.4, 12.7, 12.8, 22.6, 22.7),
(70, 53, 'Female', 11.4, 11.5, 12.8, 12.9, 22.9, 23.0),
(71, 54, 'Female', 11.5, 11.6, 12.9, 13.0, 23.2, 23.3),
(72, 55, 'Female', 11.6, 11.7, 13.1, 13.2, 23.5, 23.6),
(73, 56, 'Female', 11.7, 11.8, 13.2, 13.3, 23.8, 23.9),
(74, 57, 'Female', 11.8, 11.9, 13.3, 13.4, 24.1, 24.2),
(75, 58, 'Female', 11.9, 12.0, 13.4, 13.5, 24.4, 24.5),
(76, 59, 'Female', 12.0, 12.1, 13.5, 13.6, 24.6, 24.7),
(77, 16, 'Male', 7.5, 7.6, 8.3, 8.4, 13.1, 13.2),
(78, 17, 'Male', 7.7, 7.8, 8.5, 8.6, 13.4, 13.5),
(79, 18, 'Male', 7.8, 7.9, 8.7, 8.8, 13.7, 13.8),
(80, 19, 'Male', 8.0, 8.1, 8.8, 8.9, 13.9, 14.0),
(81, 20, 'Male', 8.1, 8.2, 9.0, 9.1, 14.2, 14.3),
(82, 21, 'Male', 8.2, 8.3, 9.1, 9.2, 14.5, 14.6),
(83, 22, 'Male', 8.4, 8.5, 9.3, 9.4, 14.7, 14.8),
(84, 23, 'Male', 8.5, 8.6, 9.4, 9.5, 15.0, 15.1),
(85, 24, 'Male', 8.6, 8.7, 9.6, 9.7, 15.3, 15.4),
(86, 25, 'Male', 8.8, 8.9, 9.7, 9.8, 15.5, 15.6),
(87, 26, 'Male', 8.9, 9.0, 9.9, 10.0, 15.8, 15.9),
(88, 27, 'Male', 9.0, 9.1, 10.0, 10.1, 16.1, 16.2),
(89, 28, 'Male', 9.1, 9.2, 10.1, 10.2, 16.3, 16.4),
(90, 29, 'Male', 9.2, 9.3, 10.3, 10.4, 16.6, 16.7),
(91, 30, 'Male', 9.4, 9.5, 10.4, 10.5, 16.9, 17.0),
(92, 31, 'Male', 9.5, 9.6, 10.6, 10.7, 17.1, 17.2),
(93, 32, 'Male', 9.6, 9.7, 10.7, 10.8, 17.4, 17.5),
(94, 33, 'Male', 9.7, 9.8, 10.8, 10.9, 17.6, 17.7),
(95, 34, 'Male', 9.8, 9.9, 10.9, 11.0, 17.8, 17.9),
(96, 35, 'Male', 9.9, 10.0, 11.1, 11.2, 18.1, 18.2),
(97, 36, 'Male', 10.0, 10.1, 11.2, 11.3, 18.3, 18.4),
(98, 37, 'Male', 10.1, 10.2, 11.3, 11.4, 18.6, 18.7),
(99, 38, 'Male', 10.2, 10.3, 11.4, 11.5, 18.8, 18.9),
(100, 39, 'Male', 10.3, 10.4, 11.5, 11.6, 19.0, 19.1),
(101, 40, 'Male', 10.4, 10.5, 11.7, 11.8, 19.3, 19.4),
(102, 41, 'Male', 10.5, 10.6, 11.8, 11.9, 19.5, 19.6),
(103, 42, 'Male', 10.6, 10.7, 11.9, 12.0, 19.7, 19.8),
(104, 43, 'Male', 10.7, 10.8, 12.0, 12.1, 20.0, 20.1),
(105, 44, 'Male', 10.8, 10.9, 12.1, 12.2, 20.2, 20.3),
(106, 45, 'Male', 10.9, 11.0, 12.3, 12.4, 20.5, 20.6),
(107, 46, 'Male', 11.0, 11.1, 12.4, 12.5, 20.7, 20.8),
(108, 47, 'Male', 11.1, 11.2, 12.5, 12.6, 20.9, 21.0),
(109, 48, 'Male', 11.2, 11.3, 12.6, 12.7, 21.2, 21.3),
(110, 49, 'Male', 11.3, 11.4, 12.7, 12.8, 21.4, 21.5),
(111, 50, 'Male', 11.4, 11.5, 12.8, 12.9, 21.7, 21.8),
(112, 51, 'Male', 11.5, 11.6, 13.0, 13.1, 21.9, 22.0),
(113, 52, 'Male', 11.6, 11.7, 13.1, 13.2, 22.2, 22.3),
(114, 53, 'Male', 11.7, 11.8, 13.2, 13.3, 22.4, 22.5),
(115, 54, 'Male', 11.8, 11.9, 13.3, 13.4, 22.7, 22.8),
(116, 55, 'Male', 11.9, 12.0, 13.4, 13.5, 22.9, 23.0),
(117, 56, 'Male', 12.0, 12.1, 13.5, 13.6, 23.2, 23.3),
(118, 57, 'Male', 12.1, 12.2, 13.6, 13.7, 23.4, 23.5),
(119, 58, 'Male', 12.2, 12.3, 13.7, 13.8, 23.7, 23.8),
(120, 59, 'Male', 12.3, 12.4, 13.9, 14.0, 23.9, 24.0);

-- --------------------------------------------------------

--
-- Table structure for table `weight_for_length`
--

CREATE TABLE `weight_for_length` (
  `wfl_id` int(11) NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `length_cm` decimal(4,1) NOT NULL,
  `severely_wasted` decimal(4,1) NOT NULL,
  `wasted_from` decimal(4,1) NOT NULL,
  `wasted_to` decimal(4,1) NOT NULL,
  `normal_from` decimal(4,1) NOT NULL,
  `normal_to` decimal(4,1) NOT NULL,
  `overweight_from` decimal(4,1) NOT NULL,
  `overweight_to` decimal(4,1) NOT NULL,
  `obese` decimal(4,1) NOT NULL,
  `age_group` enum('0-23months','24-60months') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `weight_for_length`
--

INSERT INTO `weight_for_length` (`wfl_id`, `sex`, `length_cm`, `severely_wasted`, `wasted_from`, `wasted_to`, `normal_from`, `normal_to`, `overweight_from`, `overweight_to`, `obese`, `age_group`) VALUES
(1, 'Male', 45.0, 1.8, 1.9, 2.0, 2.0, 3.0, 3.1, 3.3, 3.4, '0-23months'),
(2, 'Male', 45.5, 1.8, 1.9, 2.0, 2.1, 3.1, 3.2, 3.4, 3.5, '0-23months'),
(3, 'Male', 46.0, 1.9, 2.0, 2.1, 2.2, 3.1, 3.2, 3.5, 3.6, '0-23months'),
(4, 'Male', 46.5, 2.0, 2.1, 2.2, 2.3, 3.2, 3.3, 3.6, 3.7, '0-23months'),
(5, 'Male', 47.0, 2.0, 2.1, 2.2, 2.3, 3.3, 3.4, 3.7, 3.8, '0-23months'),
(6, 'Male', 47.5, 2.1, 2.2, 2.3, 2.4, 3.4, 3.5, 3.8, 3.9, '0-23months'),
(7, 'Male', 48.0, 2.2, 2.3, 2.4, 2.5, 3.6, 3.7, 3.9, 4.0, '0-23months'),
(8, 'Male', 48.5, 2.2, 2.3, 2.5, 2.6, 3.7, 3.8, 4.0, 4.1, '0-23months'),
(9, 'Male', 49.0, 2.3, 2.4, 2.5, 2.6, 3.8, 3.9, 4.2, 4.3, '0-23months'),
(10, 'Male', 49.5, 2.4, 2.5, 2.6, 2.7, 3.9, 4.0, 4.3, 4.4, '0-23months'),
(11, 'Male', 50.0, 2.5, 2.6, 2.7, 2.8, 4.0, 4.1, 4.4, 4.5, '0-23months'),
(12, 'Male', 50.5, 2.6, 2.7, 2.8, 2.9, 4.1, 4.2, 4.5, 4.6, '0-23months'),
(13, 'Male', 51.0, 2.6, 2.7, 2.9, 3.0, 4.2, 4.3, 4.7, 4.8, '0-23months'),
(14, 'Male', 51.5, 2.7, 2.8, 3.0, 3.1, 4.4, 4.5, 4.8, 4.9, '0-23months'),
(15, 'Male', 52.0, 2.8, 2.9, 3.1, 3.2, 4.5, 4.6, 5.0, 5.1, '0-23months'),
(16, 'Male', 52.5, 2.9, 3.0, 3.2, 3.3, 4.6, 4.7, 5.1, 5.2, '0-23months'),
(17, 'Male', 53.0, 3.0, 3.1, 3.3, 3.4, 4.8, 4.9, 5.3, 5.4, '0-23months'),
(18, 'Male', 53.5, 3.1, 3.2, 3.4, 3.5, 4.9, 5.0, 5.4, 5.5, '0-23months'),
(19, 'Male', 54.0, 3.2, 3.3, 3.5, 3.6, 5.1, 5.2, 5.6, 5.7, '0-23months'),
(20, 'Male', 54.5, 3.3, 3.4, 3.6, 3.7, 5.3, 5.4, 5.8, 5.9, '0-23months'),
(21, 'Male', 55.0, 3.5, 3.6, 3.7, 3.8, 5.4, 5.5, 6.0, 6.1, '0-23months'),
(22, 'Male', 55.5, 3.6, 3.7, 3.9, 4.0, 5.6, 5.7, 6.1, 6.2, '0-23months'),
(23, 'Male', 56.0, 3.7, 3.8, 4.0, 4.1, 5.8, 5.9, 6.3, 6.4, '0-23months'),
(24, 'Male', 56.5, 3.8, 3.9, 4.1, 4.2, 5.9, 6.0, 6.5, 6.6, '0-23months'),
(25, 'Male', 57.0, 3.9, 4.0, 4.2, 4.3, 6.1, 6.2, 6.7, 6.8, '0-23months'),
(26, 'Male', 57.5, 4.0, 4.1, 4.4, 4.5, 6.3, 6.4, 6.9, 7.0, '0-23months'),
(27, 'Male', 58.0, 4.2, 4.3, 4.5, 4.6, 6.4, 6.5, 7.1, 7.2, '0-23months'),
(28, 'Male', 58.5, 4.3, 4.4, 4.6, 4.7, 6.6, 6.7, 7.2, 7.3, '0-23months'),
(29, 'Male', 59.0, 4.4, 4.5, 4.7, 4.8, 6.8, 6.9, 7.4, 7.5, '0-23months'),
(30, 'Male', 59.5, 4.5, 4.6, 4.9, 5.0, 7.0, 7.1, 7.6, 7.7, '0-23months'),
(31, 'Male', 60.0, 4.6, 4.7, 5.0, 5.1, 7.1, 7.2, 7.8, 7.9, '0-23months'),
(32, 'Male', 60.5, 4.7, 4.8, 5.1, 5.2, 7.3, 7.4, 8.0, 8.1, '0-23months'),
(33, 'Male', 61.0, 4.8, 4.9, 5.2, 5.3, 7.4, 7.5, 8.1, 8.2, '0-23months'),
(34, 'Male', 61.5, 4.9, 5.0, 5.3, 5.4, 7.6, 7.7, 8.3, 8.4, '0-23months'),
(35, 'Male', 62.0, 5.0, 5.1, 5.5, 5.6, 7.7, 7.8, 8.5, 8.6, '0-23months'),
(36, 'Male', 62.5, 5.1, 5.2, 5.6, 5.7, 7.9, 8.0, 8.6, 8.7, '0-23months'),
(37, 'Male', 63.0, 5.2, 5.3, 5.7, 5.8, 8.0, 8.1, 8.8, 8.9, '0-23months'),
(38, 'Male', 63.5, 5.3, 5.4, 5.8, 5.9, 8.2, 8.3, 8.9, 9.0, '0-23months'),
(39, 'Male', 64.0, 5.4, 5.5, 5.9, 6.0, 8.3, 8.4, 9.1, 9.2, '0-23months'),
(40, 'Male', 64.5, 5.5, 5.6, 6.0, 6.1, 8.5, 8.6, 9.3, 9.4, '0-23months'),
(41, 'Male', 65.0, 5.6, 5.7, 6.1, 6.2, 8.6, 8.7, 9.4, 9.5, '0-23months'),
(42, 'Male', 65.5, 5.7, 5.8, 6.2, 6.3, 8.7, 8.8, 9.6, 9.7, '0-23months'),
(43, 'Male', 66.0, 5.8, 5.9, 6.3, 6.4, 8.9, 9.0, 9.7, 9.8, '0-23months'),
(44, 'Male', 66.5, 5.9, 6.0, 6.4, 6.5, 9.0, 9.1, 9.9, 10.0, '0-23months'),
(45, 'Male', 67.0, 6.0, 6.1, 6.5, 6.6, 9.2, 9.3, 10.0, 10.1, '0-23months'),
(46, 'Male', 67.5, 6.1, 6.2, 6.6, 6.7, 9.3, 9.4, 10.2, 10.3, '0-23months'),
(47, 'Male', 68.0, 6.2, 6.3, 6.7, 6.8, 9.4, 9.5, 10.3, 10.4, '0-23months'),
(48, 'Male', 68.5, 6.3, 6.4, 6.8, 6.9, 9.6, 9.7, 10.5, 10.6, '0-23months'),
(49, 'Male', 69.0, 6.4, 6.5, 6.9, 7.0, 9.7, 9.8, 10.6, 10.7, '0-23months'),
(50, 'Male', 69.5, 6.5, 6.6, 7.0, 7.1, 9.8, 9.9, 10.8, 10.9, '0-23months'),
(51, 'Male', 70.0, 6.6, 6.7, 7.1, 7.2, 10.0, 10.1, 10.9, 11.0, '0-23months'),
(52, 'Male', 70.5, 6.7, 6.8, 7.2, 7.3, 10.1, 10.2, 11.1, 11.2, '0-23months'),
(53, 'Male', 71.0, 6.8, 6.9, 7.3, 7.4, 10.2, 10.3, 11.2, 11.3, '0-23months'),
(54, 'Male', 71.5, 6.9, 7.0, 7.4, 7.5, 10.3, 10.4, 11.3, 11.4, '0-23months'),
(55, 'Male', 72.0, 7.0, 7.1, 7.5, 7.6, 10.4, 10.5, 11.4, 11.5, '0-23months'),
(56, 'Male', 72.5, 7.1, 7.2, 7.6, 7.7, 10.5, 10.6, 11.5, 11.6, '0-23months'),
(57, 'Male', 73.0, 7.2, 7.3, 7.7, 7.8, 10.6, 10.7, 11.6, 11.7, '0-23months'),
(58, 'Male', 73.5, 7.3, 7.4, 7.8, 7.9, 10.7, 10.8, 11.7, 11.8, '0-23months'),
(59, 'Male', 74.0, 7.4, 7.5, 7.9, 8.0, 10.8, 10.9, 11.8, 11.9, '0-23months'),
(60, 'Male', 74.5, 7.5, 7.6, 8.0, 8.1, 10.9, 11.0, 11.9, 12.0, '0-23months'),
(61, 'Male', 75.0, 7.6, 7.7, 8.1, 8.2, 11.0, 11.1, 12.0, 12.1, '0-23months'),
(62, 'Male', 75.5, 7.7, 7.8, 8.2, 8.3, 11.1, 11.2, 12.1, 12.2, '0-23months'),
(63, 'Male', 76.0, 7.8, 7.9, 8.3, 8.4, 11.2, 11.3, 12.2, 12.3, '0-23months'),
(64, 'Male', 76.5, 7.9, 8.0, 8.4, 8.5, 11.3, 11.4, 12.3, 12.4, '0-23months'),
(65, 'Male', 77.0, 8.0, 8.1, 8.5, 8.6, 11.4, 11.5, 12.4, 12.5, '0-23months'),
(66, 'Male', 77.5, 8.1, 8.2, 8.6, 8.7, 11.5, 11.6, 12.5, 12.6, '0-23months'),
(67, 'Male', 78.0, 8.2, 8.3, 8.7, 8.8, 11.6, 11.7, 12.6, 12.7, '0-23months'),
(68, 'Male', 78.5, 8.3, 8.4, 8.8, 8.9, 11.7, 11.8, 12.7, 12.8, '0-23months'),
(69, 'Male', 79.0, 8.4, 8.5, 8.9, 9.0, 11.8, 11.9, 12.8, 12.9, '0-23months'),
(70, 'Male', 79.5, 8.5, 8.6, 9.0, 9.1, 11.9, 12.0, 12.9, 13.0, '0-23months'),
(71, 'Male', 80.0, 8.6, 8.7, 9.1, 9.2, 12.0, 12.1, 13.0, 13.1, '0-23months'),
(72, 'Male', 80.5, 8.7, 8.8, 9.2, 9.3, 12.1, 12.2, 13.1, 13.2, '0-23months'),
(73, 'Male', 81.0, 8.8, 8.9, 9.3, 9.4, 12.2, 12.3, 13.2, 13.3, '0-23months'),
(74, 'Male', 81.5, 8.9, 9.0, 9.4, 9.5, 12.3, 12.4, 13.3, 13.4, '0-23months'),
(75, 'Male', 82.0, 9.0, 9.1, 9.5, 9.6, 12.4, 12.5, 13.4, 13.5, '0-23months'),
(76, 'Male', 82.5, 9.1, 9.2, 9.6, 9.7, 12.5, 12.6, 13.5, 13.6, '0-23months'),
(77, 'Male', 83.0, 9.2, 9.3, 9.7, 9.8, 12.6, 12.7, 13.6, 13.7, '0-23months'),
(78, 'Male', 83.5, 9.3, 9.4, 9.8, 9.9, 12.7, 12.8, 13.7, 13.8, '0-23months'),
(79, 'Male', 84.0, 9.4, 9.5, 9.9, 10.0, 12.8, 12.9, 13.8, 13.9, '0-23months'),
(80, 'Male', 84.5, 9.5, 9.6, 10.0, 10.1, 12.9, 13.0, 13.9, 14.0, '0-23months'),
(81, 'Male', 85.0, 9.6, 9.7, 10.1, 10.2, 13.0, 13.1, 14.0, 14.1, '0-23months'),
(82, 'Male', 85.5, 9.7, 9.8, 10.2, 10.3, 13.1, 13.2, 14.1, 14.2, '0-23months'),
(83, 'Male', 86.0, 9.8, 9.9, 10.3, 10.4, 13.2, 13.3, 14.2, 14.3, '0-23months'),
(84, 'Male', 86.5, 9.9, 10.0, 10.4, 10.5, 13.3, 13.4, 14.3, 14.4, '0-23months'),
(85, 'Male', 87.0, 10.0, 10.1, 10.5, 10.6, 13.4, 13.5, 14.4, 14.5, '0-23months'),
(86, 'Male', 87.5, 10.1, 10.2, 10.6, 10.7, 13.5, 13.6, 14.5, 14.6, '0-23months'),
(87, 'Male', 88.0, 10.2, 10.3, 10.7, 10.8, 13.6, 13.7, 14.6, 14.7, '0-23months'),
(88, 'Male', 88.5, 10.3, 10.4, 10.8, 10.9, 13.7, 13.8, 14.7, 14.8, '0-23months'),
(89, 'Male', 89.0, 10.4, 10.5, 10.9, 11.0, 13.8, 13.9, 14.8, 14.9, '0-23months'),
(90, 'Male', 89.5, 10.5, 10.6, 11.0, 11.1, 13.9, 14.0, 14.9, 15.0, '0-23months'),
(91, 'Male', 90.0, 10.6, 10.7, 11.1, 11.2, 14.0, 14.1, 15.0, 15.1, '0-23months'),
(92, 'Male', 90.5, 10.7, 10.8, 11.2, 11.3, 14.1, 14.2, 15.1, 15.2, '0-23months'),
(93, 'Male', 91.0, 10.8, 10.9, 11.3, 11.4, 14.2, 14.3, 15.2, 15.3, '0-23months'),
(94, 'Male', 91.5, 10.9, 11.0, 11.4, 11.5, 14.3, 14.4, 15.3, 15.4, '0-23months'),
(95, 'Male', 92.0, 11.0, 11.1, 11.5, 11.6, 14.4, 14.5, 15.4, 15.5, '0-23months'),
(96, 'Male', 92.5, 11.1, 11.2, 11.6, 11.7, 14.5, 14.6, 15.5, 15.6, '0-23months'),
(97, 'Male', 93.0, 11.2, 11.3, 11.7, 11.8, 14.6, 14.7, 15.6, 15.7, '0-23months'),
(98, 'Male', 93.5, 11.3, 11.4, 11.8, 11.9, 14.7, 14.8, 15.7, 15.8, '0-23months'),
(99, 'Male', 94.0, 11.4, 11.5, 11.9, 12.0, 14.8, 14.9, 15.8, 15.9, '0-23months'),
(100, 'Male', 94.5, 11.5, 11.6, 12.0, 12.1, 14.9, 15.0, 15.9, 16.0, '0-23months'),
(101, 'Male', 95.0, 11.6, 11.7, 12.1, 12.2, 15.0, 15.1, 16.0, 16.1, '0-23months'),
(102, 'Male', 95.5, 11.7, 11.8, 12.2, 12.3, 15.1, 15.2, 16.1, 16.2, '0-23months'),
(103, 'Male', 96.0, 11.8, 11.9, 12.3, 12.4, 15.2, 15.3, 16.2, 16.3, '0-23months'),
(104, 'Male', 96.5, 11.9, 12.0, 12.4, 12.5, 15.3, 15.4, 16.3, 16.4, '0-23months'),
(105, 'Male', 97.0, 12.0, 12.1, 12.5, 12.6, 15.4, 15.5, 16.4, 16.5, '0-23months'),
(106, 'Male', 97.5, 12.1, 12.2, 12.6, 12.7, 15.5, 15.6, 16.5, 16.6, '0-23months'),
(107, 'Male', 98.0, 12.2, 12.3, 12.7, 12.8, 15.6, 15.7, 16.6, 16.7, '0-23months'),
(108, 'Male', 98.5, 12.3, 12.4, 12.8, 12.9, 15.7, 15.8, 16.7, 16.8, '0-23months'),
(109, 'Male', 99.0, 12.4, 12.5, 12.9, 13.0, 15.8, 15.9, 16.8, 16.9, '0-23months'),
(110, 'Male', 99.5, 12.5, 12.6, 13.0, 13.1, 15.9, 16.0, 16.9, 17.0, '0-23months'),
(111, 'Male', 100.0, 12.6, 12.7, 13.1, 13.2, 16.0, 16.1, 17.0, 17.1, '0-23months'),
(112, 'Male', 100.5, 12.7, 12.8, 13.2, 13.3, 16.1, 16.2, 17.1, 17.2, '0-23months'),
(113, 'Male', 101.0, 12.8, 12.9, 13.3, 13.4, 16.2, 16.3, 17.2, 17.3, '0-23months'),
(114, 'Male', 101.5, 12.9, 13.0, 13.4, 13.5, 16.3, 16.4, 17.3, 17.4, '0-23months'),
(115, 'Male', 102.0, 13.0, 13.1, 13.5, 13.6, 16.4, 16.5, 17.4, 17.5, '0-23months'),
(116, 'Male', 102.5, 13.1, 13.2, 13.6, 13.7, 16.5, 16.6, 17.5, 17.6, '0-23months'),
(117, 'Male', 103.0, 13.2, 13.3, 13.7, 13.8, 16.6, 16.7, 17.6, 17.7, '0-23months'),
(118, 'Male', 103.5, 13.3, 13.4, 13.8, 13.9, 16.7, 16.8, 17.7, 17.8, '0-23months'),
(119, 'Male', 104.0, 13.4, 13.5, 13.9, 14.0, 16.8, 16.9, 17.8, 17.9, '0-23months'),
(120, 'Male', 104.5, 13.5, 13.6, 14.0, 14.1, 16.9, 17.0, 17.9, 18.0, '0-23months'),
(121, 'Male', 105.0, 13.6, 13.7, 14.1, 14.2, 17.0, 17.1, 18.0, 18.1, '0-23months'),
(122, 'Male', 105.5, 13.7, 13.8, 14.2, 14.3, 17.1, 17.2, 18.1, 18.2, '0-23months'),
(123, 'Male', 106.0, 13.8, 13.9, 14.3, 14.4, 17.2, 17.3, 18.2, 18.3, '0-23months'),
(124, 'Male', 106.5, 13.9, 14.0, 14.4, 14.5, 17.3, 17.4, 18.3, 18.4, '0-23months'),
(125, 'Male', 107.0, 14.0, 14.1, 14.5, 14.6, 17.4, 17.5, 18.4, 18.5, '0-23months'),
(126, 'Male', 107.5, 14.1, 14.2, 14.6, 14.7, 17.5, 17.6, 18.5, 18.6, '0-23months'),
(127, 'Male', 108.0, 14.2, 14.3, 14.7, 14.8, 17.6, 17.7, 18.6, 18.7, '0-23months'),
(128, 'Male', 108.5, 14.3, 14.4, 14.8, 14.9, 17.7, 17.8, 18.7, 18.8, '0-23months'),
(129, 'Male', 109.0, 14.4, 14.5, 14.9, 15.0, 17.8, 17.9, 18.8, 18.9, '0-23months'),
(130, 'Male', 109.5, 14.5, 14.6, 15.0, 15.1, 17.9, 18.0, 18.9, 19.0, '0-23months'),
(131, 'Male', 110.0, 14.6, 14.7, 15.1, 15.2, 18.0, 18.1, 19.0, 19.1, '0-23months'),
(132, 'Female', 45.0, 1.8, 1.9, 2.0, 2.1, 3.0, 3.1, 3.3, 3.4, '0-23months'),
(133, 'Female', 45.5, 1.9, 2.0, 2.1, 2.2, 3.1, 3.2, 3.4, 3.5, '0-23months'),
(134, 'Female', 46.0, 2.0, 2.1, 2.2, 2.3, 3.1, 3.3, 3.5, 3.6, '0-23months'),
(135, 'Female', 46.5, 2.0, 2.1, 2.2, 2.3, 3.2, 3.4, 3.6, 3.7, '0-23months'),
(136, 'Female', 47.0, 2.1, 2.2, 2.3, 2.4, 3.3, 3.5, 3.7, 3.8, '0-23months'),
(137, 'Female', 47.5, 2.1, 2.2, 2.3, 2.5, 3.4, 3.6, 3.8, 3.9, '0-23months'),
(138, 'Female', 48.0, 2.2, 2.3, 2.4, 2.6, 3.5, 3.7, 3.9, 4.0, '0-23months'),
(139, 'Female', 48.5, 2.3, 2.4, 2.5, 2.7, 3.6, 3.8, 4.0, 4.1, '0-23months'),
(140, 'Female', 49.0, 2.3, 2.4, 2.5, 2.7, 3.7, 3.9, 4.1, 4.2, '0-23months'),
(141, 'Female', 49.5, 2.4, 2.5, 2.6, 2.8, 3.8, 4.0, 4.2, 4.3, '0-23months'),
(142, 'Female', 50.0, 2.5, 2.6, 2.7, 2.9, 3.9, 4.1, 4.3, 4.4, '0-23months'),
(143, 'Female', 50.5, 2.6, 2.7, 2.8, 3.0, 4.0, 4.2, 4.5, 4.6, '0-23months'),
(144, 'Female', 51.0, 2.7, 2.8, 2.9, 3.1, 4.2, 4.4, 4.7, 4.8, '0-23months'),
(145, 'Female', 51.5, 2.8, 2.9, 3.0, 3.2, 4.3, 4.5, 4.8, 4.9, '0-23months'),
(146, 'Female', 52.0, 2.9, 3.0, 3.1, 3.3, 4.5, 4.7, 5.0, 5.1, '0-23months'),
(147, 'Female', 52.5, 3.0, 3.1, 3.2, 3.4, 4.6, 4.8, 5.1, 5.2, '0-23months'),
(148, 'Female', 53.0, 3.1, 3.2, 3.3, 3.5, 4.8, 4.9, 5.3, 5.4, '0-23months'),
(149, 'Female', 53.5, 3.2, 3.3, 3.4, 3.6, 4.9, 5.1, 5.4, 5.5, '0-23months'),
(150, 'Female', 54.0, 3.3, 3.4, 3.5, 3.7, 5.0, 5.2, 5.6, 5.7, '0-23months'),
(151, 'Female', 54.5, 3.4, 3.5, 3.6, 3.8, 5.2, 5.4, 5.8, 5.9, '0-23months'),
(152, 'Female', 55.0, 3.5, 3.6, 3.7, 3.9, 5.4, 5.5, 6.0, 6.1, '0-23months'),
(153, 'Female', 55.5, 3.6, 3.7, 3.8, 4.0, 5.5, 5.7, 6.1, 6.2, '0-23months'),
(154, 'Female', 56.0, 3.7, 3.8, 3.9, 4.1, 5.7, 5.9, 6.3, 6.4, '0-23months'),
(155, 'Female', 56.5, 3.8, 3.9, 4.0, 4.2, 5.8, 6.0, 6.5, 6.6, '0-23months'),
(156, 'Female', 57.0, 3.9, 4.0, 4.1, 4.3, 6.0, 6.2, 6.7, 6.8, '0-23months'),
(157, 'Female', 57.5, 4.0, 4.1, 4.2, 4.4, 6.2, 6.4, 6.9, 7.0, '0-23months'),
(158, 'Female', 58.0, 4.1, 4.2, 4.3, 4.5, 6.4, 6.6, 7.1, 7.2, '0-23months'),
(159, 'Female', 58.5, 4.2, 4.3, 4.4, 4.6, 6.6, 6.8, 7.3, 7.4, '0-23months'),
(160, 'Female', 59.0, 4.3, 4.4, 4.5, 4.7, 6.7, 6.9, 7.4, 7.5, '0-23months'),
(161, 'Female', 59.5, 4.4, 4.5, 4.6, 4.8, 6.9, 7.1, 7.6, 7.7, '0-23months'),
(162, 'Female', 60.0, 4.5, 4.6, 4.7, 4.9, 7.1, 7.3, 7.8, 7.9, '0-23months'),
(163, 'Female', 60.5, 4.6, 4.7, 4.8, 5.0, 7.2, 7.4, 7.9, 8.0, '0-23months'),
(164, 'Female', 61.0, 4.7, 4.8, 4.9, 5.1, 7.3, 7.5, 8.0, 8.1, '0-23months'),
(165, 'Female', 61.5, 4.8, 4.9, 5.0, 5.2, 7.4, 7.6, 8.1, 8.2, '0-23months'),
(166, 'Female', 62.0, 4.9, 5.0, 5.1, 5.3, 7.5, 7.7, 8.2, 8.3, '0-23months'),
(167, 'Female', 62.5, 5.0, 5.1, 5.2, 5.4, 7.6, 7.8, 8.3, 8.4, '0-23months'),
(168, 'Female', 63.0, 5.1, 5.2, 5.3, 5.5, 7.7, 7.9, 8.4, 8.5, '0-23months'),
(169, 'Female', 63.5, 5.2, 5.3, 5.4, 5.6, 7.8, 8.0, 8.5, 8.6, '0-23months'),
(170, 'Female', 64.0, 5.3, 5.4, 5.5, 5.7, 7.9, 8.1, 8.6, 8.7, '0-23months'),
(171, 'Female', 64.5, 5.4, 5.5, 5.6, 5.8, 8.0, 8.2, 8.7, 8.8, '0-23months'),
(172, 'Female', 65.0, 5.5, 5.6, 5.7, 5.9, 8.1, 8.3, 8.8, 8.9, '0-23months'),
(173, 'Female', 65.5, 5.6, 5.7, 5.8, 6.0, 8.2, 8.4, 8.9, 9.0, '0-23months'),
(174, 'Female', 66.0, 5.7, 5.8, 5.9, 6.1, 8.3, 8.5, 9.0, 9.1, '0-23months'),
(175, 'Female', 66.5, 5.8, 5.9, 6.0, 6.2, 8.4, 8.6, 9.1, 9.2, '0-23months'),
(176, 'Female', 67.0, 5.9, 6.0, 6.1, 6.3, 8.5, 8.7, 9.2, 9.3, '0-23months'),
(177, 'Female', 67.5, 6.0, 6.1, 6.2, 6.4, 8.6, 8.8, 9.3, 9.4, '0-23months'),
(178, 'Female', 68.0, 6.1, 6.2, 6.3, 6.5, 8.7, 8.9, 9.4, 9.5, '0-23months'),
(179, 'Female', 68.5, 6.2, 6.3, 6.4, 6.6, 8.8, 9.0, 9.5, 9.6, '0-23months'),
(180, 'Female', 69.0, 6.3, 6.4, 6.5, 6.7, 8.9, 9.1, 9.6, 9.7, '0-23months'),
(181, 'Female', 69.5, 6.4, 6.5, 6.6, 6.8, 9.0, 9.2, 9.7, 9.8, '0-23months'),
(182, 'Female', 70.0, 6.5, 6.6, 6.7, 6.9, 9.1, 9.3, 9.8, 9.9, '0-23months'),
(183, 'Female', 70.5, 6.6, 6.7, 6.8, 7.0, 9.2, 9.4, 9.9, 10.0, '0-23months'),
(184, 'Female', 71.0, 6.7, 6.8, 6.9, 7.1, 9.3, 9.5, 10.0, 10.1, '0-23months'),
(185, 'Female', 71.5, 6.8, 6.9, 7.0, 7.2, 9.4, 9.6, 10.1, 10.2, '0-23months'),
(186, 'Female', 72.0, 6.9, 7.0, 7.1, 7.3, 9.5, 9.7, 10.2, 10.3, '0-23months'),
(187, 'Female', 72.5, 7.0, 7.1, 7.2, 7.4, 9.6, 9.8, 10.3, 10.4, '0-23months'),
(188, 'Female', 73.0, 7.1, 7.2, 7.3, 7.5, 9.7, 9.9, 10.4, 10.5, '0-23months'),
(189, 'Female', 73.5, 7.2, 7.3, 7.4, 7.6, 9.8, 10.0, 10.5, 10.6, '0-23months'),
(190, 'Female', 74.0, 7.3, 7.4, 7.5, 7.7, 9.9, 10.1, 10.6, 10.7, '0-23months'),
(191, 'Female', 74.5, 7.4, 7.5, 7.6, 7.8, 10.0, 10.2, 10.7, 10.8, '0-23months'),
(192, 'Female', 75.0, 7.5, 7.6, 7.7, 7.9, 10.1, 10.3, 10.8, 10.9, '0-23months'),
(193, 'Female', 75.5, 7.6, 7.7, 7.8, 8.0, 10.2, 10.4, 10.9, 11.0, '0-23months'),
(194, 'Female', 76.0, 7.7, 7.8, 7.9, 8.1, 10.3, 10.5, 11.0, 11.1, '0-23months'),
(195, 'Female', 76.5, 7.8, 7.9, 8.0, 8.2, 10.4, 10.6, 11.1, 11.2, '0-23months'),
(196, 'Female', 77.0, 7.9, 8.0, 8.1, 8.3, 10.5, 10.7, 11.2, 11.3, '0-23months'),
(197, 'Female', 77.5, 8.0, 8.1, 8.2, 8.4, 10.6, 10.8, 11.3, 11.4, '0-23months'),
(198, 'Female', 78.0, 8.1, 8.2, 8.3, 8.5, 10.7, 10.9, 11.4, 11.5, '0-23months'),
(199, 'Female', 78.5, 8.2, 8.3, 8.4, 8.6, 10.8, 11.0, 11.5, 11.6, '0-23months'),
(200, 'Female', 79.0, 8.3, 8.4, 8.5, 8.7, 10.9, 11.1, 11.6, 11.7, '0-23months'),
(201, 'Female', 79.5, 8.4, 8.5, 8.6, 8.8, 11.0, 11.2, 11.7, 11.8, '0-23months'),
(202, 'Female', 80.0, 8.5, 8.6, 8.7, 8.9, 11.1, 11.3, 11.8, 11.9, '0-23months'),
(203, 'Female', 80.5, 8.6, 8.7, 8.8, 9.0, 11.2, 11.4, 11.9, 12.0, '0-23months'),
(204, 'Female', 81.0, 8.7, 8.8, 8.9, 9.1, 11.3, 11.5, 12.0, 12.1, '0-23months'),
(205, 'Female', 81.5, 8.8, 8.9, 9.0, 9.2, 11.4, 11.6, 12.1, 12.2, '0-23months'),
(206, 'Female', 82.0, 8.9, 9.0, 9.1, 9.3, 11.5, 11.7, 12.2, 12.3, '0-23months'),
(207, 'Female', 82.5, 9.0, 9.1, 9.2, 9.4, 11.6, 11.8, 12.3, 12.4, '0-23months'),
(208, 'Female', 83.0, 9.1, 9.2, 9.3, 9.5, 11.7, 11.9, 12.4, 12.5, '0-23months'),
(209, 'Female', 83.5, 9.2, 9.3, 9.4, 9.6, 11.8, 12.0, 12.5, 12.6, '0-23months'),
(210, 'Female', 84.0, 9.3, 9.4, 9.5, 9.7, 11.9, 12.1, 12.6, 12.7, '0-23months'),
(211, 'Female', 84.5, 9.4, 9.5, 9.6, 9.8, 12.0, 12.2, 12.7, 12.8, '0-23months'),
(212, 'Female', 85.0, 9.5, 9.6, 9.7, 9.9, 12.1, 12.3, 12.8, 12.9, '0-23months'),
(213, 'Female', 85.5, 9.6, 9.7, 9.8, 10.0, 12.2, 12.4, 12.9, 13.0, '0-23months'),
(214, 'Female', 86.0, 9.7, 9.8, 9.9, 10.1, 12.3, 12.5, 13.0, 13.1, '0-23months'),
(215, 'Female', 86.5, 9.8, 9.9, 10.0, 10.2, 12.4, 12.6, 13.1, 13.2, '0-23months'),
(216, 'Female', 87.0, 9.9, 10.0, 10.1, 10.3, 12.5, 12.7, 13.2, 13.3, '0-23months'),
(217, 'Female', 87.5, 10.0, 10.1, 10.2, 10.4, 12.6, 12.8, 13.3, 13.4, '0-23months'),
(218, 'Female', 88.0, 10.1, 10.2, 10.3, 10.5, 12.7, 12.9, 13.4, 13.5, '0-23months'),
(219, 'Female', 88.5, 10.2, 10.3, 10.4, 10.6, 12.8, 13.0, 13.5, 13.6, '0-23months'),
(220, 'Female', 89.0, 10.3, 10.4, 10.5, 10.7, 12.9, 13.1, 13.6, 13.7, '0-23months'),
(221, 'Female', 89.5, 10.4, 10.5, 10.6, 10.8, 13.0, 13.2, 13.7, 13.8, '0-23months'),
(222, 'Female', 90.0, 10.5, 10.6, 10.7, 10.9, 13.1, 13.3, 13.8, 13.9, '0-23months'),
(223, 'Female', 90.5, 10.6, 10.7, 10.8, 11.0, 13.2, 13.4, 13.9, 14.0, '0-23months'),
(224, 'Female', 91.0, 10.7, 10.8, 10.9, 11.1, 13.3, 13.5, 14.0, 14.1, '0-23months'),
(225, 'Female', 91.5, 10.8, 10.9, 11.0, 11.2, 13.4, 13.6, 14.1, 14.2, '0-23months'),
(226, 'Female', 92.0, 10.9, 11.0, 11.1, 11.3, 13.5, 13.7, 14.2, 14.3, '0-23months'),
(227, 'Female', 92.5, 11.0, 11.1, 11.2, 11.4, 13.6, 13.8, 14.3, 14.4, '0-23months'),
(228, 'Female', 93.0, 11.1, 11.2, 11.3, 11.5, 13.7, 13.9, 14.4, 14.5, '0-23months'),
(229, 'Female', 93.5, 11.2, 11.3, 11.4, 11.6, 13.8, 14.0, 14.5, 14.6, '0-23months'),
(230, 'Female', 94.0, 11.3, 11.4, 11.5, 11.7, 13.9, 14.1, 14.6, 14.7, '0-23months'),
(231, 'Female', 94.5, 11.4, 11.5, 11.6, 11.8, 14.0, 14.2, 14.7, 14.8, '0-23months'),
(232, 'Female', 95.0, 11.5, 11.6, 11.7, 11.9, 14.1, 14.3, 14.8, 14.9, '0-23months'),
(233, 'Female', 95.5, 11.6, 11.7, 11.8, 12.0, 14.2, 14.4, 14.9, 15.0, '0-23months'),
(234, 'Female', 96.0, 11.7, 11.8, 11.9, 12.1, 14.3, 14.5, 15.0, 15.1, '0-23months'),
(235, 'Female', 96.5, 11.8, 11.9, 12.0, 12.2, 14.4, 14.6, 15.1, 15.2, '0-23months'),
(236, 'Female', 97.0, 11.9, 12.0, 12.1, 12.3, 14.5, 14.7, 15.2, 15.3, '0-23months'),
(237, 'Female', 97.5, 12.0, 12.1, 12.2, 12.4, 14.6, 14.8, 15.3, 15.4, '0-23months'),
(238, 'Female', 98.0, 12.1, 12.2, 12.3, 12.5, 14.7, 14.9, 15.4, 15.5, '0-23months'),
(239, 'Female', 98.5, 12.2, 12.3, 12.4, 12.6, 14.8, 15.0, 15.5, 15.6, '0-23months'),
(240, 'Female', 99.0, 12.3, 12.4, 12.5, 12.7, 14.9, 15.1, 15.6, 15.7, '0-23months'),
(241, 'Female', 99.5, 12.4, 12.5, 12.6, 12.8, 15.0, 15.2, 15.7, 15.8, '0-23months'),
(242, 'Female', 100.0, 12.5, 12.6, 12.7, 12.9, 15.1, 15.3, 15.8, 15.9, '0-23months'),
(243, 'Female', 100.5, 12.6, 12.7, 12.8, 13.0, 15.2, 15.4, 15.9, 16.0, '0-23months'),
(244, 'Female', 101.0, 12.7, 12.8, 12.9, 13.1, 15.3, 15.5, 16.0, 16.1, '0-23months'),
(245, 'Female', 101.5, 12.8, 12.9, 13.0, 13.2, 15.4, 15.6, 16.1, 16.2, '0-23months'),
(246, 'Female', 102.0, 12.9, 13.0, 13.1, 13.3, 15.5, 15.7, 16.2, 16.3, '0-23months'),
(247, 'Female', 102.5, 13.0, 13.1, 13.2, 13.4, 15.6, 15.8, 16.3, 16.4, '0-23months'),
(248, 'Female', 103.0, 13.1, 13.2, 13.3, 13.5, 15.7, 15.9, 16.4, 16.5, '0-23months'),
(249, 'Female', 103.5, 13.2, 13.3, 13.4, 13.6, 15.8, 16.0, 16.5, 16.6, '0-23months'),
(250, 'Female', 104.0, 13.3, 13.4, 13.5, 13.7, 15.9, 16.1, 16.6, 16.7, '0-23months'),
(251, 'Female', 104.5, 13.4, 13.5, 13.6, 13.8, 16.0, 16.2, 16.7, 16.8, '0-23months'),
(252, 'Female', 105.0, 13.5, 13.6, 13.7, 13.9, 16.1, 16.3, 16.8, 16.9, '0-23months'),
(253, 'Female', 105.5, 13.6, 13.7, 13.8, 14.0, 16.2, 16.4, 16.9, 17.0, '0-23months'),
(254, 'Female', 106.0, 13.7, 13.8, 13.9, 14.1, 16.3, 16.5, 17.0, 17.1, '0-23months'),
(255, 'Female', 106.5, 13.8, 13.9, 14.0, 14.2, 16.4, 16.6, 17.1, 17.2, '0-23months'),
(256, 'Female', 107.0, 13.9, 14.0, 14.1, 14.3, 16.5, 16.7, 17.2, 17.3, '0-23months'),
(257, 'Female', 107.5, 14.0, 14.1, 14.2, 14.4, 16.6, 16.8, 17.3, 17.4, '0-23months'),
(258, 'Female', 108.0, 14.1, 14.2, 14.3, 14.5, 16.7, 16.9, 17.4, 17.5, '0-23months'),
(259, 'Female', 108.5, 14.2, 14.3, 14.4, 14.6, 16.8, 17.0, 17.5, 17.6, '0-23months'),
(260, 'Female', 109.0, 14.3, 14.4, 14.5, 14.7, 16.9, 17.1, 17.6, 17.7, '0-23months'),
(261, 'Female', 109.5, 14.4, 14.5, 14.6, 14.8, 17.0, 17.2, 17.7, 17.8, '0-23months'),
(262, 'Female', 110.0, 14.5, 14.6, 14.7, 14.9, 17.1, 17.3, 17.8, 17.9, '0-23months'),
(263, 'Female', 65.0, 5.5, 5.6, 6.0, 6.1, 8.7, 8.8, 9.7, 9.8, '24-60months'),
(264, 'Female', 65.5, 5.6, 5.7, 6.1, 6.2, 8.9, 9.0, 9.8, 9.9, '24-60months'),
(265, 'Female', 66.0, 5.7, 5.8, 6.2, 6.3, 9.0, 9.1, 10.0, 10.1, '24-60months'),
(266, 'Female', 66.5, 5.7, 5.8, 6.3, 6.4, 9.2, 9.2, 10.1, 10.2, '24-60months'),
(267, 'Female', 67.0, 5.8, 5.9, 6.3, 6.4, 9.3, 9.4, 10.2, 10.3, '24-60months'),
(268, 'Female', 67.5, 5.9, 6.0, 6.4, 6.5, 9.4, 9.5, 10.4, 10.5, '24-60months'),
(269, 'Female', 68.0, 6.0, 6.1, 6.5, 6.6, 9.5, 9.6, 10.5, 10.6, '24-60months'),
(270, 'Female', 68.5, 6.1, 6.2, 6.6, 6.7, 9.7, 9.8, 10.7, 10.8, '24-60months'),
(271, 'Female', 69.0, 6.2, 6.3, 6.7, 6.8, 9.8, 9.9, 10.8, 10.9, '24-60months'),
(272, 'Female', 69.5, 6.2, 6.3, 6.8, 6.9, 9.9, 10.0, 10.9, 11.0, '24-60months'),
(273, 'Female', 70.0, 6.3, 6.4, 6.9, 7.0, 10.0, 10.1, 11.1, 11.2, '24-60months'),
(274, 'Female', 70.5, 6.4, 6.5, 7.0, 7.1, 10.1, 10.2, 11.2, 11.3, '24-60months'),
(275, 'Female', 71.0, 6.5, 6.6, 7.0, 7.1, 10.3, 10.4, 11.3, 11.4, '24-60months'),
(276, 'Female', 71.5, 6.6, 6.7, 7.1, 7.2, 10.4, 10.5, 11.5, 11.6, '24-60months'),
(277, 'Female', 72.0, 6.6, 6.7, 7.2, 7.3, 10.5, 10.6, 11.6, 11.7, '24-60months'),
(278, 'Female', 72.5, 6.7, 6.8, 7.3, 7.4, 10.6, 10.7, 11.7, 11.8, '24-60months'),
(279, 'Female', 73.0, 6.8, 6.9, 7.4, 7.5, 10.7, 10.8, 11.8, 11.9, '24-60months'),
(280, 'Female', 73.5, 6.9, 7.0, 7.5, 7.6, 10.8, 10.9, 12.0, 12.1, '24-60months'),
(281, 'Female', 74.0, 6.9, 7.0, 7.5, 7.6, 11.0, 11.1, 12.1, 12.2, '24-60months'),
(282, 'Female', 74.5, 7.0, 7.1, 7.6, 7.7, 11.1, 11.2, 12.2, 12.3, '24-60months'),
(283, 'Female', 75.0, 7.1, 7.2, 7.7, 7.8, 11.2, 11.3, 12.3, 12.4, '24-60months'),
(284, 'Female', 75.5, 7.1, 7.2, 7.8, 7.9, 11.3, 11.4, 12.5, 12.6, '24-60months'),
(285, 'Female', 76.0, 7.2, 7.3, 7.9, 8.0, 11.4, 11.5, 12.6, 12.7, '24-60months'),
(286, 'Female', 76.5, 7.3, 7.4, 7.9, 8.0, 11.5, 11.6, 12.7, 12.8, '24-60months'),
(287, 'Female', 77.0, 7.4, 7.5, 8.0, 8.1, 11.6, 11.7, 12.8, 12.9, '24-60months'),
(288, 'Female', 77.5, 7.4, 7.5, 8.1, 8.2, 11.7, 11.8, 12.9, 13.0, '24-60months'),
(289, 'Female', 78.0, 7.5, 7.6, 8.2, 8.3, 11.8, 11.9, 13.1, 13.2, '24-60months'),
(290, 'Female', 78.5, 7.6, 7.7, 8.3, 8.4, 12.0, 12.1, 13.2, 13.3, '24-60months'),
(291, 'Female', 79.0, 7.7, 7.8, 8.3, 8.4, 12.1, 12.2, 13.3, 13.4, '24-60months'),
(292, 'Female', 79.5, 7.7, 7.8, 8.4, 8.5, 12.2, 12.3, 13.4, 13.5, '24-60months'),
(293, 'Female', 80.0, 7.8, 7.9, 8.5, 8.6, 12.3, 12.4, 13.6, 13.8, '24-60months'),
(294, 'Female', 80.5, 7.9, 8.0, 8.6, 8.7, 12.4, 12.5, 13.7, 13.8, '24-60months'),
(295, 'Female', 81.0, 8.0, 8.1, 8.7, 8.8, 12.6, 12.7, 13.9, 14.0, '24-60months'),
(296, 'Female', 81.5, 8.1, 8.2, 8.8, 8.9, 12.7, 12.8, 14.0, 14.1, '24-60months'),
(297, 'Female', 82.0, 8.2, 8.3, 8.9, 9.0, 12.8, 12.9, 14.1, 14.2, '24-60months'),
(298, 'Female', 82.5, 8.3, 8.4, 9.0, 9.1, 13.0, 13.1, 14.3, 14.4, '24-60months'),
(299, 'Female', 83.0, 8.4, 8.5, 9.1, 9.2, 13.1, 13.2, 14.5, 14.6, '24-60months'),
(300, 'Female', 83.5, 8.4, 8.5, 9.2, 9.3, 13.3, 13.4, 14.6, 14.8, '24-60months'),
(301, 'Female', 84.0, 8.5, 8.6, 9.3, 9.4, 13.4, 13.5, 14.8, 14.9, '24-60months'),
(302, 'Female', 84.5, 8.6, 8.7, 9.4, 9.5, 13.5, 13.6, 14.9, 15.0, '24-60months'),
(303, 'Female', 85.0, 8.7, 8.8, 9.5, 9.6, 13.7, 13.8, 15.1, 15.2, '24-60months'),
(304, 'Female', 85.5, 8.8, 8.9, 9.6, 9.7, 13.8, 13.9, 15.3, 15.4, '24-60months'),
(305, 'Female', 86.0, 8.9, 9.0, 9.7, 9.8, 14.0, 14.1, 15.4, 15.5, '24-60months'),
(306, 'Female', 86.5, 9.0, 9.1, 9.8, 9.9, 14.2, 14.3, 15.6, 15.7, '24-60months'),
(307, 'Female', 87.0, 9.1, 9.2, 9.9, 10.0, 14.3, 14.4, 15.8, 15.9, '24-60months'),
(308, 'Female', 87.5, 9.2, 9.3, 10.0, 10.1, 14.5, 14.6, 15.9, 16.0, '24-60months'),
(309, 'Female', 88.0, 9.3, 9.4, 10.1, 10.2, 14.6, 14.7, 16.1, 16.2, '24-60months'),
(310, 'Female', 88.5, 9.4, 9.5, 10.2, 10.3, 14.8, 14.9, 16.3, 16.4, '24-60months'),
(311, 'Female', 89.0, 9.5, 9.6, 10.3, 10.4, 14.9, 15.0, 16.4, 16.5, '24-60months'),
(312, 'Female', 89.5, 9.6, 9.7, 10.4, 10.5, 15.1, 15.2, 16.6, 16.7, '24-60months'),
(313, 'Female', 90.0, 9.7, 9.8, 10.5, 10.6, 15.2, 15.3, 16.8, 16.9, '24-60months'),
(314, 'Female', 90.5, 9.8, 9.9, 10.6, 10.7, 15.4, 15.5, 16.9, 17.0, '24-60months'),
(315, 'Female', 91.0, 9.9, 10.0, 10.8, 10.9, 15.5, 15.6, 17.1, 17.2, '24-60months'),
(316, 'Female', 91.5, 10.0, 10.1, 10.9, 11.0, 15.7, 15.8, 17.3, 17.4, '24-60months'),
(317, 'Female', 92.0, 10.1, 10.2, 11.0, 11.1, 15.8, 15.9, 17.4, 17.5, '24-60months'),
(318, 'Female', 92.5, 10.2, 10.3, 11.1, 11.2, 16.0, 16.1, 17.6, 17.7, '24-60months'),
(319, 'Female', 93.0, 10.3, 10.4, 11.2, 11.3, 16.1, 16.2, 17.8, 17.9, '24-60months'),
(320, 'Female', 93.5, 10.4, 10.5, 11.3, 11.4, 16.3, 16.4, 17.9, 18.0, '24-60months'),
(321, 'Female', 94.0, 10.5, 10.6, 11.4, 11.5, 16.4, 16.5, 18.1, 18.2, '24-60months'),
(322, 'Female', 94.5, 10.5, 10.6, 11.5, 11.6, 16.6, 16.7, 18.3, 18.4, '24-60months'),
(323, 'Female', 95.0, 10.7, 10.8, 11.6, 11.7, 16.7, 16.8, 18.5, 18.6, '24-60months'),
(324, 'Female', 95.5, 10.7, 10.8, 11.7, 11.8, 16.9, 17.0, 18.6, 18.7, '24-60months'),
(325, 'Female', 96.0, 10.8, 10.9, 11.8, 11.9, 17.0, 17.1, 18.8, 18.9, '24-60months'),
(326, 'Female', 96.5, 10.9, 11.0, 11.9, 12.0, 17.2, 17.3, 19.0, 19.1, '24-60months'),
(327, 'Female', 97.0, 11.0, 11.1, 12.0, 12.1, 17.4, 17.5, 19.2, 19.3, '24-60months'),
(328, 'Female', 97.5, 11.1, 11.2, 12.1, 12.2, 17.5, 17.6, 19.3, 19.4, '24-60months'),
(329, 'Female', 98.0, 11.2, 11.3, 12.2, 12.3, 17.7, 17.8, 19.5, 19.6, '24-60months'),
(330, 'Female', 98.5, 11.3, 11.4, 12.3, 12.4, 17.9, 18.0, 19.7, 19.8, '24-60months'),
(331, 'Female', 99.0, 11.4, 11.5, 12.4, 12.5, 18.0, 18.1, 19.9, 20.0, '24-60months'),
(332, 'Female', 99.5, 11.5, 11.6, 12.6, 12.7, 18.2, 18.3, 20.1, 20.2, '24-60months'),
(333, 'Female', 100.0, 11.6, 11.7, 12.7, 12.8, 18.4, 18.5, 20.3, 20.4, '24-60months'),
(334, 'Female', 100.5, 11.8, 11.9, 12.8, 12.9, 18.6, 18.7, 20.5, 20.6, '24-60months'),
(335, 'Female', 101.0, 11.9, 12.0, 12.9, 13.0, 18.7, 18.8, 20.7, 20.8, '24-60months'),
(336, 'Female', 101.5, 12.0, 12.1, 13.0, 13.1, 18.9, 19.0, 20.9, 21.0, '24-60months'),
(337, 'Female', 102.0, 12.1, 12.2, 13.2, 13.3, 19.1, 19.2, 21.1, 21.2, '24-60months'),
(338, 'Female', 102.5, 12.2, 12.3, 13.3, 13.4, 19.3, 19.4, 21.4, 21.5, '24-60months'),
(339, 'Female', 103.0, 12.3, 12.4, 13.4, 13.5, 19.5, 19.6, 21.6, 21.7, '24-60months'),
(340, 'Female', 103.5, 12.4, 12.5, 13.5, 13.6, 19.7, 19.8, 21.8, 21.9, '24-60months'),
(341, 'Female', 104.0, 12.5, 12.6, 13.7, 13.8, 19.9, 20.0, 22.0, 22.1, '24-60months'),
(342, 'Female', 104.5, 12.7, 12.8, 13.8, 13.9, 20.1, 20.2, 22.3, 22.4, '24-60months'),
(343, 'Female', 105.0, 12.8, 12.9, 13.9, 14.0, 20.3, 20.4, 22.5, 22.6, '24-60months'),
(344, 'Female', 105.5, 12.9, 13.0, 14.1, 14.2, 20.5, 20.6, 22.7, 22.8, '24-60months'),
(345, 'Female', 106.0, 13.0, 13.1, 14.2, 14.3, 20.8, 20.9, 23.0, 23.1, '24-60months'),
(346, 'Female', 106.5, 13.2, 13.3, 14.4, 14.5, 21.0, 21.1, 23.2, 23.3, '24-60months'),
(347, 'Female', 107.0, 13.3, 13.4, 14.5, 14.6, 21.2, 21.3, 23.5, 23.6, '24-60months'),
(348, 'Female', 107.5, 13.4, 13.5, 14.6, 14.7, 21.4, 21.5, 23.7, 23.8, '24-60months'),
(349, 'Female', 108.0, 13.6, 13.7, 14.8, 14.9, 21.7, 21.8, 24.0, 24.1, '24-60months'),
(350, 'Female', 108.5, 13.7, 13.8, 14.9, 15.0, 21.9, 22.0, 24.3, 24.4, '24-60months'),
(351, 'Female', 109.0, 13.8, 13.9, 15.1, 15.2, 22.1, 22.2, 24.5, 24.6, '24-60months'),
(352, 'Female', 109.5, 14.0, 14.1, 15.3, 15.4, 22.4, 22.5, 24.8, 24.9, '24-60months'),
(353, 'Female', 110.0, 14.1, 14.2, 15.4, 15.5, 22.6, 22.7, 25.1, 25.2, '24-60months'),
(354, 'Female', 110.5, 14.3, 14.4, 15.6, 15.7, 22.9, 23.0, 25.4, 25.5, '24-60months'),
(355, 'Female', 111.0, 14.4, 14.5, 15.7, 15.8, 23.1, 23.2, 25.7, 25.8, '24-60months'),
(356, 'Female', 111.5, 14.6, 14.7, 15.9, 16.0, 23.4, 23.5, 26.0, 26.1, '24-60months'),
(357, 'Female', 112.0, 14.7, 14.8, 16.1, 16.2, 23.6, 23.7, 26.2, 26.3, '24-60months'),
(358, 'Female', 112.5, 14.9, 15.0, 16.2, 16.3, 23.9, 24.0, 26.5, 26.6, '24-60months'),
(359, 'Female', 113.0, 15.0, 15.1, 16.4, 16.5, 24.2, 24.3, 26.8, 26.9, '24-60months'),
(360, 'Female', 113.5, 15.2, 15.3, 16.6, 16.7, 24.4, 24.5, 27.1, 27.2, '24-60months'),
(361, 'Female', 114.0, 15.3, 15.4, 16.7, 16.8, 24.7, 24.8, 27.4, 27.5, '24-60months'),
(362, 'Female', 114.5, 15.5, 15.6, 16.9, 17.0, 25.0, 25.1, 27.8, 27.9, '24-60months'),
(363, 'Female', 115.0, 15.6, 15.7, 17.1, 17.2, 25.2, 25.3, 28.1, 28.2, '24-60months'),
(364, 'Female', 115.5, 15.8, 15.9, 17.2, 17.3, 25.5, 25.6, 28.4, 28.5, '24-60months'),
(365, 'Female', 116.0, 15.9, 16.0, 17.4, 17.5, 25.8, 25.9, 28.7, 28.8, '24-60months'),
(366, 'Female', 116.5, 16.1, 16.2, 17.6, 17.7, 26.1, 26.2, 29.0, 29.1, '24-60months'),
(367, 'Female', 117.0, 16.2, 16.3, 17.7, 17.8, 26.3, 26.4, 29.3, 29.4, '24-60months'),
(368, 'Female', 117.5, 16.4, 16.5, 17.9, 18.0, 26.6, 26.7, 29.6, 29.7, '24-60months'),
(369, 'Female', 118.0, 16.5, 16.6, 18.1, 18.2, 26.9, 27.0, 29.9, 30.0, '24-60months'),
(370, 'Female', 118.5, 16.7, 16.8, 18.3, 18.4, 27.2, 27.3, 30.3, 30.4, '24-60months'),
(371, 'Female', 119.0, 16.8, 16.9, 18.4, 18.5, 27.4, 27.5, 30.6, 30.7, '24-60months'),
(372, 'Female', 119.5, 17.0, 17.1, 18.6, 18.7, 27.7, 27.8, 30.9, 31.0, '24-60months'),
(373, 'Female', 120.0, 17.2, 17.3, 18.8, 18.9, 28.0, 28.1, 31.2, 31.3, '24-60months'),
(374, 'Male', 65.0, 5.8, 5.9, 6.2, 6.3, 8.8, 8.9, 9.6, 9.7, '24-60months'),
(375, 'Male', 65.5, 5.9, 6.0, 6.3, 6.4, 8.9, 9.0, 9.8, 9.9, '24-60months'),
(376, 'Male', 66.0, 6.0, 6.1, 6.4, 6.5, 9.1, 9.2, 10.0, 10.0, '24-60months'),
(377, 'Male', 66.5, 6.0, 6.1, 6.5, 6.6, 9.2, 9.3, 10.1, 10.2, '24-60months'),
(378, 'Male', 67.0, 6.1, 6.2, 6.6, 6.7, 9.4, 9.5, 10.2, 10.3, '24-60months'),
(379, 'Male', 67.5, 6.2, 6.3, 6.7, 6.8, 9.5, 9.6, 10.4, 10.5, '24-60months'),
(380, 'Male', 68.0, 6.3, 6.4, 6.8, 6.9, 9.6, 9.7, 10.5, 10.6, '24-60months'),
(381, 'Male', 68.5, 6.4, 6.5, 6.9, 7.0, 9.8, 9.9, 10.7, 10.8, '24-60months'),
(382, 'Male', 69.0, 6.5, 6.6, 7.0, 7.1, 9.9, 10.0, 10.8, 10.9, '24-60months'),
(383, 'Male', 69.5, 6.6, 6.7, 7.1, 7.2, 10.0, 10.1, 11.0, 11.1, '24-60months'),
(384, 'Male', 70.0, 6.7, 6.8, 7.2, 7.3, 10.2, 10.3, 11.1, 11.2, '24-60months'),
(385, 'Male', 70.5, 6.8, 6.9, 7.3, 7.4, 10.4, 10.4, 11.3, 11.4, '24-60months'),
(386, 'Male', 71.0, 6.8, 6.9, 7.4, 7.5, 10.4, 10.5, 11.4, 11.5, '24-60months'),
(387, 'Male', 71.5, 6.9, 7.0, 7.5, 7.6, 10.6, 10.7, 11.6, 11.7, '24-60months'),
(388, 'Male', 72.0, 7.0, 7.1, 7.6, 7.7, 10.7, 10.8, 11.7, 11.8, '24-60months'),
(389, 'Male', 72.5, 7.1, 7.2, 7.7, 7.8, 10.8, 10.9, 11.8, 11.9, '24-60months'),
(390, 'Male', 73.0, 7.2, 7.3, 7.8, 7.9, 11.0, 11.1, 12.0, 12.1, '24-60months'),
(391, 'Male', 73.5, 7.2, 7.3, 7.8, 7.9, 11.1, 11.2, 12.1, 12.1, '24-60months'),
(392, 'Male', 74.0, 7.3, 7.4, 7.9, 8.0, 11.2, 11.2, 12.2, 12.3, '24-60months'),
(393, 'Male', 74.5, 7.4, 7.5, 8.0, 8.1, 11.3, 11.4, 12.4, 12.5, '24-60months'),
(394, 'Male', 75.0, 7.5, 7.6, 8.1, 8.2, 11.4, 11.5, 12.5, 12.6, '24-60months'),
(395, 'Male', 75.5, 7.6, 7.7, 8.2, 8.3, 11.6, 11.7, 12.6, 12.7, '24-60months'),
(396, 'Male', 76.0, 7.6, 7.7, 8.3, 8.4, 11.7, 11.8, 12.8, 12.9, '24-60months'),
(397, 'Male', 76.5, 7.7, 7.8, 8.4, 8.5, 11.8, 11.9, 12.9, 13.0, '24-60months'),
(398, 'Male', 77.0, 7.8, 7.9, 8.4, 8.5, 11.9, 12.0, 13.0, 13.1, '24-60months'),
(399, 'Male', 77.5, 7.9, 8.0, 8.5, 8.6, 12.0, 12.1, 13.1, 13.2, '24-60months'),
(400, 'Male', 78.0, 7.9, 8.0, 8.6, 8.7, 12.1, 12.2, 13.3, 13.4, '24-60months'),
(401, 'Male', 78.5, 8.0, 8.1, 8.7, 8.8, 12.2, 12.3, 13.3, 13.5, '24-60months'),
(402, 'Male', 79.0, 8.1, 8.2, 8.7, 8.8, 12.3, 12.4, 13.5, 13.6, '24-60months'),
(403, 'Male', 79.5, 8.2, 8.3, 8.8, 8.9, 12.4, 12.5, 13.6, 13.7, '24-60months'),
(404, 'Male', 80.0, 8.2, 8.3, 8.9, 9.0, 12.6, 12.7, 13.7, 13.8, '24-60months'),
(405, 'Male', 80.5, 8.3, 8.4, 9.0, 9.1, 12.7, 12.8, 13.8, 13.9, '24-60months'),
(406, 'Male', 81.0, 8.4, 8.5, 9.1, 9.2, 12.8, 12.9, 14.0, 14.1, '24-60months'),
(407, 'Male', 81.5, 8.5, 8.6, 9.2, 9.3, 12.9, 13.0, 14.1, 14.2, '24-60months'),
(408, 'Male', 82.0, 8.6, 8.7, 9.2, 9.3, 13.0, 13.1, 14.2, 14.3, '24-60months'),
(409, 'Male', 82.5, 8.6, 8.7, 9.3, 9.4, 13.1, 13.2, 14.4, 14.5, '24-60months'),
(410, 'Male', 83.0, 8.7, 8.8, 9.4, 9.5, 13.3, 13.4, 14.5, 14.6, '24-60months'),
(411, 'Male', 83.5, 8.8, 8.9, 9.5, 9.6, 13.4, 13.5, 14.6, 14.7, '24-60months'),
(412, 'Male', 84.0, 8.9, 9.0, 9.6, 9.7, 13.5, 13.6, 14.8, 14.9, '24-60months'),
(413, 'Male', 84.5, 9.0, 9.1, 9.8, 9.9, 13.7, 13.8, 14.9, 15.0, '24-60months'),
(414, 'Male', 85.0, 9.1, 9.2, 9.9, 10.0, 13.8, 13.9, 15.1, 15.2, '24-60months'),
(415, 'Male', 85.5, 9.2, 9.3, 10.0, 10.1, 13.9, 14.0, 15.1, 15.3, '24-60months'),
(416, 'Male', 86.0, 9.3, 9.4, 10.1, 10.2, 14.1, 14.2, 15.4, 15.5, '24-60months'),
(417, 'Male', 86.5, 9.4, 9.5, 10.2, 10.3, 14.2, 14.3, 15.5, 15.6, '24-60months'),
(418, 'Male', 87.0, 9.5, 9.6, 10.3, 10.4, 14.4, 14.5, 15.7, 15.8, '24-60months'),
(419, 'Male', 87.5, 9.6, 9.7, 10.4, 10.5, 14.5, 14.6, 15.8, 15.9, '24-60months'),
(420, 'Male', 88.0, 9.7, 9.8, 10.5, 10.6, 14.7, 14.8, 16.0, 16.1, '24-60months'),
(421, 'Male', 88.5, 9.8, 9.9, 10.6, 10.7, 14.8, 14.9, 16.1, 16.2, '24-60months'),
(422, 'Male', 89.0, 9.9, 10.0, 10.7, 10.8, 14.9, 15.0, 16.3, 16.4, '24-60months'),
(423, 'Male', 89.5, 10.0, 10.1, 10.8, 10.9, 15.1, 15.2, 16.4, 16.5, '24-60months'),
(424, 'Male', 90.0, 10.1, 10.2, 10.9, 11.0, 15.2, 15.3, 16.6, 16.7, '24-60months'),
(425, 'Male', 90.5, 10.3, 10.4, 11.1, 11.2, 15.3, 15.4, 16.7, 16.8, '24-60months'),
(426, 'Male', 91.0, 10.3, 10.4, 11.1, 11.2, 15.5, 15.6, 16.9, 17.0, '24-60months'),
(427, 'Male', 91.5, 10.4, 10.5, 11.2, 11.3, 15.6, 15.7, 17.0, 17.1, '24-60months'),
(428, 'Male', 92.0, 10.5, 10.6, 11.3, 11.4, 15.8, 15.9, 17.2, 17.3, '24-60months'),
(429, 'Male', 92.5, 10.6, 10.7, 11.4, 11.5, 15.9, 16.0, 17.3, 17.4, '24-60months'),
(430, 'Male', 93.0, 10.7, 10.8, 11.5, 11.6, 16.0, 16.1, 17.5, 17.6, '24-60months'),
(431, 'Male', 93.5, 10.8, 10.9, 11.6, 11.7, 16.2, 16.3, 17.6, 17.7, '24-60months'),
(432, 'Male', 94.0, 10.9, 11.0, 11.7, 11.8, 16.3, 16.4, 17.8, 17.9, '24-60months'),
(433, 'Male', 94.5, 11.0, 11.1, 11.8, 11.9, 16.5, 16.6, 17.9, 18.0, '24-60months'),
(434, 'Male', 95.0, 11.1, 11.2, 11.9, 12.0, 16.6, 16.7, 18.1, 18.2, '24-60months'),
(435, 'Male', 95.5, 11.1, 11.2, 12.0, 12.1, 16.7, 16.8, 18.3, 18.4, '24-60months'),
(436, 'Male', 96.0, 11.2, 11.3, 12.1, 12.2, 16.9, 17.0, 18.4, 18.5, '24-60months'),
(437, 'Male', 96.5, 11.3, 11.4, 12.2, 12.3, 17.0, 17.1, 18.6, 18.7, '24-60months'),
(438, 'Male', 97.0, 11.4, 11.5, 12.3, 12.4, 17.2, 17.3, 18.8, 18.9, '24-60months'),
(439, 'Male', 97.5, 11.5, 11.6, 12.4, 12.5, 17.4, 17.5, 18.9, 19.0, '24-60months'),
(440, 'Male', 98.0, 11.6, 11.7, 12.5, 12.6, 17.5, 17.6, 19.1, 19.2, '24-60months'),
(441, 'Male', 98.5, 11.7, 11.8, 12.7, 12.8, 17.7, 17.8, 19.3, 19.4, '24-60months'),
(442, 'Male', 99.0, 11.8, 11.9, 12.8, 12.9, 17.9, 18.0, 19.5, 19.6, '24-60months'),
(443, 'Male', 99.5, 11.9, 12.0, 12.9, 13.0, 18.0, 18.1, 19.7, 19.8, '24-60months'),
(444, 'Male', 100.0, 12.0, 12.1, 13.0, 13.1, 18.2, 18.3, 19.9, 20.0, '24-60months'),
(445, 'Male', 100.5, 12.1, 12.2, 13.1, 13.2, 13.2, 18.4, 20.1, 20.2, '24-60months'),
(446, 'Male', 101.0, 12.2, 12.3, 13.2, 13.3, 18.5, 18.6, 20.3, 20.4, '24-60months'),
(447, 'Male', 101.5, 12.3, 12.4, 13.3, 13.4, 18.7, 18.8, 20.5, 20.6, '24-60months'),
(448, 'Male', 102.0, 12.4, 12.5, 13.5, 13.6, 18.9, 19.0, 20.7, 20.8, '24-60months'),
(449, 'Male', 102.5, 12.5, 12.6, 13.6, 13.7, 19.1, 19.2, 20.9, 21.0, '24-60months'),
(450, 'Male', 103.0, 12.7, 12.8, 13.7, 13.8, 19.3, 19.4, 21.1, 21.2, '24-60months'),
(451, 'Male', 103.5, 12.8, 12.9, 13.9, 14.0, 19.5, 19.6, 21.3, 21.4, '24-60months'),
(452, 'Male', 104.0, 12.9, 13.0, 13.9, 14.0, 19.7, 19.8, 21.6, 21.7, '24-60months'),
(453, 'Male', 104.5, 13.0, 13.1, 14.1, 14.2, 19.9, 20.0, 21.8, 21.9, '24-60months'),
(454, 'Male', 105.0, 13.1, 13.2, 14.2, 14.3, 20.1, 20.2, 22.0, 22.1, '24-60months'),
(455, 'Male', 105.5, 13.2, 13.3, 14.3, 14.4, 20.3, 20.4, 22.2, 22.3, '24-60months'),
(456, 'Male', 106.0, 13.3, 13.4, 14.4, 14.5, 20.5, 20.6, 22.5, 22.6, '24-60months'),
(457, 'Male', 106.5, 13.4, 13.5, 14.6, 14.7, 20.7, 20.8, 22.7, 22.8, '24-60months'),
(458, 'Male', 107.0, 13.6, 13.7, 14.7, 14.8, 20.9, 21.0, 22.9, 23.0, '24-60months'),
(459, 'Male', 107.5, 13.7, 13.8, 14.8, 14.9, 21.1, 21.2, 23.2, 23.3, '24-60months'),
(460, 'Male', 108.0, 13.8, 13.9, 15.0, 15.1, 21.3, 21.4, 23.4, 23.5, '24-60months'),
(461, 'Male', 108.5, 13.9, 14.0, 15.1, 15.2, 21.5, 21.6, 23.7, 23.8, '24-60months'),
(462, 'Male', 109.0, 14.0, 14.1, 15.3, 15.4, 21.8, 21.9, 23.9, 24.0, '24-60months'),
(463, 'Male', 109.5, 14.2, 14.3, 15.4, 15.5, 22.0, 22.1, 24.2, 24.3, '24-60months'),
(464, 'Male', 110.0, 14.3, 14.4, 15.5, 15.6, 22.2, 22.3, 24.4, 24.5, '24-60months'),
(465, 'Male', 110.5, 14.4, 14.5, 15.7, 15.8, 22.4, 22.5, 24.7, 24.8, '24-60months'),
(466, 'Male', 111.0, 14.5, 14.6, 15.8, 15.9, 22.7, 22.8, 25.0, 25.1, '24-60months'),
(467, 'Male', 111.5, 14.7, 14.8, 15.9, 16.0, 22.9, 23.0, 25.2, 25.3, '24-60months'),
(468, 'Male', 112.0, 14.8, 14.9, 16.1, 16.2, 23.1, 23.2, 25.5, 25.6, '24-60months'),
(469, 'Male', 112.5, 14.9, 15.0, 16.2, 16.3, 23.4, 23.5, 25.8, 25.9, '24-60months'),
(470, 'Male', 113.0, 15.1, 15.2, 16.4, 16.5, 23.6, 23.7, 26.0, 26.1, '24-60months'),
(471, 'Male', 113.5, 15.2, 15.3, 16.5, 16.6, 23.9, 24.0, 26.3, 26.4, '24-60months'),
(472, 'Male', 114.0, 15.3, 15.4, 16.7, 16.8, 24.1, 24.2, 26.6, 26.7, '24-60months'),
(473, 'Male', 114.5, 15.5, 15.6, 16.8, 16.9, 24.4, 24.5, 26.9, 27.0, '24-60months'),
(474, 'Male', 115.0, 15.6, 15.7, 17.0, 17.1, 24.6, 24.7, 27.2, 27.3, '24-60months'),
(475, 'Male', 115.5, 15.7, 15.8, 17.1, 17.2, 24.9, 25.0, 27.5, 27.6, '24-60months'),
(476, 'Male', 116.0, 15.9, 16.0, 17.3, 17.4, 25.1, 25.2, 27.8, 27.9, '24-60months'),
(477, 'Male', 116.5, 16.0, 16.1, 17.4, 17.5, 25.4, 25.5, 28.0, 28.1, '24-60months'),
(478, 'Male', 117.0, 16.1, 16.2, 17.6, 17.7, 25.6, 25.7, 28.3, 28.4, '24-60months'),
(479, 'Male', 117.5, 16.3, 16.4, 17.7, 17.8, 25.9, 26.0, 28.6, 28.7, '24-60months'),
(480, 'Male', 118.0, 16.4, 16.5, 17.9, 18.0, 26.1, 26.2, 28.9, 29.0, '24-60months'),
(481, 'Male', 118.5, 16.5, 16.6, 18.0, 18.1, 26.4, 26.5, 29.2, 29.3, '24-60months'),
(482, 'Male', 119.0, 16.7, 16.8, 18.2, 18.3, 26.6, 26.7, 29.5, 29.6, '24-60months'),
(483, 'Male', 119.5, 16.8, 16.9, 18.4, 18.5, 26.9, 27.0, 29.8, 29.9, '24-60months'),
(484, 'Male', 120.0, 17.0, 17.1, 18.5, 18.6, 27.2, 27.3, 30.1, 30.2, '24-60months');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `barangays`
--
ALTER TABLE `barangays`
  ADD PRIMARY KEY (`barangay_id`);

--
-- Indexes for table `category_inventory`
--
ALTER TABLE `category_inventory`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `children`
--
ALTER TABLE `children`
  ADD PRIMARY KEY (`child_id`),
  ADD KEY `guardian_id` (`guardian_id`),
  ADD KEY `idx_barangay_id` (`barangay_id`);

--
-- Indexes for table `growth_records`
--
ALTER TABLE `growth_records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_child_id` (`child_id`),
  ADD KEY `fk_weight_id` (`weight_id`),
  ADD KEY `fk_height_for_age` (`height_id`),
  ADD KEY `fk_weight_for_length` (`wfl_id`),
  ADD KEY `fk_muac_id` (`muac_id`);

--
-- Indexes for table `guardians`
--
ALTER TABLE `guardians`
  ADD PRIMARY KEY (`guardian_id`);

--
-- Indexes for table `height_for_age`
--
ALTER TABLE `height_for_age`
  ADD PRIMARY KEY (`height_id`);

--
-- Indexes for table `interventions`
--
ALTER TABLE `interventions`
  ADD PRIMARY KEY (`intervention_id`),
  ADD KEY `child_id` (`child_id`),
  ADD KEY `fk_intervention_type` (`type_id`);

--
-- Indexes for table `intervention_items`
--
ALTER TABLE `intervention_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `intervention_id` (`intervention_id`),
  ADD KEY `inventory_id` (`inventory_id`);

--
-- Indexes for table `intervention_types`
--
ALTER TABLE `intervention_types`
  ADD PRIMARY KEY (`type_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`),
  ADD KEY `fk_inventory_category` (`category_id`);

--
-- Indexes for table `muac`
--
ALTER TABLE `muac`
  ADD PRIMARY KEY (`muac_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `fk_password_resets_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `fk_barangay` (`barangay_id`);

--
-- Indexes for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `weight_for_age`
--
ALTER TABLE `weight_for_age`
  ADD PRIMARY KEY (`weight_id`);

--
-- Indexes for table `weight_for_length`
--
ALTER TABLE `weight_for_length`
  ADD PRIMARY KEY (`wfl_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `barangay_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `category_inventory`
--
ALTER TABLE `category_inventory`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `children`
--
ALTER TABLE `children`
  MODIFY `child_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `growth_records`
--
ALTER TABLE `growth_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `guardians`
--
ALTER TABLE `guardians`
  MODIFY `guardian_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `height_for_age`
--
ALTER TABLE `height_for_age`
  MODIFY `height_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=119;

--
-- AUTO_INCREMENT for table `interventions`
--
ALTER TABLE `interventions`
  MODIFY `intervention_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `intervention_items`
--
ALTER TABLE `intervention_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `intervention_types`
--
ALTER TABLE `intervention_types`
  MODIFY `type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `muac`
--
ALTER TABLE `muac`
  MODIFY `muac_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=882018;

--
-- AUTO_INCREMENT for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=140;

--
-- AUTO_INCREMENT for table `weight_for_age`
--
ALTER TABLE `weight_for_age`
  MODIFY `weight_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- AUTO_INCREMENT for table `weight_for_length`
--
ALTER TABLE `weight_for_length`
  MODIFY `wfl_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=485;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `children`
--
ALTER TABLE `children`
  ADD CONSTRAINT `children_ibfk_1` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`guardian_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `children_ibfk_2` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `growth_records`
--
ALTER TABLE `growth_records`
  ADD CONSTRAINT `fk_height_for_age` FOREIGN KEY (`height_id`) REFERENCES `height_for_age` (`height_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_muac` FOREIGN KEY (`muac_id`) REFERENCES `muac` (`muac_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_muac_id` FOREIGN KEY (`muac_id`) REFERENCES `muac` (`muac_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_weight_for_length` FOREIGN KEY (`wfl_id`) REFERENCES `weight_for_length` (`wfl_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_weight_id` FOREIGN KEY (`weight_id`) REFERENCES `weight_for_age` (`weight_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `growth_records_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`child_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `interventions`
--
ALTER TABLE `interventions`
  ADD CONSTRAINT `fk_intervention_type` FOREIGN KEY (`type_id`) REFERENCES `intervention_types` (`type_id`),
  ADD CONSTRAINT `interventions_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`child_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `intervention_items`
--
ALTER TABLE `intervention_items`
  ADD CONSTRAINT `intervention_items_ibfk_1` FOREIGN KEY (`intervention_id`) REFERENCES `interventions` (`intervention_id`),
  ADD CONSTRAINT `intervention_items_ibfk_2` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`);

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `fk_inventory_category` FOREIGN KEY (`category_id`) REFERENCES `category_inventory` (`category_id`);

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD CONSTRAINT `user_activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

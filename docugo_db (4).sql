-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 12, 2026 at 03:13 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `docugo_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `alumni_employment`
--

CREATE TABLE `alumni_employment` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `job_title` varchar(150) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `employment_type` enum('full_time','part_time','contract','freelance','internship') DEFAULT 'full_time',
  `work_setup` enum('onsite','remote','hybrid') DEFAULT 'onsite',
  `date_started` date DEFAULT NULL,
  `date_ended` date DEFAULT NULL,
  `is_current` tinyint(1) DEFAULT 1,
  `salary_range` varchar(50) DEFAULT NULL,
  `work_location` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `linkedin_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `target_type` enum('all','user') DEFAULT 'all',
  `target_user_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `message`, `target_type`, `target_user_id`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'test', 'hi', 'user', 5, 1, '2026-05-12 00:44:50', '2026-05-12 00:44:50');

-- --------------------------------------------------------

--
-- Table structure for table `claim_stubs`
--

CREATE TABLE `claim_stubs` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `stub_code` varchar(30) NOT NULL,
  `qr_code_data` text DEFAULT NULL,
  `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_printed` tinyint(1) DEFAULT 0,
  `printed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `claim_stubs`
--

INSERT INTO `claim_stubs` (`id`, `request_id`, `user_id`, `stub_code`, `qr_code_data`, `total_fee`, `is_printed`, `printed_at`, `created_at`) VALUES
(1, 1, 7, 'STUB-832FFBDBD0', NULL, 50.00, 1, '2026-04-26 03:03:22', '2026-04-25 19:03:19');

-- --------------------------------------------------------

--
-- Table structure for table `document_requests`
--

CREATE TABLE `document_requests` (
  `id` int(11) NOT NULL,
  `request_code` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `purpose` text NOT NULL,
  `copies` int(11) DEFAULT 1,
  `preferred_release_date` date DEFAULT NULL,
  `release_mode` enum('pickup','delivery') DEFAULT 'pickup',
  `delivery_address` text DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `status` enum('pending','approved','for_signature','processing','ready','paid','released','cancelled') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `estimated_release_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_requests`
--

INSERT INTO `document_requests` (`id`, `request_code`, `user_id`, `document_type_id`, `purpose`, `copies`, `preferred_release_date`, `release_mode`, `delivery_address`, `payment_reference`, `status`, `remarks`, `requested_at`, `updated_at`, `approved_at`, `paid_at`, `released_at`, `estimated_release_date`) VALUES
(1, 'DOC-2026-7D223F', 7, 6, 'hasdsahdjadhj', 1, '2026-04-20', 'pickup', '', NULL, 'ready', NULL, '2026-04-19 06:11:39', '2026-04-25 19:03:19', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `document_signatures`
--

CREATE TABLE `document_signatures` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `signed_by` int(11) NOT NULL,
  `signer_role` varchar(100) DEFAULT NULL,
  `signature_file` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `signed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `fee` decimal(10,2) DEFAULT 0.00,
  `processing_days` int(11) DEFAULT 3,
  `requires_signature` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`id`, `name`, `description`, `fee`, `processing_days`, `requires_signature`, `is_active`, `created_at`) VALUES
(1, 'Transcript of Records', 'Official academic transcript', 100.00, 5, 0, 1, '2026-04-15 07:45:03'),
(2, 'Certificate of Enrollment', 'Proof of enrollment for current students', 30.00, 1, 0, 1, '2026-04-15 07:45:03'),
(3, 'Certificate of Graduation', 'Official certificate of graduation', 50.00, 3, 0, 1, '2026-04-15 07:45:03'),
(4, 'Good Moral Certificate', 'Character certificate', 30.00, 2, 0, 1, '2026-04-15 07:45:03'),
(5, 'Diploma (Replacement)', 'Replacement diploma', 500.00, 10, 0, 1, '2026-04-15 07:45:03'),
(6, 'Authentication', 'Document authentication', 50.00, 3, 0, 1, '2026-04-15 07:45:03'),
(7, 'sdsdsad', 'asdsadas', 120.00, 3, 0, 1, '2026-04-19 06:10:11'),
(8, 'JKFKSJDH', 'jahsd', 120.00, 3, 1, 1, '2026-05-12 00:36:11');

-- --------------------------------------------------------

--
-- Table structure for table `email_verifications`
--

CREATE TABLE `email_verifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `graduate_tracer`
--

CREATE TABLE `graduate_tracer` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employment_status` enum('employed','unemployed','self_employed','further_studies','not_looking') DEFAULT 'unemployed',
  `employer_name` varchar(200) DEFAULT NULL,
  `job_title` varchar(150) DEFAULT NULL,
  `employment_sector` enum('government','private','ngo','self','other') DEFAULT NULL,
  `degree_relevance` enum('very_relevant','relevant','somewhat_relevant','not_relevant') DEFAULT NULL,
  `further_studies` tinyint(1) DEFAULT 0,
  `school_further_studies` varchar(200) DEFAULT NULL,
  `professional_license` varchar(200) DEFAULT NULL,
  `date_submitted` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `graduate_tracer`
--

INSERT INTO `graduate_tracer` (`id`, `user_id`, `employment_status`, `employer_name`, `job_title`, `employment_sector`, `degree_relevance`, `further_studies`, `school_further_studies`, `professional_license`, `date_submitted`) VALUES
(2, 5, '', '', '', '', '', 0, '', '', '2026-04-16 10:01:57'),
(3, 6, '', '', '', '', '', 0, '', '', '2026-04-16 11:43:25'),
(4, 7, '', '', '', '', '', 0, '', '', '2026-04-18 04:37:37');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `is_read`, `created_at`) VALUES
(1, 7, 'Your document request #1 has been approved and is now being processed.', 1, '2026-04-25 19:00:59'),
(2, 7, 'Your document request #1 is now being prepared.', 1, '2026-04-25 19:01:36'),
(3, 7, 'Your document request #1 is ready for pickup. Please proceed to the Registrar\'s Office to pay and claim your document.', 1, '2026-04-25 19:03:19');

-- --------------------------------------------------------

--
-- Table structure for table `payment_audit_logs`
--

CREATE TABLE `payment_audit_logs` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `action` enum('payment_recorded','payment_updated','receipt_issued','status_changed') NOT NULL,
  `performed_by` int(11) NOT NULL,
  `old_value` varchar(100) DEFAULT NULL,
  `new_value` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_records`
--

CREATE TABLE `payment_records` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `official_receipt_number` varchar(100) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('unpaid','paid') DEFAULT 'unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `release_schedules`
--

CREATE TABLE `release_schedules` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `released_by` int(11) DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_logs`
--

CREATE TABLE `request_logs` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `request_logs`
--

INSERT INTO `request_logs` (`id`, `request_id`, `changed_by`, `old_status`, `new_status`, `notes`, `changed_at`) VALUES
(1, 1, 1, 'pending', 'approved', '', '2026-04-25 19:00:59'),
(2, 1, 1, 'approved', 'processing', '', '2026-04-25 19:01:36'),
(3, 1, 1, 'processing', 'ready', '', '2026-04-25 19:03:19');

-- --------------------------------------------------------

--
-- Table structure for table `request_signatures`
--

CREATE TABLE `request_signatures` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `office_id` int(11) NOT NULL,
  `status` enum('pending','signed','rejected') DEFAULT 'pending',
  `signed_by` int(11) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `signature_offices`
--

CREATE TABLE `signature_offices` (
  `id` int(11) NOT NULL,
  `office_name` varchar(100) NOT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `signature_offices`
--

INSERT INTO `signature_offices` (`id`, `office_name`, `office_code`, `created_at`) VALUES
(1, 'Computer Laboratory', 'COMP_LAB', '2026-05-07 14:05:46'),
(2, 'Registrar', 'REGISTRAR', '2026-05-07 14:05:46'),
(3, 'Library', 'LIBRARY', '2026-05-07 14:05:46'),
(4, 'Department Head', 'DEPT_HEAD', '2026-05-07 14:05:46');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `student_id` varchar(20) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `gender` enum('male','female','prefer_not_to_say') DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','alumni','registrar','admin') DEFAULT 'student',
  `course` varchar(100) DEFAULT NULL,
  `year_graduated` year(4) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','pending') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `office_role` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `student_id`, `first_name`, `middle_name`, `last_name`, `email`, `gender`, `birthdate`, `password`, `role`, `course`, `year_graduated`, `contact_number`, `address`, `profile_picture`, `status`, `created_at`, `updated_at`, `office_role`) VALUES
(1, NULL, 'Admin', NULL, 'DocuGo', 'admin@adfc.edu.ph', NULL, NULL, '$2y$10$11SveHoXs8bmZO/OoMzZA.Ftz44hQdU6RMIGRqtWZWDNBLyi8OncK', 'admin', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-15 07:45:03', '2026-04-15 08:06:10', NULL),
(2, '2932-12-12312', 'Arghin', 'Sebugon', 'Adolfo', 'adolfoarghinmark1@gmail.com', 'male', '2004-07-22', '$2y$10$6iAzzV/aEYSd5/zKZbXEVOcXJ5V0FP7IzciM9B57m7KIcjQVrVFd2', 'student', 'BSIT', NULL, '09691894248', NULL, NULL, 'active', '2026-04-15 13:13:53', '2026-04-16 09:32:38', NULL),
(5, '1111-22-33333', 'Arghin Mark', 'Sebugon', 'Adolfo', 'adolfoarghinmark@gmail.com', 'male', '2004-07-21', '$2y$10$JZbgtp2YieQfYa.ac9/my.9cdbkvWNqfXEuwOMl/b/9EVx8Ka6By6', 'student', 'BSIT', NULL, '09691894248', 'Brgy 108, Tagpuro Sangyaw Village', 'uploads/profile/user_5_1776490061.png', 'active', '2026-04-16 10:01:57', '2026-04-18 05:28:38', NULL),
(6, '1234-12-12345', 'Juan', 'Santos', 'Cruz', 'adolfoarghinmark3@gmail.com', 'male', '2004-06-29', '$2y$10$IaBaDP/BnIu0JOCNe3RQXuShigvOGYLXloWMKuqTd.QJ7miiqC/Hm', 'alumni', NULL, '2024', '09691894248', NULL, NULL, 'active', '2026-04-16 11:43:25', '2026-04-16 12:04:55', NULL),
(7, '1232-22-32323', 'Buddy', NULL, 'Dudzzz', 'buddydudzzz@gmail.com', 'male', '2004-06-22', '$2y$10$lcr4QQ0vz7tMrp530Mtms.JS.kIGmXsZnCLUy.YYz/TaBGaIvdy16', 'alumni', 'BSIT', '2024', '09691894248', NULL, NULL, 'active', '2026-04-18 04:37:37', '2026-04-18 04:38:25', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alumni_employment`
--
ALTER TABLE `alumni_employment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `target_user_id` (`target_user_id`);

--
-- Indexes for table `claim_stubs`
--
ALTER TABLE `claim_stubs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_id` (`request_id`),
  ADD UNIQUE KEY `stub_code` (`stub_code`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `document_requests`
--
ALTER TABLE `document_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_code` (`request_code`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `document_type_id` (`document_type_id`);

--
-- Indexes for table `document_signatures`
--
ALTER TABLE `document_signatures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_signature_request` (`request_id`),
  ADD KEY `fk_signature_user` (`signed_by`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `graduate_tracer`
--
ALTER TABLE `graduate_tracer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payment_audit_logs`
--
ALTER TABLE `payment_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `performed_by` (`performed_by`);

--
-- Indexes for table `payment_records`
--
ALTER TABLE `payment_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `processed_by` (`processed_by`);

--
-- Indexes for table `release_schedules`
--
ALTER TABLE `release_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_id` (`request_id`),
  ADD KEY `released_by` (`released_by`);

--
-- Indexes for table `request_logs`
--
ALTER TABLE `request_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `request_signatures`
--
ALTER TABLE `request_signatures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `office_id` (`office_id`);

--
-- Indexes for table `signature_offices`
--
ALTER TABLE `signature_offices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `office_code` (`office_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alumni_employment`
--
ALTER TABLE `alumni_employment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `claim_stubs`
--
ALTER TABLE `claim_stubs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_requests`
--
ALTER TABLE `document_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_signatures`
--
ALTER TABLE `document_signatures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `email_verifications`
--
ALTER TABLE `email_verifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `graduate_tracer`
--
ALTER TABLE `graduate_tracer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `payment_audit_logs`
--
ALTER TABLE `payment_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_records`
--
ALTER TABLE `payment_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `release_schedules`
--
ALTER TABLE `release_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_logs`
--
ALTER TABLE `request_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `request_signatures`
--
ALTER TABLE `request_signatures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `signature_offices`
--
ALTER TABLE `signature_offices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alumni_employment`
--
ALTER TABLE `alumni_employment`
  ADD CONSTRAINT `alumni_employment_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `claim_stubs`
--
ALTER TABLE `claim_stubs`
  ADD CONSTRAINT `claim_stubs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `claim_stubs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_requests`
--
ALTER TABLE `document_requests`
  ADD CONSTRAINT `document_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_requests_ibfk_2` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`);

--
-- Constraints for table `document_signatures`
--
ALTER TABLE `document_signatures`
  ADD CONSTRAINT `fk_signature_request` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_signature_user` FOREIGN KEY (`signed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `graduate_tracer`
--
ALTER TABLE `graduate_tracer`
  ADD CONSTRAINT `graduate_tracer_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_audit_logs`
--
ALTER TABLE `payment_audit_logs`
  ADD CONSTRAINT `payment_audit_logs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_audit_logs_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `payment_records`
--
ALTER TABLE `payment_records`
  ADD CONSTRAINT `payment_records_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_records_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_records_ibfk_3` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `release_schedules`
--
ALTER TABLE `release_schedules`
  ADD CONSTRAINT `release_schedules_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `release_schedules_ibfk_2` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `request_logs`
--
ALTER TABLE `request_logs`
  ADD CONSTRAINT `request_logs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `request_logs_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `request_signatures`
--
ALTER TABLE `request_signatures`
  ADD CONSTRAINT `request_signatures_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `request_signatures_ibfk_2` FOREIGN KEY (`office_id`) REFERENCES `signature_offices` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

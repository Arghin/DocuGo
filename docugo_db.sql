-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 19, 2026 at 12:53 PM
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
  `payment_status` enum('unpaid','paid') DEFAULT 'unpaid',
  `payment_reference` varchar(100) DEFAULT NULL,
  `status` enum('pending','processing','ready','released','cancelled') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_requests`
--

INSERT INTO `document_requests` (`id`, `request_code`, `user_id`, `document_type_id`, `purpose`, `copies`, `preferred_release_date`, `release_mode`, `delivery_address`, `payment_status`, `payment_reference`, `status`, `remarks`, `requested_at`, `updated_at`) VALUES
(1, 'DOC-2026-7D223F', 7, 6, 'hasdsahdjadhj', 1, '2026-04-20', 'pickup', '', 'unpaid', NULL, 'pending', NULL, '2026-04-19 06:11:39', '2026-04-19 06:11:39');

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
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`id`, `name`, `description`, `fee`, `processing_days`, `is_active`, `created_at`) VALUES
(1, 'Transcript of Records', 'Official academic transcript', 100.00, 5, 1, '2026-04-15 07:45:03'),
(2, 'Certificate of Enrollment', 'Proof of enrollment for current students', 30.00, 1, 1, '2026-04-15 07:45:03'),
(3, 'Certificate of Graduation', 'Official certificate of graduation', 50.00, 3, 1, '2026-04-15 07:45:03'),
(4, 'Good Moral Certificate', 'Character certificate', 30.00, 2, 1, '2026-04-15 07:45:03'),
(5, 'Diploma (Replacement)', 'Replacement diploma', 500.00, 10, 1, '2026-04-15 07:45:03'),
(6, 'Authentication', 'Document authentication', 50.00, 3, 1, '2026-04-15 07:45:03'),
(7, 'sdsdsad', 'asdsadas', 120.00, 3, 1, '2026-04-19 06:10:11');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `student_id`, `first_name`, `middle_name`, `last_name`, `email`, `gender`, `birthdate`, `password`, `role`, `course`, `year_graduated`, `contact_number`, `address`, `profile_picture`, `status`, `created_at`, `updated_at`) VALUES
(1, NULL, 'Admin', NULL, 'DocuGo', 'admin@adfc.edu.ph', NULL, NULL, '$2y$10$11SveHoXs8bmZO/OoMzZA.Ftz44hQdU6RMIGRqtWZWDNBLyi8OncK', 'admin', NULL, NULL, NULL, NULL, NULL, 'active', '2026-04-15 07:45:03', '2026-04-15 08:06:10'),
(2, '2932-12-12312', 'Arghin', 'Sebugon', 'Adolfo', 'adolfoarghinmark1@gmail.com', 'male', '2004-07-22', '$2y$10$6iAzzV/aEYSd5/zKZbXEVOcXJ5V0FP7IzciM9B57m7KIcjQVrVFd2', 'student', 'BSIT', NULL, '09691894248', NULL, NULL, 'active', '2026-04-15 13:13:53', '2026-04-16 09:32:38'),
(5, '1111-22-33333', 'Arghin Mark', 'Sebugon', 'Adolfo', 'adolfoarghinmark@gmail.com', 'male', '2004-07-21', '$2y$10$JZbgtp2YieQfYa.ac9/my.9cdbkvWNqfXEuwOMl/b/9EVx8Ka6By6', 'student', 'BSIT', NULL, '09691894248', 'Brgy 108, Tagpuro Sangyaw Village', 'uploads/profile/user_5_1776490061.png', 'active', '2026-04-16 10:01:57', '2026-04-18 05:28:38'),
(6, '1234-12-12345', 'Juan', 'Santos', 'Cruz', 'adolfoarghinmark3@gmail.com', 'male', '2004-06-29', '$2y$10$IaBaDP/BnIu0JOCNe3RQXuShigvOGYLXloWMKuqTd.QJ7miiqC/Hm', 'alumni', NULL, '2024', '09691894248', NULL, NULL, 'active', '2026-04-16 11:43:25', '2026-04-16 12:04:55'),
(7, '1232-22-32323', 'Buddy', NULL, 'Dudzzz', 'buddydudzzz@gmail.com', 'male', '2004-06-22', '$2y$10$lcr4QQ0vz7tMrp530Mtms.JS.kIGmXsZnCLUy.YYz/TaBGaIvdy16', 'alumni', 'BSIT', '2024', '09691894248', NULL, NULL, 'active', '2026-04-18 04:37:37', '2026-04-18 04:38:25');

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
-- Indexes for table `document_requests`
--
ALTER TABLE `document_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_code` (`request_code`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `document_type_id` (`document_type_id`);

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
-- Indexes for table `request_logs`
--
ALTER TABLE `request_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `changed_by` (`changed_by`);

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
-- AUTO_INCREMENT for table `document_requests`
--
ALTER TABLE `document_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_logs`
--
ALTER TABLE `request_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
-- Constraints for table `document_requests`
--
ALTER TABLE `document_requests`
  ADD CONSTRAINT `document_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_requests_ibfk_2` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`);

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
-- Constraints for table `request_logs`
--
ALTER TABLE `request_logs`
  ADD CONSTRAINT `request_logs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `request_logs_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

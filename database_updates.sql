-- Database updates for DocuGo enhancements
-- Run these SQL statements to update the database schema

-- 0. Add timestamp columns for status tracking
ALTER TABLE `document_requests` 
ADD COLUMN `approved_at` datetime NULL AFTER `updated_at`,
ADD COLUMN `paid_at` datetime NULL AFTER `approved_at`,
ADD COLUMN `released_at` datetime NULL AFTER `paid_at`,
ADD COLUMN `estimated_release_date` date NULL AFTER `released_at`;
ALTER TABLE `document_requests` 
MODIFY COLUMN `status` enum('pending','approved','for_signature','processing','ready','paid','released','cancelled') 
DEFAULT 'pending';

-- 2. Add requires_signature field to document_types
ALTER TABLE `document_types` 
ADD COLUMN `requires_signature` tinyint(1) DEFAULT 0 AFTER `processing_days`;

-- 3. Create payment_records table (if not exists)
CREATE TABLE IF NOT EXISTS `payment_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `official_receipt_number` varchar(100) NOT NULL,
  `payment_date` datetime NOT NULL,
  `processed_by` int(11) NOT NULL,
  `status` enum('pending','paid','failed','refunded') DEFAULT 'paid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `official_receipt_number` (`official_receipt_number`),
  KEY `request_id` (`request_id`),
  KEY `user_id` (`user_id`),
  KEY `processed_by` (`processed_by`),
  CONSTRAINT `payment_records_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_records_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_records_ibfk_3` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Create claim_stubs table (if not exists) - enhanced with print tracking
CREATE TABLE IF NOT EXISTS `claim_stubs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `stub_code` varchar(20) NOT NULL,
  `qr_code_data` text NOT NULL,
  `total_fee` decimal(10,2) NOT NULL,
  `print_status` enum('not_printed','printed','reprinted') DEFAULT 'not_printed',
  `printed_at` datetime NULL,
  `printed_by` int(11) NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `stub_code` (`stub_code`),
  UNIQUE KEY `request_id` (`request_id`),
  KEY `user_id` (`user_id`),
  KEY `printed_by` (`printed_by`),
  CONSTRAINT `claim_stubs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `claim_stubs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `claim_stubs_ibfk_3` FOREIGN KEY (`printed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Create release_schedules table (if not exists)
CREATE TABLE IF NOT EXISTS `release_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `released_by` int(11) NOT NULL,
  `released_at` datetime NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_id` (`request_id`),
  KEY `released_by` (`released_by`),
  CONSTRAINT `release_schedules_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `release_schedules_ibfk_2` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Create payment_audit_logs table (if not exists)
CREATE TABLE IF NOT EXISTS `payment_audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `performed_by` int(11) NOT NULL,
  `old_value` varchar(50) DEFAULT NULL,
  `new_value` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `payment_id` (`payment_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `payment_audit_logs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_audit_logs_ibfk_2` FOREIGN KEY (`payment_id`) REFERENCES `payment_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_audit_logs_ibfk_3` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 7. Add indexes for better search performance
ALTER TABLE `document_requests` ADD INDEX `idx_request_code` (`request_code`);
ALTER TABLE `document_requests` ADD INDEX `idx_user_status` (`user_id`, `status`);
ALTER TABLE `document_requests` ADD INDEX `idx_requested_at` (`requested_at`);
ALTER TABLE `users` ADD INDEX `idx_name` (`first_name`, `last_name`);
ALTER TABLE `document_types` ADD INDEX `idx_name` (`name`);

-- 8. Insert sample data for document types that require signature
-- Update existing document types that typically require signature
-- Example: Authentication, Good Moral Certificate might require signature
UPDATE `document_types` SET `requires_signature` = 1 WHERE `name` IN ('Authentication', 'Good Moral Certificate');
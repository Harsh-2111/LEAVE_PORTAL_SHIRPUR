CREATE DATABASE IF NOT EXISTS `hostel_leave` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hostel_leave`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `leave_call_log`;
DROP TABLE IF EXISTS `leave_requests`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `students`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `students` (
  `sap_id` CHAR(11) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `student_contact` VARCHAR(20) DEFAULT NULL,
  `gender` ENUM('male','female') DEFAULT NULL,
  `course` ENUM('BTech','MBATech','BPharm','MPharm','Agriculture') DEFAULT NULL,
  `year` VARCHAR(50) NOT NULL,
  `branch` VARCHAR(100) NOT NULL,
  `batch` VARCHAR(50) NOT NULL,
  `hostel_block` VARCHAR(50) DEFAULT NULL,
  `room_no` VARCHAR(50) DEFAULT NULL,
  `parent_name` VARCHAR(150) DEFAULT NULL,
  `parent_email` VARCHAR(255) DEFAULT NULL,
  `parent_contact` VARCHAR(20) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sap_id`),
  KEY `idx_students_branch` (`branch`),
  KEY `idx_students_batch` (`batch`),
  KEY `idx_students_parent_email` (`parent_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login_id` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('student','warden','admin','security') NOT NULL,
  `warden_gender` ENUM('male','female') DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_login_id` (`login_id`),
  KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sap_id` CHAR(11) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `leave_days` INT NOT NULL,
  `reason` TEXT NOT NULL,
  `school` ENUM('MPSTME','SPTM') DEFAULT NULL,
  `hostel` VARCHAR(40) DEFAULT NULL,
  `email_from` VARCHAR(255) DEFAULT NULL,
  `email_received_at` DATETIME DEFAULT NULL,
  `parent_email_matched` TINYINT(1) NOT NULL DEFAULT 0,
  `logged_by` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('Pending Verification','Granted','Rejected','Cancelled') NOT NULL DEFAULT 'Pending Verification',
  `pass_token` CHAR(64) DEFAULT NULL,
  `qr_code_data` TEXT DEFAULT NULL,
  `granted_by` BIGINT UNSIGNED DEFAULT NULL,
  `granted_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_leave_pass_token` (`pass_token`),
  KEY `idx_leave_requests_sap_status` (`sap_id`, `status`),
  KEY `idx_leave_requests_status_created` (`status`, `created_at`),
  KEY `idx_leave_requests_dates` (`start_date`, `end_date`),
  CONSTRAINT `fk_leave_requests_student` FOREIGN KEY (`sap_id`) REFERENCES `students` (`sap_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_leave_requests_logged_by` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_call_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `leave_id` BIGINT UNSIGNED NOT NULL,
  `called_by` BIGINT UNSIGNED NOT NULL,
  `called_number` VARCHAR(20) NOT NULL,
  `called_at` DATETIME NOT NULL,
  `outcome` ENUM('Confirmed','Denied','Not Reachable','Wrong Person') NOT NULL,
  `remarks` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_leave_call_log_leave` (`leave_id`),
  KEY `idx_leave_call_log_called_by` (`called_by`),
  CONSTRAINT `fk_leave_call_log_leave` FOREIGN KEY (`leave_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_leave_call_log_user` FOREIGN KEY (`called_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity` VARCHAR(100) NOT NULL,
  `entity_id` BIGINT UNSIGNED DEFAULT NULL,
  `details` JSON DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user_id` (`user_id`),
  KEY `idx_audit_entity` (`entity`, `entity_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_sessions` (
  `session_id` VARCHAR(128) NOT NULL,
  `session_data` MEDIUMBLOB NOT NULL,
  `expires_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_app_sessions_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `leave_requests`
  ADD COLUMN `emergency_contact_name` VARCHAR(150) NULL,
  ADD COLUMN `emergency_contact_relationship` VARCHAR(50) NULL,
  ADD COLUMN `emergency_contact_phone` VARCHAR(20) NULL;

CREATE TABLE IF NOT EXISTS `leave_policies` (
  `hostel` VARCHAR(40) NOT NULL,
  `max_leave_days` INT NULL,
  `max_requests_per_month` INT NULL,
  `minimum_notice_hours` INT NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hostel`),
  CONSTRAINT `fk_leave_policy_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(150) NOT NULL,
  `body` TEXT NOT NULL,
  `target_role` VARCHAR(20) NOT NULL DEFAULT 'all',
  `target_hostel` VARCHAR(40) NULL,
  `expires_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_announcements_active` (`created_at`, `expires_at`),
  CONSTRAINT `fk_announcement_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



USE `hostel_leave`;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_student_profile_schema//
CREATE PROCEDURE migrate_student_profile_schema()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'student_contact') THEN
    ALTER TABLE students ADD COLUMN student_contact VARCHAR(20) DEFAULT NULL AFTER name;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'gender') THEN
    ALTER TABLE students ADD COLUMN gender ENUM('male','female') DEFAULT NULL AFTER student_contact;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'course') THEN
    ALTER TABLE students ADD COLUMN course ENUM('BTech','MBATech','BPharm','MPharm','Agriculture') DEFAULT NULL AFTER gender;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'official_email_id') THEN
    ALTER TABLE students ADD COLUMN official_email_id VARCHAR(255) DEFAULT NULL AFTER parent_email;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'mother_contact') THEN
    ALTER TABLE students ADD COLUMN mother_contact VARCHAR(20) DEFAULT NULL AFTER parent_contact;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'warden_gender') THEN
    ALTER TABLE users ADD COLUMN warden_gender ENUM('male','female') DEFAULT NULL AFTER role;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'warden_hostel') THEN
    ALTER TABLE users ADD COLUMN warden_hostel ENUM('BOYS HOSTEL 1','NEW BOYS HOSTEL','NEW GIRLS HOSTEL','OLD GIRLS HOSTEL') DEFAULT NULL AFTER warden_gender;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = 'school') THEN
    ALTER TABLE leave_requests ADD COLUMN school ENUM('MPSTME','SPTM') DEFAULT NULL AFTER reason;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = 'hostel') THEN
    ALTER TABLE leave_requests ADD COLUMN hostel VARCHAR(40) DEFAULT NULL AFTER school;
  END IF;
END//
CALL migrate_student_profile_schema()//
DROP PROCEDURE migrate_student_profile_schema//
DELIMITER ;

ALTER TABLE leave_requests
  MODIFY email_from VARCHAR(255) DEFAULT NULL,
  MODIFY email_received_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `app_sessions` (
  `session_id` VARCHAR(128) NOT NULL,
  `session_data` MEDIUMBLOB NOT NULL,
  `expires_at` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_app_sessions_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DELIMITER //
DROP PROCEDURE IF EXISTS migrate_leave_request_contact_address_times//
CREATE PROCEDURE migrate_leave_request_contact_address_times()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND column_name = 'start_time'
  ) THEN
    ALTER TABLE leave_requests ADD COLUMN start_time TIME DEFAULT NULL AFTER start_date;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND column_name = 'end_time'
  ) THEN
    ALTER TABLE leave_requests ADD COLUMN end_time TIME DEFAULT NULL AFTER end_date;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND column_name = 'home_address'
  ) THEN
    ALTER TABLE leave_requests ADD COLUMN home_address VARCHAR(150) DEFAULT NULL AFTER reason;
  END IF;
END//
CALL migrate_leave_request_contact_address_times()//
DROP PROCEDURE migrate_leave_request_contact_address_times//
DELIMITER ;

USE `hostel_leave`;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_leave_request_room//
CREATE PROCEDURE migrate_leave_request_room()
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND column_name = 'room_no'
  ) THEN
    ALTER TABLE leave_requests ADD COLUMN room_no VARCHAR(50) DEFAULT NULL AFTER hostel;
  END IF;
END//
CALL migrate_leave_request_room()//
DROP PROCEDURE migrate_leave_request_room//
DELIMITER ;

USE `hostel_leave`;

SET @official_email_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'students'
    AND column_name = 'official_email_id'
);

SET @official_email_migration = IF(
  @official_email_column_exists = 0,
  'ALTER TABLE students ADD COLUMN official_email_id VARCHAR(255) DEFAULT NULL AFTER parent_email',
  'SELECT 1'
);

PREPARE official_email_migration_statement FROM @official_email_migration;
EXECUTE official_email_migration_statement;
DEALLOCATE PREPARE official_email_migration_statement;

USE `hostel_leave`;

SET @mother_contact_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'students'
    AND column_name = 'mother_contact'
);

SET @mother_contact_migration = IF(
  @mother_contact_column_exists = 0,
  'ALTER TABLE students ADD COLUMN mother_contact VARCHAR(20) DEFAULT NULL AFTER parent_contact',
  'SELECT 1'
);

PREPARE mother_contact_migration_statement FROM @mother_contact_migration;
EXECUTE mother_contact_migration_statement;
DEALLOCATE PREPARE mother_contact_migration_statement;

USE `hostel_leave`;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_hostel_leave_v4//
CREATE PROCEDURE migrate_hostel_leave_v4()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = 'gate_out_time') THEN
    ALTER TABLE leave_requests ADD COLUMN gate_out_time DATETIME DEFAULT NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = 'gate_in_time') THEN
    ALTER TABLE leave_requests ADD COLUMN gate_in_time DATETIME DEFAULT NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = 'return_timing_status') THEN
    ALTER TABLE leave_requests ADD COLUMN return_timing_status ENUM('PENDING_EXIT','OUTSIDE','ON_TIME','EARLY_RETURN','LATE_RETURN') NOT NULL DEFAULT 'PENDING_EXIT';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = 'variance_summary') THEN
    ALTER TABLE leave_requests ADD COLUMN variance_summary VARCHAR(100) DEFAULT NULL;
  END IF;
END//
CALL migrate_hostel_leave_v4()//
DROP PROCEDURE migrate_hostel_leave_v4//
DELIMITER ;

ALTER TABLE leave_requests
  MODIFY status ENUM('Pending Verification','Granted','Rejected','Cancelled','CHECKED_OUT','CHECKED_IN') NOT NULL DEFAULT 'Pending Verification';

USE `hostel_leave`;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_gate_attendance_indexes//
CREATE PROCEDURE migrate_gate_attendance_indexes()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND index_name = 'idx_leave_requests_gate_out'
  ) THEN
    CREATE INDEX idx_leave_requests_gate_out ON leave_requests (gate_out_time);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND index_name = 'idx_leave_requests_gate_in'
  ) THEN
    CREATE INDEX idx_leave_requests_gate_in ON leave_requests (gate_in_time);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND index_name = 'idx_leave_requests_status_gate_times'
  ) THEN
    CREATE INDEX idx_leave_requests_status_gate_times
      ON leave_requests (status, gate_out_time, gate_in_time);
  END IF;
END//
CALL migrate_gate_attendance_indexes()//
DROP PROCEDURE migrate_gate_attendance_indexes//
DELIMITER ;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_portal_contact_fields//
CREATE PROCEDURE migrate_portal_contact_fields()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND column_name = 'emergency_contact_name'
  ) THEN
    ALTER TABLE leave_requests ADD COLUMN emergency_contact_name VARCHAR(150) NULL;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND column_name = 'emergency_contact_relationship'
  ) THEN
    ALTER TABLE leave_requests ADD COLUMN emergency_contact_relationship VARCHAR(50) NULL;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND column_name = 'emergency_contact_phone'
  ) THEN
    ALTER TABLE leave_requests ADD COLUMN emergency_contact_phone VARCHAR(20) NULL;
  END IF;
END//
CALL migrate_portal_contact_fields()//
DROP PROCEDURE migrate_portal_contact_fields//
DELIMITER ;

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

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `leave_id` BIGINT UNSIGNED NULL,
  `type` VARCHAR(40) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(255) NULL,
  `read_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_created` (`user_id`, `created_at`),
  KEY `idx_notifications_unread` (`user_id`, `read_at`),
  CONSTRAINT `fk_notifications_user_migration` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_leave_migration` FOREIGN KEY (`leave_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


USE `hostel_leave`;

UPDATE users
SET login_id = 'id-admin',
    password_hash = '$2y$10$dbeNIL9CJeN6QAnMF6cGdecpD4.rbcC0NTZPCWWxw4RgUXhlglTVS',
    name = 'System Admin',
    is_active = 1
WHERE login_id = 'admin' AND role = 'admin';

UPDATE users
SET password_hash = '$2y$10$xAwZzF.xW8AZlVdXtJQ3ueTrEwJr.HrHjHC.KEpITd1K6KI00clbi',
    warden_gender = 'male',
    name = 'Boys Hostel Warden',
    is_active = 1
WHERE login_id = 'warden1' AND role = 'warden';

UPDATE users
SET login_id = 'warden2',
    password_hash = '$2y$10$.kNr/5M/YarQwStCOE1IoOZvjCYPlRSRWUrTWBBCR.Zk4w2wxrb2G',
    warden_gender = 'male',
    name = 'Boys Hostel Warden',
    is_active = 1
WHERE login_id = 'warden_girls' AND role = 'warden';

INSERT INTO users (login_id, password_hash, role, warden_gender, name, must_change_password, is_active)
VALUES ('warden2', '$2y$10$.kNr/5M/YarQwStCOE1IoOZvjCYPlRSRWUrTWBBCR.Zk4w2wxrb2G', 'warden', 'male', 'Boys Hostel Warden', 1, 1)
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role = VALUES(role),
  warden_gender = VALUES(warden_gender),
  name = VALUES(name),
  is_active = VALUES(is_active);

UPDATE users
SET password_hash = '$2y$10$wVWraGzAoO.9/4n2pGW4b.ovSCj6sHrOfoxxJFSFhrGgBrcT9iFCS',
    name = 'Security Officer',
    is_active = 1
WHERE login_id = 'security' AND role = 'security';

USE `hostel_leave`;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_warden_hostel_scope//
CREATE PROCEDURE migrate_warden_hostel_scope()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'warden_hostel'
  ) THEN
    ALTER TABLE users
      ADD COLUMN warden_hostel ENUM('BOYS HOSTEL 1','NEW BOYS HOSTEL','NEW GIRLS HOSTEL','OLD GIRLS HOSTEL') DEFAULT NULL
      AFTER warden_gender;
  END IF;

  IF EXISTS (SELECT 1 FROM users WHERE login_id = 'warden_girls' AND role = 'warden') THEN
    IF EXISTS (SELECT 1 FROM users WHERE login_id = 'warden2' AND role = 'warden') THEN
      UPDATE users SET is_active = 0 WHERE login_id = 'warden_girls' AND role = 'warden';
    ELSE
      UPDATE users SET login_id = 'warden2' WHERE login_id = 'warden_girls' AND role = 'warden';
    END IF;
  END IF;

  IF EXISTS (SELECT 1 FROM users WHERE login_id IN ('warden1', 'warden2', 'warden3', 'warden4') AND role <> 'warden') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A required warden login ID is already assigned to a non-warden account.';
  END IF;

  IF NOT EXISTS (SELECT 1 FROM users WHERE login_id = 'warden1' AND role = 'warden')
    OR NOT EXISTS (SELECT 1 FROM users WHERE login_id = 'warden2' AND role = 'warden') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing warden1 and warden2 accounts must be present before applying this migration.';
  END IF;

  UPDATE users
  SET warden_gender = 'male',
      warden_hostel = 'NEW BOYS HOSTEL',
      name = 'Boys Hostel Warden'
  WHERE login_id = 'warden1' AND role = 'warden';

  UPDATE users
  SET warden_gender = 'male',
      warden_hostel = 'BOYS HOSTEL 1',
      name = 'Boys Hostel Warden'
  WHERE login_id = 'warden2' AND role = 'warden';

  UPDATE leave_requests
  SET hostel = UPPER(TRIM(hostel))
  WHERE UPPER(TRIM(hostel)) IN (
    'BOYS HOSTEL 1',
    'NEW BOYS HOSTEL',
    'NEW GIRLS HOSTEL',
    'OLD GIRLS HOSTEL'
  );

  UPDATE leave_requests lr
  INNER JOIN students s ON s.sap_id = lr.sap_id
  SET lr.hostel = UPPER(TRIM(s.hostel_block))
  WHERE (lr.hostel IS NULL OR TRIM(lr.hostel) = '')
    AND UPPER(TRIM(s.hostel_block)) IN (
      'BOYS HOSTEL 1',
      'NEW BOYS HOSTEL',
      'NEW GIRLS HOSTEL',
      'OLD GIRLS HOSTEL'
    );

  INSERT INTO users
    (login_id, password_hash, role, warden_gender, warden_hostel, name, must_change_password, is_active)
  VALUES
    ('warden3', '$2y$12$fx6zOwaIrh.8GRMFoI46RuZoi/.stwpoiRK8ZapDUqhy.OH/U9oEi', 'warden', 'female', 'NEW GIRLS HOSTEL', 'New Girls Hostel Warden', 1, 1),
    ('warden4', '$2y$12$rtp8GKuy7FD492BozT43uO09c6Jbw.VO4sPwucFje6psllExVZ9hq', 'warden', 'female', 'OLD GIRLS HOSTEL', 'Old Girls Hostel Warden', 1, 1)
  ON DUPLICATE KEY UPDATE
    role = VALUES(role),
    warden_gender = VALUES(warden_gender),
    warden_hostel = VALUES(warden_hostel),
    name = VALUES(name),
    is_active = VALUES(is_active);
END//
CALL migrate_warden_hostel_scope()//
DROP PROCEDURE migrate_warden_hostel_scope//
DELIMITER ;

CREATE TABLE IF NOT EXISTS app_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

START TRANSACTION;
SET @should_apply_student_password_migration = NOT EXISTS (
    SELECT 1 FROM app_migrations WHERE version = 'v6_student_passwords'
);

INSERT INTO app_migrations (version)
SELECT 'v6_student_passwords' WHERE @should_apply_student_password_migration = 1;

UPDATE users
SET must_change_password = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE role = 'student'
  AND @should_apply_student_password_migration = 1;

DELETE FROM app_sessions
WHERE @should_apply_student_password_migration = 1;
COMMIT;

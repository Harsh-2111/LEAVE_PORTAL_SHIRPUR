USE `hostel_leave`;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_hostel_leave_v3//
CREATE PROCEDURE migrate_hostel_leave_v3()
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
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'warden_gender') THEN
    ALTER TABLE users ADD COLUMN warden_gender ENUM('male','female') DEFAULT NULL AFTER role;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = 'school') THEN
    ALTER TABLE leave_requests ADD COLUMN school ENUM('MPSTME','SPTM') DEFAULT NULL AFTER reason;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = 'hostel') THEN
    ALTER TABLE leave_requests ADD COLUMN hostel VARCHAR(40) DEFAULT NULL AFTER school;
  END IF;
END//
CALL migrate_hostel_leave_v3()//
DROP PROCEDURE migrate_hostel_leave_v3//
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

UPDATE students SET student_contact = '9822000001', gender = 'male', course = 'BTech' WHERE sap_id = '12345678901';
UPDATE students SET student_contact = '9822000002', gender = 'female', course = 'BTech' WHERE sap_id = '12345678902';
UPDATE students SET student_contact = '9822000003', gender = 'male', course = 'BTech' WHERE sap_id = '12345678903';
UPDATE students SET student_contact = '9822000004', gender = 'female', course = 'BTech' WHERE sap_id = '12345678904';

UPDATE users SET warden_gender = 'male' WHERE login_id = 'warden1' AND role = 'warden';

INSERT INTO users (login_id, password_hash, role, warden_gender, name, must_change_password, is_active)
VALUES ('warden_girls', '$2y$10$jUaW1ritsJSxfLPk8/B2cOIHmh3OxpaGTh.teqBrgppv96qpTPgd2', 'warden', 'female', 'Girls Hostel Warden', 1, 1)
ON DUPLICATE KEY UPDATE role = VALUES(role), warden_gender = VALUES(warden_gender), name = VALUES(name), is_active = VALUES(is_active);
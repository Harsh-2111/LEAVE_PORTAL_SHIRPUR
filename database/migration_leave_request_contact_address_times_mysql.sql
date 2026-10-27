USE `hostel_leave`;

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

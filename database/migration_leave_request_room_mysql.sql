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

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

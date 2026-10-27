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

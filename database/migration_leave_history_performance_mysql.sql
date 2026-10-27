USE `hostel_leave`;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_leave_history_performance_indexes//
CREATE PROCEDURE migrate_leave_history_performance_indexes()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND index_name = 'idx_leave_requests_sap_created'
  ) THEN
    CREATE INDEX idx_leave_requests_sap_created
      ON leave_requests (sap_id, created_at);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_requests'
      AND index_name = 'idx_leave_requests_hostel_status_end_created'
  ) THEN
    CREATE INDEX idx_leave_requests_hostel_status_end_created
      ON leave_requests (hostel, status, end_date, created_at);
  END IF;
END//
CALL migrate_leave_history_performance_indexes()//
DROP PROCEDURE migrate_leave_history_performance_indexes//
DELIMITER ;

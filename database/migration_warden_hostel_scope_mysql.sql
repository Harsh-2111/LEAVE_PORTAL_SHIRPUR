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
      warden_hostel = 'NEW BOYS HOSTEL'
  WHERE login_id = 'warden1' AND role = 'warden';

  UPDATE users
  SET warden_gender = 'male',
      warden_hostel = 'BOYS HOSTEL 1'
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

USE `hostel_leave`;

INSERT INTO `users` (`login_id`, `password_hash`, `role`, `name`, `must_change_password`, `is_active`)
VALUES
  ('admin', '$2y$10$dbeNIL9CJeN6QAnMF6cGdecpD4.rbcC0NTZPCWWxw4RgUXhlglTVS', 'admin', 'System Admin', 1, 1),
  ('warden1', '$2y$10$xAwZzF.xW8AZlVdXtJQ3ueTrEwJr.HrHjHC.KEpITd1K6KI00clbi', 'warden', 'Boys Hostel Warden', 1, 1),
  ('security', '$2y$10$wVWraGzAoO.9/4n2pGW4b.ovSCj6sHrOfoxxJFSFhrGgBrcT9iFCS', 'security', 'Security Officer', 1, 1)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `name` = VALUES(`name`),
  `must_change_password` = VALUES(`must_change_password`),
  `is_active` = VALUES(`is_active`);

UPDATE users
SET warden_gender = 'male', warden_hostel = 'NEW BOYS HOSTEL'
WHERE login_id = 'warden1' AND role = 'warden';

INSERT INTO users (login_id, password_hash, role, warden_gender, warden_hostel, name, must_change_password, is_active)
VALUES
  ('warden2', '$2y$10$.kNr/5M/YarQwStCOE1IoOZvjCYPlRSRWUrTWBBCR.Zk4w2wxrb2G', 'warden', 'male', 'BOYS HOSTEL 1', 'Boys Hostel 1 Warden', 1, 1),
  ('warden3', '$2y$12$fx6zOwaIrh.8GRMFoI46RuZoi/.stwpoiRK8ZapDUqhy.OH/U9oEi', 'warden', 'female', 'NEW GIRLS HOSTEL', 'New Girls Hostel Warden', 1, 1),
  ('warden4', '$2y$12$rtp8GKuy7FD492BozT43uO09c6Jbw.VO4sPwucFje6psllExVZ9hq', 'warden', 'female', 'OLD GIRLS HOSTEL', 'Old Girls Hostel Warden', 1, 1)
ON DUPLICATE KEY UPDATE
  role = VALUES(role),
  warden_gender = VALUES(warden_gender),
  warden_hostel = VALUES(warden_hostel),
  name = VALUES(name),
  is_active = VALUES(is_active);

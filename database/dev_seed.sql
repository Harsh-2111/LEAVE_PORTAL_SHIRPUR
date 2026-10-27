USE `hostel_leave`;

INSERT INTO `students` (`sap_id`, `name`, `year`, `branch`, `batch`, `hostel_block`, `room_no`, `parent_name`, `parent_email`, `parent_contact`, `is_active`)
VALUES
  ('12345678901', 'Aarav Mehta', '2nd Year', 'BTECH CS', 'A1', 'A', '101', 'Rahul Mehta', 'rahul.mehta@example.com', '9876543210', 1),
  ('12345678902', 'Ira Shah', '3rd Year', 'BTECH IT', 'E1', 'C', '204', 'Nisha Shah', 'nisha.shah@example.com', '9812345678', 1),
  ('12345678903', 'Karan Singh', '1st Year', 'BTECH CE', 'C1', 'B', '115', 'Sanjay Singh', 'sanjay.singh@example.com', '9898123456', 1),
  ('12345678904', 'Meera Joshi', '4th Year', 'BTECH AI-ML', 'F1', 'D', '308', 'Vikram Joshi', 'vikram.joshi@example.com', '9734567890', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `year` = VALUES(`year`),
  `branch` = VALUES(`branch`),
  `batch` = VALUES(`batch`),
  `hostel_block` = VALUES(`hostel_block`),
  `room_no` = VALUES(`room_no`),
  `parent_name` = VALUES(`parent_name`),
  `parent_email` = VALUES(`parent_email`),
  `parent_contact` = VALUES(`parent_contact`),
  `is_active` = VALUES(`is_active`);

INSERT INTO `users` (`login_id`, `password_hash`, `role`, `name`, `must_change_password`, `is_active`)
VALUES
  ('admin', '$2y$10$rqkxOUM4E2tebT.lfEJxHugg/KBxjHEAUo/uzvARlzhWlWA7kH2Hy', 'admin', 'System Admin', 1, 1),
  ('warden1', '$2y$10$jUaW1ritsJSxfLPk8/B2cOIHmh3OxpaGTh.teqBrgppv96qpTPgd2', 'warden', 'Hostel Warden', 1, 1),
  ('security', '$2y$10$5ptS8Sc4gx/R74KFYkF7Ku7/lH2Y0J3KezlLkSu1vZdRza9cd.GFe', 'security', 'Security Officer', 1, 1),
  ('12345678901', '$2y$10$yIIsyIpQX7wy8Cy24Dd1Z.XQaLF.hVvY28/418VORhhDtrzlwA5Mu', 'student', 'Aarav Mehta', 1, 1),
  ('12345678902', '$2y$10$yIIsyIpQX7wy8Cy24Dd1Z.XQaLF.hVvY28/418VORhhDtrzlwA5Mu', 'student', 'Ira Shah', 1, 1),
  ('12345678903', '$2y$10$yIIsyIpQX7wy8Cy24Dd1Z.XQaLF.hVvY28/418VORhhDtrzlwA5Mu', 'student', 'Karan Singh', 1, 1),
  ('12345678904', '$2y$10$yIIsyIpQX7wy8Cy24Dd1Z.XQaLF.hVvY28/418VORhhDtrzlwA5Mu', 'student', 'Meera Joshi', 1, 1)
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

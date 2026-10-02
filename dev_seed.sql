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

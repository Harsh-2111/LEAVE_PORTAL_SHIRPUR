INSERT INTO public.students (sap_id, name, student_contact, gender, course, year, branch, batch, hostel_block, room_no, parent_name, parent_email, parent_contact, is_active)
VALUES
  ('12345678901', 'Aarav Mehta', '9822000001', 'male', 'BTech', '2nd Year', 'BTECH CS', 'A1', 'A', '101', 'Rahul Mehta', 'rahul.mehta@example.com', '9876543210', TRUE),
  ('12345678902', 'Ira Shah', '9822000002', 'female', 'BTech', '3rd Year', 'BTECH IT', 'E1', 'C', '204', 'Nisha Shah', 'nisha.shah@example.com', '9812345678', TRUE),
  ('12345678903', 'Karan Singh', '9822000003', 'male', 'BTech', '1st Year', 'BTECH CE', 'C1', 'B', '115', 'Sanjay Singh', 'sanjay.singh@example.com', '9898123456', TRUE),
  ('12345678904', 'Meera Joshi', '9822000004', 'female', 'BTech', '4th Year', 'BTECH AI-ML', 'F1', 'D', '308', 'Vikram Joshi', 'vikram.joshi@example.com', '9734567890', TRUE)
ON CONFLICT (sap_id) DO UPDATE SET
  name = EXCLUDED.name,
  student_contact = EXCLUDED.student_contact,
  gender = EXCLUDED.gender,
  course = EXCLUDED.course,
  year = EXCLUDED.year,
  branch = EXCLUDED.branch,
  batch = EXCLUDED.batch,
  hostel_block = EXCLUDED.hostel_block,
  room_no = EXCLUDED.room_no,
  parent_name = EXCLUDED.parent_name,
  parent_email = EXCLUDED.parent_email,
  parent_contact = EXCLUDED.parent_contact,
  is_active = EXCLUDED.is_active,
  updated_at = NOW();

INSERT INTO public.users (login_id, password_hash, role, warden_gender, name, must_change_password, is_active)
VALUES
  ('admin', '$2y$10$rqkxOUM4E2tebT.lfEJxHugg/KBxjHEAUo/uzvARlzhWlWA7kH2Hy', 'admin', NULL, 'System Admin', TRUE, TRUE),
  ('warden1', '$2y$10$jUaW1ritsJSxfLPk8/B2cOIHmh3OxpaGTh.teqBrgppv96qpTPgd2', 'warden', 'male', 'Hostel Warden', TRUE, TRUE),
  ('warden_girls', '$2y$10$jUaW1ritsJSxfLPk8/B2cOIHmh3OxpaGTh.teqBrgppv96qpTPgd2', 'warden', 'female', 'Girls Hostel Warden', TRUE, TRUE),
  ('security', '$2y$10$5ptS8Sc4gx/R74KFYkF7Ku7/lH2Y0J3KezlLkSu1vZdRza9cd.GFe', 'security', NULL, 'Security Officer', TRUE, TRUE),
  ('12345678901', '$2y$10$yIIsyIpQX7wy8Cy24Dd1Z.XQaLF.hVvY28/418VORhhDtrzlwA5Mu', 'student', NULL, 'Aarav Mehta', TRUE, TRUE),
  ('12345678902', '$2y$10$yIIsyIpQX7wy8Cy24Dd1Z.XQaLF.hVvY28/418VORhhDtrzlwA5Mu', 'student', NULL, 'Ira Shah', TRUE, TRUE),
  ('12345678903', '$2y$10$yIIsyIpQX7wy8Cy24Dd1Z.XQaLF.hVvY28/418VORhhDtrzlwA5Mu', 'student', NULL, 'Karan Singh', TRUE, TRUE),
  ('12345678904', '$2y$10$yIIsyIpQX7wy8Cy24Dd1Z.XQaLF.hVvY28/418VORhhDtrzlwA5Mu', 'student', NULL, 'Meera Joshi', TRUE, TRUE)
ON CONFLICT (login_id) DO UPDATE SET
  password_hash = EXCLUDED.password_hash,
  role = EXCLUDED.role,
  warden_gender = EXCLUDED.warden_gender,
  name = EXCLUDED.name,
  must_change_password = EXCLUDED.must_change_password,
  is_active = EXCLUDED.is_active;

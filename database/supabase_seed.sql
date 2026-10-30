INSERT INTO public.users (login_id, password_hash, role, warden_gender, name, must_change_password, is_active)
VALUES
  ('admin', '$2y$10$dbeNIL9CJeN6QAnMF6cGdecpD4.rbcC0NTZPCWWxw4RgUXhlglTVS', 'admin', NULL, 'System Admin', TRUE, TRUE),
  ('warden1', '$2y$10$xAwZzF.xW8AZlVdXtJQ3ueTrEwJr.HrHjHC.KEpITd1K6KI00clbi', 'warden', 'male', 'Boys Hostel Warden', TRUE, TRUE),
  ('warden2', '$2y$10$.kNr/5M/YarQwStCOE1IoOZvjCYPlRSRWUrTWBBCR.Zk4w2wxrb2G', 'warden', 'male', 'Boys Hostel Warden', TRUE, TRUE),
  ('security', '$2y$10$wVWraGzAoO.9/4n2pGW4b.ovSCj6sHrOfoxxJFSFhrGgBrcT9iFCS', 'security', NULL, 'Security Officer', TRUE, TRUE)
ON CONFLICT (login_id) DO UPDATE SET
  password_hash = EXCLUDED.password_hash,
  role = EXCLUDED.role,
  warden_gender = EXCLUDED.warden_gender,
  name = EXCLUDED.name,
  must_change_password = EXCLUDED.must_change_password,
  is_active = EXCLUDED.is_active;

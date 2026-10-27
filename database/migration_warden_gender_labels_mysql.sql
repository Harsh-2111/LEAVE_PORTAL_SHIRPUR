USE `hostel_leave`;

UPDATE users
SET warden_gender = 'male',
    warden_hostel = CASE login_id
      WHEN 'warden1' THEN 'NEW BOYS HOSTEL'
      WHEN 'warden2' THEN 'BOYS HOSTEL 1'
    END,
    name = 'Boys Hostel Warden'
WHERE login_id IN ('warden1', 'warden2')
  AND role = 'warden';

UPDATE users
SET warden_gender = 'female',
    warden_hostel = CASE login_id
      WHEN 'warden3' THEN 'NEW GIRLS HOSTEL'
      WHEN 'warden4' THEN 'OLD GIRLS HOSTEL'
    END,
    name = CASE login_id
      WHEN 'warden3' THEN 'New Girls Hostel Warden'
      WHEN 'warden4' THEN 'Old Girls Hostel Warden'
    END
WHERE login_id IN ('warden3', 'warden4')
  AND role = 'warden';

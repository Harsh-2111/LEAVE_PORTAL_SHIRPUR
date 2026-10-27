BEGIN;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS warden_hostel VARCHAR(40);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conrelid = 'public.users'::regclass
      AND conname = 'users_warden_hostel_check'
  ) THEN
    ALTER TABLE public.users
      ADD CONSTRAINT users_warden_hostel_check
      CHECK (warden_hostel IS NULL OR warden_hostel IN (
        'BOYS HOSTEL 1',
        'NEW BOYS HOSTEL',
        'NEW GIRLS HOSTEL',
        'OLD GIRLS HOSTEL'
      ));
  END IF;

  IF EXISTS (SELECT 1 FROM public.users WHERE login_id = 'warden_girls' AND role = 'warden') THEN
    IF EXISTS (SELECT 1 FROM public.users WHERE login_id = 'warden2' AND role = 'warden') THEN
      UPDATE public.users SET is_active = FALSE WHERE login_id = 'warden_girls' AND role = 'warden';
    ELSE
      UPDATE public.users SET login_id = 'warden2' WHERE login_id = 'warden_girls' AND role = 'warden';
    END IF;
  END IF;

  IF EXISTS (SELECT 1 FROM public.users WHERE login_id IN ('warden1', 'warden2', 'warden3', 'warden4') AND role <> 'warden') THEN
    RAISE EXCEPTION 'A required warden login ID is already assigned to a non-warden account.';
  END IF;

  IF NOT EXISTS (SELECT 1 FROM public.users WHERE login_id = 'warden1' AND role = 'warden')
    OR NOT EXISTS (SELECT 1 FROM public.users WHERE login_id = 'warden2' AND role = 'warden') THEN
    RAISE EXCEPTION 'Existing warden1 and warden2 accounts must be present before applying this migration.';
  END IF;
END
$$;

UPDATE public.users
SET warden_gender = 'male',
    warden_hostel = 'NEW BOYS HOSTEL'
WHERE login_id = 'warden1' AND role = 'warden';

UPDATE public.users
SET warden_gender = 'male',
    warden_hostel = 'BOYS HOSTEL 1'
WHERE login_id = 'warden2' AND role = 'warden';

UPDATE public.leave_requests
SET hostel = UPPER(TRIM(hostel))
WHERE UPPER(TRIM(hostel)) IN (
  'BOYS HOSTEL 1',
  'NEW BOYS HOSTEL',
  'NEW GIRLS HOSTEL',
  'OLD GIRLS HOSTEL'
);

UPDATE public.leave_requests lr
SET hostel = UPPER(TRIM(s.hostel_block))
FROM public.students s
WHERE s.sap_id = lr.sap_id
  AND (lr.hostel IS NULL OR TRIM(lr.hostel) = '')
  AND UPPER(TRIM(s.hostel_block)) IN (
    'BOYS HOSTEL 1',
    'NEW BOYS HOSTEL',
    'NEW GIRLS HOSTEL',
    'OLD GIRLS HOSTEL'
  );

INSERT INTO public.users
  (login_id, password_hash, role, warden_gender, warden_hostel, name, must_change_password, is_active)
VALUES
  ('warden3', '$2y$12$fx6zOwaIrh.8GRMFoI46RuZoi/.stwpoiRK8ZapDUqhy.OH/U9oEi', 'warden', 'female', 'NEW GIRLS HOSTEL', 'New Girls Hostel Warden', TRUE, TRUE),
  ('warden4', '$2y$12$rtp8GKuy7FD492BozT43uO09c6Jbw.VO4sPwucFje6psllExVZ9hq', 'warden', 'female', 'OLD GIRLS HOSTEL', 'Old Girls Hostel Warden', TRUE, TRUE)
ON CONFLICT (login_id) DO UPDATE SET
  role = EXCLUDED.role,
  warden_gender = EXCLUDED.warden_gender,
  warden_hostel = EXCLUDED.warden_hostel,
  name = EXCLUDED.name,
  is_active = TRUE;

COMMIT;

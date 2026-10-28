ALTER TABLE public.students
  ADD COLUMN IF NOT EXISTS official_email_id VARCHAR(255);

ALTER TABLE public.students
  ADD COLUMN IF NOT EXISTS mother_contact VARCHAR(20);

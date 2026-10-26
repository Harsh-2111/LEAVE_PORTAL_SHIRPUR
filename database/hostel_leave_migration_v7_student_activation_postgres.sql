ALTER TABLE public.students
  ADD COLUMN IF NOT EXISTS student_email VARCHAR(255);

CREATE INDEX IF NOT EXISTS idx_students_student_email
  ON public.students (student_email);

CREATE TABLE IF NOT EXISTS public.student_activation_tokens (
  user_id BIGINT NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at TIMESTAMPTZ NOT NULL,
  activated_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  PRIMARY KEY (user_id)
);

CREATE INDEX IF NOT EXISTS idx_student_activation_expires
  ON public.student_activation_tokens (expires_at);

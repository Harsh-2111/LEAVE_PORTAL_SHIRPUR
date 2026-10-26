CREATE TABLE IF NOT EXISTS public.app_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.app_migrations WHERE version = 'v6_student_passwords'
    ) THEN
        UPDATE public.users
        SET must_change_password = TRUE,
            updated_at = NOW()
        WHERE role = 'student';

        DELETE FROM public.app_sessions;

        INSERT INTO public.app_migrations (version)
        VALUES ('v6_student_passwords');
    END IF;
END
$$;

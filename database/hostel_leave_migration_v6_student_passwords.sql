CREATE TABLE IF NOT EXISTS app_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

START TRANSACTION;
SET @apply_v6_student_passwords = NOT EXISTS (
    SELECT 1 FROM app_migrations WHERE version = 'v6_student_passwords'
);

INSERT INTO app_migrations (version)
SELECT 'v6_student_passwords' WHERE @apply_v6_student_passwords = 1;

UPDATE users
SET must_change_password = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE role = 'student'
  AND @apply_v6_student_passwords = 1;

DELETE FROM app_sessions
WHERE @apply_v6_student_passwords = 1;
COMMIT;

USE `hostel_leave`;

SET @official_email_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'students'
    AND column_name = 'official_email_id'
);

SET @official_email_migration = IF(
  @official_email_column_exists = 0,
  'ALTER TABLE students ADD COLUMN official_email_id VARCHAR(255) DEFAULT NULL AFTER parent_email',
  'SELECT 1'
);

PREPARE official_email_migration_statement FROM @official_email_migration;
EXECUTE official_email_migration_statement;
DEALLOCATE PREPARE official_email_migration_statement;

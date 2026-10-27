USE `hostel_leave`;

SET @mother_contact_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'students'
    AND column_name = 'mother_contact'
);

SET @mother_contact_migration = IF(
  @mother_contact_column_exists = 0,
  'ALTER TABLE students ADD COLUMN mother_contact VARCHAR(20) DEFAULT NULL AFTER parent_contact',
  'SELECT 1'
);

PREPARE mother_contact_migration_statement FROM @mother_contact_migration;
EXECUTE mother_contact_migration_statement;
DEALLOCATE PREPARE mother_contact_migration_statement;

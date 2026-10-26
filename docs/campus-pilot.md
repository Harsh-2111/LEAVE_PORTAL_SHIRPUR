# Campus pilot checklist

## Before importing real student records

- Take a database backup and test the process in a separate staging project first.
- Confirm the campus is authorized to use the student and guardian information in this portal. Do not send the real roster through chat or commit it to Git.
- Keep database credentials and Supabase service-role keys on the server only. Do not put them in frontend files or share them with students.
- Start with the provided CSV template and a small synthetic test file, then verify student login, password change, leave submission, Warden review, gate scanning, and exports.
- Wardens are currently scoped by student gender (Boys/Girls), not by individual hostel block. Confirm that this matches the campus' staff responsibilities before onboarding students.

## Database update

Run `database/migration_leave_request_room_postgres.sql` once in Supabase SQL Editor before deploying this version. It adds a nullable room field to leave requests so students can enter their room when applying. For MySQL, use `database/migration_leave_request_room_mysql.sql`.

The student importer uses temporary passwords and does not need an activation migration. If you already ran the previous activation migration, leave its unused student email column and token table in place; do not rerun or manually drop them.

## Roster import

The Admin importer accepts UTF-8 CSV only (not `.xlsx`), up to 2,500 data rows and 2 MB per upload. In Excel, use **Save As → CSV UTF-8**. Check that SAP IDs and parent phone numbers remain intact; format these columns as text if Excel removes leading zeroes. Use the header row from `frontend/student_password_template.csv`. Student phone, batch, and room number are optional and can be omitted.

Valid records are committed in one transaction. Invalid rows are skipped and reported by CSV row number. A normal roster re-import updates profiles without changing existing student passwords. A SAP ID already used by a staff account is rejected instead of changing that account's role.

For each new student account, the importer creates a unique 8-character temporary password in the form `stud@` plus three random digits, stores only its hash, and downloads the SAP ID/password pairs as a CSV after the database transaction succeeds. Students must change their password at first login. Because this format has only 1,000 combinations, an import cannot issue more than 1,000 unique temporary passwords. The optional **Reset existing student passwords** checkbox also replaces passwords for matching existing student accounts and signs all users out; use it only when intentionally onboarding or resetting that roster.

Treat the downloaded credential CSV as sensitive: keep it only on an Admin-controlled device, distribute each student's password privately, do not post the file or passwords in a group chat, and securely delete the file after distribution. Do not assign one shared password to the whole roster. This short format is easier to guess than a stronger random password, so use it only for initial sign-in and have students change it immediately. Test first with a few authorized test accounts; for accounts that were imported during previous testing, select **Reset existing student passwords** to generate credentials for them.

## Capacity and operations

The importer writes profiles in batches and is designed for a pilot roster up to 2,500 rows. Warden pending and follow-up queues are paginated; approved-history tables also load one page at a time. Run a full 600-student synthetic pilot on the actual deployment and database plan before production use. Monitor PHP request timeouts, database connection limits, backups, and Supabase usage; capacity depends on the hosting tier and concurrent traffic, not only the student count.

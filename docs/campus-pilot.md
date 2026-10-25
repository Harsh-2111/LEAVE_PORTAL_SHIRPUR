# Campus pilot checklist

## Before importing real student records

- Take a database backup and test the process in a separate staging project first.
- Confirm the campus is authorized to use the student and guardian information in this portal. Do not send the real roster through chat or commit it to Git.
- Keep database credentials and Supabase service-role keys on the server only. Do not put them in frontend files or share them with students.
- Start with the provided CSV template and a small synthetic test file, then verify student login, password change, leave submission, Warden review, gate scanning, and exports.
- Wardens are currently scoped by student gender (Boys/Girls), not by individual hostel block. Confirm that this matches the campus' staff responsibilities before onboarding students.

## Database update

For the existing PostgreSQL/Supabase installation, apply `database/hostel_leave_migration_v6_student_passwords_postgres.sql` in the Supabase SQL editor, after confirming the `users` and `app_sessions` tables exist. It requires every existing student account to change its password and clears active sessions so existing logins cannot bypass the new requirement. This signs all users out. The migration records its completion and will not repeat that reset on later runs.

For a MySQL installation, use `database/hostel_leave_migration_v6_student_passwords.sql` once instead. Back up the database first.

## Roster import

The Admin importer accepts UTF-8 CSV only (not `.xlsx`), up to 2,500 data rows and 2 MB per upload. In Excel, use **Save As → CSV UTF-8**. Check that SAP IDs and phone numbers remain intact; format these columns as text if Excel removes leading zeroes. Keep the header names from `frontend/student_template.csv`.

Valid records are committed in one transaction. Invalid rows are skipped and reported by CSV row number. A roster re-import updates profiles and reactivates matching student accounts without resetting their passwords. A SAP ID already used by a staff account is rejected instead of changing that account's role.

For each genuinely new student account, the importer creates a random temporary password, stores only its password hash, and marks the account as requiring a password change. On the initial campus roster import, an explicit confirmation option can also rotate passwords for every existing student account represented in that CSV. This clears all active sessions, so everyone must sign in again. Use that option only when ready to distribute the new credential sheet; ordinary re-imports leave existing passwords alone. After the import, the browser downloads a CSV with SAP IDs and issued temporary passwords. Treat it as a sensitive one-time credential sheet: transfer it through a campus-approved private channel, do not email it as a normal attachment or store it in a shared folder, and securely delete local copies after distribution. If the download is lost, do not re-import expecting passwords to be regenerated; use the established account-recovery process.

## Capacity and operations

The importer writes profiles in batches and is designed for a pilot roster up to 2,500 rows. Warden pending and follow-up queues are paginated; approved-history tables also load one page at a time. Run a full 600-student synthetic pilot on the actual deployment and database plan before production use. Monitor PHP request timeouts, database connection limits, backups, and Supabase usage; capacity depends on the hosting tier and concurrent traffic, not only the student count.

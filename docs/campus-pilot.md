# Campus pilot checklist

## Before importing real student records

- Take a database backup and test the process in a separate staging project first.
- Confirm the campus is authorized to use the student and guardian information in this portal. Do not send the real roster through chat or commit it to Git.
- Keep database credentials and Supabase service-role keys on the server only. Do not put them in frontend files or share them with students.
- Start with the provided CSV template and a small synthetic test file, then verify student login, password change, leave submission, Warden review, gate scanning, and exports.
- Leave start and return times are campus local time (IST); verify same-day and overnight requests with the college before onboarding.
- Wardens are currently scoped by student gender (Boys/Girls), not by individual hostel block. Confirm that this matches the campus' staff responsibilities before onboarding students.

## Database update

Run `database/migration_leave_request_contact_address_times_postgres.sql` in Supabase SQL Editor before deploying this version. It adds the nullable leave start/return times and home address. For MySQL, use `database/migration_leave_request_contact_address_times_mysql.sql`. Also apply `database/migration_leave_request_room_postgres.sql` (or its MySQL equivalent) if the room-number migration has not already been run.

For an existing database, also apply the gate-attendance indexes to speed up date-specific reports and attendance filters. These index migrations assume the gate timestamp columns already exist; if not, run the existing gate attendance migration first. In Supabase, run `database/migration_gate_attendance_indexes_postgres.sql` in SQL Editor. For MySQL, run this from the project directory:

```powershell
Get-Content -Raw .\database\migration_gate_attendance_indexes_mysql.sql | docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" hostel_leave'
```

No additional database migration is required for student self-signup. The importer updates roster records only; it no longer creates or resets student passwords. Existing temporary-password accounts can be claimed through signup after the student's SAP ID and parent contact details are matched. If you already ran the previous activation migration, leave its unused student email column and token table in place; do not rerun or manually drop them.

## Roster import

The Admin importer accepts UTF-8 CSV only (not `.xlsx`), up to 2,500 data rows and 2 MB per upload. In Excel, use **Save As → CSV UTF-8**. Check that SAP IDs and parent phone numbers remain intact; format these columns as text if Excel removes leading zeroes. Use the header row from `frontend/student_roster_template.csv`. Student phone, batch, and room number are optional and can be omitted.

Valid records are committed in one transaction. Invalid rows are skipped and reported by CSV row number. A normal roster re-import updates profiles without changing existing student passwords. A SAP ID already used by a staff account is rejected instead of changing that account's role.

The importer validates each row, reports missing or invalid details, and updates student records without touching account passwords. Students select **Sign Up** on the student login page, provide their SAP ID plus the parent contact and email recorded in the roster, then create and confirm a password of at least 8 characters. Setup verification expires after 10 minutes and repeated failed identity checks lock signup for three hours. Existing accounts created by the earlier importer can use signup once to replace their temporary password; accounts with an already-set password should sign in or use **Forgot password** instead.

The parent contact and email check is a roster match, not a one-time verification code. Treat the student roster as confidential and only import records that the college has authorized the portal to use. Test first with a few authorized test accounts.

## Capacity and operations

The importer writes profiles in batches and is designed for a pilot roster up to 2,500 rows. Warden pending and follow-up queues are paginated; approved-history tables also load one page at a time. Gate attendance refresh pauses in hidden tabs and checks for updates once per minute while visible. Run a full 600-student synthetic pilot on the actual deployment and database plan before production use. Monitor PHP request timeouts, database connection limits, backups, and Supabase usage; capacity depends on the hosting tier and concurrent traffic, not only the student count. Database indexes and lighter polling help, but they do not increase the connection or compute limits of the selected hosting plan.

# Campus pilot checklist

## Before importing real student records

- Take a database backup and test the process in a separate staging project first.
- Confirm the campus is authorized to use the student and guardian information in this portal. Do not send the real roster through chat or commit it to Git.
- Keep database credentials and Supabase service-role keys on the server only. Do not put them in frontend files or share them with students.
- Start with the provided CSV template and a small synthetic test file, then verify student login, password change, leave submission, Warden review, gate scanning, and exports.
- Wardens are currently scoped by student gender (Boys/Girls), not by individual hostel block. Confirm that this matches the campus' staff responsibilities before onboarding students.

## Database update

For an existing PostgreSQL/Supabase installation, apply `database/hostel_leave_migration_v7_student_activation_postgres.sql` in the Supabase SQL editor before using the activation-email importer. It adds the student email field and one-time token table. If you created a new database from the updated `supabase_schema.sql`, you do not need this migration.

For an existing MySQL installation, use `database/hostel_leave_migration_v7_student_activation.sql` once instead. New installations from the updated MySQL schema already include these objects.

## Roster import

The Admin importer accepts UTF-8 CSV only (not `.xlsx`), up to 2,500 data rows and 2 MB per upload. In Excel, use **Save As → CSV UTF-8**. Check that SAP IDs and phone numbers remain intact; format these columns as text if Excel removes leading zeroes. Use the header row from `frontend/student_activation_template.csv`. Every student needs a valid official `@nmims.in` address in the `student_email` column.

Valid records are committed in one transaction. Invalid rows are skipped and reported by CSV row number. A roster re-import updates profiles and reactivates matching student accounts without resetting passwords or sending another email. A SAP ID already used by a staff account is rejected instead of changing that account's role.

For each genuinely new account, the importer creates a random unusable initial password, stores only its hash, and emails a single-use activation link to the student's official email. The student chooses their password on the activation page; the app never sends passwords by email. Links expire after 24 hours. If delivery fails, re-import the same CSV with **Resend activation links** selected; that sends new links only to accounts still awaiting activation and invalidates their previous links. Activated students are not reset.

### Configure activation email on Vercel

This implementation sends through Resend. Create a Resend account, add and verify an email-sending domain that the campus authorizes, and create an API key. Do not assume that owning an `@nmims.in` mailbox grants permission to send as that domain; campus IT/domain administrators must approve and configure it. In Vercel → Project → Settings → Environment Variables, configure these for Production, then redeploy:

| Variable | Value |
| --- | --- |
| `RESEND_API_KEY` | Private Resend API key; never put it in frontend files or Git |
| `ACTIVATION_FROM_EMAIL` | Sender name/address using the verified, campus-approved domain |
| `APP_BASE_URL` | Public site origin, for example `https://leaveportal-rose.vercel.app` (no trailing slash) |

Test the flow with a few authorized test accounts first. Confirm the import reports emails sent, verify the message arrives, open the link, set a password, then check that reusing the same link fails. The student's inbox must be reachable to activate the account.

## Capacity and operations

The importer writes profiles in batches and is designed for a pilot roster up to 2,500 rows. Warden pending and follow-up queues are paginated; approved-history tables also load one page at a time. Run a full 600-student synthetic pilot on the actual deployment and database plan before production use. Monitor PHP request timeouts, database connection limits, backups, and Supabase usage; capacity depends on the hosting tier and concurrent traffic, not only the student count.

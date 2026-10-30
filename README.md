# Hostel Leave App

This project is a PHP + MySQL application with a vanilla JavaScript UI and Docker-based development environment.

## Prerequisites

- Docker Desktop
- WSL2 enabled on Windows
- A local Docker engine available to the terminal

## Run the project

1. Open a terminal in the project root.
2. Run:

```bash
docker compose up -d --build
```

3. Open the app in your browser:
   - App: http://localhost:8080
   - phpMyAdmin: http://localhost:8081

## Useful commands

### Stop the app

```bash
docker compose down
```

### Reset the database completely

```bash
docker compose down -v
```

### Back up the database

```bash
docker compose exec db mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" hostel_leave > backup.sql
```

## Database setup

The project is organized into `frontend/`, `backend/`, and `database/`. Current schemas and bootstrap seeds live in `database/`; upgrade scripts are grouped under `database/migrations/`.

## Vercel deployment with Supabase

The application supports both local MySQL and Supabase PostgreSQL. Docker keeps using MySQL by default; set `DB_DRIVER=pgsql` on Vercel. Vercel uses the included `vercel.json` and `vercel-php` runtime. All API requests are routed through one `api/index.php` function, and PHP sessions are stored in the database so they work across serverless requests.

1. Create a Supabase project and open **SQL Editor**.
2. Run `database/supabase_schema.sql`, then run `database/supabase_seed.sql` to create the tables and staff bootstrap accounts. Student records are added through the Admin CSV importer. Do not use the MySQL schema/seed files for Supabase.
3. In the Supabase dashboard, choose **Connect → Transaction pooler**. Copy the host, port, username, and password from the connection details. Supabase recommends this mode for serverless functions; its default port is `6543` and the shared-pooler username is usually `postgres.<project-ref>`.
4. In Vercel **Project → Settings → Environment Variables**, set these for **Production**:

| Variable | Value |
| --- | --- |
| `DB_DRIVER` | `pgsql` |
| `DB_HOST` | Supabase transaction-pooler host from Connect |
| `DB_PORT` | Supabase transaction-pooler port, usually `6543` |
| `DB_NAME` | `postgres` (or the database shown in the connection string) |
| `DB_USER` | Supabase pooler username from Connect |
| `DB_PASS` | Supabase database password |
| `DB_SSLMODE` | `require` |
| `QR_HMAC_SECRET` | A unique, long random value |
| `HOSTEL_EMAIL` | Your hostel contact email |
| `APP_ENV` | `production` |
| `CRON_SECRET` | A long random value used to authorize scheduled history cleanup |

5. Redeploy the latest commit. The PHP client uses emulated prepares for the Supabase transaction pooler and requires SSL. The browser calls the API through `/api/index.php?endpoint=...`; root-level PHP URLs are not function routes.

Vercel runs the protected leave-history cleanup at 00:00 UTC on the first day of January, March, May, July, September, and November. It removes previous-period leave requests and related call/audit history in one database transaction. Set `CRON_SECRET` in Vercel so only the scheduled job can run it.

Do not use the direct database host if it is unreachable from Vercel, and do not use any placeholder values from `.env.example` as production credentials. Keep database passwords and QR secrets in Vercel, not in Git or chat. Local Docker continues using MySQL by default; set `DB_DRIVER=mysql` there.

## Optional portal features

For an existing database, see the migration instructions below before applying an upgrade. Leave policies are unrestricted until an administrator configures a limit.

Students can cancel their own pending or approved leave requests before gate exit. Admins can manage announcements and configure optional hostel policies. Students and wardens can view announcements and a read-only leave calendar. The admin audit-history screen has been removed; internal audit records remain in use by gate and security workflows.

Wardens receive notifications only for leave requests assigned to their hostel group. Notifications include the student's name, SAP ID, year, hostel block, and room when available. Selecting a new-leave notification opens that exact pending request for review. Notification updates are polled while the portal is visible; overdue-return notices are generated when a relevant student or warden checks notifications. No external email or SMS service is used.

The warden's Approved Leave History section also provides current-week (Monday through Sunday) and current-calendar-month CSV reports. Reports include approved leave details, scheduled dates, gate exit and entry times, return timing, and the security staff recorded for gate scans.

Forgot-password access is available only to students. Admins can reset an active warden account from the Admin Portal; the generated temporary password is displayed once, and the warden must set a new password at the next login. Share temporary passwords securely. This reset feature uses the existing users and audit tables and requires no database migration.

## Staff bootstrap accounts

Choose the matching role on the login screen:

| Role | Login ID | Initial password |
| --- | --- | --- |
| Boys warden | `warden1` | `warden@123` |
| Girls warden | `warden2` | `warden@123` |
| Admin | `id-admin` | `admin@123` |
| Security | `security` | `security@123` |

These are initial staff credentials included for setup. Change them before deploying the application for real use. Student accounts are created through student signup after the Admin imports the roster.

## Leave workflow

Students sign in with their SAP ID, submit a leave request, and see its status in their history. Their profile is loaded from the student database; course controls the school selection, and gender controls hostel choices. The request appears only in the matching boys' or girls' warden queue. The warden records the parent call outcome; a confirmed request receives a gate pass and locally rendered QR code, which Security can verify.

If a parent cannot be reached or the person answering is not the parent, the warden must enter a reason. The leave remains `Pending Verification` for the student but moves out of the Warden's main pending list into **Show Not Reached/Wrong person Leave**. From that follow-up table, a warden can approve or reject the request; a reason is required for either resolution. Approval grants the request and creates its gate pass; rejection changes its status to `Rejected`. Each call attempt and its remarks are retained in the call log.

The Admin importer updates student records only; it does not create passwords. Use the original roster's **Father E Mail** value in a `father_email` column in the CSV uploaded to the Admin portal. The generated `official_email_id` field is the student's email address and is not accepted as the father's email. The importer accepts `father_email`, `Father E Mail`, or the legacy `parent_email` header. If the generated CSV has no father's email column, add one and copy values from the original roster before importing. A corrected import updates existing student profiles; students then sign up with their SAP ID, either parent contact number, and father's email from the roster.

### Existing database volume

The `database/migrations/` folder contains two complete upgrade bundles: one for MySQL and one for PostgreSQL/Supabase. Each bundles schema updates together with legacy account/data changes.

**Important:** each complete bundle also changes staff credentials, maps warden assignments, and may require student password changes and clear sessions. Back up the database first. Do not run a bundle on a database that already has the desired schema/data state. The live Supabase database was previously verified to have the required current schema, so do not rerun this bundle there.

For an older MySQL database that still needs the bundled upgrades, run this once from the project directory:

```powershell
Get-Content -Raw .\database\migrations\mysql_upgrade.sql | docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" hostel_leave'
```

For an older Supabase database that still needs the bundled upgrades, review the script and run `database/migrations/supabase_upgrade.sql` once in the SQL Editor.

Docker runs the MySQL bundle only when a new database volume is initialized. Fresh installations already create current tables from `mysql_schema.sql` or `supabase_schema.sql`; student profiles are populated through the Admin CSV importer. The room number is collected on each leave request, so it is not required in the roster CSV. The importer does not require student email addresses or email-provider settings; any unused old activation tables/columns do not affect temporary-password imports.

## Production checklist

- Use HTTPS in front of the app
- Use strong passwords for DB and app credentials
- Set `APP_ENV=production`
- Do not expose phpMyAdmin in production
- Schedule daily database backups
- Keep `QR_HMAC_SECRET` in a secure environment secret manager

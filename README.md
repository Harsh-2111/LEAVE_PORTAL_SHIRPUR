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

The database is created automatically from the schema file in the project root and then seeded with dev data on first boot.

## Vercel deployment with Supabase

The application supports both local MySQL and Supabase PostgreSQL. Docker keeps using MySQL by default; set `DB_DRIVER=pgsql` on Vercel. Vercel uses the included `vercel.json` and `vercel-php` runtime. All API requests are routed through one `api/index.php` function, and PHP sessions are stored in the database so they work across serverless requests.

1. Create a Supabase project and open **SQL Editor**.
2. Run `supabase_schema.sql`, then run `supabase_seed.sql` to create the tables and demo accounts. Do not use the MySQL schema/seed files for Supabase.
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

5. Redeploy the latest commit. The PHP client uses emulated prepares for the Supabase transaction pooler and requires SSL. The browser calls the API through `/api/index.php?endpoint=...`; root-level PHP URLs are not function routes.

Do not use the direct database host if it is unreachable from Vercel, and do not use any placeholder values from `.env.example` as production credentials. Keep database passwords and QR secrets in Vercel, not in Git or chat. Local Docker continues using MySQL by default; set `DB_DRIVER=mysql` there.

## Demo accounts

Choose the matching role on the login screen:

| Role | Login ID | Initial password |
| --- | --- | --- |
| Student (Aarav Mehta) | `12345678901` | `Student@1234` |
| Student (Ira Shah) | `12345678902` | `Student@1234` |
| Student (Karan Singh) | `12345678903` | `Student@1234` |
| Student (Meera Joshi) | `12345678904` | `Student@1234` |
| Boys warden | `warden1` | `Warden@123` |
| Girls warden | `warden_girls` | `Warden@123` |
| Admin | `admin` | `Admin@123` |
| Security | `security` | `Security@123` |

## Leave workflow

Students sign in with their SAP ID, submit a leave request, and see its status in their history. Their profile is loaded from the student database; course controls the school selection, and gender controls hostel choices. The request appears only in the matching boys' or girls' warden queue. The warden records the parent call outcome; a confirmed request receives a gate pass and locally rendered QR code, which Security can verify.

The admin CSV import uses the columns in `student_template.csv`, including student contact, gender, course, year, parent email, and parent contact. Imported students receive an initial password `Student@` plus the last four digits of their SAP ID.

### Existing database volume

The MySQL entrypoint scripts run only when the database volume is first initialized. To apply the additive v3 migration to an existing volume, run this once from the project directory:

```powershell
Get-Content -Raw .\hostel_leave_migration_v3.sql | docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" hostel_leave'
```

This migration preserves existing requests and adds the new profile and warden-group fields. Existing non-demo student records need their new profile values populated through the updated CSV import before those students can submit requests.

## Production checklist

- Use HTTPS in front of the app
- Use strong passwords for DB and app credentials
- Set `APP_ENV=production`
- Do not expose phpMyAdmin in production
- Schedule daily database backups
- Keep `QR_HMAC_SECRET` in a secure environment secret manager

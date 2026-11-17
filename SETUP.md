# SETUP - LiteInsurance (Namecheap / cPanel)

## Deploy (shared hosting)
1) Upload `liteinsurance.php` (and optionally `index.html` and the `fonts/` folder) into `public_html/`.
2) In cPanel -> MultiPHP Manager, set PHP 8.1+ for your domain (8.3 recommended).
3) Ensure PDO + SQLite are enabled (common on shared hosting).
4) Permissions: `liteinsurance.php` = 0644, folders = 0755. The app needs to create:
   - `liteinsurance.db` (SQLite database)
   - `liteinsurance_error.log` (server-side error log)
   - `liteinsurance_uploads/` (CSV staging, created automatically)
5) Visit `https://YOURDOMAIN/liteinsurance.php` and register the first user (becomes `admin`).
6) Open Settings and review the setup doctor for PHP, PDO SQLite, DB path, upload path, upload limits, `.env`, and `CRON_TOKEN`.
7) Import & health -> “Download sample CSV” -> Upload -> Map columns -> Review -> Import.
8) Settings -> set currency, terminology and whether premiums are monthly or annual.
9) Settings -> Users & access: registration is invite-only by default. Open it while teammates create accounts, then close it again. Turn on "Require two-step verification" if you want every user on TOTP (Account page).
10) Settings -> Data retention: choose how long to keep staged uploads, audit events, contact history and long-expired policies. The daily cron applies it.

Real CSVs can use a mapped Record ID and optional Display name. Agency-system exports (Policy #, Effective/Expiration Date, Line of Business, Company, Agent/CSR, Customer ID, Primary Email/Phone) are recognised automatically. Re-import a newer export at any time: matching Record IDs are updated in place and renewal stage, owner, contact history and opt-outs are kept. Unmapped columns are preserved as custom fields and can be referenced later as `custom.field_key` in rules and segment filters.

## Optional: .env config (recommended)
Create `public_html/.env` (not committed) based on `.env.example`.
- `CRON_TOKEN` should be a long random string (32+ chars).
- You may set `DB_PATH` and `UPLOAD_DIR` if you want the DB/uploads in a different folder.

## Cron (cPanel)
Use cron to recompute LTV/risk tiers without long web requests:
- Command (recommended):
  - `php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN`
- Schedule example:
  - Daily at minimum (renewal windows and `days_to_expiry` rules move every day); every 15 minutes for busy books.

## Backups
- Back up `liteinsurance.db` regularly (download via File Manager or cPanel backups).
- If you use `.env`, back it up securely (it contains secrets).

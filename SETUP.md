# SETUP - LiteInsurance (Namecheap / cPanel)

## Deploy (shared hosting)
1) Upload `liteinsurance.php` (and optionally `index.html`) into `public_html/`.
2) In cPanel -> MultiPHP Manager, set PHP 8.0+ for your domain.
3) Ensure PDO + SQLite are enabled (common on shared hosting).
4) Permissions: `liteinsurance.php` = 0644, folders = 0755. The app needs to create:
   - `liteinsurance.db` (SQLite database)
   - `liteinsurance_error.log` (server-side error log)
   - `liteinsurance_uploads/` (CSV staging, created automatically)
5) Visit `https://YOURDOMAIN/liteinsurance.php` and register the first user (becomes `admin`).
6) Open Settings and review the setup doctor for PHP, PDO SQLite, DB path, upload path, upload limits, `.env`, and `CRON_TOKEN`.
7) Import -> “Download sample CSV” -> Upload -> Map columns -> Preview -> Confirm & import.

Real CSVs can use a mapped Record ID and optional Display name. Unmapped columns are preserved as custom fields and can be referenced later as `custom.field_key` in rules and segment filters.

## Optional: .env config (recommended)
Create `public_html/.env` (not committed) based on `.env.example`.
- `CRON_TOKEN` should be a long random string (32+ chars).
- You may set `DB_PATH` and `UPLOAD_DIR` if you want the DB/uploads in a different folder.

## Cron (cPanel)
Use cron to recompute LTV/risk tiers without long web requests:
- Command (recommended):
  - `php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN`
- Schedule example:
  - Every 15 minutes, or nightly depending on data volume.

## Backups
- Back up `liteinsurance.db` regularly (download via File Manager or cPanel backups).
- If you use `.env`, back it up securely (it contains secrets).

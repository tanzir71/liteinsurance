# LiteInsurance

Portable, single-file PHP 8+ + SQLite dashboard for importing policyholder CSVs, computing LTV, applying JSON risk rules, building segments, and simulating campaigns.

- App entry: `liteinsurance.php`
- Landing: `index.html`
- Setup: `SETUP.md`
- Security notes: `SECURITY.md`
- Repo: https://github.com/tanzir71/liteinsurance

## Quick start
1) Upload `liteinsurance.php` into `public_html/`.
2) Visit `/liteinsurance.php` and register the first user (admin).
3) Import -> “Download sample CSV” -> Upload -> Map -> Preview -> Confirm & import.

## Configuration
- Optional `.env` (see `.env.example`):
  - `CRON_TOKEN` (recommended for web cron invocation)
  - `DB_PATH`, `UPLOAD_DIR` (move DB/uploads outside webroot when possible)

## Cron
Use cPanel cron for scheduled recompute:
- `php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN`

## Data & privacy
By default, UI masks names in lists. Treat the SQLite DB as sensitive and back it up securely.

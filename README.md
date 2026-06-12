# LiteInsurance

Self-hosted insurance operations dashboard for turning policyholder CSV exports into LTV estimates, risk tiers, segments, and campaign simulations.

LiteInsurance is intentionally small: one PHP 8 file, SQLite persistence, no build step, and no vendor cloud by default.

## Try It

- Landing page: `index.html`
- No-install demo: `demo.html` starts with seed data, then lets evaluators upload a local CSV, preserve and edit custom CSV fields, and edit risk rules visually or as JSON locally.
- Production app: `liteinsurance.php`

## Quick Start

1. Upload `liteinsurance.php` into `public_html/`.
2. Visit `/liteinsurance.php` and register the first user. The first user becomes admin.
3. Open `Settings` and review the setup doctor for PHP, SQLite, path, upload, and cron readiness.
4. Open `Import`, choose `Load sample rules + data`, then preview and confirm the import.
5. Explore Dashboard, Profiles, Rules, Segments, Campaigns, and Settings.

## What It Does

| Area | Capability |
|---|---|
| Import | CSV upload, Record ID mapping, custom CSV fields, preview, staged commit |
| Data quality | Mean/mode imputation, imputed flags, confidence score |
| LTV | Premium, remaining term, policy multiplier, retention adjustment |
| Risk | Priority-ordered visual or JSON rules with a rule tester |
| Segments | Rule IDs plus validated WHERE-style filters, including `custom.field_key` |
| Campaigns | Cross-sell rate, offer value, contact cost, lift, net revenue |
| Ops | Audit log, setup doctor, cron recompute, SQLite backup path |
| Security | CSRF, CSP, sessions, rate limits, upload hardening, PII masking |

## Files

| File | Purpose |
|---|---|
| `liteinsurance.php` | Single-file PHP + SQLite app |
| `index.html` | Commercial landing page |
| `demo.html` | Browser-only demo with 200-policy seed data, local CSV upload, editable custom fields, visual/JSON rules, and local storage |
| `docs.html` | Deployment and operating docs |
| `compare.html` | Honest comparison guide |
| `SETUP.md` | cPanel/shared-hosting setup notes |
| `SECURITY.md` | Security controls and hardening checklist |

## Configuration

Optional `.env` keys:

- `CRON_TOKEN`: recommended for web cron invocation
- `DB_PATH`: move SQLite DB outside webroot when hosting allows
- `UPLOAD_DIR`: move staged uploads outside webroot when hosting allows

## Cron

Use cPanel cron or shell cron to recompute LTV/risk tiers:

```sh
php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN
```

## Privacy

By default, UI lists mask names and the app keeps data in SQLite on your own server. Treat `liteinsurance.db`, `.env`, and staged uploads as sensitive files and back them up securely.

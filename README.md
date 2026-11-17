# LiteInsurance

Self-hosted insurance operations dashboard for turning policyholder CSV exports into LTV estimates, risk tiers, segments, and campaign simulations.

LiteInsurance is intentionally small: one PHP file (8.1+, 8.3 recommended), SQLite persistence, no build step, and no vendor cloud by default.

v1.1 added the day-to-day agency workflow; v1.2 adds two-step sign-in, user management, data retention and a demo that scores exactly like the app. Together: a renewal worklist and board, per-channel consent and opt-outs, re-imports that update in place, a plain-language list builder, and a "why" panel that explains every score.

## Try It

- Landing page: `index.html`
- No-install demo: `demo.html` starts with seed data, then lets evaluators upload a local CSV, preserve and edit custom CSV fields, and edit risk rules visually or as JSON locally.
- Production app: `liteinsurance.php`

## Quick Start

1. Upload `liteinsurance.php` into `public_html/`.
2. Visit `/liteinsurance.php` and register the first user. The first user becomes admin.
3. Open `Settings` and review the setup doctor for PHP, SQLite, path, upload, and cron readiness.
4. Open `Import`, choose `Load sample rules + data`, then preview and confirm the import.
5. Explore Today, Renewals, Book, Lists, Campaigns, Import & health, Scoring rules, and Settings.

## What It Does

| Area | Capability |
|---|---|
| Import | CSV upload, Record ID mapping, AMS header recognition, custom CSV fields, preview, staged commit, re-import updates in place |
| Renewals | Expiration worklist, stage board, owners, 120/90/75/45/30/15-day touchpoints, contact log, call list CSV, calendar (.ics) |
| Consent | Per-channel consent and opt-outs, suppression paste, consent-filtered exports and campaign reach |
| Data quality | Mean/mode imputation, imputed flags, confidence score |
| LTV | Premium, remaining term, policy multiplier, retention adjustment |
| Risk | Visual or JSON rules, highest priority first, optional `stop`, a rule tester, and a per-policy "why" panel |
| Lists | Plain-language builder over validated WHERE-style filters, including `days_to_expiry`, `policies_in_household` and `custom.field_key` |
| Campaigns | Channel reach, conversions, gross/net, ROI, break-even rate, saved scenarios |
| Ops | Audit log + CSV export, data inventory, setup doctor, cron recompute, SQLite backup path |
| Security | CSRF, `'self'`-only CSP, sessions, rate limits, optional TOTP two-step sign-in, invite-only registration, upload hardening, PII masking with audited reveal |
| Privacy | Retention rules (uploads, audit log, contact history, expired policies), erase-a-person, full wipe, data inventory |

## Files

| File | Purpose |
|---|---|
| `liteinsurance.php` | Single-file PHP + SQLite app |
| `index.html` | Commercial landing page |
| `demo.html` | Browser-only demo of the App UI v2 screens (Today, Renewals, Book, Lists, Campaigns, Import & health, Scoring rules) with 200-policy seed data, local CSV upload, editable custom fields, visual/JSON rules, and local storage |
| `docs.html` | Deployment and operating docs |
| `compare.html` | Honest comparison guide |
| `SETUP.md` | cPanel/shared-hosting setup notes |
| `SECURITY.md` | Security controls and hardening checklist |
| `fonts/` | Optional self-hosted Inter + JetBrains Mono (SIL OFL); upload next to the PHP file |
| `LICENSE` | MIT |

## Configuration

Optional `.env` keys:

- `CRON_TOKEN`: recommended for web cron invocation
- `DB_PATH`: move SQLite DB outside webroot when hosting allows
- `UPLOAD_DIR`: move staged uploads outside webroot when hosting allows
- `CURRENCY_SYMBOL`: default currency symbol (also editable in Settings)

Currency, terminology, premium period (monthly/annual), LTV assumptions, consent policy and renewal cadence live in **Settings** and are stored in SQLite.

## Cron

Use cPanel cron or shell cron (daily) to recompute LTV, risk tiers and list sizes:

```sh
php /home/USER/public_html/liteinsurance.php action=cron_jobs cron_token=YOUR_TOKEN
```

## Tests

```sh
node test/commercial-readiness.test.mjs
node test/hotfix-regression.test.mjs
node test/demo-parity.test.mjs
```

All need `php` on PATH. The regression test boots `php -S` against a throwaway database (register, import, re-import, two-step sign-in, erasure, cron); the parity test checks the browser demo against `php liteinsurance.php action=parity_vectors`.

## Privacy

By default, UI lists mask names, revealing personal details is audited, and the app keeps data in SQLite on your own server. Treat `liteinsurance.db`, `.env`, and staged uploads as sensitive files and back them up securely.

## License

MIT — see [LICENSE](LICENSE). Bundled Inter font files are under the SIL Open Font License 1.1 (`fonts/OFL.txt`).

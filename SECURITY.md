# SECURITY - LiteInsurance

## Security fixes applied
- SQL injection: removed unsafe dynamic SQL in segment filtering; filters are parsed into a safe AST and compiled into SQL with placeholders (no raw WHERE concatenation).
- SQL injection: removed SQL string concatenation in cron segment updates (prepared statement).
- XSS: added `htmlEscape()` and ensured UI outputs escape reflected content.
- CSRF: server verifies CSRF on all POST requests and returns 403 for programmatic requests without a valid token.
- Auth hardening: session cookies are `HttpOnly` + `SameSite=Lax`, strict session mode enabled, and `session_regenerate_id(true)` is called after login and register.
- Session timeout: enforced idle timeout (default 30 minutes).
- Rate limiting: added SQLite-backed rate limiting for login/registration and import upload (per IP windowed counters).
- Upload hardening: `.csv` extension required, MIME checked (best-effort), randomized stored filenames, and uploads folder is denied via `.htaccess` + `index.html`.
- Error handling: fatal errors/exceptions are logged server-side (JSON lines) to `liteinsurance_error.log`; stack traces are not shown to users.
- Security headers: CSP (nonce-based), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, COOP/CORP, and HSTS (HTTPS only).
- v1.1: POST actions take precedence over query-string actions (fixes the register/login loop) and every state change is POST + CSRF; JSON endpoints (`segment_count`, `policy_cell_update`, `rule_test`) are admin-only.
- v1.1: inline event handlers removed so the nonce CSP no longer has to be loosened; confirmations use `data-confirm`.
- v1.1 privacy: names, emails and phones are masked in list views; revealing them on a policy is recorded as `pii.reveal` in the audit log. Exports (list CSV, call list, .ics, audit CSV) are audited and list exports are filtered by channel consent; opt-outs are always excluded from outreach exports.
- v1.2 sign-in: optional TOTP two-step verification (RFC 6238, SHA-1, 6 digits, ±1 step, replay-protected), 8 one-time recovery codes stored as SHA-256 hashes, rate-limited verification, admin "require for everyone" switch and per-user reset. Self-registration is closed by default after the first admin.
- v1.2 privacy: retention rules (staged uploads, audit log, contact history, expired/inactive policies) run with cron; erase-a-person deletes matching policies and their contact history and audits only a hash of the identifier; staged CSVs are deleted after import.
- v1.2 CSP: fonts are self-hosted, so the policy is `'self'` only (no Google Fonts allowances).
- v1.1 roles: non-admin users can work renewals (stage, owner, contact log, consent) and read the Book; import, rules, lists, campaigns, settings and inline edits stay admin-only.

## Rotating secrets / keys
- `CRON_TOKEN` (recommended): set in `.env` and rotate if it may be exposed. Update your cPanel cron line to match.
- If you rotate `.env`, treat it as a secret file and store it outside of version control.

## Logging controls
- Errors are written to `liteinsurance_error.log` as JSON lines.
- Avoid logging PII: keep `DEBUG=false` in `liteinsurance.php` and do not add request/body dumps in production.

## Production hardening checklist (recommended)
- Enforce HTTPS (TLS) at the hosting layer; ensure HSTS is enabled only on HTTPS.
- Keep self-registration closed (default) and require two-step verification for everyone in Settings -> Users & access.
- Add WAF / bot protection for `/liteinsurance.php` on the domain (Cloudflare, ModSecurity).
- Move uploads/DB outside webroot when possible (`UPLOAD_DIR`, `DB_PATH` in `.env`).
- Consider upgrading persistence to Postgres/MySQL for multi-user concurrency at scale.

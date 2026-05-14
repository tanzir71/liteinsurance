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

## Rotating secrets / keys
- `CRON_TOKEN` (recommended): set in `.env` and rotate if it may be exposed. Update your cPanel cron line to match.
- If you rotate `.env`, treat it as a secret file and store it outside of version control.

## Logging controls
- Errors are written to `liteinsurance_error.log` as JSON lines.
- Avoid logging PII: keep `DEBUG=false` in `liteinsurance.php` and do not add request/body dumps in production.

## Production hardening checklist (recommended)
- Enforce HTTPS (TLS) at the hosting layer; ensure HSTS is enabled only on HTTPS.
- Restrict admin registration: keep the admin as the first user only and disable public registration if needed (code change).
- Add WAF / bot protection for `/liteinsurance.php` on the domain (Cloudflare, ModSecurity).
- Move uploads/DB outside webroot when possible (`UPLOAD_DIR`, `DB_PATH` in `.env`).
- Consider upgrading persistence to Postgres/MySQL for multi-user concurrency at scale.

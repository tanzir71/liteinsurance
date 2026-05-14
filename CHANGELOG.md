# CHANGELOG

## 2026-05-14
- Security hardening: CSP + security headers, server-side error logging, session strict mode + idle timeout.
- Auth protections: CSRF enforcement improvements and SQLite-backed rate limiting for login/registration and uploads.
- SQL injection fixes: removed unsafe dynamic WHERE usage for segment filters; compiled filters use placeholders.
- Upload protections: enforced `.csv` + MIME checks, randomized stored filenames, and uploads directory access denied.
- UX: added Docs/Security links in app footer and a minimal landing page (`index.html`).

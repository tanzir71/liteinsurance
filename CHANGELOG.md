# CHANGELOG

## 2026-06-12
- Rebuilt the landing page with the Volt/TinyProctor reference design system.
- Added browser-only `demo.html`, `docs.html`, `compare.html`, `robots.txt`, `sitemap.xml`, and `llms.txt`.
- Replaced the five-row sample CSV with a deterministic 200-policy generator.
- Seeded demo-friendly rules, segments, and sample campaign simulations.
- Restyled the PHP dashboard with a square, ruled-paper compatibility CSS layer.
- Added commercial readiness checks under `test/`.

## 2026-05-14
- Security hardening: CSP + security headers, server-side error logging, session strict mode + idle timeout.
- Auth protections: CSRF enforcement improvements and SQLite-backed rate limiting for login/registration and uploads.
- SQL injection fixes: removed unsafe dynamic WHERE usage for segment filters; compiled filters use placeholders.
- Upload protections: enforced `.csv` + MIME checks, randomized stored filenames, and uploads directory access denied.
- UX: added Docs/Security links in app footer and a minimal landing page (`index.html`).

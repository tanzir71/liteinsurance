# CHANGELOG

## 2026-11-17 — v1.2
Added
- Two-step sign-in (TOTP, RFC 6238) with one-time recovery codes, an Account page (password change, enrol/disable), and an admin switch to require it for everyone.
- Users & access in Settings: make admin/user, reset two-step, remove users, and open or close self-registration. **Registration is now invite-only by default** once the first admin exists; existing installs keep working, but new staff need an admin to open registration.
- Data retention (staged uploads, audit log, contact history, long-expired or inactive policies) applied by the daily cron or on demand, plus "Erase a person" and "Delete all policy data". Staged CSVs are deleted as soon as an import commits.
- Demo parity: `demo.html` now runs a shared scoring engine (`<script id="li-engine">`) that mirrors the PHP import pipeline, with the v1.1 screens (Today worklist, Renewals board, Book, Lists, Campaigns with consent reach). `test/demo-parity.test.mjs` proves the demo's sample CSV is byte-identical to the app's and that all 200 policies get the same tier, confidence, rule hits and LTV on three dates.
- Self-hosted fonts in `fonts/` (Inter and JetBrains Mono, SIL OFL 1.1). Google Fonts removed from the app and every page; the app's CSP is now `'self'` only.
- MIT `LICENSE`.
- Sample book gains `agent_code`, `renewal_probability` and `source_channel` custom columns (also used by the demo).

Changed
- `demo.html` rebuilt to match the App UI v2 design: navy sidebar (Today, Renewals, Book, Lists, Campaigns, Import & health, Scoring rules), a top bar with search (`/`) and per-screen actions, and the designed screens: KPI cards and renewal worklist with Opportunities, Data health and Consent; a Renewals board grouped by the 120/90/75/45/30/15-day cadence; a Book table with bulk status changes and a "Why this tier" panel (rule conditions, LTV formula, confidence, consent); a plain-language list builder with consent filters and a live result; Campaigns with a funnel, break-even and saved scenarios; and Import & health with column mapping. Masked names use `•` and can be revealed per policy; sensitive sample values stay masked in the mapping table.


## 2026-11-17 — v1.1
Fixes
- Registration and sign-in no longer loop on a fresh install: POST actions now take precedence over the query string, and auth forms post to an explicit URL.
- Money values no longer render as `262145…`: the `CURRENCY_SYMBOL` constant collided with a PHP built-in; it is now `LI_CURRENCY_SYMBOL`, overridable in Settings or `.env`.
- Filters with the value `0` (e.g. `days_to_expiry >= 0`) no longer fail with "Invalid token".
- Numeric rule/filter comparisons no longer match blank values (a blank age is not `< 30`).
- Imputed values are rounded and categorical values keep consistent casing; age derived from DOB is no longer counted as imputed.
- Inline `onclick`/`onsubmit` handlers (blocked by the CSP) replaced with `data-confirm` / `data-rule-test`, so delete confirmations and the rule tester work.
- Right-aligned table columns now actually align right.

Added
- New navigation built around the agency day: Today, Renewals, Book, Lists, Campaigns, Import & health, Scoring rules, Settings.
- Today: KPIs, renewal worklist (30/60/90/120 days), opportunities (single-line households, renewal premium jumps, top LTV), data health, consent summary, first-run checklist.
- Renewals board with stages, owners, touchpoint cadence, consent-aware call-list CSV and calendar (.ics) export.
- Book: saved views, policy panel with masked PII + audited reveal, consent editing, contact log, and a "why" panel (rule hits, LTV formula, confidence deductions).
- Lists: plain-language builder that compiles to the validated filter grammar, presets, live consent-aware counts, channel-filtered exports.
- Campaigns: channel reach from consent, conversions, gross/net, ROI, break-even rate, saved scenarios.
- Settings: currency, terminology pack, premium period, LTV assumptions, consent policy, renewal cadence, suppression paste, data inventory, audit-log CSV export.
- Import: renewal, contact and consent fields; AMS header recognition; re-imports update in place (premium changes kept as prior premium); data-health card.
- Rules: highest priority runs first, first tier setter wins (same outcome as before), optional `stop`, match counts.
- Additive SQLite migration (`PRAGMA user_version = 11`): new policy columns, `settings` and `contact_log` tables. Existing databases upgrade on first load.
- Sample book now includes effective/expiration dates, status, line of business, carrier, producer, household, email/phone and consent columns (existing columns unchanged).
- Landing page v2 (`index.html`) and `test/hotfix-regression.test.mjs`.
- Restyled app: navy sidebar, white top bar, 4px radii, new logo.


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

# Build Brief: Local-First, Single-File "Lite" Targeting & Segmentation App

This is a generic, domain-agnostic specification for an in-browser data tool, written so it
can be handed to an LLM to build an equivalent app for a different sector. It abstracts a
working reference app (a healthcare-professional targeting tool) into reusable concepts, then
maps them to the insurance domain. Build the generic engine first; apply the domain mapping last.

---

## 1. Product concept

A **single self-contained HTML file** (no build step, no server, no external runtime
dependencies beyond optional web fonts) that runs entirely in the browser. It lets a
non-technical user:

1. **Import** their own tabular data (CSV).
2. **Normalize / enrich** it automatically (fill gaps, score data quality).
3. **Edit** records inline like a spreadsheet.
4. **Define rules** that classify and score records.
5. **Build segments** (saved filters) over the enriched data.
6. **Simulate** an outcome (e.g. campaign ROI) over a segment.
7. **Export** results back to CSV.

All data stays in the browser (`localStorage`). It ships with **deterministic seed data** so
the app is fully functional on first load; real imported data replaces the seed. Position it as
a lightweight, privacy-first alternative to a heavy enterprise platform.

> If there is also a "real" backend version (e.g. a single-file PHP + SQLite app), the in-browser
> demo should **mirror the backend's logic 1:1** — same rule JSON format, same field semantics,
> same segment filter grammar — so the demo is a faithful preview, not a separate codebase.

---

## 2. Data model

- **Fixed/standard fields**: a known set of columns the app understands natively (id, name, plus
  domain attributes). **Only `id` and `name` are required**; any field can be chosen from any
  column during import mapping.
- **Custom fields (the flexibility key)**: any imported column that doesn't map to a standard
  field is stored per-record in a **schema-less bag** (`custom.<name>`). No schema migration is
  needed. Custom fields are **first-class everywhere downstream**: shown in the grid, editable,
  usable in rules (`custom.<name>`), usable in segment filters, sortable, and exported.
- **Imputation + confidence**: missing standard fields are filled with sensible defaults and
  flagged as "imputed"; each imputation lowers a per-record **confidence score (0–100)**. Nothing
  is silently guessed — imputed values are visually marked, and segments can demand a minimum
  confidence ("strict mode").
- **Derived fields** (computed by the rules engine, not stored as input): a category/persona, a
  numeric priority score, a compliance/eligibility flag, and a list of tags.

---

## 3. Core features (build these as the generic engine)

### 3.1 CSV import with flexible mapping
- Accept file drop, file picker, and pasted CSV text.
- Quote-aware CSV parser (handles commas/newlines inside quoted fields).
- Column-mapping UI: auto-match headers to standard fields (exact + fuzzy/alias match);
  unmatched columns offered as custom fields (with editable names); columns can be ignored.
- Upsert-by-id semantics; validate that id + name resolve.

### 3.2 Rules engine
- Rules have: name, integer **priority** (higher runs first), **conditions** (JSON), **actions** (JSON).
- **Condition grammar**: leaf `{field, op, value}`; ops = `=, !=, >, >=, <, <=, in, contains, regex`;
  grouping via nested `{all:[...]}` / `{any:[...]}` or `{match:"AND|OR", conditions:[...]}`;
  `fields` may be standard fields, `tags`, or `custom.<name>`; `continue_on_match:false` stops the chain.
- **Actions**: set category/persona, set priority score, add priority delta, set compliance flag,
  add tags.
- Evaluate all rules in priority order per record; recompute **live** on any edit; track per-rule
  **hit counts**. Also compute a base priority from a few weighted signals before rules run.

### 3.3 Segments (saved filters)
- SQL-like `WHERE` grammar over standard + derived + `custom.*` fields, combined with `AND`/`OR`.
- A "strict" mode that additionally requires confidence ≥ threshold.
- Show member counts and member lists; export a segment to CSV.
- (Backend note: store filters in human-readable `custom.x` form; translate to the storage query
  — e.g. `json_extract(metadata,'$.custom.x')` — only at execution time. Degrade gracefully if the
  storage engine lacks the needed capability.)

### 3.4 Outcome simulator
- Pick a segment, set a few rate/cost/value inputs, compute funnel + ROI live.

### 3.5 Dashboard
- KPI cards (record count, rule count, segment count, average priority) and simple bar charts
  (category distribution, an attribute distribution, data-quality gaps).

### 3.6 Persistence
- Serialize a **slim** version of state (un-impute before saving so imputation re-derives on load)
  to `localStorage` under a versioned key. Load on boot; fall back to deterministic seed data;
  offer "reset to sample."

---

## 4. Spreadsheet-style editable grid (the "not a static site" layer)

Make the records table behave like Google Sheets / Airtable:

- **Cell selection** (click), **edit** (click-again / double-click / Enter / type-to-edit),
  **type-aware editors**: text, number, `datalist` autocomplete for enumerated fields, and a
  click/space **toggle** for booleans.
- **Keyboard navigation**: arrows to move, `Tab`/`Shift+Tab`, `Enter` (commit + move down),
  `Esc` (cancel), `Del` (clear cell), `Space` (toggle).
- Every edit **re-runs the rules engine and persists immediately**; derived columns update live.
- **Add row**, **add custom-field column**, **remove custom-field column**, **delete row** — inline.
- **Undo / redo**: hook a history stack into the **single persistence choke point** so every
  mutation is exactly one undo step (no per-action sprinkling). Wire `Ctrl+Z` / `Ctrl+Shift+Z` /
  `Ctrl+Y` plus toolbar buttons; disable buttons when stacks are empty; suppress history while
  restoring.
- **Clipboard**: copy a cell; **paste a TSV block straight from Excel/Sheets**, writing across
  rows/columns from the selected cell with per-column type coercion; treat a paste as one undo step.
- **Export** the whole grid to CSV including derived + custom columns.

**Gotcha to avoid**: when a cell's edit-commit handler and a global key-navigation handler both
listen for the same key (e.g. `Enter`), stop event propagation in the edit handler so committing
doesn't immediately re-open/re-trigger via the global handler.

---

## 5. Mobile / responsive

- Mobile-first CSS: base styles target phones, `min-width` media queries layer on larger screens.
- **Real hamburger menu**, not wrapped/shrunk links: a CSS checkbox toggle reveals a stacked
  dropdown with full-width 44–48px tap targets; animate the icon to an ✕; a tiny script closes the
  menu on link tap or outside click. (Wrapped nav links are the #1 "not optimized for mobile" tell.)
- Tabs become a single horizontally-scrollable strip instead of wrapping.
- Wide tables live in an `overflow-x:auto` container so they scroll without breaking the page.
- Tighten hero/section padding and scale down large headings on small screens.

---

## 6. Engineering & verification practices (replicate these too)

- **One self-contained file**; inline CSS/JS; no bundler; CDN/fonts optional and degradable.
- **Deterministic seeded sample data** (seeded PRNG) so output is reproducible.
- **Verify headlessly with a DOM simulator** (e.g. `jsdom`): boot the page, dispatch real
  click/keydown/paste events, and assert on `localStorage` + DOM — don't just eyeball.
  Keep a regression suite green (engine logic + grid interactions).
- **Validate the file** after every programmatic edit: syntax check the script and confirm the
  file is still valid UTF-8 (watch for tools that mangle multibyte characters / truncate large files;
  prefer atomic full-file rewrites if an editor proves unreliable).
- For responsive work, **render and screenshot at phone width** before claiming it's done
  (deploy to a live URL if local `file://` rendering is blocked).

---

## 7. Domain mapping → Insurance

Replace the generic concepts with insurance equivalents. Everything else stays the same.

| Generic concept            | Insurance equivalent (pick per use case)                                  |
|----------------------------|---------------------------------------------------------------------------|
| Record / profile           | Policyholder, lead/prospect, applicant, or claim                          |
| `id`, `name`               | Policy number / lead id, insured name                                     |
| Standard attributes        | Line of business, product, region/state, agent/producer, status, premium, renewal date, contact-consent |
| Custom fields              | Risk score, tier, lead source, prior claims count, credit band, territory |
| Category / persona         | Risk tier / segment (e.g. Preferred, Standard, High-risk; or Cross-sell, Win-back, Do-Not-Contact) |
| Priority score             | Lead score / propensity-to-bind / renewal-priority                        |
| Compliance / eligibility flag | Underwriting eligibility, regulatory contactability (TCPA/DNC), licensing-by-state |
| Tags                       | Campaign flags (e.g. `bundle_eligible`, `lapse_risk`, `nb_quote_open`)    |
| Rules                      | Eligibility / underwriting-triage / routing / scoring rules               |
| Segments                   | Books of business, campaign cohorts, renewal batches                      |
| Outcome simulator          | Campaign ROI, renewal-retention lift, or quote→bind funnel                |
| Confidence score           | Data-completeness score for a quote/lead                                  |

**Example insurance rules** (same JSON format):
- "No consent (TCPA) → Do Not Contact, priority 5, tag `suppressed`" (stops the chain).
- "Auto + renewal within 30 days → Renewal Priority, +15 priority, tag `renewal_soon`."
- "`custom.risk_score >= 80` → +10 priority, tag `high_value`."
- "Lapsed < 90 days → Win-back, tag `winback`."

**Example insurance segments** (same filter grammar):
- `line_of_business = 'auto' AND custom.risk_score >= 80 AND confidence_score >= 70`
- `status = 'lapsed' AND region = 'TX'`
- `renewal_priority >= 60`

---

## 8. Suggested build order

1. Data model + seed data + imputation/confidence.
2. Rules engine + segment filter parser (with unit tests).
3. localStorage persistence (single choke point).
4. CSV import + column mapping.
5. Dashboard, segments UI, simulator.
6. Spreadsheet-style editable grid.
7. Undo/redo + clipboard + full-grid export.
8. Mobile responsiveness + hamburger menu.
9. Headless verification suite throughout.

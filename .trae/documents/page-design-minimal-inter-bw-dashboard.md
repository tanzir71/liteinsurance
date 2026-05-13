# Page Design Spec — Minimal Inter Black/White UI

## Global (All Pages)
### Layout
- Desktop-first, centered content with max width 1120px.
- App shell uses CSS Grid:
  - 240px left nav + fluid main content.
  - Header fixed height (56px) at top.
- Spacing scale: 4 / 8 / 12 / 16 / 24 / 32.

### Meta Information
- Title pattern: `AppName — {Page/Tab}`
- Description: “Manage imports, rules, segments, and campaigns.”
- Open Graph: title mirrors page title; monochrome preview image optional.

### Global Styles
- Font: Inter, `font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;`
- Colors:
  - Background: `#ffffff`
  - Text: `#111111`
  - Muted text: `#555555`
  - Border: `#e6e6e6`
  - Focus ring: `#111111`
- Typography:
  - H1 24/32, H2 18/24, Body 14/20, Small 12/16.
- Components:
  - Buttons: black background + white text; hover darken; disabled uses 50% opacity.
  - Secondary button: white background + black border; hover light gray.
  - Inputs: 1px border, 10px radius, focus ring 2px.
  - Tables: border-collapse; zebra optional with very light gray (`#fafafa`).
- Feedback:
  - Error: black text with thin left border; keep monochrome.
  - Success: same style, different label text (no green).

### Interaction & States
- POST actions always come from a `<form>` including hidden `csrf_token`.
- Inline validation: show message below the field in small text.
- Confirm-destructive actions via lightweight dialog (native `<dialog>` or simple modal).

---

## Page 1 — Login
### Layout
- Single column, centered card (max 420px) with generous whitespace.

### Page Structure
1. Header area: app name.
2. Login card:
   - Title “Sign in”.
   - Form fields.
   - Primary action.
3. Footer text: “Session secured, CSRF protected.” (small).

### Sections & Components
- Login Form
  - Email/Username input (required)
  - Password input (required)
  - Hidden `csrf_token`
  - Submit button
- Error Banner
  - Renders above the form on invalid credentials/CSRF.

Responsive
- On small screens, card becomes full-width with 16px padding.

---

## Page 2 — Dashboard (Tabs: Import / Rules / Segments / Campaigns)
### Layout
- CSS Grid app shell:
  - Top header row.
  - Left nav column.
  - Main content area with tab header + content.

### Page Structure
1. Top Header
   - Left: app name.
   - Right: current user + “Logout” (POST form + CSRF).
2. Left Navigation
   - Vertical items: Import, Rules, Segments, Campaigns.
   - Active state: bold text + left border.
3. Main Content
   - Tab title + short helper text.
   - Primary actions row (e.g., “Upload CSV”, “New Rule”).
   - Content area (tables/forms).

### Sections & Components (by tab)

#### Tab: Import
- Upload Panel
  - File input (CSV)
  - Hidden `csrf_token`
  - Upload button
  - Notes: required headers and max file size (text only)
- Import Preview (after selecting file)
  - Show filename, row count (if available), header list.
- Import History Table
  - Columns: Created, Filename, Status, Rows, Error

#### Tab: Rules
- Rules List
  - Search input (client-side optional; server-side filter optional)
  - Table columns: Name, Enabled, Updated, Actions
- Rule Editor
  - Name
  - Definition JSON textarea (monospace)
  - Enabled toggle
  - Save button (POST + CSRF)

#### Tab: Segments
- Segments List
  - Table columns: Name, Updated, Actions
- Segment Editor
  - Name
  - Criteria JSON textarea (monospace)
  - “Estimate size” action (GET or POST; if POST then include CSRF)
  - Save/Delete actions

#### Tab: Campaigns
- Campaigns List
  - Table columns: Name, Status, Updated, Actions
- Campaign Editor
  - Name
  - Segment selection (multi-select list or checkbox list)
  - Status selector (draft/active/paused)
  - Save action

Responsive
- Below 900px:
  - Left nav collapses into a top horizontal tab bar.
  - Tables become horizontally scrollable with sticky first column optional.

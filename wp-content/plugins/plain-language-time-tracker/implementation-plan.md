# Plain Language Time Tracker — Comprehensive Review & Next Steps

## Context

Phases 1 (MVP) and 2 (Intelligence) are complete. Phase 3 (Polish) is partially done. Development has been incremental with many small changes — this review checks the work against `development-notes.md` and `project-overview.md`, identifies tech debt, and builds a coherent plan for what comes next.

---

## Part 1: Audit Summary

### What's Done (Phases 1–2 + partial Phase 3)
Core workflow complete: daily log → parse → review/verify → reports. Plus: client/project management, alias learning, project prediction, tags, billable rates, log archive, inline entry editing.

### Phase 3 Remaining
| Feature | Status |
|---|---|
| Archive projects | Done |
| Tag system | Done |
| Better reporting/visualization | Partial — reports exist, no charts |
| CSV export | Not started |
| Settings/preferences | Not started |

### Development Preferences Alignment
- **Aligned**: Vanilla JS, plain CSS, WordPress conventions, security, prefixing, comments ✅
- **OOP vs. Procedural**: `development-notes.md` says procedural, but plugin uses classes throughout. **Decision**: Keep classes as-is — they're simple namespace-like groupings, not complex OOP. Update dev notes to acknowledge.
- **Function size**: Some methods exceed the 20–30 line preference. These are naturally complex operations that would be harder to follow if split artificially.

---

## Part 2: Issues Found

### A. AJAX overuse
- **3 dead endpoints** never called by any JS: `pltt_get_daily_log`, `pltt_update_entry`, `pltt_get_clients`
- **5 endpoints on clients.php** use AJAX but immediately reload the page — defeats the purpose of AJAX. These should be standard form POSTs with nonce + redirect.
- `pltt_save_entries` (review screen) does AJAX then redirects — form POST would be simpler.
- **Keep AJAX only where it genuinely helps UX**: auto-save (`pltt_save_daily_log`), inline deletion (`pltt_delete_entry`, `pltt_delete_daily_log`), dynamic dropdowns (`pltt_get_projects`), mid-editing creation (`pltt_create_client`/`pltt_create_project` from review screen), process log (`pltt_process_log`).

### B. Alias system scalability
- `PLTT_Aliases::find_in_text()` loads ALL aliases into memory, loops in PHP
- **Fix**: Cache aliases in a transient, invalidate on alias CRUD

### C. Unbounded tag query
- `PLTT_Entries::get_all_tags()` — no LIMIT, no caching, grows with entry count
- **Fix**: Cache in a transient, invalidate on entry save

### D. No query caching
- Client list, project list, tag list queried fresh on every page load
- **Fix**: Transient caching with invalidation on CRUD

### E. Code duplication across database classes
- Identical CRUD + NULL handling patterns repeated in 4 classes
- **Fix**: Extract shared helpers into `helpers.php`

### F. Magic numbers
- 30-day prediction window, 0.7 confidence threshold, 50/20 pagination, 1500ms debounce
- **Fix**: Define as constants for now; some may become settings later

### G. Custom CSS duplicating WordPress admin styles
Tables and buttons already use WordPress classes correctly (`.widefat`, `.striped`, `.button-primary`). But several areas reinvent what WordPress provides out of the box:
- **Notices**: Custom `.pltt-notice`, `.pltt-notice-warning`, `.pltt-notice-info` duplicate WordPress `.notice` classes → remove custom, use WP built-in
- **Cards**: Custom `.pltt-card` duplicates WordPress `.card` class → replace
- **Delete links**: Custom `.pltt-link-danger` and `.pltt-delete-entry` styling when WordPress has `.delete` and `.button-link-delete` → use WP classes
- **Inline styles**: `clients.php` has inline `<style>` block (lines 189–227) that should be in admin.css, plus `style="width: 100%"` on inputs that should use `.regular-text`
- **Hint/help text**: Custom `.pltt-log-hint` styling when WordPress `.description` class exists
- Modals are custom-built — this is justified since WordPress admin has no standard modal component

### H. Minor items
- `PLTT_VERSION` still 1.0.0 — should reflect current state
- No foreign key constraints (acceptable — WordPress/dbDelta limitation)
- Single nonce for all AJAX (acceptable for single-user tool)

---

## Part 3: Implementation Plan (in order)

### Step 1: AJAX Simplification & Dead Code Removal

**Remove dead endpoints** (no JS calls them):
- `pltt_get_daily_log` — initial content rendered server-side
- `pltt_update_entry` — review uses batch save instead
- `pltt_get_clients` — all client lists rendered server-side

**Convert clients.php CRUD to form POSTs** (currently AJAX → page reload):
- `pltt_update_client` → standard form POST with nonce, redirect back
- `pltt_delete_client` → form POST with confirmation, redirect back
- `pltt_update_project` → form POST with nonce, redirect back
- `pltt_delete_project` → form POST with confirmation, redirect back
- Keep `pltt_create_client` and `pltt_create_project` as AJAX — they're also called from review.php modals where inline creation mid-editing is genuinely useful

**Convert save_entries to form POST**:
- `pltt_save_entries` → standard form submission, server processes and redirects to daily log
- Simpler than AJAX → brief message → JS redirect

**Files**:
- `includes/api/class-pltt-ajax.php` — remove dead handlers, keep AJAX for retained endpoints
- `templates/clients.php` — convert modals to form submissions for update/delete
- `assets/js/review.js` — remove AJAX save, let form submit naturally
- New form processing: add `admin_post` hooks for the converted endpoints (e.g., `admin_post_pltt_update_client`)
- Could add a new `includes/api/class-pltt-form-handlers.php` or handle in existing admin classes

### Step 2: CSS Cleanup — Use WordPress Built-in Classes

Replace custom CSS with WordPress admin equivalents where they exist. Reduces CSS maintenance and keeps styling consistent with the WordPress admin.

**Replace custom notices with WordPress `.notice` classes**:
- Remove `.pltt-notice`, `.pltt-notice-warning`, `.pltt-notice-info` from `admin.css`
- Update templates to use `<div class="notice notice-warning">`, `<div class="notice notice-info">`, etc.
- Files: `admin.css`, `review.php`, `reports.php`, `log-archive.php`

**Replace custom cards with WordPress `.card` class**:
- Remove `.pltt-card` styles from `admin.css`
- Update templates: change `class="pltt-card"` to `class="card"` (WordPress provides background, border, padding)
- May need minimal overrides for specific layout (flex grid for summary cards)
- Files: `admin.css`, `review.php`, `reports.php`, `log-archive.php`, `daily-log.php`

**Use WordPress delete/danger link classes**:
- Remove `.pltt-link-danger` from `log-archive.css`
- Remove custom `.pltt-delete-entry` color/hover styles from `review.css` — already uses `.button-link-delete`
- Files: `review.css`, `log-archive.css`, `log-archive.php`

**Move inline styles out of clients.php**:
- Move the `<style>` block (lines 189–227) into `admin.css`
- Replace `style="width: 100%"` on form inputs with WordPress `.regular-text` class
- Files: `clients.php`, `admin.css`

**Use WordPress `.description` class for help text**:
- Replace custom `.pltt-log-hint` styling with WordPress `.description` where appropriate
- Files: `daily-log.css`, `daily-log.php`

### Step 3: Caching & Performance

1. **Transient caching** for aliases, clients, projects, tags
   - Helper functions: `pltt_flush_alias_cache()`, `pltt_flush_client_cache()`, `pltt_flush_project_cache()`, `pltt_flush_tag_cache()`
   - Call invalidation from relevant CRUD methods
   - Files: all `includes/database/` classes, `includes/helpers.php`

2. **Optimize alias matching** — `find_in_text()` uses cached data instead of fresh DB query

3. **Bound the tag query** — cache `get_all_tags()` in a transient

### Step 4: Constants & Version

- Extract magic numbers to named constants in main plugin file:
  - `PLTT_PREDICTION_WINDOW_DAYS` (30)
  - `PLTT_CONFIDENCE_THRESHOLD` (0.7)
  - `PLTT_ENTRIES_PER_PAGE` (50)
  - `PLTT_LOGS_PER_PAGE` (20)
  - `PLTT_AUTOSAVE_DEBOUNCE_MS` (1500)
- Bump `PLTT_VERSION` to 1.1.0
- Files: `plain-language-time-tracker.php`, then update references throughout

### Step 5: Review Screen Visual Indicators

Two subtle visual treatments on the review screen:

**Archived project indicator**:
- Add CSS class to entry rows tied to archived projects
- Small "(Archived)" badge or muted row styling

**Unverified entry indicator**:
- Similar subtle treatment for entries that haven't been verified yet
- Helps quickly spot entries needing attention

**Summary card update**:
- Remove the "unverified" count row from summary cards
- Add "billable time" row to summary cards instead (more useful information)

Files: `templates/review.php`, `assets/css/review.css`, `includes/admin/class-pltt-review.php`

### Step 6: CSV Export

- Add "Export CSV" button to reports page
- Use a direct URL request (not AJAX) — `admin_post_pltt_export_csv` handler
- Reuse `PLTT_Entries::get_entries()` with same filters as current report view
- Stream CSV with `Content-Type: text/csv` and `Content-Disposition: attachment`
- Columns: date, start time, end time, duration, description, client, project, tags, billable, rate, amount
- Files: new handler in form handlers or AJAX class, `templates/reports.php`, `assets/js/reports.js`

### Step 7: Settings Page (personal tool scope)

This is a personal tool, not a product — settings should reflect what's actually useful day-to-day.

- New admin submenu page
- Settings:
  - Default billable status for new entries (yes/no)
  - Default hourly rate (used when client/project don't specify one)
  - Display preferences (time format 12h/24h, date format)
- Use WordPress Settings API (`register_setting`, `add_settings_section`, `add_settings_field`)
- Magic numbers (prediction window, confidence, pagination) stay as constants — not worth a UI for personal use
- Files: new `includes/admin/class-pltt-settings.php`, new `templates/settings.php`, main plugin file

### Step 8: Database Class Consolidation

- Extract shared CRUD helpers into `helpers.php`:
  - `pltt_db_insert($table, $data, $formats, $nullable_fields)`
  - `pltt_db_update($table, $id, $data, $formats, $nullable_fields)`
  - `pltt_db_delete($table, $id)`
- Refactor each DB class to use these helpers
- Files: `includes/helpers.php`, all `includes/database/` files

---

## Part 4: Future Work (beyond this plan)

These items are noted for the roadmap but don't need architectural preparation now. They'll be planned in detail when the time comes.

**Reports & filtering improvements**:
- Multi-select filtering (select multiple clients, projects, tags)
- Multi-select dropdowns with search fields and integrated include/exclude logic
- Improved reports page design and layout

**Table & layout improvements**:
- Better entry grouping (by day) in reports table
- Sortable table columns
- Clients page → standard WordPress-style table (currently two-column layout)
- Projects → standard table with pagination (list will grow over time)

**Project estimates**:
- Time-based or cost-based estimates per project
- Track progress against estimates in reports

**Other future features**:
- Calendar view
- Reporting visualizations/charts
- Gap detection, overlap handling
- Bulk entry updates
- Invoice tracking

---

## Part 5: Verification

After each step:
- **Step 1 (AJAX)**: Test all client/project CRUD on clients.php — create, edit, delete should work via form POST. Test review screen save — form submits and redirects. Test retained AJAX still works (auto-save, inline delete, dynamic dropdowns, create from review modal).
- **Step 2 (CSS)**: Visual check all screens — daily log, review, reports, clients, log archive. Notices, cards, delete links should look consistent with WordPress admin styling. No broken layouts. Verify no inline `<style>` remains in clients.php.
- **Step 3 (Caching)**: Load review page — alias predictions still work. Check reports — tag filter populates. Create/edit a client — cache refreshes, new data appears immediately.
- **Step 4 (Constants)**: Grep for old hardcoded values — should be replaced by constants. Version bump visible in plugin list.
- **Step 5 (Indicators)**: Create entry with archived project — visual indicator shows on review. View unverified entries — indicator shows. Summary cards show billable time instead of unverified count.
- **Step 6 (CSV)**: Apply filters on reports, click Export CSV, open in spreadsheet. Data matches filtered report view.
- **Step 7 (Settings)**: Change default billable status. Process new entries. Confirm new default applied.
- **Step 8 (DB consolidation)**: Full workflow test — log → parse → review → save → reports. All CRUD operations for clients, projects, entries, aliases still work.

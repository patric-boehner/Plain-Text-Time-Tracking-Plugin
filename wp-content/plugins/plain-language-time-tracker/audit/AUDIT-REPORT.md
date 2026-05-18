# Plain Language Time Tracker — Full Audit Report

_Generated 2026-05-17 from a 6-subagent deep review across security, optimization, and end-to-end traceability (UI → handlers → business logic → data → DB)._

This is the human-readable summary. The companion file **`TASK-BACKLOG.md`** in this folder turns every finding into a discrete, picker-friendly task with the exact file/line ranges and acceptance criteria a subagent needs to do the work.

---

## TL;DR — what we found

The plugin is **structurally healthy and security-conscious**. The senior-level patterns documented in memory (nonces, capability checks, allowlists, transactional wrappers, helper consolidation) are present and consistent across the AJAX and form-handler surfaces. **No critical security vulnerabilities exist.** Junior-level mistakes (raw `$_POST` echoes, unbounded queries, missing nonces) are absent.

That said, the auditors surfaced **three categories of real work**:

1. **A handful of moderate security hardening fixes** — most notably a phishing vector via an unused `pltt_error_message` query parameter, missing entry-date scoping when bulk-saving from the Review screen, and a couple of input-validation gaps where the documented hardening pattern (`pltt_validate_hourly_rate`, status allowlist) was forgotten.
2. **A meaningful pile of dead code, duplication, and N+1 queries** — ~600 LOC of safely-deletable code, repeated cap-check / notice-render / date-nav code across 4-9 sites each, and the Clients/Projects/Reports admin pages all run per-row `get_stats()` queries inside foreach loops.
3. **A small set of traceability defects** — most importantly a possible `PLTT_Tags::create()` arity mismatch between the AJAX and form paths, and a `PLTT_Daily_Log` class that mixes admin rendering with direct `$wpdb` access (it bypasses the data layer entirely). Plus a memory-vs-code drift on `DB_VERSION` (memory says 1.9.1; code is 1.9.4).

Headline counts:

| Category | Count |
|---|---|
| Security (Critical) | **0** |
| Security (High) | 3 |
| Security (Medium) | 13 |
| Security (Low) | 24 |
| Optimization (Dead / Duplication / N+1 / Complexity / Performance / Structural / Frontend) | **139** |
| Traceability (contract / signature / drift) | 14 |

---

## Section 1 — Security

### Threat model

Every entry point requires `manage_options`. There are no `wp_ajax_nopriv_*` handlers, no public-facing endpoints, no REST routes, and no `admin_init` action-link dispatchers. The plugin's attack surface is **authenticated WordPress administrators**, so most findings are framed as "what could a phishing or XSS-via-other-plugin chain accomplish?" rather than unauthenticated exploitation.

### Critical: 0

None. No code path enables unauthenticated access, code execution, capability escalation, or unrecoverable data loss outside admin intent.

### High: 3 (genuinely worth fixing soon)

| # | Where | Risk |
|---|---|---|
| **SEC-H1** | `templates/clients.php:57-60`, `templates/projects.php:52-54` — `?pltt_error_message=...` query param is echoed into a real admin notice (escaped, so not XSS) | **Phishing**: an admin clicking a crafted plugin link sees an attacker-controlled error banner inside the real WP admin chrome. No form handler ever sets this param — it's a dead branch begging to be removed. |
| **SEC-H2** | `includes/admin/class-pltt-review.php:233-316` (and the bulk-save handler at `includes/api/class-pltt-form-handlers.php:371-416`) — `PLTT_Review::save_entries()` accepts a JSON `entries[]` array where each row carries its own `entry_id`, and never verifies the row's `entry_date` matches the form's `$date`. | **Data integrity**: a forged or CSRF-chained submit could overwrite arbitrary entries on any date — change `start_time`, flip `billable`, or hide hours by marking them `billed=1`. |
| **SEC-H3** | Same bulk-save path — no cap on `count($entries)` and no regex validation of per-row `start_time`/`end_time` strings before they hit `date_create()`. | **Mass-corruption window**: the unbounded array + lax time validation lets a single forged POST mass-edit entries. |

### Medium: 13

Highlights (full list in `TASK-BACKLOG.md`):

- **SEC-M1** `pltt_set_nullable_fields()` interpolates raw column names; safe today (only static literals passed), but no allowlist guard — second-order SQLi risk if a future caller forwards user input. **Add an explicit allowlist inside the helper.**
- **SEC-M2** `handle_update_project` accepts an arbitrary `status` string — no allowlist. Memory says it should be `['active','archived']`.
- **SEC-M3** `pltt_sanitize_date()` silently falls back to **today** on invalid input. In destructive paths (`pltt_process_log` deletes the date's entries, `pltt_delete_daily_log`, `save_entries`) this means a bad/forged date wipes today's data instead of erroring. Add a `pltt_sanitize_date_strict()` variant for destructive contexts.
- **SEC-M4** `error_log()` at `includes/api/class-pltt-ajax.php:170` dumps full entry JSON (including `raw_text` and `description`, which may contain client work descriptions) into `wp-content/debug.log` when `WP_DEBUG_LOG` is on.
- **SEC-M5** All AJAX endpoints share a single nonce action (`pltt_ajax_nonce`). Destructive endpoints (`delete_entry`, `delete_daily_log`) should use per-action nonces.
- **SEC-M6** `update_entry_field` bypasses `PLTT_Entries::update()` and writes directly via `$wpdb->update` for `billable`/`billed`. Reads `duration_minutes`, computes, writes — no transaction, room for race.
- **SEC-M7** `pltt_validate_hourly_rate()` is documented as required "before storing any hourly rate" — it's not called in `pltt_create_client`, `pltt_create_project`, `handle_update_client`, or `handle_update_project`.
- **SEC-M8** Tag names have no length cap before INSERT IGNORE; MySQL silently truncates at varchar(100) and two near-identical 101-char tags collide on the truncated prefix.

### Low: 24

Mostly defense-in-depth: removing dead constants (`PLTT_INTERNAL_CLIENT_ID`), tightening the `return_to` validation at render time (not just at handler time), per-row data-attr exposure on the Clients/Projects tables (info disclosure to anyone who can read the DOM), `console.error` left in production, `WP_DEBUG && time()` cache-busting amplifying load on misconfigured prod. Catalogued in `TASK-BACKLOG.md`.

### Top 5 to fix first

1. **SEC-H1** — delete the `pltt_error_message` branch from `templates/clients.php` and `templates/projects.php`. ~5-line fix.
2. **SEC-H2** — add `$original->entry_date === $date` check in `PLTT_Review::save_entries()` per row.
3. **SEC-H3** — cap `count($entries)` and add `^\d{1,2}:\d{2}(:\d{2})?$` regex on start_time/end_time.
4. **SEC-M3** — add `pltt_sanitize_date_strict()` and use it in destructive paths.
5. **SEC-M4** — strip `description`/`raw_text` from the `error_log()` dump.

---

## Section 2 — Optimization

The optimization auditor cataloged **139 distinct findings** across seven categories. They cluster into a small number of "consolidation groups" that are best tackled together.

### Where the weight is

| Category | Count | Where it hurts most |
|---|---|---|
| Dead code | 48 | ~600 LOC across PHP helpers, methods, CSS rules, and JS handlers that have no callers. Safe to delete in one PR. |
| Duplication | 27 | 4–9× repeated capability checks, notice-rendering, URL-cleanup script blocks, billing-type badge code, date-nav widget logic. |
| N+1 queries / DB | 15 | Clients page, Projects page, and the Reports summary view each call `get_stats()` once per row. Tag sync inserts one row at a time. |
| Complexity | 10 | `PLTT_Reports::render()` is 234 LOC; `templates/reports.php` is 797 LOC; `templates/projects.php` is 690 LOC (260 of which is inline `<script>`). |
| Performance | 14 | DOM rebuilds in tag picker, multiple unscoped `document` click listeners, per-row data-attr bloat, `add_query_arg($_GET, ...)` passing raw superglobal. |
| Structural | 15 | Admin classes doing direct `$wpdb` queries (`PLTT_Daily_Log` is the worst offender), heavy logic in templates, magic strings everywhere, inline JS interpolated with translation strings. |
| Frontend (JS/CSS) | 10 | 4 `!important` overrides fighting WP admin styles, three separate `.pltt-date-nav` rule blocks in `admin.css`, multiple competing `document` keydown handlers. |

### Top 10 highest-impact changes

1. **OPT-N1+N2** — Add `PLTT_Entries::get_stats_by_client_bulk()` and `get_stats_by_project_bulk()`; refactor `templates/clients.php`, `templates/projects.php` to use them. Drops N queries → 1 each.
2. **OPT-N3** — Same treatment for the Reports summary allocation-stats loop.
3. **OPT-DUP18** — Extract the duplicated date-nav widget (open/close, keyboard nav, outside-click) from `reports.js` and `log-archive.js` into a shared `PLTT.dateNav()`. ~250 LOC dedupe.
4. **OPT-DUP5** — Replace the inline rate-resolution cascade in `PLTT_Review::format_entries_for_review()` (lines 144-160) with `pltt_resolve_billable_rate()`. The canonical helper exists; this is the last hand-rolled cascade.
5. **OPT-C1 + OPT-S3** — Split `PLTT_Reports::render()` (234 LOC) into `parse_input()`, `compute_summary_cards()`, `build_chart_data()`, etc.; pull computational sections out of `templates/reports.php` (797 LOC).
6. **Dead-code sweep** — delete OPT-D1–D47 in a single PR.
7. **OPT-DUP1 + OPT-DUP2 + OPT-DUP3 + OPT-DUP4** — Extract `pltt_render_admin_notices()`, URL-cleanup JS, and `verify_capability()` helper. Removes ~150 LOC.
8. **OPT-DUP22–25** — Collapse three `.pltt-date-nav` blocks, three group-header rules, four `.pltt-tag-pills` rules into single canonical declarations.
9. **OPT-DUP20** — Extract the inline JS from `templates/projects.php` and `templates/clients.php` into dedicated `.js` files (currently ~260 LOC inline per template).
10. **OPT-N8+N9** — Multi-VALUES INSERT in `PLTT_Tags::sync_entry_tags()` instead of one INSERT IGNORE per tag.

### Consolidation groups (the way to tackle the backlog)

- **Group A — "Verify and Reject" boilerplate**: DUP1, DUP2, DUP3, DUP4. One sweep, ~150 LOC removed.
- **Group B — "Date Nav Widget" unification**: DUP18, F4, F7. ~250 LOC removed.
- **Group C — "Bulk Stats" backend**: N1, N2, N3. Two new methods on `PLTT_Entries`.
- **Group D — "Dead Code sweep"**: D1–D27 (PHP) + D30–D47 (CSS/JS).
- **Group E — "Inline Template JS → Files"**: DUP20, S4, F10.
- **Group F — "Per-row update() refactor"**: C2, C3. Schema-driven sanitization map.
- **Group G — "Rate / Billing Type display helpers"**: DUP6, DUP10, DUP7.
- **Group H — "Inline Toggle handler"**: DUP19, F5. ~120 LOC removed.

---

## Section 3 — Traceability

I ran four layer-scoped agents (UI → request handlers → business logic → data access + database) and cross-checked their contracts. **Most contracts hold.** Below are the genuine defects and the contracts that need a documentation refresh.

### What lines up cleanly

- **Every UI AJAX action has a registered AJAX handler**, and every form `action` value has a registered `admin_post_*` handler. Layer-1 catalog cross-referenced against layer-2's registered actions: **zero missing handlers.**
- **Every nonce name in the UI matches the nonce action verified in the handler**: `pltt_save_entries`, `pltt_update_client`, `pltt_delete_client`, `pltt_update_project`, `pltt_delete_project`, `pltt_manage_tag` (shared for create/rename/delete), `pltt_bulk_tag_group`, plus the shared AJAX `pltt_ajax_nonce`. **All match.**
- **Allowlists are enforced where memory says they should be**: `recurring_period` against `PLTT_ALLOWED_RECURRING_PERIODS` in both AJAX create_project and form update_project; `billable`/`billed` strictly `=== 0|1` in `update_entry_field`; `field` parameter in `update_entry_field` against `['billable','billed','tags']`. **All present.**
- **`verify_request()` pattern** is used correctly (with early `return`) in every AJAX handler. **No bare calls.**
- **WP_Error in redirects** uses only `get_error_code()`, never `get_error_message()`, in both `handle_delete_client` and `handle_delete_project`. **Both correct.**
- **Schema vs. code**: every column the data layer references exists in the schema; no orphaned columns; no phantom columns; types align with `%s`/`%d`/`%f` formats. **No drift.**
- **No SQL injection**: every dynamic SQL value is bound via `prepare()`; every interpolated `orderby`/`order` value is whitelisted before splicing; every `$col_prefix`/`$entry_ref` interpolation uses only static literals from callers.

### Traceability defects (must-fix)

| # | Where | Issue |
|---|---|---|
| **TRC-1** | `includes/api/class-pltt-ajax.php:474` vs `includes/api/class-pltt-form-handlers.php:257` | `PLTT_Tags::create($tag_name)` (1 arg, AJAX) vs `PLTT_Tags::create($tag_name, $group_name)` (2 args, form). Layer-4 confirms the signature is `create(string $name, ?string $group_name = null): int\|false` — **default is present, so the contract holds**. Flagging as a discipline issue: both call sites should be uniform. |
| **TRC-2** | `includes/admin/class-pltt-daily-log.php` (entire class) | Eight methods reach directly into `$wpdb` instead of routing through a `PLTT_Daily_Logs` data-access class like the other tables. The admin layer is doing data work. Three docblocks (`save_log`, `get_all`, `count_all`) omit args the code actually accepts (`$preserve_processed`, `date_from`, `date_to`). |
| **TRC-3** | `includes/admin/class-pltt-review.php:139-161` | `format_entries_for_review()` reimplements the rate-resolution cascade inline instead of calling `pltt_resolve_billable_rate()`. Memory's OPT-M3 was supposed to consolidate this — this site was missed. |
| **TRC-4** | `includes/admin/class-pltt-review.php:269-273` | `pltt_time_to_minutes()` can return `false`. The parser calls (`includes/parser/class-pltt-time-parser.php:212-227`) check for `false`. `PLTT_Review::save_entries()` does not. `false - 540` evaluates to `-540` and is stored as a negative duration. |
| **TRC-5** | `includes/database/class-pltt-database.php:181-188` | `maybe_upgrade()` calls `create_tables()` (which writes `pltt_db_version` to `DB_VERSION`) **before** `migrate()` runs. If a migration step fails mid-flight, the option already says the new version and no retry happens. **Order bug.** |
| **TRC-6** | `includes/database/class-pltt-entries.php:246` | `PLTT_Entries::update()` always opens a transaction without nested-TX detection. `create()` correctly detects nesting via `SELECT @@in_transaction`. Latent risk if a future caller wraps `update()` in its own TX. |
| **TRC-7** | `includes/api/class-pltt-ajax.php:198-293` | `update_entry_field` writes directly via `$wpdb->update` for billable/billed instead of going through `PLTT_Entries::update()`. Skips the transactional wrapper. Currently fine because no nullable or tag columns are touched, but any future change to `PLTT_Entries::update()` will silently not apply to inline reports edits. |
| **TRC-8** | `assets/js/shared.js:64` (`PLTT.formatDuration`) vs `includes/helpers.php:24` (`pltt_format_duration`) | JS twin lacks the PHP version's guards against non-numeric and negative input. For integer minute counts from the server: outputs match. For client-side float accumulation: PHP rounds (`(int) round()`), JS truncates (`Math.floor`); diverge by ±1 minute. |
| **TRC-9** | `assets/js/shared.js:82` (`PLTT.formatHours`) vs `includes/helpers.php:48` (`pltt_format_hours`) | PHP uses `number_format(_, 2)` which adds thousands separators; JS uses `toFixed(2)` which does not. Visible drift for sums ≥1000 hours. |
| **TRC-10** | Memory `DB_VERSION` says 1.9.1 | Actual `class-pltt-database.php:23` is **`'1.9.4'`** — three patch versions ahead (1.9.2 added `budget_fee`, 1.9.3 added `is_internal`, 1.9.4 added `group_name`). Memory needs updating. |

### Traceability nits (worth knowing about, not urgent)

- `PLTT_Form_Handlers::handle_bulk_assign_group` ignores the return value of `PLTT_Tags::bulk_set_group()` and always reports success even on DB failure.
- `update_entry_field` has a double `wp_send_json_success()` in the billable branch (lines 266-269 and again at 292). Safe because `wp_send_json_*` exits, but missing a `return;`.
- `handle_update_client` / `handle_update_project` pass `hourly_rate => ''` to clear the column. Memory directive says `pltt_set_nullable_fields()` is required because `wpdb` cannot write NULL via `%d`/`%f`. Layer-4 confirms `update()` routes empty strings through that helper correctly — **this works**, but the contract is fragile and worth a comment.
- `pltt_get_internal_client_id()` (`includes/helpers.php:354`) bypasses `PLTT_Clients` and queries `$wpdb` directly. Memory's "data access classes own SQL" rule is broken here.
- `pltt_resolve_billable_rate()` callers in `templates/reports.php:721` and `templates/partials/client-context-card.php:46` don't pass pre-loaded caches, so each loop iteration issues 2 DB queries. The helper's caching contract is implemented but unused at these call sites (N+1).

---

## Section 4 — How to use this report

This file is the **map**. The companion `TASK-BACKLOG.md` is the **work queue**.

Every finding in this report appears in `TASK-BACKLOG.md` as a discrete task with:

- A stable ID (`SEC-H2`, `OPT-N1`, `TRC-5`, etc. — matches the IDs above)
- Exact file paths and line numbers
- A short description of the problem
- The recommended fix (specific enough that another subagent can do it without further research)
- Severity, category, and an estimated risk-of-change
- Acceptance criteria
- Any "blocked-by" or "tackle-together-with" relationships (e.g. consolidation groups)

Subagents should pick tasks by ID, read just that entry, and ship the fix. The intent is that you (or an automation) can spin off agents like _"go pick three Low-risk dead-code items from `TASK-BACKLOG.md` and submit a single PR for them"_ or _"work `SEC-H2` and `SEC-H3` together — they touch the same file."_

### Suggested sequencing

The feature freeze is in effect until end of March 2026 (memory). Everything below is bug-fix or hygiene — eligible to ship during the freeze:

1. **Today (1 hour):** `SEC-H1` (delete dead phishing branch). Trivial.
2. **This week (small PR):** `SEC-H2`, `SEC-H3`, `SEC-M3`, `SEC-M4`. All security, all small.
3. **This week (medium PR):** Group D (dead code sweep). Visible payoff, ~600 LOC removed, no behavior change.
4. **Next sprint:** Group C (bulk stats), Group A (Verify and Reject boilerplate). Measurable performance and DRY gains.
5. **Backlog:** Groups B, E, F, G, H — these are larger refactors with higher review burden; tackle once the small fixes are out.
6. **After freeze:** TRC-2 (refactor `PLTT_Daily_Log` to a proper data-access class) — touches the layer boundary and is the right post-freeze hygiene investment.

### Memory updates needed

The audit surfaced these stale memory entries (separately tracked in the project memory and updated as part of this audit):

- `DB_VERSION` is `1.9.4`, not `1.9.1`. (TRC-10)
- The `pltt_resolve_billable_rate()` cache-behavior note ("If a cache is provided but the ID is not found in it, the DB is NOT queried as a fallback") is inaccurate — code falls back to DB on miss regardless. The function's own docblock matches the code; memory drifted.

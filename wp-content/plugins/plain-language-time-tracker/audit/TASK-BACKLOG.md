# Plain Language Time Tracker — Task Backlog

This file is **machine-readable for subagents**. Each task is self-contained: file paths, line numbers, the recommended fix, severity, and acceptance criteria. Spin off a subagent with _"work task ID `XXX` from `audit/TASK-BACKLOG.md`"_ and it should have everything it needs.

**Companion doc:** `AUDIT-REPORT.md` (human-readable narrative explaining how these were found).

**Plugin root:** `/Users/patrickb/Local Development/plain-text-time-tracker/app/public/wp-content/plugins/plain-language-time-tracker`

---

## Shipped in the May 2026 audit-implementation pass

Net diff: **−424 LOC** across 26 files (~1,000 deleted, ~590 added for helpers, security checks, and bulk-query plumbing). All PHP files pass `php -l`; all JS files pass `node --check`.

- **Security highs/mediums** — SEC-H1, H2, H3, M1, M2, M3, M4, M6, M7, M8, M9, M11, M12, M13
- **Traceability fixes** — TRC-4, TRC-5, TRC-6, TRC-7, TRC-8, TRC-9, TRC-11, TRC-12, TRC-13
- **Dead code sweep** — OPT-D1 through D27 (PHP) and OPT-D30 through D47 (CSS/JS), excluding D11 (kept Aliases::update — see notes), D14 (kept), D23 (kept pltt_validate_date, used by both sanitize_date variants), D28 (column drop deferred — needs migration), D43 (naming reconciliation, deferred), D48 (modal-reset audit, deferred)
- **Consolidation Groups A, C, G, H** — OPT-DUP1 (admin notices helper), OPT-DUP5 (replaced inline rate cascade), OPT-DUP6 (billing-type badge helper), OPT-DUP7 (hoisted billing_type), OPT-DUP19 (bindInlineToggle), OPT-N1, OPT-N2, OPT-N3 (new `PLTT_Entries::get_stats_grouped_by()`)

**Skipped** as out of scope for this pass (per the "freeze-safe" filter):
- Group B (Date Nav widget extraction, OPT-DUP18) — risk: low/med, ~250 LOC dedupe but cross-file refactor
- Group E (inline JS → dedicated files, OPT-DUP20/S4/F10) — risk: med, touches build assumptions
- OPT-C1, C5, C6 (god-method/template splits) — risk: med
- OPT-S15 (break up `get_stats()` SQL) — risk: high
- OPT-S2 / TRC-2 (move `PLTT_Daily_Log` data methods to a dedicated class) — explicitly post-freeze
- SEC-M5 (per-action nonces) — risk: med, UI changes needed
- SEC-M10 (process_log confirm flag) — UI design needed
- Remaining DUP and S items not in the listed groups

These are still in the backlog below for a future pass.

---

## How to use this file

Each task has:

- **ID** (`SEC-H1`, `OPT-D3`, `TRC-5`, etc.) — stable, never reused.
- **Title** — short.
- **Severity** — `Critical | High | Medium | Low`.
- **Category** — `Security | Dead | Duplication | N+1 | Complexity | Performance | Structural | Frontend | Contract | Drift`.
- **Risk of change** — `low | med | high`.
- **Group** — optional consolidation tag (e.g. `Group D: Dead-code sweep`).
- **Where** — file paths + line ranges to read.
- **Problem** — 1–3 sentences.
- **Fix** — concrete change; specific enough another agent doesn't need to research.
- **Accept** — what "done" looks like.

When you ship a task, mark it `[x]` in the checkboxes below and reference the task ID in the commit message.

---

## SECURITY

### High

#### `SEC-H1` — Remove `pltt_error_message` phishing branch
- [ ] Done
- **Severity:** High • **Category:** Security • **Risk:** low
- **Where:** `templates/clients.php:57-60`; `templates/projects.php:52-54`
- **Problem:** Both templates read `$_GET['pltt_error_message']` and echo it (via `esc_html`) into a real admin error notice. No form handler ever writes this query param — it's a dead branch that lets an attacker craft a `/wp-admin/admin.php?page=pltt-clients&pltt_error_message=...` URL to phish an admin with a custom error banner inside the legitimate admin chrome.
- **Fix:** Delete the entire `if ( isset( $_GET['pltt_error_message'] ) ) { ... }` block from both files. The allowlisted `pltt_error` code-based notices remain.
- **Accept:** Crafted URL with `?pltt_error_message=anything` no longer renders any banner. Existing `pltt_error=client_delete_failed` etc. still render correctly.

#### `SEC-H2` — Add entry-date scoping in `PLTT_Review::save_entries()`
- [ ] Done
- **Severity:** High • **Category:** Security • **Risk:** low
- **Where:** `includes/admin/class-pltt-review.php:213-335`
- **Problem:** Each row in the posted `entries[]` array carries its own `entry_id`. The handler never verifies the row whose ID is `entry_id` actually belongs to the form's `$date`. A forged or CSRF-chained submit can overwrite arbitrary entries on any date (flip billable, change times, mark unbilled hours billed=1).
- **Fix:** In the per-row loop, after fetching `$original = PLTT_Entries::get($entry_id)`, add `if ( ! $original || $original->entry_date !== $date ) { $errors++; continue; }`. The `$original` fetch is already done around line 242 for the rate-snapshot logic — re-use it.
- **Accept:** Posting an `entries[][id]=N` where entry N's `entry_date` differs from the form's `$date` does not update entry N; it's counted as an error.

#### `SEC-H3` — Cap `entries[]` count and validate times in bulk save
- [ ] Done
- **Severity:** High • **Category:** Security • **Risk:** low
- **Where:** `includes/api/class-pltt-form-handlers.php:371-416`; `includes/admin/class-pltt-review.php:213-335`
- **Problem:** `json_decode($_POST['entries'], true)` accepts an unbounded array. Per-row `start_time`/`end_time` go through `sanitize_text_field` only — PHP's `date_create()` accepts strings like `"tomorrow midnight"`, `"+1 day"`. Combined with SEC-H2, this is the mass-corruption path.
- **Fix:** In `handle_save_entries` after decoding, add `if ( count($entries) > 200 ) { /* redirect with pltt_error=too_many_entries */ }`. In `PLTT_Review::save_entries` row loop, add `if ( ! preg_match('/^\\d{1,2}:\\d{2}(:\\d{2})?$/', $entry_data['start_time'] ?? '') ) { $errors++; continue; }` and the same for `end_time`.
- **Accept:** Posting >200 entries redirects with an error. Posting `start_time=tomorrow` increments the error counter and does not write.

### Medium

#### `SEC-M1` — Allowlist column names in `pltt_set_nullable_fields()`
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/helpers.php:816-836`
- **Problem:** Field names are wrapped in `esc_sql()` and interpolated. All current callers pass static literals, so it's safe today. `esc_sql()` does NOT make column names safe — second-order SQLi if any future caller forwards user input.
- **Fix:** Inside the function, add an explicit allowlist of legitimate nullable columns: `$allowed = [ 'client_id', 'project_id', 'hourly_rate', 'recurring_period', 'budget_hours', 'budget_fee', 'last_used', 'group_name' ];` then `$fields = array_intersect($fields, $allowed);` before the loop.
- **Accept:** Passing a non-allowed field name is silently dropped; all existing callers continue to work.

#### `SEC-M2` — Allowlist `status` in `handle_update_project`
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/api/class-pltt-form-handlers.php:136-201` (status read around line 155-157)
- **Problem:** `status` is `sanitize_text_field`'d and forwarded to `PLTT_Projects::update()` with no allowlist. Layer-4 `PLTT_Projects::update()` does allowlist `['active','archived']` internally (good) — but the handler should reject early.
- **Fix:** After reading `$status`, `if ( '' !== $status && ! in_array( $status, [ 'active', 'archived' ], true ) ) { /* redirect with pltt_error=invalid_status */ }`.
- **Accept:** Posting `status=foo` redirects with an error code; valid status values still save.

#### `SEC-M3` — Add `pltt_sanitize_date_strict()` for destructive paths
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/helpers.php:171-174`; usage in `includes/api/class-pltt-ajax.php:71, 96, 121, 487` and `includes/api/class-pltt-form-handlers.php:377`
- **Problem:** `pltt_sanitize_date()` silently coerces invalid input to **today**. In `pltt_process_log` (deletes the date's existing entries before reprocessing), `pltt_delete_daily_log`, and `handle_save_entries`, a forged or malformed `date` wipes today's data instead of erroring out.
- **Fix:** Add `function pltt_sanitize_date_strict($date): string { $date = sanitize_text_field($date); return pltt_validate_date($date) ? $date : ''; }`. Replace the call in `process_log` (`includes/api/class-pltt-ajax.php:121`), `delete_daily_log` (line 487), `handle_save_entries` (`form-handlers.php:377`), and `save_daily_log`/`update_daily_log` (lines 71, 96). Bail with the appropriate error response when the strict variant returns `''`.
- **Accept:** Posting `date=garbage` to any destructive endpoint returns an error JSON or error redirect — never silently mutates today's data.

#### `SEC-M4` — Strip PII from `error_log()` dump
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/api/class-pltt-ajax.php:170`
- **Problem:** `error_log( sprintf( 'PLTT: Entry creation failed for date %s. Entry data: %s', $date, wp_json_encode( $entry ) ) )` dumps the entry's `raw_text` and `description` (which can contain client work descriptions and rates) into `wp-content/debug.log` when `WP_DEBUG_LOG` is on.
- **Fix:** Either remove the JSON dump entirely, or include only non-sensitive fields: `error_log( sprintf( 'PLTT: Entry creation failed for date %s. start=%s end=%s', $date, $entry['start_time'] ?? '?', $entry['end_time'] ?? '?' ) );`.
- **Accept:** A failed entry creation no longer writes `raw_text` or `description` to the PHP error log.

#### `SEC-M5` — Per-action nonces for destructive AJAX endpoints
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** med
- **Where:** all `wp_ajax_*` registrations in `includes/api/class-pltt-ajax.php:23-40`; nonce creation in `includes/admin/class-pltt-admin.php:224`
- **Problem:** All AJAX endpoints share a single `pltt_ajax_nonce`. If the nonce leaks (via Referer header, XSS in another plugin), an attacker can perform any destructive action.
- **Fix:** Use per-action nonces for `pltt_delete_entry`, `pltt_delete_daily_log`, and `pltt_process_log` (which deletes existing entries). Generate them with `wp_create_nonce('pltt_delete_entry_' . $entry_id)` from the page-render side; verify with `check_ajax_referer('pltt_delete_entry_' . $entry_id, 'nonce')`. UI sends the per-entry nonce as a `data-nonce` attribute on each row.
- **Accept:** Each delete uses a row-specific nonce; the shared `plttData.nonce` no longer authorizes deletes.

#### `SEC-M6` — Wrap `update_entry_field` billable branch in a transaction
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/api/class-pltt-ajax.php:228-269`
- **Problem:** When toggling `billable=1`, the handler reads `duration_minutes` via `PLTT_Entries::get()`, computes `billable_amount`, then issues a separate `$wpdb->update()`. No transaction — a concurrent edit can change `duration_minutes` between read and write.
- **Fix:** Either wrap the read+compute+write in `$wpdb->query('START TRANSACTION')` / `COMMIT`, or rewrite as a single SQL `UPDATE … SET billable=%d, billable_rate=%f, billable_amount = ROUND(duration_minutes/60.0 * %f, 2) WHERE id=%d` so MySQL computes against the current row.
- **Accept:** No race window between reading `duration_minutes` and writing `billable_amount`.

#### `SEC-M7` — Call `pltt_validate_hourly_rate()` in all rate-write paths
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/api/class-pltt-ajax.php:322-357` (`create_client`); `includes/api/class-pltt-ajax.php:383-447` (`create_project`); `includes/api/class-pltt-form-handlers.php:72-104` (`handle_update_client`); `includes/api/class-pltt-form-handlers.php:136-201` (`handle_update_project`)
- **Problem:** Memory directive says `pltt_validate_hourly_rate()` must be called before storing any hourly rate. None of these four handlers call it. The data layer (`PLTT_Projects::create/update`, `PLTT_Clients::create/update`) does call it, so this is defense-in-depth — but the directive is documented and was forgotten.
- **Fix:** In each handler, after computing the float rate, `if ( '' !== $rate ) { $valid = pltt_validate_hourly_rate( $rate ); if ( is_wp_error( $valid ) ) { /* error response */ return; } }`.
- **Accept:** Posting `hourly_rate=-50` or `hourly_rate=99999` returns an error from each handler, not just the data layer.

#### `SEC-M8` — Length-validate tag names before INSERT
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/database/class-pltt-tags.php:108-135`; `includes/api/class-pltt-ajax.php:452-477`; `includes/api/class-pltt-form-handlers.php:233-265`
- **Problem:** Tag `name` column is `varchar(100)`. MySQL silently truncates on `INSERT IGNORE`. Two near-identical 101-char tags collide on the truncated 100-char prefix; the second insert silently fails.
- **Fix:** In both `pltt_create_tag` AJAX and form handlers, after lowercase+trim, `if ( mb_strlen( $tag_name ) > 100 ) { /* error response with "Tag too long" */ return; }`.
- **Accept:** Posting a 200-char tag name returns "Tag too long"; tags ≤100 chars still create.

#### `SEC-M9` — `PLTT_Aliases::record_usage()` race
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/database/class-pltt-aliases.php:302-330`
- **Problem:** Read `use_count`/`correct_count`, compute new confidence, write back. No locking; concurrent calls overwrite each other. Low real-world risk on a single-user plugin but worth a SQL-side fix.
- **Fix:** Rewrite as `$wpdb->query( $wpdb->prepare( "UPDATE {table} SET use_count = use_count + 1, correct_count = correct_count + %d, confidence = (correct_count + %d) / (use_count + 1), last_used = NOW() WHERE id = %d", $delta, $delta, $id ) );`
- **Accept:** Two simultaneous `record_usage` calls produce monotonic counter increments.

#### `SEC-M10` — `process_log` requires explicit confirm on destructive replace
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `includes/api/class-pltt-ajax.php:116-191`
- **Problem:** `pltt_process_log` calls `PLTT_Entries::delete_by_date($date)` then recreates from the parsed content. Successful nonce-bearing forge → admin loses the date's verified entries irreversibly.
- **Fix:** Require `$_POST['confirm_replace'] === '1'` when entries already exist for that date. The UI's `confirm()` dialog (`daily-log.js:175`) is the only current gate; mirror it server-side.
- **Accept:** Calling `pltt_process_log` for a date with existing entries without `confirm_replace=1` returns an error.

#### `SEC-M11` — Validate `return_to` at render, not just at handler
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `templates/review.php:26-99`; `includes/api/class-pltt-form-handlers.php:400-411`
- **Problem:** `$return_to` is rendered into the "Back to Reports" anchor (line 34) and into a hidden form input (line 99) using `esc_url()` / `esc_attr()`. `wp_validate_redirect()` runs only at the handler. Net effect: an admin clicking a crafted link to `?return_to=/wp-admin/users.php?action=delete&user=2&_wpnonce=BOGUS` sees a misleading back-link.
- **Fix:** In `review.php` around line 27, replace `esc_url_raw( wp_unslash( $_GET['return_to'] ) )` with `wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return_to'] ) ), '' )` so an off-host or invalid URL becomes `''` and the back-link doesn't render.
- **Accept:** Crafted `return_to` pointing to another admin path with a query string still renders, but pointing off-host is silently dropped.

#### `SEC-M12` — Filter `$_GET` in Reports `return_url` builder
- [ ] Done
- **Severity:** Medium • **Category:** Security • **Risk:** low
- **Where:** `templates/reports.php:759`
- **Problem:** `add_query_arg( $_GET, admin_url( 'admin.php' ) )` forwards the entire `$_GET` including any attacker-injected params (e.g. `pltt_error`, `pltt_error_message` — see SEC-H1) into the `return_url` that's then passed to the Review screen as `return_to`.
- **Fix:** `$allowed = array_flip( [ 'page', 'view', 'from', 'to', 'client_id', 'project_id', 'tag', 'billable', 'billed', 'client_negate', 'project_negate', 'tag_negate', 'paged' ] ); $return_url = add_query_arg( array_intersect_key( $_GET, $allowed ), admin_url( 'admin.php' ) );`
- **Accept:** Extraneous query params (`pltt_error_message=...`) do not survive the round-trip into the Review screen's back-link.

#### `SEC-M13` — Cast IDs to int in `review.js` option-building HTML
- [ ] Done
- **Severity:** Medium (defense-in-depth) • **Category:** Security • **Risk:** low
- **Where:** `assets/js/review.js:328-346`
- **Problem:** `project.id` from an AJAX response is interpolated raw into `value="..."` and `innerHTML`. Currently safe because all IDs are integers from the DB — but if a future bug let a non-integer reach the DB, this becomes DOM XSS.
- **Fix:** `'value="' + parseInt( project.id, 10 ) + '"'` and same for any other ID interpolation in the file.
- **Accept:** Non-numeric IDs in the response no longer reach `innerHTML`.

### Low

`SEC-L1` Verify `_wpnonce` unslashing is consistent across all `wp_verify_nonce` callers — currently is (one-pass review confirmed); add comment to `verify_nonce()` helper documenting this is intentional.

`SEC-L2` Delete `PLTT_INTERNAL_CLIENT_ID = 3` constant at `plain-language-time-tracker.php:36` — dead since the `is_internal` flag was added.

`SEC-L3` Add `delete_transient('pltt_clients_list'); delete_transient('pltt_projects_list'); delete_transient('pltt_aliases_list');` to `uninstall.php:31`.

`SEC-L4` Remove `pltt_deactivate()` (`plain-language-time-tracker.php:82-86`) and its `register_deactivation_hook` — empty function.

`SEC-L5` Replace `WP_DEBUG && time()` cache-buster in `class-pltt-admin.php:199` with a less aggressive value (or document that prod must never enable `WP_DEBUG`).

`SEC-L6` `pltt_extract_tags` regex DoS — add `pcre.backtrack_limit` documentation comment; admin-only so self-DoS risk is bounded.

`SEC-L7` Merged into `SEC-M4`.

`SEC-L8` `find_in_text()` regex builds — already uses `preg_quote`; add a code comment to that effect at `class-pltt-aliases.php:343-348`.

`SEC-L9` Strict `^\d{1,2}:\d{2}(:\d{2})?$` regex on `start_time`/`end_time` in `normalize_time()` — covered by SEC-H3 in the bulk-save path; this is the parser-side equivalent at `class-pltt-time-parser.php:130`.

`SEC-L10` Confirmed safe — `templates/review.php:80` original notes use `esc_html`.

`SEC-L11` Informational — not a plugin responsibility (CSP, X-Frame-Options).

`SEC-L12` Same as `SEC-M5` — per-action nonces. Marked as M5; ignore L12.

`SEC-L13` Confirmed safe — inline `<script>` blocks correctly use `esc_js()`.

`SEC-L14` In `templates/tags.php:163-167`, the URL cleanup script doesn't strip `pltt_error_message`. Harmless because the tags template doesn't render that param, but fix once SEC-H1 closes the rendering — strip the listener entirely.

`SEC-L15` `learn_client_alias()` does not length-cap potential aliases — DB truncates at varchar(100). Add `mb_strlen` check before `PLTT_Aliases::create`.

`SEC-L16` Confirmed safe — `assets/js/shared.js` `fetch` uses `credentials: 'same-origin'` and includes nonce in body.

`SEC-L17` Confirmed safe — `log-archive.js` delete goes through `PLTT.ajax` with nonce.

`SEC-L18` Informational — `dbDelta` requires `ALTER` privileges; standard WP assumption.

`SEC-L19` `pltt_get_internal_client_id()` static var cache may go stale if internal client is renamed/deleted mid-request. Effectively impossible in normal flow.

`SEC-L20` Confirmed safe — `pltt-top-project-name` `title` uses `esc_attr`.

`SEC-L21` Currency input filter is client-only; server-side `floatval('1,500')` truncates to 1.0. Add server-side comma-strip in rate-write paths (combine with SEC-M7).

`SEC-L22` Cosmetic — `pltt_format_currency` hard-codes `$`.

`SEC-L23` Confirmed safe — tag picker reads DB-stored sanitized names.

`SEC-L24` Confirmed safe — `assets/js/daily-log.js:189-192` validates same-origin redirect.

`SEC-L25` Merged into `SEC-L5`.

---

## OPTIMIZATION — Dead Code (Group D)

These can ship as one large delete-PR. Zero behavior change expected.

### PHP — methods never called

`OPT-D1` Delete `PLTT_Entries::get_summary_by_client()` — `includes/database/class-pltt-entries.php:511-535`. Zero callers.

`OPT-D2` Delete `PLTT_Entries::get_total_minutes()` — `includes/database/class-pltt-entries.php:395-431`. Superseded by `get_stats()`.

`OPT-D3` Delete `PLTT_Entries::get_daily_totals()` — `includes/database/class-pltt-entries.php:697-718`. Superseded by `get_chart_daily_totals()`.

`OPT-D4` Delete `PLTT_Clients::get_by_name()` — `includes/database/class-pltt-clients.php:312-320`.

`OPT-D5` Delete `PLTT_Clients::count()` — `includes/database/class-pltt-clients.php:327-333`.

`OPT-D6` Delete `PLTT_Projects::get_recent_for_client()` — `includes/database/class-pltt-projects.php:397-417`.

`OPT-D7` Delete `PLTT_Projects::archive()` — `includes/database/class-pltt-projects.php:375-377`. Trivial wrapper; callers use `update()` directly.

`OPT-D8` Delete `PLTT_Projects::restore()` — `includes/database/class-pltt-projects.php:385-387`.

`OPT-D9` Delete `PLTT_Projects::count()` — `includes/database/class-pltt-projects.php:531-544`.

`OPT-D10` Delete `PLTT_Aliases::save()` — `includes/database/class-pltt-aliases.php:140-163`. Callers use `create()` directly.

`OPT-D11` Delete `PLTT_Aliases::update()` — `includes/database/class-pltt-aliases.php:223-294`. **Verify first** — review agent says no callers, but `record_usage` writes via `$wpdb->update` directly so this may be intentional public API surface; if so, leave with a TODO.

`OPT-D12` Delete `PLTT_Aliases::delete()` — `includes/database/class-pltt-aliases.php:385-402`.

`OPT-D13` Mark `PLTT_Aliases::find_in_text()` as `private` (only internal caller `get_best_client_match`) — `includes/database/class-pltt-aliases.php:338-359`.

`OPT-D14` Keep `PLTT_Aliases::get()` for now — single caller `record_usage` may grow.

`OPT-D15` Delete `PLTT_Tags::get()` — `includes/database/class-pltt-tags.php:24-32`. Zero callers.

### PHP — helpers superseded or unused

`OPT-D16` Delete `pltt_get_cached_clients()` — `includes/helpers.php:615-625`. Cache is never read.

`OPT-D17` Delete `pltt_get_cached_projects()` — `includes/helpers.php:640-656`.

`OPT-D18` Delete `pltt_get_cached_tags()` — `includes/helpers.php:694-704`.

`OPT-D19` Delete `pltt_flush_client_cache`, `pltt_flush_project_cache`, `pltt_flush_tag_cache` (`helpers.php:630-632, 661-663, 709-711`) and their callers in the data-access classes — they flush transients that are never set (see D16-D18).

`OPT-D20` Delete `pltt_minutes_to_time()` — `includes/helpers.php:81-96`. Zero callers.

`OPT-D21` Delete `pltt_get_current_time()` — `includes/helpers.php:146-148`. Zero callers.

`OPT-D22` Delete `pltt_raw_text_has_ambiguous_time()` — `includes/helpers.php:247-260`. Zero callers.

`OPT-D23` Inline `pltt_validate_date()` into `pltt_sanitize_date()` (its only caller). `includes/helpers.php:156-174`. **Do NOT do this before SEC-M3** — that task adds `pltt_sanitize_date_strict()` which also calls `pltt_validate_date()`.

### PHP — constants

`OPT-D24` Delete `PLTT_INTERNAL_CLIENT_ID` constant — `plain-language-time-tracker.php:36`. (Same as `SEC-L2`.)

`OPT-D25` Delete `PLTT_Aliases::CONFIDENCE_THRESHOLD` constant — `includes/database/class-pltt-aliases.php:23`. Zero references.

`OPT-D26` Delete `delete_transient('pltt_daily_log_cache')` and `wp_clear_scheduled_hook('pltt_daily_cleanup')` from `uninstall.php:30, 34`. Symbols are not used anywhere.

`OPT-D27` Delete empty `pltt_deactivate()` function + its `register_deactivation_hook` — `plain-language-time-tracker.php:82-86`. (Same as `SEC-L4`.)

### DB columns

`OPT-D28` Audit `billable_rate` column on `pltt_time_entries` — written by review/AJAX but never read by reporting. Either start using it for audit display, or drop the column in a 1.9.5 migration. Risk: med (touches schema).

`OPT-D29` Remove `raw_text` hidden input from `templates/review.php:210` — it's read by `learn_client_alias()` but the round-trip through the form is unused.

### JS / CSS — dead

`OPT-D30` Delete `_removeTag()` method — `assets/js/tag-picker.js:325-333`. Zero callers.

`OPT-D31` Delete `.status-good` and `.status-warning` rules in `assets/css/admin.css:225-255`. Only `.status-increase`, `.status-decrease`, `.status-neutral` are emitted.

`OPT-D32` Delete the six `.pltt-status-*` rules in `assets/css/admin.css:601-627`. The save indicator uses different scoped classes.

`OPT-D33` Delete `.pltt-tags-toolbar` rules in `assets/css/admin.css:1145-1157`.

`OPT-D34` Delete `.pltt-two-column`, `.pltt-section`, `.pltt-section-header` rules and their `@media` block in `assets/css/admin.css:940-971`.

`OPT-D35` Delete `.pltt-alloc-ok`, `.pltt-alloc-warn` rules in `assets/css/admin.css:366-379`.

`OPT-D36` Delete `.pltt-no-duration` rule in `assets/css/admin.css:468-470`.

`OPT-D37` Delete `.pltt-card-value-name`, `.pltt-card-client` rules in `assets/css/admin.css:147-163`.

`OPT-D38` Delete `.card-detail` rule in `assets/css/admin.css:226-229`.

`OPT-D39` Delete `.pltt-rate-source` rules in `assets/css/admin.css:1086-1091`.

`OPT-D40` Delete `.pltt-summary-total` rule in `assets/css/reports.css:44-46`.

`OPT-D41` Delete `.pltt-chart-container` rule in `assets/css/reports.css:49-55`.

`OPT-D42` Delete `#pltt-export-csv` rule in `assets/css/reports.css:74-76`. There's no export feature.

`OPT-D43` Reconcile `.pltt-deleting` (`log-archive.css:13-17`) vs `.pltt-entry-row.deleting` (`review.css:190-193`) — pick one naming convention.

`OPT-D44` Delete `/* gap: 10px; */` commented-out CSS in `assets/css/admin.css:112`.

`OPT-D45` Delete commented-out properties in `.pltt-form-actions` — `assets/css/admin.css:589-591`.

`OPT-D46` Delete `/* background: var(--pltt-bg-light); */` in `assets/css/review.css:19`.

`OPT-D47` Remove `console.error('PLTT Error:', error)` in `assets/js/shared.js:35` (or gate behind a debug flag).

`OPT-D48` Audit project modal reset handler at `templates/projects.php:535-553` — `addProjectBtn` doesn't clear `recurring_period` or `non_billable`. Confirm intentional or fix.

---

## OPTIMIZATION — Duplication

### Group A — "Verify and Reject" boilerplate

`OPT-DUP1` Add `pltt_render_admin_notices( array $message_map, array $error_map )` helper in `helpers.php` and replace the four duplicated notice blocks in `templates/clients.php:42-70`, `templates/projects.php:37-64`, `templates/tags.php:49-73`, `templates/daily-log.php:66-76`. **Do AFTER `SEC-H1`** so the helper doesn't carry the dead phishing branch forward.

`OPT-DUP2` Move the URL-cleanup script (4 copies in `templates/clients.php:192-199`, `templates/projects.php:525-532`, `templates/tags.php:161-167`, `templates/daily-log.php:155-163`) into `shared.js` as an auto-run on `DOMContentLoaded`.

`OPT-DUP3` Extract `verify_capability()` (or `verify_form_request($nonce_action)`) helper in `class-pltt-form-handlers.php`. Replaces 9 copies at `:73-75, 110-112, 137-139, 207-209, 234-236, 271-273, 314-316, 342-344, 372-374`.

`OPT-DUP4` Similar consolidation for the 6 copies in `class-pltt-admin.php:107-109, 128-130, 139-141, 150-152, 161-163, 172-174`.

### Group G — Rate / Billing Type display helpers

`OPT-DUP5` Replace inline rate cascade in `PLTT_Review::format_entries_for_review()` (`includes/admin/class-pltt-review.php:139-161`) with a call to `pltt_resolve_billable_rate()`. (Also `TRC-3`.)

`OPT-DUP6` Add `pltt_render_billing_type_badge( $billing_type )` helper. Replaces the 8-line block in `templates/projects.php:209-217` and `templates/reports.php:690-698`.

`OPT-DUP7` Hoist `$billing_type = pltt_get_billing_type( $row )` once per loop iteration in `templates/reports.php:669, 689` — currently called twice.

`OPT-DUP10` Add `pltt_render_rate_with_source( $client, $project = null )` helper. Replaces three rate-display blocks at `templates/clients.php:124-136`, `templates/projects.php:219-231`, `templates/partials/client-context-card.php:44-58`.

### Group B — Date Nav Widget

`OPT-DUP18` Extract a shared `PLTT.dateNav( widget, { onApply, presets, yearSwitcher } )` into `shared.js` from `assets/js/log-archive.js:18-177` and `assets/js/reports.js:18-264`. ~250 LOC removed.

### Group H — Inline Toggle handler

`OPT-DUP19` Extract `bindInlineToggle({selector, field, getValue, setVisual})` in `reports.js`. Consolidates the billable-toggle (`:431-510`) and invoiced-toggle (`:515-557`) handlers. ~120 LOC removed.

### Group E — Inline JS to dedicated files

`OPT-DUP20` Extract `templates/projects.php:375-690` and `templates/clients.php:188-278` inline JS into `assets/js/projects.js` and `assets/js/clients.js`. Use `wp_localize_script` for translations.

### CSS

`OPT-DUP22` Consolidate three `.pltt-date-nav` blocks in `assets/css/admin.css:109, 478, 738` into one canonical block.

`OPT-DUP23` Introduce `.pltt-section-group` base class for `.pltt-project-group`, `.pltt-tag-group`, `.pltt-date-group`, `.pltt-week-group` (admin.css:1094-1102, reports.css:79-86, log-archive.css:20-26).

`OPT-DUP24` Consolidate three group-header rules into single `.pltt-group-header` class — `admin.css:1104-1114`, `reports.css:87-96`, `log-archive.css:28-37`.

`OPT-DUP25` Consolidate `.pltt-tag-pills` defaults into `tag-picker.css`; remove duplicates from `daily-log.css:121-125`, `reports.css:205, 255-262`.

`OPT-DUP26` Replace four `!important` overrides in `.pltt-input-adornment-wrap input` (`admin.css:541-549`) with higher-specificity selectors.

`OPT-DUP27` Remove duplicated row-actions hover-reveal rules from `review.css:81-103`; rely on existing `.widefat .row-actions` rules in `admin.css:409-419`.

### Misc

`OPT-DUP8` Cache `PLTT_Tags::get_name_to_group_map()` result in a local — currently called twice on the same Reports page render (`templates/reports.php:320, 383`).

`OPT-DUP9` Add `pltt_build_reports_view_url( $context_args, $stats )` helper — replaces the "View" link builder at `templates/clients.php:99-112` and `templates/projects.php:175-187`.

`OPT-DUP11` Extract `private static function billable_amount_expr( $table_alias )` SQL fragment builder — `class-pltt-entries.php:482, 576`.

`OPT-DUP12` Extract `pltt_check_dependent_count($table, $where_column, $id, $singular, $plural, $error_code)` for the four delete-with-dependency-check blocks in `class-pltt-clients.php:236-280` and `class-pltt-projects.php`.

`OPT-DUP13` In `class-pltt-log-archive.php:34-43`, stop mutating `$_GET` — convert old `?month=YYYY-MM` to local variables instead.

`OPT-DUP14` Decide if both AJAX `pltt_create_tag` and form `pltt_create_tag` paths must exist; if yes, extract a shared validate-and-create static. See also `TRC-1`.

### JS

`OPT-DUP15` Move `formatTimeForDisplay()` and `formatDateForDisplay()` from `review.js:137-152` into `PLTT.formatTime12()` / `PLTT.formatDateShort()` in `shared.js`.

`OPT-DUP16` Have `setBillableVisual` (`review.js:444-456`) call into a shared `applyBillableClasses(symbol, isBillable)` helper used by the change handler at `review.js:52-61`.

`OPT-DUP17` Extract `buildProjectOption(proj)` returning an `<option>` element. Used by `review.js:310-368` and `reports.js:275-334`.

`OPT-DUP21` Move `BILLING_DESCRIPTIONS` from `templates/projects.php:381-394` to PHP and pass via `wp_localize_script`.

---

## OPTIMIZATION — N+1 / DB

### Group C — Bulk stats backend

`OPT-N1` Add `PLTT_Entries::get_stats_by_client_bulk( int[] $client_ids ): array` (`client_id => stats`). Refactor `templates/clients.php:28-34` to use it. Drops N queries → 1.

`OPT-N2` Add `PLTT_Entries::get_stats_by_project_bulk( int[] $project_ids, array $args = [] ): array`. Refactor `templates/projects.php:23-29` and `class-pltt-reports.php:209-230` to use it. (Also covers `OPT-N3`.)

### Other

`OPT-N3` Subsumed into `OPT-N2`.

`OPT-N4` Same as `OPT-DUP8` — cache `get_name_to_group_map()` result.

`OPT-N5` Add `$args['fields']` to `PLTT_Projects::get_all()` to allow `SELECT id, client_id, name, status` only on the Reports filter dropdown path (`templates/reports.php:68`).

`OPT-N6` In `PLTT_Entries::get_top_projects_for_period()`, accept `$internal_client_id` or `$args['exclude_internal']` to avoid re-computing it (`class-pltt-reports.php:106-108`).

`OPT-N7` Migration loop in `class-pltt-database.php:280-308` is one-shot per upgrade — leave but document.

`OPT-N8` Replace the per-tag `INSERT IGNORE INTO {pltt_tags}` loop in `PLTT_Tags::sync_entry_tags()` (`class-pltt-tags.php:449-455`) with a single multi-VALUES INSERT IGNORE.

`OPT-N9` Same for the junction `INSERT IGNORE INTO {pltt_entry_tags}` loop at `:478-493`.

`OPT-N10` Consider bulk insert in `process_log` (`class-pltt-ajax.php:153-173`) — multiple entries + multiple tag-syncs per call. Lower priority.

`OPT-N11` After confirming the 1.9.3 migration ran everywhere, remove the `LOWER(c.name) != 'internal'` fallback in `class-pltt-entries.php:466-484`.

`OPT-N12` Add `$args['fields'] => 'minimal'` to `PLTT_Entries::get_all()` to omit `raw_text` in list/Reports contexts. Risk: med.

`OPT-N13` Subsumed into `OPT-D2`.

`OPT-N14` Pre-filter aliases in `PLTT_Aliases::find_in_text()` (`class-pltt-aliases.php:342-348`) by extracted potentials instead of looping every alias. Risk: med.

`OPT-N15` Re-indent `budget_fee decimal(10,2) DEFAULT NULL,` in `class-pltt-database.php:75`. Cosmetic.

---

## OPTIMIZATION — Complexity

`OPT-C1` Split `PLTT_Reports::render()` (`class-pltt-reports.php:26-260`, 234 LOC) into `parse_input()`, `compute_summary_cards()`, `build_chart_data()`, `build_summary_view()`, `build_detailed_view()`. Risk: med.

`OPT-C2` Refactor `PLTT_Entries::update()` (`class-pltt-entries.php:246-334`) with a per-field declaration table (name → format → sanitizer → nullable flag). Risk: low.

`OPT-C3` Same treatment for `PLTT_Projects::update()` (`class-pltt-projects.php:241-367`).

`OPT-C4` Split `PLTT_Ajax::update_entry_field()` (`class-pltt-ajax.php:198-293`) into `update_field_billable`, `update_field_billed`, `update_field_tags`.

`OPT-C5` Move computational sections out of `templates/reports.php` (797 LOC): grouping at `:73-104`, preset detection at `:159-167`, Y-axis ceiling at `:511-523`, budget logic at `:653-722`. Risk: med.

`OPT-C6` Extract `templates/projects.php` JS to `assets/js/projects.js` (covers `OPT-DUP20`).

`OPT-C7` Extract `compute_billable_amount()` and `to_formatted_entry()` from `PLTT_Review::format_entries_for_review()` (`class-pltt-review.php:101-200`).

`OPT-C8` Extract `prepare_entry_update_data()` and `snapshot_billable()` from `PLTT_Review::save_entries()` (`class-pltt-review.php:213-335`).

`OPT-C9` Drive `applyBillingTypeUI()` (`templates/projects.php:414`, ~90 LOC) from a `STATES` data table instead of nested branches. Risk: med.

`OPT-C10` Extract `render_entry_row()` from `pltt_render_entry_table()` (`helpers.php:916-1060`).

---

## OPTIMIZATION — Performance

`OPT-P1` Render tag-picker DOM once on init; show/hide via classList instead of `innerHTML = ''` + rebuild — `assets/js/tag-picker.js:156, 298`.

`OPT-P2` Replace `document.querySelectorAll('.pltt-client-select')` with event delegation on the form — `assets/js/review.js:506-515` (and project select handler `:400`).

`OPT-P3` Track open `.pltt-time-cell` elements in a `Set` instead of looping all on Escape — `assets/js/review.js:292`.

`OPT-P4` Fuse the two `foreach` over `$all_clients`/`$all_projects` at `templates/reports.php:84-98, 102-104` into one loop.

`OPT-P5` Move per-row data-attr blob in `templates/clients.php:114` into a `<script type="application/json">` keyed by client ID. Risk: med.

`OPT-P6` Same for `templates/projects.php:189`.

`OPT-P7` Consolidate the multiple global `document.addEventListener('click')` listeners (reports.js:175-179, tag-picker.js:353-357) into one outside-click manager.

`OPT-P8` Already uses CSS vars — no action needed.

`OPT-P9` Cancel pending debounce in `assets/js/daily-log.js:268-283` when `navigatingAway` is set.

`OPT-P10` Same as `SEC-L5` / `OPT-D` note.

`OPT-P11` Covered by `SEC-M12`.

`OPT-P12` Cache `.pltt-card-value`, `.displaying-num`, `.widefat tbody` queries at init in `assets/js/log-archive.js:217-237`.

`OPT-P13` Replace inline `style="display:none"` in `templates/reports.php:236, 258, 310` with the existing `.pltt-hidden` class.

`OPT-P14` Add transient cache to `pltt_get_internal_client_id()` (`includes/helpers.php:354-364`) for cross-request hits.

---

## OPTIMIZATION — Structural

`OPT-S1` Leave-for-now: 100% static methods, no DI. Flag as a future direction.

`OPT-S2` Create `includes/database/class-pltt-daily-logs.php` containing the CRUD currently in `class-pltt-daily-log.php` (lines 34-268). Move only the data methods; admin `render()` stays. (See also `TRC-2`.) Risk: low.

`OPT-S3` Covered by `OPT-C5`.

`OPT-S4` Covered by `OPT-DUP20` / `OPT-E`.

`OPT-S5` Replace `typeof X !== 'undefined'` globals (`reports.js:275, 567`) with `wp_localize_script('pltt-reports', 'plttReports', {...})`.

`OPT-S6` Centralize budget mutual-exclusion ("clear hours when fee is set") in `PLTT_Projects::create/update` — currently enforced in the handler at `handle_update_project:182-187` and the AJAX `create_project:431`, but not in the data layer.

`OPT-S7` In `pltt_create_project` AJAX (`class-pltt-ajax.php`) validate `budget_hours`/`budget_fee` are non-negative numerics before `floatval`.

`OPT-S8` Make Reports view whitelist a class const `PLTT_Reports::VIEWS` and reuse — currently duplicated at `class-pltt-reports.php:42-44` and `templates/reports.php`.

`OPT-S9` Wrap `PLTT_Aliases::STOPWORDS` in `apply_filters('pltt_alias_stopwords', self::STOPWORDS)`.

`OPT-S10` Introduce constants/enums for magic strings: `PLTT_Status::ACTIVE`, `PLTT_BillingType::HOURLY`, etc.

`OPT-S11` Replace `save_log($date, $content, $preserve_processed = false)` boolean trap (`class-pltt-daily-log.php:51`) with two methods or named-arg array.

`OPT-S12` Have `PLTT_Review::save_entries()` (`class-pltt-review.php:212-335`) return `WP_Error|array` consistently; split alias-learning side-effect into a separate post-hook.

`OPT-S13` Covered by `SEC-M12`.

`OPT-S14` Add a sanity check in `PLTT_Database::maybe_upgrade()` that `DB_VERSION` matches the maximum `version_compare` step in `migrate()`. (See also `TRC-5`.)

`OPT-S15` Consider breaking the huge SQL in `PLTT_Entries::get_stats()` (`class-pltt-entries.php:466-484`) into 2-3 smaller queries. Risk: high — touches all reports.

---

## OPTIMIZATION — Frontend

`OPT-F1` Covered by `OPT-DUP26`.

`OPT-F2` Scope `.pltt-date-nav` grid override (`assets/css/admin.css:478-487`) to `.pltt-header .pltt-date-nav` and move it near the header rules.

`OPT-F3` Verify `display: contents` on `.pltt-entry-meta .pltt-tag-pills` (`reports.css:206`) maintains a11y focus/selection. Risk: med.

`OPT-F4` Covered by `OPT-P7`.

`OPT-F5` Covered by `OPT-DUP19`.

`OPT-F6` Localize `'en-US'` in `amount.toLocaleString(...)` at `reports.js:487`.

`OPT-F7` Drop `'all_tags' => $all_tags` from the `pltt_render_entry_table` call at `templates/reports.php:776` if pickers use the JS global. Verify, then simplify the signature.

`OPT-F8` Guard `onClose` in tag-picker (`tag-picker.js:382-386`) so it only fires when tags actually changed since opening — prevents spurious save AJAX when switching between pickers.

`OPT-F9` Delegate `.pltt-delete-entry` click handler to the form instead of `document` (`review.js:639-671`).

`OPT-F10` Covered by `OPT-DUP20`.

---

## TRACEABILITY

`TRC-1` `PLTT_Tags::create()` arity — AJAX (`class-pltt-ajax.php:474`) calls with 1 arg; form (`class-pltt-form-handlers.php:257`) calls with 2. Layer-4 confirms signature has default `null` for `$group_name`, so contract holds. **Action:** add a code comment on `PLTT_Tags::create()` noting the two call patterns; optionally make the AJAX path also accept `group_name`.

`TRC-2` Move `PLTT_Daily_Log` data methods to a new `includes/database/class-pltt-daily-logs.php` (covers `OPT-S2`). Also fix docblocks for `save_log` (missing `$preserve_processed`), `get_all` (missing `date_from`/`date_to`), `count_all` (missing `date_from`/`date_to`). Risk: low (mechanical move).

`TRC-3` In `PLTT_Review::format_entries_for_review()` (`class-pltt-review.php:139-161`), replace the inline rate cascade with `pltt_resolve_billable_rate()`. Covered by `OPT-DUP5`.

`TRC-4` In `PLTT_Review::save_entries()` (`class-pltt-review.php:269-273`), check `pltt_time_to_minutes()` return for `false` before subtraction. Throw an error or skip the row. Otherwise `false - 540 = -540` is stored as a negative duration.

`TRC-5` Fix `PLTT_Database::maybe_upgrade()` (`class-pltt-database.php:181-188`) — currently calls `create_tables()` (which writes `pltt_db_version` to `DB_VERSION`) **before** `migrate()` runs. Reorder so migration steps run first; only write the version option after `migrate()` returns successfully.

`TRC-6` Add nested-TX detection to `PLTT_Entries::update()` (`class-pltt-entries.php:246`) matching the pattern in `create()` (line 213 uses `SELECT @@in_transaction`).

`TRC-7` Route `update_entry_field` billable/billed writes through `PLTT_Entries::update()` instead of direct `$wpdb->update` (`class-pltt-ajax.php:198-293`). Restores the transactional wrapper and any future side-effects. Combine with `SEC-M6`.

`TRC-8` Sync `PLTT.formatDuration` (`assets/js/shared.js:64`) with `pltt_format_duration` (`helpers.php:24`) — add `Number.isFinite` and negative guards; use `Math.round` instead of `Math.floor` to match PHP's `(int) round()`.

`TRC-9` Sync `PLTT.formatHours` (`shared.js:82`) with `pltt_format_hours` (`helpers.php:48`) — use `(min / 60).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })` to match PHP's `number_format(_, 2)` thousands separator.

`TRC-10` Update memory `MEMORY.md` entry — `DB_VERSION` is `1.9.4`, not `1.9.1`. (Handled separately during this audit.)

`TRC-11` `handle_bulk_assign_group` ignores `PLTT_Tags::bulk_set_group()` return value (`class-pltt-form-handlers.php:341-366`). Check return and report failure via `pltt_error=group_assign_failed`.

`TRC-12` Fix double `wp_send_json_success()` in `update_entry_field` billable branch (`class-pltt-ajax.php:266-269` and `:292`). Add `return;` after the first call.

`TRC-13` Add a comment to `handle_update_client` / `handle_update_project` that passing `hourly_rate => ''` correctly routes through `pltt_set_nullable_fields()` in the data layer to write NULL — documents the fragile contract.

`TRC-14` Reconcile `pltt_resolve_billable_rate()` cache-behavior contract — code falls back to DB on cache miss regardless of whether a cache was supplied. Memory was inaccurate; the function's docblock matches the code. **Action:** update memory (covered separately); no code change needed.

---

## Picker hints for subagent runs

- **"Easiest wins (1 PR, ~1 hour each):"** `SEC-H1`, `SEC-L4`, `SEC-L2` (and equivalents), `OPT-D44`–`OPT-D47`.
- **"Dead-code sweep PR:"** all of `OPT-D1`–`OPT-D48` together. Single commit, large diff, all-deletions.
- **"Bulk-stats N+1 fix:"** `OPT-N1` + `OPT-N2` (and `OPT-N3` which is subsumed) in one PR. Requires adding two methods to `PLTT_Entries`.
- **"Hardening pass:"** `SEC-H2`, `SEC-H3`, `SEC-M3`, `SEC-M4`, `SEC-M7` together.
- **"Date-nav widget refactor:"** `OPT-DUP18` + `OPT-P7` + `OPT-F4` (the last two subsumed).
- **"Inline-template-JS extraction:"** `OPT-DUP20` + `OPT-C6` + `OPT-S4` together — touches `templates/projects.php`, `templates/clients.php`, creates two new JS files.

Each task ID is stable. Reference them in commit messages and PR titles for traceability back to this audit.

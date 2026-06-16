# Security Review — Plain Language Time Tracker

**Date:** 2026-06-15
**Reviewer:** Senior Application Security Engineer (adversarial review)
**Scope:** All PHP, JS, and templates under the plugin root.
**Plugin version:** 1.9.19 — DB 1.9.5

## Executive summary

This codebase is **unusually well-hardened**. It carries the fingerprints of a prior
security audit (May 2026): nearly every sensitive sink has a `SEC-*` / `TRC-*` / `OPT-*`
comment marker and a corresponding mitigation. I reviewed every AJAX handler, every
`admin_post` handler, the full database layer, helpers, the parser, all templates, and
the relevant JS.

Key positives verified:

- **Every** AJAX handler (12 of them) uses `if ( ! self::verify_request() ) { return; }`
  — nonce (`check_ajax_referer`) + capability (`pltt_user_can_access`) with the return
  guard, exactly as the project conventions require.
- **Every** `admin_post` handler (9) checks `pltt_user_can_access()` AND `verify_nonce()`
  before doing work.
- **Every** page controller in `PLTT_Admin` gates on `pltt_user_can_access()` (=
  `manage_options`) before rendering.
- All `$wpdb` queries with variable input use `$wpdb->prepare()`. Hand-built `IN()`
  clauses are built from `array_fill(..., '%d')` placeholders fed through `prepare()`.
  Table names come only from `PLTT_Database::get_table_name()`. ORDER BY / column names
  are allowlisted. The one raw-identifier path (`pltt_set_nullable_fields`) has an
  explicit column allowlist (SEC-M1).
- Destructive date paths (`process_log`, `delete_daily_log`, `save_entries`) use
  `pltt_sanitize_date_strict()` (fail-closed) — SEC-M3.
- `billable` / `billed` are validated as strictly `0|1`; `recurring_period` against the
  `PLTT_ALLOWED_RECURRING_PERIODS` allowlist; hourly rates via `pltt_validate_hourly_rate()`
  — at both the handler boundary and the data layer.
- WP_Error → redirect uses only `get_error_code()` (SEC-M2).
- Ownership/IDOR is not a meaningful boundary here: this is a single-operator,
  `manage_options`-only tool. There is no lower-privilege role that owns a subset of the
  data, so cross-tenant IDOR does not apply. `save_entries` still adds a date-ownership
  check (SEC-H2) as defense-in-depth.
- `uninstall.php` guards on `WP_UNINSTALL_PLUGIN`. Output is escaped (`esc_html`,
  `esc_attr`, `esc_url`, `esc_textarea`, `wp_strip_all_tags`). JS builds option markup
  with `PLTT.escapeHtml()` + `parseInt()` for IDs.

No Critical or High issues were found. The findings below are Medium/Low
hardening items, the most material being an information-disclosure concern in the
1.9.5 migration log file.

---

## Findings

### SEC-M1 — Migration log written to publicly-accessible uploads URL with predictable name

**Severity:** Medium
**File:** `includes/database/class-pltt-database.php:518-621` (`write_migration_1_9_5_log`), surfaced at `includes/admin/class-pltt-admin.php:40-62`

**Excerpt:**
```php
$filename = 'pltt-migration-1.9.5-' . gmdate( 'Ymd-His' ) . '.txt';
$filepath = trailingslashit( $upload_dir['basedir'] ) . $filename;
$fileurl  = trailingslashit( $upload_dir['baseurl'] ) . $filename;
...
$lines[] = sprintf( 'Project: %s (%s -- %s)', $p->name, $p->client_name, $p->type );
...
$lines[] = sprintf( '  #%d | %s | %s | "%s"', (int) $e->id, $e->entry_date, ..., $desc );
```

**Why it's exploitable:** The 1.9.5 migration dumps client names, project names, and
(truncated) time-entry descriptions — i.e. client-confidential work descriptions — into a
plain-text file under `wp-content/uploads/`, which is web-served on a default WordPress
install. The filename is `pltt-migration-1.9.5-YYYYMMDD-HHMMSS.txt`: only the timestamp is
unknown, and the admin notice later exposes the exact URL via
`get_option('pltt_migration_1_9_5_log_url')`. The file has no `.htaccess`/nginx protection,
no `index.php` guard, no random suffix, and is never deleted (only the *option* is deleted
on dismiss/uninstall — the file persists). An unauthenticated party who learns or guesses
the URL (or finds it cached/logged) can read confidential billing data. This only triggers
on installs that upgrade across 1.9.5 with retainer/fixed-fee data, but the impact is real
PII/business-confidential disclosure.

**Fix:** Write the log outside the web root or to a per-install randomized subdirectory of
uploads protected with an `.htaccess`/`index.php` deny, e.g.
`wp-upload/pltt-private-<wp_generate_password(20,false)>/...`, and store the path (not a
public URL) in the option; serve it through an authenticated `admin-post.php` download
handler (capability + nonce) instead of a direct link. At minimum, append
`wp_generate_password( 12, false )` to the filename and add an `index.html` + `.htaccess`
(`Deny from all`) to the directory, and delete the file on dismiss/uninstall.

---

### SEC-L1 — `wp_json_encode` output emitted into inline `<script>` without `JSON_HEX_TAG`

**Severity:** Low
**File:** `templates/reports.php:377-390`; `templates/review.php:94`

**Excerpt:**
```php
wp_add_inline_script(
    'pltt-reports',
    'var plttProjectsByClient = ' . wp_json_encode( $projects_by_client ) . ';' .
    'var plttClientNames = ' . wp_json_encode( $client_names ) . ';' .
    'var plttAllTags = ' . wp_json_encode( $all_tags ) . ';' .
    ...
);
```

**Why it's (barely) exploitable:** `wp_json_encode()` does not set `JSON_HEX_TAG` /
`JSON_HEX_AMP` by default, so a client/project/tag name containing the literal
`</script>` would terminate the inline script element and allow arbitrary markup to
follow. However, the names are only ever authored by a `manage_options` user (the same
person viewing the page), so this is self-XSS / stored-by-admin, not a privilege-boundary
crossing — hence Low. Still worth hardening because the values originate from a
free-text DB column and flow into a script context.

**Fix:** Pass the encode flags:
`wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )`,
or prefer `wp_localize_script()` (which handles this), or build the JS data object via a
`wp_add_inline_script` payload that does not interpolate names into a raw `<script>`
without the hex flags.

---

### SEC-L2 — Migration log filename uses `gmdate` only; no cleanup of stale log files

**Severity:** Low (related to SEC-M1; tracked separately for the cleanup aspect)
**File:** `includes/database/class-pltt-database.php:524`, `drop_tables()` at `:626-650`, `uninstall.php`

**Why it matters:** Even after the admin dismisses the migration notice
(`delete_option('pltt_migration_1_9_5_log_url')`) or uninstalls the plugin, the physical
`.txt` file written to `wp-content/uploads/` is **never** removed — neither
`dismiss_migration_1_9_5_notice()` nor `drop_tables()`/`uninstall.php` unlink it. The
confidential log lingers indefinitely.

**Fix:** Track the absolute file path (not just the URL) in an option and `wp_delete_file()`
it in the dismiss handler and in `drop_tables()` / uninstall.

---

### SEC-L3 — Reports back-link copies raw `$_GET` values (allowlisted keys only)

**Severity:** Low (defense-in-depth; currently mitigated)
**File:** `templates/reports.php:801`

**Excerpt:**
```php
$return_url = add_query_arg( array_intersect_key( wp_unslash( $_GET ), $return_allowed ), admin_url( 'admin.php' ) );
```

**Why it's low:** Keys are restricted to a fixed allowlist, but the *values* are raw
`$_GET` (not run through `sanitize_text_field`). The resulting URL is, however, always
emitted through `esc_url()` (line 815) and the destination is re-validated server-side via
`wp_validate_redirect()` in `handle_save_entries` (`return_to`), so neither reflected XSS
nor open-redirect is reachable today. Flagged only because relying on every downstream
consumer to escape is fragile.

**Fix:** Sanitize the values at the point of capture, e.g.
`array_map( 'sanitize_text_field', array_intersect_key( wp_unslash( $_GET ), $return_allowed ) )`.

---

## Patterns checked and cleared (no issue)

- **SQL injection across the data layer** — `PLTT_Entries`, `PLTT_Projects`, `PLTT_Clients`,
  `PLTT_Tags`, `PLTT_Aliases`, `PLTT_Daily_Log`, `PLTT_Database`: every interpolated value
  goes through `$wpdb->prepare()`. Interpolated identifiers are either table names from
  `get_table_name()` or allowlisted ORDER BY/GROUP BY columns. `IN()` clauses use
  `array_fill(0, n, '%d'|'%s')` placeholders. The interpolated `{$internal_client_id}` and
  `{$exclude_clause}` in `get_stats`/`get_chart_daily_totals` derive from
  `pltt_get_internal_client_id()` (an `(int)` cast of a DB lookup) — not user input.
- **Nonce/capability on all AJAX** — all 12 `wp_ajax_pltt_*` handlers use the
  `verify_request()` return-guard pattern.
- **CSRF on all `admin_post`** — all 9 handlers verify `_wpnonce` + capability; the
  migration-dismiss handler uses `check_admin_referer`.
- **billable/billed/recurring_period validation** — strict `in_array(..., [0,1], true)` and
  `PLTT_ALLOWED_RECURRING_PERIODS` allowlist enforced in `update_entry_field`,
  `create_project`, `handle_update_project`. `PLTT_Projects::create/update` re-validate.
- **WP_Error in redirects** — `get_error_code()` only (SEC-M2), confirmed in all three
  delete/update handlers.
- **Date tampering / data wipe** — `pltt_sanitize_date_strict()` fail-closed on destructive
  paths; `save_entries` enforces per-row date ownership (SEC-H2) + row cap of 200 (SEC-H3).
- **Mass-assignment** — `PLTT_Entries::update` and `PLTT_Projects::update` use field
  allowlists with per-field format/sanitization; no blind `$wpdb->update($data)`.
- **Template XSS** — every dynamic echo uses `esc_html` / `esc_attr` / `esc_url` /
  `esc_textarea`; the two `// phpcs:ignore EscapeOutput` spots (`helpers.php:1315`,
  `class-pltt-review.php:143`) concatenate spans whose inner text was already `esc_html`'d.
- **JS DOM XSS** — `assets/js/shared.js escapeHtml()` uses `textContent`; `review.js` /
  `reports.js` build `<option>` markup with `PLTT.escapeHtml(project.name)` and
  `parseInt(project.id, 10)`; `reports.js` filter rebuild uses `textContent`/`createElement`.
  `review.js:1015` injects server-rendered `row_html` that was escaped server-side.
- **uninstall.php** — guards `WP_UNINSTALL_PLUGIN`, drops tables and deletes options.
  (Only gap: does not delete the SEC-M1/SEC-L2 migration log file.)
- **Open redirect** — `handle_save_entries` routes `return_to` through
  `wp_validate_redirect()`; `redirect_back()` uses `wp_get_referer()` + `wp_safe_redirect()`.
- **Deserialization / SSRF / file ops** — no `unserialize()` of untrusted input; the only
  file write is the migration log (SEC-M1); no outbound HTTP from user input.
- **Secrets / debug** — no hardcoded secrets; `error_log` calls deliberately omit
  raw_text/description (SEC-M4); cache-buster `time()` only under `WP_DEBUG`.
- **Parser** — `PLTT_Time_Parser` operates on regex over text; no SQL/output; normalized
  times pass through `date_create` and are re-validated against `/^\d{1,2}:\d{2}/` at the
  handler boundary before reaching the DB.
```

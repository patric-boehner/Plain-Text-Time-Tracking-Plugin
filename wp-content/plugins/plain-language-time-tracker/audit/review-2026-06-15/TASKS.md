# Task Backlog — Review 2026-06-15

Pickable tasks from the security / optimization / traceability review. See [`REVIEW-REPORT.md`](REVIEW-REPORT.md) for context and [`raw/`](raw/) for full per-finding detail.

**How to use this file (for an implementing agent):**
- Pick one task by ID. Read its row, then open the cited `file:line` AND the linked raw findings file for the full excerpt + rationale before editing.
- Each task is independent unless "Depends/Related" says otherwise.
- Bump `DB_VERSION` only for schema changes (none of these require it except where noted).
- Keep `pltt_format_duration()` / `formatDuration()` in sync if you touch duration formatting.
- After fixing, update the `Status` column to `DONE` and note the commit.

Effort: **S** = <1h, **M** = a few hours, **L** = half-day+.

---

## P1 — Correctness bugs (do first)

| ID | Sev | Title | File:line | Fix | Effort | Status |
|----|-----|-------|-----------|-----|--------|--------|
| TRC-DB23 | High | Transaction guard non-functional on MySQL 8 | `includes/database/class-pltt-entries.php:205` (create), `:308` (update) | `@@in_transaction` is MariaDB-only; on MySQL the probe errors → `$own_transaction` always true → inner `START TRANSACTION` implicitly commits any outer transaction, breaking atomicity for the `save_entries` loop (ajax.php:149) and `update_entry_field` billable branch (ajax.php:244). Replace the server-var probe with a plugin-owned transaction-depth counter (static int incremented on START, decremented on COMMIT/ROLLBACK; only the outermost actually starts/commits), or use SQL SAVEPOINTs. | M | **DONE** (v1.9.20) — added `PLTT_Database::begin/commit/rollback_transaction()` depth+failed-flag manager; routed create/update + both ajax wrappers through it. |
| TRC-API1 | Med | Empty client name redirects as "success" | `includes/api/class-pltt-form-handlers.php:110` | `PLTT_Clients::update()` returns `WP_Error` (truthy) on empty name; `if ( $result )` treats it as success. Add `if ( is_wp_error( $result ) ) { … redirect with $result->get_error_code() }` before the truthy check, matching the sibling delete/project handlers. | S | **DONE** (v1.9.20) — added `is_wp_error()` guard; mapped `missing_name` message in clients.php. |

## P1 — Privacy / info disclosure

| ID | Sev | Title | File:line | Fix | Effort | Status |
|----|-----|-------|-----------|-----|--------|--------|
| SEC-M1 | Med | Migration log with client data in public uploads | `includes/database/class-pltt-database.php:518-621`; notice at `includes/admin/class-pltt-admin.php:40-62` | Log contains client/project names + work descriptions in `wp-content/uploads/` with a guessable filename and no access control, URL shown in an admin notice. Move out of the web root (e.g. a protected dir or transient/option), or drop the file approach entirely. Don't surface a public URL. | M | **DONE** (v1.9.20) — **feature removed entirely** (one-time, 1.9.5-specific, already spent). Kept the migration flip; dropped log build/notice/download. Added one-time `maybe_purge_migration_1_9_5_log()` (gated by autoloaded flag) in `maybe_upgrade()` that deletes the options + unlinks any leftover `pltt-migration-1.9.5-*.txt`, so other installs self-remediate. Two leaked files removed from this install; live install verified clean. |
| SEC-L2 | Low | Migration log file never cleaned up | `includes/database/class-pltt-database.php:626-650`; `uninstall.php` | The `.txt` log is never deleted on notice-dismiss, `drop_tables()`, or uninstall. Add lifecycle cleanup. **Related: SEC-M1** — fix together. | S | **DONE** (v1.9.20) — moot now the feature is gone; `purge_migration_1_9_5_log()` + the autoloaded-flag sweep remove all artifacts, and `drop_tables()` calls it (covers uninstall). |

---

## P2 — Optimization quick wins (low risk, high clarity)

| ID | Title | File:line | Fix | Effort | Status |
|----|-------|-----------|-----|--------|--------|
| OPT-DEAD1 | Delete unused `PLTT_Tags::get_for_entry()` | `includes/database/class-pltt-tags.php:333` | Zero callers (grep-proven). Remove. | S | **DONE** (v1.9.21) |
| OPT-DEAD2 | Delete unused `PLTT_Tags::set_group()` | `includes/database/class-pltt-tags.php:186` | Zero callers. Remove. | S | **DONE** (v1.9.21) |
| OPT-DEAD3 | Delete unused `pltt_count_working_days()` | `includes/helpers.php:435` | Zero callers. Remove. | S | **DONE** (v1.9.21) |
| OPT-DEAD4 | Remove dead `modal._refreshFocusTrap` | `assets/js/shared.js:148` | Exposed but never called. Remove. | S | **DONE** (v1.9.21) |
| OPT-N-A | Drop duplicate windowed `get_stats()` | `includes/admin/class-pltt-project-detail.php:67` vs `class-pltt-project-report.php:93` | Same windowed stats computed twice per Project Detail render. Compute once, pass down. | S | **DONE** (v1.9.21) |
| OPT-N-B | `get_all_groups()` runs twice per render | `includes/admin/class-pltt-project-report.php:81` & `:107` | Cache the result for the request / pass it. | S | **DONE** (v1.9.21) |

---

## P2 — Traceability cleanup (latent / dead paths)

| ID | Sev | Title | File:line | Fix | Effort | Status |
|----|-----|-------|-----------|-----|--------|--------|
| TRC-UI3 | Low | Orphaned `bulk_assign_group` handler | `includes/api/class-pltt-form-handlers.php:39` | `handle_bulk_assign_group` (nonce `pltt_bulk_tag_group`) is registered + implemented but no UI emits its form/`tag_ids[]`/nonce. **Decide:** wire up the UI (part of in-progress tag-taxonomy work) OR remove the handler. Check [`project-tag-taxonomy-cleanup`] before deleting. | S | **DONE** (v1.9.21) |
| TRC-UI1 | Low | Dead create-client fallback + nonce/action mismatch | `templates/clients.php:135-138` | Form defaults to `action=pltt_create_client` (no `admin_post` handler — AJAX-only) and its nonce field names `pltt_update_client`. Either add a real `admin_post` no-JS fallback handler with a matching nonce, or remove the misleading `action`/nonce so the form is honestly AJAX-only. | S | **DONE** (v1.9.21) |
| TRC-UI2 | Low | Same dead fallback for create-project | `templates/projects.php:227-230` | `action=pltt_create_project`, no handler, nonce names `pltt_update_project`. Same fix as TRC-UI1. | S | **DONE** (v1.9.21) |
| TRC-API2 | Low | `create_client` missing `is_wp_error()` guard | `includes/api/class-pltt-ajax.php:351` | Latent (inputs pre-validated). Add `is_wp_error()` guard before passing `Clients::create` return to `Clients::get()`. | S | **DONE** (v1.9.21) |
| TRC-API3 | Low | `create_project` missing `is_wp_error()` guard | `includes/api/class-pltt-ajax.php:456` | Latent. Same shape as TRC-API2. | S | **DONE** (v1.9.21) |
| SEC-L1 | Low | `wp_json_encode` without `JSON_HEX_TAG` in inline script | `templates/reports.php:377-390`, `templates/review.php:94` | A `</script>` in an admin-authored name could break out (self-XSS). Add `JSON_HEX_TAG \| JSON_HEX_AMP` flags. | S | **DONE** (v1.9.21) |
| SEC-L3 | Low | Back-link copies raw `$_GET` (allowlisted) | `templates/reports.php:801` | Mitigated today by `esc_url()` + `wp_validate_redirect()`. Hardening only — explicitly sanitize each copied value by type. | S | **DONE** (v1.9.21) |
| TRC-BIZ-DOC1 | Low | Docblock contradicts code on cache-miss fallback | `includes/helpers.php:1118-1120` (vs `:1137`, `:1145`) | `pltt_resolve_billable_rate()` docblock says a cache miss does NOT hit the DB; the code does. Fix the comment to match (DB fallback on miss). Doc-only. | S | **DONE** (v1.9.21) |

---

## P3 — Duplication consolidation (do before it drifts further)

The post-freeze Project Detail report files are re-implementing money math that already exists. Consolidate into helpers and route callers through it. Full excerpts in [`raw/optimization.md`](raw/optimization.md).

| ID | Title | Locations | Fix | Effort | Status |
|----|-------|-----------|-----|--------|--------|
| OPT-DUP-A | Billable-amount math repeated 7× | helpers / review / ajax (`round((min/60)*rate,2)`) | Extract one `pltt_compute_billable_amount($minutes,$rate)` helper; replace all sites. | M | **DONE** (v1.9.22) |
| OPT-DUP-B | `COALESCE(billable_amount, ROUND(...))` SQL 4× | `includes/database/class-pltt-entries.php:446,529,593,657` | Extract a shared SQL fragment/constant. Expands existing OPT-DUP11. | S | **DONE** (v1.9.22) |
| OPT-DUP-C | Budget→allocation-minutes cascade 3× | `includes/helpers.php:1628`, `class-pltt-project-report.php:212,825` | Single helper. | M | **DONE** (v1.9.22) |
| OPT-DUP-D | verify-cap + nonce boilerplate (9×/6×) | form-handlers / admin | Extract a shared guard helper. Existing OPT-DUP3/4. | M | **DONE** (v1.9.23) — `PLTT_Form_Handlers::guard($action)` (cap+nonce, 8 handlers) and `PLTT_Admin::require_access()` (6 render methods). |
| OPT-DUP-E/F/G/I/J | Effective-rate calc 3×; per-period delta/arrow 2×; overage-dollar blocks 2×; `<option>` builder 3× (incl. review.js:1276); review.js twin-IIFE ~200 LOC modal dup | various — see raw | Consolidate per item. J is the largest (relates to the planned native-`<dialog>` refactor). | M–L | **E/F/G DONE** (v1.9.24) — `pltt_effective_rate()`, `pltt_pct_change_indicator()`, `pltt_resolve_entry_amount()`. **I/J still TODO** (JS `<option>` builder + review.js twin IIFEs). |

---

## P3 — Sizing / structure

| ID | Title | File:line | Fix | Effort | Status |
|----|-------|-----------|-----|--------|--------|
| OPT-SIZE-A | 161-line god-function `pltt_compute_overage_threshold()` | `includes/helpers.php:1605` | Decompose into named sub-steps. | M | TODO |
| OPT-SIZE-B | `PLTT_Reports::render()` 249 lines | (OPT-C1) | Extract sections. | M | TODO |
| OPT-SIZE-C | `pltt_render_entry_table()` 178 lines | (OPT-C10) | Extract row/header builders. | M | TODO |
| OPT-SIZE-E | Timeline template doing controller work | `templates/.../project-detail-report.php:352-438` | Move computation out of the template into the report class. | M | TODO |

---

## P3 — Performance

| ID | Title | Where | Fix | Effort | Status |
|----|-------|-------|-----|--------|--------|
| OPT-N-C | `SELECT *` over full-lifetime entries | `class-pltt-project-report.php:62` | Select only needed columns. | S | TODO |
| OPT-N-D | `PLTT_Entries::get()` called twice in save | `includes/api/class-pltt-ajax.php:605,618` | Reuse the first fetch. | S | TODO |
| OPT-N-E | Per-row client/project `get()` in render | render_entry_row path | Use bulk loaders (`get_multiple`). | S | TODO |
| OPT-PERF-A | `is_period` re-derived 3× (drift risk) | Project Detail render | Compute once. | S | **DONE** (v1.9.23) — resolver returns explicit `is_period`; 4 derivations now read it. Same batch also landed OPT-PERF-B (`pltt_chart_y_ceiling()` helper, parity-verified), OPT-PERF-C (per-bucket total stored in `pltt_build_period_chart_data`; the format_duration/num dups were already gone via OPT-DUP-C), and the chart-context unpack dedup (the `chart-by-period.php` partial now takes a single `$chart` array). |
| OPT-PERF-E | JS `updateBillableCards` re-implements PHP delta math, no SYNC marker | `assets/js/` (reports) | At minimum add a SYNC comment pointing at the PHP source; ideally share the contract. | S | **DONE** (v1.9.24) — SYNC comment on `reports.js updateBillableCards` ↔ `pltt_pct_change_indicator()`. |

---

### Notes for implementers
- **TRC-DB23 is the only finding that changes runtime behavior on the real DB** — prioritize and test it against MySQL 8 (LocalWP default), not just MariaDB.
- The security posture is already strong; the SEC-* items are privacy/hardening, not exploitable-by-outsider bugs.
- Where an ID echoes an existing `audit/TASK-BACKLOG.md` entry, reconcile rather than duplicate.

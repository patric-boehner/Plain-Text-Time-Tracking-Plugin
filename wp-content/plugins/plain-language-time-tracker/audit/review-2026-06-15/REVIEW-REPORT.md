# Code Review — Security, Optimization & Traceability

**Date:** 2026-06-15
**Branch:** feature/project-detail-page (plugin v1.9.18 / DB 1.9.5)
**Method:** Six parallel specialist sub-agents — one security, one optimization, and four traceability passes (one per layer: UI → API handlers → business logic → database). Each read both sides of every call edge and verified against the actual code.

Raw per-agent findings live in [`raw/`](raw/). The pickable task list is [`TASKS.md`](TASKS.md).

---

## Executive summary

**The codebase is in genuinely good shape.** This is clearly post-audit code — the May 2026 `SEC-*`/`OPT-*`/`TRC-*` backlog is largely shipped, and the mitigations are real, not cosmetic. There is **no Critical or High security issue**, and the call graph is sound: of **142 traced call edges across four layers, 134 verified clean** and the 8 flags are mostly latent or low-severity.

The findings that actually matter are a short list:

| # | What | Why it matters | Severity |
|---|------|----------------|----------|
| 1 | **Transaction guard is non-functional on MySQL** (`TRC-DB23`) | The "no partial data" atomicity the code promises is silently defeated on the production DB engine. Real correctness bug. | **High (functional)** |
| 2 | **Empty client name saved as "success"** (`TRC-API1`) | Reachable: a `WP_Error` is treated as truthy success and the user sees "client updated" when nothing was. | **Medium** |
| 3 | **Migration log written to public uploads** (`SEC-M1`) | Client names + project names + work descriptions in a world-readable file with a guessable URL, surfaced in an admin notice. | **Medium (privacy)** |
| 4 | **Dead create-fallback + nonce/action mismatch** (`TRC-UI1/UI2`) | Latent dead code; the no-JS path is broken and the nonce field names the wrong action. | **Low** |
| 5 | **Orphaned bulk-group handler** (`TRC-UI3`) | A registered, nonce-checked `admin_post` endpoint with no UI that reaches it. | **Low** |

Everything else is hardening, consolidation, and performance polish — worth doing, not urgent.

---

## 1. Security

**Result: 0 Critical · 0 High · 1 Medium · 3 Low.** Full detail: [`raw/security.md`](raw/security.md).

The security agent reviewed every PHP file, all templates, and the key JS, verifying each sink against the actual code. It explicitly **cleared** the high-value attack surfaces:

- All **12 AJAX handlers** use the `if ( ! self::verify_request() ) { return; }` return-guard (nonce + capability).
- All **9 `admin_post` handlers** check capability + nonce.
- Every `$wpdb` query with variable input uses `prepare()`; `IN()` clauses are built with generated placeholders; `ORDER BY`/identifiers are allowlisted.
- Strict `0|1` validation on `billable`/`billed`; `recurring_period` allowlist enforced; fail-closed date sanitizing on destructive paths.
- `WP_Error` → redirect uses `get_error_code()` only; field-allowlist updates (no mass-assignment); fully escaped templates and `escapeHtml`/`parseInt` JS; guarded `uninstall.php`.
- IDOR is not a meaningful boundary here — this is a single-operator `manage_options`-only tool with no lower-privilege role owning data.

**The findings:**

- **SEC-M1 (Medium)** — `class-pltt-database.php:518-621` writes a migration log containing client names, project names, and work descriptions into `wp-content/uploads/` with a guessable timestamped filename and no access protection, then exposes the URL in an admin notice (`class-pltt-admin.php:40-62`). Information disclosure of business data.
- **SEC-L1 (Low)** — `wp_json_encode()` without `JSON_HEX_TAG` emitted into inline `<script>` (`templates/reports.php:377-390`, `templates/review.php:94`); a `</script>` inside a project/client/tag name could break out. Admin-authored data only → self-XSS.
- **SEC-L2 (Low)** — the migration `.txt` log is never deleted (notice-dismiss, `drop_tables()`, or uninstall). Pairs with SEC-M1.
- **SEC-L3 (Low)** — Reports back-link copies raw allowlisted `$_GET` values; mitigated today by `esc_url()` + `wp_validate_redirect()`. Defense-in-depth only.

---

## 2. Optimization

Full detail: [`raw/optimization.md`](raw/optimization.md). Most of the May backlog (OPT-D* dead-code sweep, OPT-DUP5 rate cascade, OPT-N1/N2) is **already done**. New issues cluster in the post-freeze **Project Detail report** files.

**Counts:** 4 dead-code · 11 duplication · 5 N+1/DB · 5 sizing · 5 performance.

**Highest-ROI:**
- **Dead code (grep-proven, zero callers):** `PLTT_Tags::get_for_entry()` (class-pltt-tags.php:333), `PLTT_Tags::set_group()` (:186), `pltt_count_working_days()` (helpers.php:435), dead `modal._refreshFocusTrap` (shared.js:148). Four clean deletes.
- **Redundant queries per Project Detail render:** duplicate windowed `get_stats()` (project-detail.php:67 vs project-report.php:93) and `get_all_groups()` run twice (project-report.php:81/107). Two S-effort wins.
- **Money-math duplication before it drifts:** billable-amount `round((min/60)*rate,2)` repeated 7×; `COALESCE(billable_amount, ROUND(...))` SQL 4×; budget→allocation-minutes cascade 3×. Consolidate into helpers.
- **God-function:** `pltt_compute_overage_threshold()` — 161 lines (helpers.php:1605).

See TASKS.md for the full enumerated list with line refs.

---

## 3. Traceability

Four agents traced the call graph layer by layer, opening both the caller and callee for every edge.

| Layer | Scope | Edges/elements | OK | Flagged |
|-------|-------|----------------|----|---------|
| **UI** | templates/, assets/js/ | 39 interactive + 1 orphan | 36 | 3 |
| **API handlers** | PLTT_Ajax, PLTT_Form_Handlers | 20 handlers / 42 downstream edges | 39 | 3 |
| **Business logic** | admin/, parser/, helpers | 61 edges | 60 | 1 (doc) |
| **Database** | database/ | 64 methods | 63 | 1 |

**The chain holds end-to-end.** Every AJAX `action` string in JS maps 1:1 to a registered handler; the `pltt_ajax_nonce`/`nonce` pair matches `check_ajax_referer`; `admin_post` forms carry the correct action-specific `_wpnonce`; field sets sent match `$_POST` reads; arg order/count/types match method signatures throughout; bulk loaders are keyed correctly by every caller; the previously hand-rolled rate cascade in `format_entries_for_review` now correctly delegates to `pltt_resolve_billable_rate()`.

**Flags, by layer:**

- **DB — TRC-DB23 (High, functional):** `$wpdb->get_var('SELECT @@in_transaction')` at `class-pltt-entries.php:205` (`create`) and `:308` (`update`). `@@in_transaction` exists in MariaDB but **not MySQL 8.x**, which LocalWP runs. On MySQL the query errors, `get_var` returns NULL, so `$own_transaction` is **always true** → `create()`/`update()` always issue their own `START TRANSACTION`. When a caller already holds an outer transaction (`class-pltt-ajax.php:149` save_entries loop; `:244` update_entry_field billable branch), the inner START **implicitly commits the outer one**, defeating the atomicity the comments (and SEC-M6) promise. *Fix: replace the server-variable probe with a plugin-owned transaction-depth counter, or use savepoints.*
- **API — TRC-API1 (Medium, reachable):** `handle_update_client` (form-handlers.php:110) guards success with `if ( $result )`, but `PLTT_Clients::update()` can return a `WP_Error` (e.g. empty name), which is truthy → redirects with the `client_updated` success message. Siblings all use `is_wp_error()`. *Fix: add an `is_wp_error()` guard matching the other handlers.*
- **API — TRC-API2 / TRC-API3 (Low, latent):** `create_client` (ajax.php:351) and `create_project` (ajax.php:456) don't `is_wp_error()`-guard the create return before passing it onward; currently unreachable because inputs are pre-validated at the boundary.
- **UI — TRC-UI1 / TRC-UI2 (Low):** create-client / create-project forms default to `action=pltt_create_client|pltt_create_project` with **no `admin_post` handler** (AJAX-only), and their nonce field names the *update* action. No-JS submit is dead; nonce/action mismatch. (clients.php:135-138, projects.php:227-230.)
- **UI — TRC-UI3 (Low):** `admin_post_pltt_bulk_assign_group` → `handle_bulk_assign_group` (form-handlers.php:39, nonce `pltt_bulk_tag_group`) is registered and implemented but **no template/JS emits its form**. Consistent with the in-progress tag-taxonomy work — wire it or remove it.
- **Business — TRC-BIZ-DOC1 (Low, doc-only):** `pltt_resolve_billable_rate()` docblock (helpers.php:1118-1120) claims a cache miss does *not* fall back to the DB; the code (and MEMORY.md) say it *does* (helpers.php:1137 & 1145). No caller is misled in practice. *Fix the comment.*

---

## Recommended order of work

1. **TRC-DB23** — real atomicity bug on the production engine. Do first.
2. **TRC-API1** — reachable wrong-success on empty client name. Small, clear fix.
3. **SEC-M1 (+ SEC-L2)** — get business data out of public uploads; clean up the log file lifecycle.
4. **Optimization quick wins** — 4 dead-code deletes + 2 redundant Project Detail queries (all S).
5. **TRC-UI1/UI2/UI3, TRC-API2/3, SEC-L1/L3, TRC-BIZ-DOC1** — hardening + dead-path cleanup.
6. **Money-math + cascade consolidation** — before it drifts further (the post-freeze report files are already re-implementing it).

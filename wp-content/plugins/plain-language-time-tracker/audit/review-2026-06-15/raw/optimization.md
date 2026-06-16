# Optimization & Code-Quality Review — 2026-06-15

Scope: all PHP and JS under the plugin root. Senior-engineer pass focused on dead code,
duplication, N+1 / DB inefficiency, sizing/structure, and performance.

**Context vs. the May 2026 audit (`audit/TASK-BACKLOG.md`).** A large amount of that backlog has
since been *shipped*: the `pltt_resolve_billable_rate` cascade in `PLTT_Review` (TRC-3/OPT-DUP5) is
fixed (now routes through the helper at `class-pltt-review.php:252`); the N+1 stat loops on the
Clients/Projects screens are fixed via `PLTT_Entries::get_stats_grouped_by()` (OPT-N1/N2);
admin-notice and billing-type-badge helpers exist; and the entire OPT-D / CSS-dead / JS-dead sweep
(D1–D47) is done — **every** previously-flagged dead PHP method, CSS rule, and JS function verified
gone or now-used (see "Backlog status" at the end). This review therefore concentrates on what is
**still present** and what is **new** since that audit — chiefly the post-freeze Project Detail
report page (`class-pltt-project-report.php`, `project-detail-report.php`, `chart-by-period.php`) and
the overage/allocation helpers.

Verification method: every dead/dup finding was grepped across `templates/`, `includes/`, and
`assets/js/` (excluding `audit/`). Line numbers confirmed against current files.

---

## 1. DEAD CODE

### OPT-DEAD1 — `PLTT_Tags::get_for_entry()` has zero callers
- **File:** `includes/database/class-pltt-tags.php:333`
- **Evidence:** `public static function get_for_entry( $entry_id )` — the singular per-entry tag loader. All callers use the bulk `get_for_entries()` (junction) instead.
- **Proof:** `grep -rn "get_for_entry\b" --include="*.php" .` → only the definition at `tags.php:333`. (`get_for_entries`, the plural, is the live one.)
- **Impact:** Dead method; also a latent N+1 footgun if a future caller reaches for it.
- **Fix:** Delete the method.
- **Effort:** S

### OPT-DEAD2 — `PLTT_Tags::set_group()` has zero callers
- **File:** `includes/database/class-pltt-tags.php:186`
- **Evidence:** `public static function set_group( $id, $group_name )`. The comment at `tags.php:143` *mentions* it ("set_group() handles NULL writes"), but no code calls it. The live path is `bulk_set_group()` (`tags.php:219`, called from `class-pltt-form-handlers.php:412`).
- **Proof:** `grep -rn "set_group" --include="*.php" .` → matches are the definition (186), the comment (143), and `bulk_set_group` (219, 412). No invocation of `::set_group(` or `self::set_group(` anywhere.
- **Impact:** Dead method.
- **Fix:** Delete it (and the stale comment reference), or fold its single-row NULL-write into `bulk_set_group`.
- **Effort:** S

### OPT-DEAD3 — `pltt_count_working_days()` has zero callers
- **File:** `includes/helpers.php:435-452`
- **Evidence:** Builds a `DatePeriod` and counts Mon–Fri days. Never used.
- **Proof:** `grep -rn "pltt_count_working_days" --include="*.php" .` → only the definition at `helpers.php:435`.
- **Impact:** Dead helper (~18 lines).
- **Fix:** Delete. (If a working-days denominator is wanted for a future "avg per working day" card, leave a TODO — but it isn't wired anywhere today.)
- **Effort:** S

### OPT-DEAD4 — Dead exposed property `modal._refreshFocusTrap`
- **File:** `assets/js/shared.js:148` (assignment) + comment `:134-135`, `:141-147`
- **Evidence:** `modal._refreshFocusTrap = refreshFocusTrap;` with comment "Expose refresh so callers can call modal._refreshFocusTrap() if they mutate content." The internal closure `refreshFocusTrap()` IS used (re-open path), but the *exposed* property is not.
- **Proof:** `grep -rn "_refreshFocusTrap" assets/js/ templates/ | grep -v shared.js` → zero hits. No external caller.
- **Impact:** Dead public surface on every modal element; an aspirational OPT-L4 optimization comment with no consumer.
- **Fix:** Keep the internal closure; delete the `modal._refreshFocusTrap = ...` assignment and its comment.
- **Effort:** S

> **Unused parameter (dead surface, not dead code):** `PLTT_Project_Report::build()`'s 3rd param
> `$client` (`class-pltt-project-report.php:55`) is documented "unused directly; reserved" and the
> sole caller passes `null` (`class-pltt-project-detail.php:71`). Drop it or use it. Effort S.

No other unreachable branches, commented-out blocks, or orphaned CSS found — the CSS sweep confirmed
all 14 previously-flagged selectors already deleted and the three new Project-Detail CSS files
(`pltt-chart.css`, `project-detail.css`, `pltt-tooltip.css`) have **zero** orphans (dynamic classes
like `pltt-bar-color-0..7`, `pltt-chart-col-empty/-today/-weekend`, tooltip `is-visible` all
verified emitted).

---

## 2. DUPLICATION

### OPT-DUP-A — Billable-amount computation `round( ($min / 60.0) * $rate, 2 )` repeated 7× in PHP
- **Files / lines:**
  - `includes/helpers.php:1376`, `:1698`, `:1742`
  - `includes/admin/class-pltt-review.php:253`, `:410`, `:415`
  - `includes/api/class-pltt-ajax.php:254`, `:599`
- **Evidence:** Every billable-amount snapshot is the same inline `round( ( $minutes / 60.0 ) * $rate, 2 )`. The companion `$rate = pltt_resolve_billable_rate(...)` cascade frequently precedes it.
- **Proof:** `grep -rn "/ 60.0 ) \* " --include="*.php" includes/` → the 8 sites above (one is the SQL variant, see OPT-DUP-B).
- **Impact:** The "minutes → billable dollars" rule is the plugin's core money math, spread across 3 files with no single source of truth. Rounding-mode or rate-resolution drift would silently mis-bill.
- **Fix:** Add `pltt_billable_amount( int $minutes, float $rate ): float` (and optionally a
  `pltt_snapshot_billable( $client_id, $project_id, $minutes, $caches = [] ): array{rate, amount}`)
  in `helpers.php`; route all 7 PHP call sites through it.
- **Effort:** M
- **Existing ID:** none (related to but distinct from OPT-DUP5, which only covered the *rate* cascade).

### OPT-DUP-B — `COALESCE(e.billable_amount, ROUND(...))` SQL fragment repeated 4× (expands OPT-DUP11)
- **File:** `includes/database/class-pltt-entries.php:446`, `:529`, `:593`, `:657`
- **Evidence:** Identical `COALESCE(SUM(CASE WHEN e.billable = 1 THEN COALESCE(e.billable_amount, ROUND(e.duration_minutes / 60.0 * COALESCE(p.hourly_rate, c.hourly_rate, 0), 2)) ELSE 0 END), 0)` (line 657 is the non-`billable=1` variant) across `get_stats`, `get_stats_grouped_by`, `get_summary_by_project`, `get_unbilled_outside_range_summary`.
- **Proof:** `grep -rn "COALESCE(e.billable_amount, ROUND" --include="*.php" includes/` → 4 hits.
- **Impact:** Same money rule duplicated in SQL; the fallback rate cascade `COALESCE(p.hourly_rate, c.hourly_rate, 0)` must stay in lock-step with the PHP `pltt_resolve_billable_rate` cascade.
- **Fix:** `private static function billable_amount_expr( string $alias = 'e' ): string` returning the fragment; interpolate it. (Backlog OPT-DUP11 named only lines 482/576 — both have since moved; this is the current, wider set.)
- **Effort:** S
- **Existing ID:** OPT-DUP11 (superseded — update line refs to 446/529/593/657).

### OPT-DUP-C — Budget → allocation-minutes cascade duplicated 3×
- **Files / lines:**
  - `includes/helpers.php:1628-1637` (`pltt_compute_overage_threshold`)
  - `includes/admin/class-pltt-project-report.php:212-218` (`cards_fixed`)
  - `includes/admin/class-pltt-project-report.php:825-831` (`build_budget_line` — comment literally says "Mirrors cards_fixed()")
- **Evidence:** All three resolve "budgeted minutes" as: explicit `budget_hours * 60`, else `budget_fee / rate * 60`.
- **Proof:** Read all three; the project-report pair is self-documented as a mirror.
- **Impact:** The budget interpretation rule (and the hours-vs-fee precedence) is triplicated; a change to budgeting must touch 3 spots or silently diverge.
- **Fix:** `pltt_budgeted_minutes( $project, float $rate ): int` in `helpers.php`; call from all three.
- **Effort:** S
- **Existing ID:** none.

### OPT-DUP-D — Verify-capability + nonce boilerplate (still 9× in form-handlers, 6× in admin)
- **Files / lines:** `includes/api/class-pltt-form-handlers.php` — `if ( ! pltt_user_can_access() ) { wp_die(...) }` at `:73, 123, 150, 245, 272, 315, 364, 392, 427` (9 copies), each followed by a `self::verify_nonce( '<action>' )`. `includes/admin/class-pltt-admin.php` — same `pltt_user_can_access()`/`wp_die` guard at `:36, 70, 160, 181, 192, 203, 214, 232` (6+ copies).
- **Proof:** `grep -n "pltt_user_can_access" includes/api/class-pltt-form-handlers.php includes/admin/class-pltt-admin.php`.
- **Impact:** Security-relevant boilerplate copy-pasted; easy to forget one on a new handler.
- **Fix:** `private static function verify_form_request( $nonce_action )` combining the cap check + `verify_nonce`; one call per handler.
- **Effort:** M
- **Existing ID:** OPT-DUP3 / OPT-DUP4 (both confirmed still open; line refs refreshed above).

### OPT-DUP-E — Effective-hourly-rate (EHR) `dollars / hours` computed 3×
- **Files / lines:** `class-pltt-project-report.php:289` (`$billable_amount / $total_hours`), `:220` (`$fee / $total_hours`), `class-pltt-reports.php:165-166` (`billable_amount / (total_minutes/60)`).
- **Evidence:** Same "dollars ÷ hours, guarded > 0" with subtly different guards (fixed-card guards on `$fee`, reports guards on `total_minutes && billable_amount`).
- **Impact:** Low correctness risk, real drift risk on the guard.
- **Fix:** `pltt_effective_rate( float $amount, int $minutes ): float`.
- **Effort:** S
- **Existing ID:** none.

### OPT-DUP-F — Per-period delta + arrow-icon selection duplicated within `templates/reports.php`
- **File:** `templates/reports.php:456-474` (Billable Hours card) and `:508-527` (Billable Amount card).
- **Evidence:** Two near-identical ~18-line blocks computing pct-change and choosing `status-neutral/increase/decrease` + `→/↑/↓`, differing only in the metric. The `< 5%` neutral threshold and the glyphs are hardcoded twice.
- **Impact:** Medium maintenance; the JS card updater (`reports.js:479-540`, see OPT-DUP-J) re-implements the *same* logic a third time, with no SYNC marker.
- **Fix:** `pltt_pct_change_indicator( $curr, $prev ): array{pct, class, icon}`; reuse in both PHP cards, and add a SYNC comment tying it to the JS copy.
- **Effort:** M
- **Existing ID:** none.

### OPT-DUP-G — Within/overage billable-dollar accumulation duplicated inside one function
- **File:** `includes/helpers.php:1692-1700` (marked-billable running total) and `:1737-1744` (overage running total).
- **Evidence:** Both blocks: `if billable && dur>0 { if billable_amount set use it; else resolve rate + round(dur/60*rate,2) }`.
- **Impact:** Same accumulation written twice in `pltt_compute_overage_threshold`.
- **Fix:** Local closure `entry_amount( $e )` (or the OPT-DUP-A helper) used by both.
- **Effort:** S
- **Existing ID:** none.

### OPT-DUP-H — Dependent-count delete-guard blocks duplicated (still open)
- **Files / lines:** `includes/database/class-pltt-clients.php:236-280` (projects-using + entries-using guards, two blocks) and `includes/database/class-pltt-projects.php:~485-500` (entries-using guard).
- **Proof:** `grep -n "Cannot delete" class-pltt-clients.php class-pltt-projects.php` → the three `_n()` singular/plural error blocks.
- **Fix:** `pltt_check_dependent_count( $table, $where_col, $id, $singular, $plural, $error_code )`.
- **Effort:** M
- **Existing ID:** OPT-DUP12 (confirmed open).

### OPT-DUP-I — `<option>` builder triplicated in JS (expands OPT-DUP17)
- **Files / lines:** `assets/js/review.js:328-346` (first IIFE `loadProjects`), `assets/js/review.js:1276-1284` (**second IIFE** `loadProjects` — a copy the backlog never listed), `assets/js/reports.js:315-326` (DOM-node variant).
- **Evidence:** All build `<option value=parseInt(id) data-billability-default=...>`; the SEC-M13 `parseInt(id,10)` hardening had to be applied in all three.
- **Proof:** `grep -n "function loadProjects" review.js` → two defs (310, 1263); `grep "buildProjectOption"` → empty (helper never created).
- **Fix:** `PLTT.buildProjectOption( proj, { archivedAllowed } )` returning an `<option>`; use everywhere.
- **Effort:** M
- **Existing ID:** OPT-DUP17 (open; note the third copy at review.js:1276).

### OPT-DUP-J — `review.js` two mutually-exclusive IIFEs duplicate ~200 LOC of modal/AJAX logic
- **File:** `assets/js/review.js`. Duplicated pairs: `calculateDuration` (`:73-88` vs `:922-933`, byte-identical), `loadProjects` (`:310-369` vs `:1263-1288`), `updateSummary` (`:677-698` vs `:1032-1039`), and the create-client/project/tag modal-save handlers (`:482-536` vs `:1293-1334`, `:541-592` vs `:1339-1379`, `:597-635` vs `:1384-1418`).
- **Evidence:** The post-parse IIFE (gated on `#pltt-review-form`) and the edit-existing IIFE differ only in row selectors (`.pltt-client-select` vs `.pltt-form-client`). They are *mutually exclusive* (second returns early at `:755-757`), so not a double-bind bug — but it's why the file is 1432 lines, and a fix to client creation must be made twice.
- **Fix:** Hoist `calculateDuration` + the three modal-save handlers to a shared scope (they target the same modal IDs `pltt-save-client/project/tag`, so one binding suffices); parameterize the row selector.
- **Effort:** L
- **Existing ID:** none (new — suggest OPT-DUP28).

### OPT-DUP-K — Other still-open JS/PHP duplications confirmed (refreshed line refs)
- **Date-nav widget** duplicated `reports.js:18-279` ↔ `log-archive.js:18-177` (~250 LOC). *Backlog OPT-DUP18 cited reports.js:18-264 — now :18-279.* Effort L.
- **`formatTimeForDisplay`/`formatDateForDisplay`** still local to `review.js:137-152, 160-166`; never moved to `PLTT.formatTime12()`/`formatDateShort()` (those names don't exist). `shared.js:257-269 getCurrentTime` duplicates the 12-hour conversion. **OPT-DUP15** open. Effort S.
- **URL notice-cleanup script** now has **5** copies: inline at `templates/clients.php:179-184`, `projects.php:484-490`, `tags.php:154-158`, `daily-log.php:158-163`, plus a 5th already-extracted copy at `assets/js/project-detail.js:13-25`. **OPT-DUP2** open. Effort M.
- **`applyBillingTypeUI`** inline in `templates/projects.php:372-462` (91 lines, nested if/else over 4 billing types) — **OPT-C9/OPT-DUP20/OPT-C6** open. Effort M.
- **Chart-context unpack** (5 locals: buckets/bucket_size/max_minutes/avg_minutes/today_key) duplicated `class-pltt-reports.php:196-200` ↔ `project-detail-report.php:129-133`, both feeding `chart-by-period.php`. New; have the partial take a single `$chart` array. Effort S.

> **In sync (no action, keep the SYNC comments):** `pltt_format_duration` (`helpers.php:24-40`) ↔
> `formatDuration` (`shared.js:63-78`) and `pltt_format_hours`/`formatHours` are verified
> byte-equivalent in behavior (Math.round/round, floor, h/m branch). TRC-8/TRC-9 satisfied.

---

## 3. N+1 / DB INEFFICIENCY

### OPT-N-A — Duplicate identical windowed `get_stats()` per recurring-project detail view
- **Files:** `includes/admin/class-pltt-project-detail.php:67` (subhead stats for the period) and `includes/admin/class-pltt-project-report.php:93-99` (windowed branch, reached via the `build()` call at `class-pltt-project-detail.php:71`).
- **Evidence:** Both run `PLTT_Entries::get_stats()` with the **same** `project_id` + `date_from` + `date_to` (a 2-LEFT-JOIN aggregate) in a single request, for a recurring project in period scope.
- **Impact:** One redundant aggregate query per Project Detail render (period lens).
- **Fix:** Compute the windowed stats once in the controller and pass into `build()` (it already accepts a `$stats` param).
- **Effort:** S

### OPT-N-B — `PLTT_Tags::get_all_groups()` runs twice per windowed render
- **Files:** `class-pltt-project-report.php:81` and `:107` both call `build_groupings()`, each of which calls `PLTT_Tags::get_all_groups()` (`:486`), an uncached `SELECT DISTINCT group_name` (`tags.php:264-274`).
- **Impact:** Identical `DISTINCT group_name` query issued twice for a windowed recurring project.
- **Fix:** Hoist `$group_names = PLTT_Tags::get_all_groups()` into `build()` and pass it down; or request-cache `get_all_groups()`.
- **Effort:** S

### OPT-N-C — `SELECT *` over the full lifetime entry set on the Project Detail page
- **File:** `class-pltt-project-report.php:62-68` loads **all** lifetime entries via `PLTT_Entries::get_all()` (`SELECT *`, `entries.php:95`), but the grouping/timeline pass only reads `id`, `entry_date`, `duration_minutes`.
- **Impact:** On a long-running project this pulls every column (incl. `raw_text`, `description`) for potentially thousands of rows just to bucket aggregates in PHP. Memory + transfer.
- **Fix:** Add a `fields` option to `get_all()` (or a dedicated lean loader) selecting only `id, entry_date, duration_minutes` for the timeline pass; or push bucketing into SQL.
- **Effort:** M
- **Existing ID:** related to OPT-N12 (omit `raw_text` in list contexts).

### OPT-N-D — `PLTT_Entries::get()` called twice in `save_entry`
- **File:** `includes/api/class-pltt-ajax.php:605` (`$existing`) and `:618` (`$saved_entry`).
- **Evidence:** The pre-update existence check and the post-update re-fetch are two separate single-row queries; the first result is only used for a null-check.
- **Impact:** One extra `SELECT * WHERE id=%d` per per-row save.
- **Fix:** Minor — the existence check could be folded, or the re-fetch is genuinely needed (fresh row for render). Acceptable, but worth noting. Effort S.

### OPT-N-E — Per-row `PLTT_Clients::get()` + `PLTT_Projects::get()` in `render_entry_row`
- **File:** `includes/admin/class-pltt-review.php:131-132`.
- **Evidence:** `PLTT_Clients::get( $formatted['client_id'] )` and `PLTT_Projects::get( $formatted['project_id'] )` — two single-row queries.
- **Impact:** Low — `render_entry_row` renders ONE entry (called only from `save_entry` AJAX), so it's 2 queries per row-save, not a loop. Flag only; not a true N+1.
- **Effort:** S (skip unless this method is ever called in a loop).

> **Still-open N items from the backlog** (verified): OPT-N8/N9 (per-tag and per-junction
> `INSERT IGNORE` loops in `PLTT_Tags::sync_entry_tags()`), OPT-N5 (`fields` arg on
> `PLTT_Projects::get_all()` for the Reports filter dropdown), OPT-N14 (`find_in_text` loops every
> alias with a `preg_match` + `usort` per call — aliases are request-cached so it's CPU not queries).

---

## 4. SIZING / STRUCTURE

### OPT-SIZE-A — `pltt_compute_overage_threshold()` — 161 lines, god-function
- **File:** `includes/helpers.php:1605-1766`
- **Evidence:** Single function doing: allocation resolution (hours/fee), period-bounds + range-spans-period guard, a full period-entry scan that simultaneously computes the boundary clock time, marker entry, overage IDs, marked-billable totals, and overage dollars (with two duplicated amount blocks — OPT-DUP-G).
- **Impact:** The most complex untested function in the plugin; entangles 5 outputs in one loop.
- **Fix:** Extract `resolve_allocation_minutes()`, `scan_period_for_crossing()` (boundary + ids), and `accumulate_billable()`.
- **Effort:** M (was not in the backlog — new since the overage feature)

### OPT-SIZE-B — `PLTT_Reports::render()` — ~249 lines, god-method
- **File:** `includes/admin/class-pltt-reports.php:26-274`
- **Evidence:** GET parsing, filter assembly, client-context, overage/unbilled context, main + prev-period (matched-slice) stats, top projects, pagination, chart context, AND the allocation-bucketing loop (`:214-243`, nested 4 deep at `:216-230`).
- **Fix:** Split into `build_filter_args()`, `build_context_cards()`, `build_prev_period_stats()`, `build_chart_context()`, `build_alloc_stats()`.
- **Effort:** L
- **Existing ID:** OPT-C1 (line range refreshed: 26-274; backlog said 26-260).

### OPT-SIZE-C — `pltt_render_entry_table()` — 178 lines
- **File:** `includes/helpers.php:1209-~1387`
- **Fix:** Extract `render_entry_row()` from the loop body.
- **Effort:** M
- **Existing ID:** OPT-C10 (confirmed open).

### OPT-SIZE-D — `PLTT_Project_Report::build_one_grouping()` — 91 lines
- **File:** `includes/admin/class-pltt-project-report.php:521-611`
- **Evidence:** Tag-membership split (outer entries × inner tags), accumulation, finalize, two different sorts (phase-order vs hours, `:577-591`), untagged handling.
- **Fix:** Extract the per-entry membership split (`:537-548`) and the sort step.
- **Effort:** M (new since backlog)

### OPT-SIZE-E — Timeline segment rendering in template — ~86 lines of controller-grade work
- **File:** `templates/partials/project-detail-report.php:352-438`
- **Evidence:** `foreach dimensions → foreach buckets → foreach segments`, building `wp_json_encode` tooltip arrays and computing `axis_pct` left/width inline in the view.
- **Fix:** Precompute segment geometry (left/width %, tooltip rows) in `PLTT_Project_Report::finalize_bucket()` so the template only echoes. Aligns with the project's "data-layer, HTML/CSS over SVG" intent.
- **Effort:** M (new)

> **Still-open structural items from the backlog** (verified present): OPT-C4
> (`PLTT_Ajax::update_entry_field()` `:203-291`, branchy field dispatch), OPT-C2/C3
> (`PLTT_Entries::update()` `:246-342` and `PLTT_Projects::update()` field-by-field tables),
> OPT-C7/C8 (`format_entries_for_review` / `save_entries` extractions), OPT-C5 (computation in
> `templates/reports.php`).

---

## 5. PERFORMANCE / RECOMPUTATION

### OPT-PERF-A — `is_period` scope test re-derived in 3 places (drift risk)
- **Files:** `class-pltt-project-detail.php:65`, `class-pltt-project-report.php:87-90`, `project-detail-report.php:28` — each recomputes `'period' === ($window['scope'] ?? 'full')`, and the guards differ subtly (the report-layer one *also* requires non-empty from/to).
- **Impact:** Trivial CPU, real correctness drift if the three guards disagree.
- **Fix:** Have `pltt_resolve_project_chart_window()` set an explicit `is_period` bool in its return.
- **Effort:** S

### OPT-PERF-B — Y-axis ceiling math lives in the view, untestable
- **File:** `templates/partials/chart-by-period.php:52-63` — the `<=1 / <=5 / <=20 / else` ceiling rounding is pure numeric logic in a partial.
- **Fix:** Move to `pltt_chart_y_ceiling( int $max_minutes ): int` alongside `pltt_build_period_chart_data()`.
- **Effort:** S
- **Existing ID:** related to OPT-C5.

### OPT-PERF-C — Repeated getter/value recomputation (micro)
- `pltt_format_duration( $total_minutes )` called twice for the same value in `cards_fixed` (`class-pltt-project-report.php:223` and `:243`).
- `self::num( $project->budget_hours )` called twice on adjacent lines (`:212-213`, `:825-826`).
- Bucket total recomputed per bucket in two loops: folded in `helpers.php:711-713` then again in `chart-by-period.php:147`.
- **Fix:** Hoist into locals; store the bucket total on the bucket in `pltt_build_period_chart_data`.
- **Effort:** S (each)

### OPT-PERF-D — Per-instance / per-row JS listeners and DOM rebuilds (still open)
Verified present from the backlog; refreshed locations:
- **Tag picker** rebuilds its entire checkbox DOM via `innerHTML=''` on every `_openDropdown` (`tag-picker.js:156`/`:373`) and rebuilds all pills on every toggle (`:298`). With many pickers per Reports row this is repeated layout churn. **OPT-P1.** Effort M.
- **Tag picker** binds a `document` click listener **per instance** (`tag-picker.js:338`); N pickers ⇒ N closures fire on every page click. **OPT-P7** (also the date-nav outside-click now at `reports.js:190-194`, not `:175-179`). Effort M.
- **review.js** post-parse IIFE attaches client/project `change` listeners **per row** (`:374-396`, `:401-436`) instead of delegating (the edit-existing IIFE *does* delegate on tbody). **OPT-P2.** Effort M.
- **review.js** Escape handler scans all `.pltt-time-cell` reading `style.display` per keypress (`:290-298`). **OPT-P3.** Effort S.
- **log-archive.js** delete callback re-queries summary/pagination/tbody each time (`:219`, `:226`, `:234`). **OPT-P12.** Effort S.
- **reports.js** `bindInlineToggle` adds two separate `document` click listeners (`:574`, called at `:618`/`:672`); could be one. Effort S (new, minor).

### OPT-PERF-E — `reports.js updateBillableCards` re-implements PHP card-delta math with no SYNC marker
- **File:** `assets/js/reports.js:479-540` (62 lines) mirrors the `templates/reports.php:456-527` delta logic (the `< 5%` neutral threshold, `status-increase/decrease`, arrow glyphs) with no comment tying them together (unlike the duration pair).
- **Fix:** After OPT-DUP-F lands a PHP helper, add a SYNC comment on both sides.
- **Effort:** S

---

## 6. CONSISTENCY / QUICK WINS

- **OPT-S5** (still open): `reports.js` reads `typeof X !== 'undefined'` globals at `:290, 312, 445, 480, 696, 711` (`plttProjectsByClient` is set inline at `reports.php:379`). Migrate to one `wp_localize_script('pltt-reports', 'plttReports', {...})` object.
- **OPT-DUP8 / OPT-N4** (still open): `PLTT_Tags::get_name_to_group_map()` called twice on one Reports render (`templates/reports.php` ~`:320` and ~`:383`); cache in a local.
- **OPT-S8** (still open): the Reports view whitelist is duplicated between `class-pltt-reports.php` and `templates/reports.php`; promote to `PLTT_Reports::VIEWS` const.
- **OPT-S6/S7** (still open): budget mutual-exclusion ("clear hours when fee set") is enforced in the handler + AJAX (`class-pltt-ajax.php:453`) but not centralized in `PLTT_Projects::create/update`.

---

## Backlog status corrections (for `audit/TASK-BACKLOG.md`)

**Confirmed DONE (close these):** OPT-DUP5/TRC-3, OPT-N1, OPT-N2/N3, OPT-N6, OPT-DUP1, OPT-DUP6,
OPT-DUP7, OPT-DUP19, OPT-D1–D47 (entire dead-code + CSS sweep), OPT-D30, OPT-D47, TRC-8, TRC-9.

**Confirmed STILL OPEN (line refs refreshed in findings above):** OPT-C1 (→26-274), OPT-C2, OPT-C3,
OPT-C4, OPT-C5, OPT-C7, OPT-C8, OPT-C9, OPT-C10, OPT-DUP2 (now 5 copies), OPT-DUP3, OPT-DUP4,
OPT-DUP8, OPT-DUP11 (→446/529/593/657), OPT-DUP12, OPT-DUP15, OPT-DUP17 (3rd copy at review.js:1276),
OPT-DUP18 (→reports.js:18-279), OPT-DUP20, OPT-N5, OPT-N8, OPT-N9, OPT-N12, OPT-N14, OPT-P1, OPT-P2,
OPT-P3, OPT-P7 (→reports.js:190-194), OPT-P12, OPT-S5, OPT-S6, OPT-S7, OPT-S8.

**NEW this review (not in the May backlog):** OPT-DEAD1, OPT-DEAD2, OPT-DEAD3, OPT-DEAD4,
OPT-DUP-A, OPT-DUP-B (expands DUP11), OPT-DUP-C, OPT-DUP-E, OPT-DUP-F, OPT-DUP-G, OPT-DUP-I (3rd copy),
OPT-DUP-J, chart-unpack dup, OPT-N-A, OPT-N-B, OPT-N-C, OPT-N-D, OPT-SIZE-A, OPT-SIZE-D, OPT-SIZE-E,
OPT-PERF-A, OPT-PERF-B, OPT-PERF-E. (Most cluster in the post-freeze Project Detail report files.)

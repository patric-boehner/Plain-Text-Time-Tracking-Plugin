# Billing Workflow — Implementation Plan (redesign-v2)

Reshape the **Billing** page and the **Insights (Reports) detailed view** so the basic billing
workflow matches the redesign-v2 mockups: commit happens *inside the detailed entry view*, and
Billing becomes a **ledger + doorway**. This is a reshape — ~80% of the server machinery already exists.

## Locked decisions (2026-07-04)
- **One resolution:** the Record-bill flow (select + commit) lives ONLY in the Insights detailed view. Billing = ledger + doorway (no inline commit). Matches `redesign-v2/billing.html`.
- **Fixed-budget billing is in the core build.** Diverges from the shipped "awareness-only, no record" model — fixed now produces a record billing the **budget amount** (see [[project-billing-model-invariants]] for the old rule this supersedes in v2).
- **Entry status vocabulary: "Invoiced" → "Billed."**
- Type-driven scope, entry-selection is **hourly-only**; recurring = period overage; fixed = full-project budget (both "confirm-the-number", read-only coverage).

## Reuse-as-is (do NOT rewrite)
- `PLTT_Billing::commit()` — `includes/class-pltt-billing.php:180`. Recomputes scope server-side; posted amount can only lower the bill. **Unchanged** except adding the fixed type (below).
- `pltt_commit_billing` AJAX — `includes/api/class-pltt-ajax.php:780`. Unchanged (already parses included/excluded ids, amount, description, date range, period).
- Dialogs: `templates/partials/billing-dialog.php` (Record bill) + `billing-copy-dialog.php` (line items), built by `pltt_build_billing_scope_view()` (`helpers.php:1722`) + `pltt_render_billing_manifest()`.
- `pltt_render_entry_table()` — `helpers.php:1407`. Already supports `billing_select` (checkbox col) + `covered_entry_ids`/`covered_entry_meta` modes.
- `invoicing.js` — the selection + commit controller (relocate its enqueue to Reports).
- Scope builders: `build_hourly_scope()` (coverage-based, all-time uncovered), `build_retainer_scopes()` (per-period overage), `get_covered_entry_ids/meta()`, `billing_records` + `billing_record_entries` tables (DB 1.9.8), remainder rule `unbilled = calculated − Σbilled − Σabsorbed`.
- Records already have **no sent/paid status** — already aligned.

## Gaps to build
1. Insights detailed single-project card (`templates/partials/project-billing-section.php`) is a read-only **bridge link** to Billing — must become the in-place **billing card** that opens the dialog.
2. `invoicing.js` + dialogs are deliberately NOT enqueued on `pltt-reports` (`class-pltt-admin.php:501-512`) — enqueue them there.
3. Detailed list is scoped/paginated by the **report date range**; a billing scope can fall outside it (hourly all-time-uncovered; a retainer period). "Review & bill" must **re-scope the entry list to the billing scope's entries** (the mockup "range reset"). Trickiest piece.
4. **Fixed-budget billing path does not exist** — `get_ready_to_invoice()` returns `array()` for fixed (`class-pltt-billing.php:53`).

## Phases

### Phase 1 — Billing resolves inside Insights detailed (single-project)
Add-new-before-removing-old: the Billing page keeps working untouched this phase.
- Replace the "To bill $X" + bridge link in `project-billing-section.php` with the **type-aware billing card** (mirror `billing.html`): title, scope line, amount, "Review & bill →".
- "Review & bill" re-queries the detailed entry list to the **billing scope** (not the current date filter) and shows a scope banner. Reuse `PLTT_Billing::get_scope()`/`get_ready_to_invoice()` to get the scope + entries.
  - **Hourly:** render entries with `billing_select` checkboxes (pre-checked); dark action bar "Bill selected · $X →". Reuse `invoicing.js` recompute + exclude path.
  - **Recurring:** read-only covered manifest; period stepper; "Bill overage $X →" (whole period, no selection).
  - **Fixed:** read-only covered manifest (full project); "Bill budget $X →".
- Open the existing `billing-dialog.php`; commit via `pltt_commit_billing` (unchanged). On success rows flip to **Billed** (coverage).
- Enqueue `invoicing.js` + `billing-dialog.php`/`billing-copy-dialog.php` on `pltt-reports` (billing.css already loads there).
- Gate all of this to `$is_single_project_view` (`class-pltt-reports.php:94`).

### Phase 2 — Demote the Billing page to ledger + doorway
- `reports-invoicing.php`: keep the by-client **outstanding** cards but change each scope's action from the inline expand/select panel to a **deep link** into `pltt-reports?view=detailed&client_id=…&project_id=…` (lands on the card in Phase 1). Remove the inline `pltt-invoicing-panel` selection + the per-scope dialogs from this page.
- Keep the **Invoiced ledger** tab (`invoicing-log.php`); light relabel to "Billing records" (columns already: Date · Client · Project · Period · Billed · Absorbed).
- Stop enqueuing `invoicing.js` on `pltt-invoicing` once its selection UI is gone (commit no longer happens here).

### Phase 3 — Fixed-budget billing (in core, per decision)
- New `build_fixed_scope($project, $with_entries)` in `PLTT_Billing`: `calculated = budget_fee`; `unbilled = budget_fee − Σbilled − Σabsorbed` (remainder rule, so it bills once and then disappears; entries added later don't reopen it because the budget is spent); coverage = all uncovered verified entries of the project; `billing_type = 'fixed_budget'`; period = null (project span for label).
- `get_ready_to_invoice()`: dispatch `fixed` → `build_fixed_scope()` instead of `array()`.
- Extend the `billing_type` allowlist in `get_scope()`/`commit()` (currently `hourly` | `retainer_overage`) to include `fixed_budget`. No new column on `billing_records` needed (reuses `calculated/billed/absorbed`).
- Card "fixed" case + scope-view `type_label` already exist (`pltt_build_billing_scope_view`).
- **Decision to confirm:** fixed bills the budget in full, once (installments deferred). Over-budget hours do not increase the bill (you eat overage).

### Phase 4 — Vocabulary + status cleanup
- "Invoiced" → "Billed" in the status column + tooltips (`helpers.php:1632-1654`, `get_covered_entry_meta` labels).
- Decide: retire the legacy `time_entries.billed` fallback in multi-project status (`helpers.php:1518-1545`) in favor of coverage-as-truth, or leave as vestigial. Recommend leaving for now, note as tech-debt.

## Risks / watch-outs
- **Re-scoping the list (gap #3)** is the main design risk: the detailed table is paginated by date range, but hourly scope is all-time-uncovered. Entering "Review & bill" must swap the list to the scope's exact entries, then swap back on cancel. Consider a dedicated "billing scope" render branch rather than abusing the filter.
- **Reports stops being read-only** — enqueue `invoicing.js` there and confirm no double-binding with `reports.js` inline editing.
- Keep `pltt_commit_billing` server-side scope recompute as the safety net — the client view can never bill more than the true scope.
- Fixed-budget divergence from shipped invariants — make sure Project settings/Insights copy no longer calls fixed "awareness-only."
- Page slugs unchanged (`pltt-invoicing`, `pltt-reports`) — enqueue allowlist keys off hook suffix; don't rename.

## Sequencing note
Phase 1 delivers billing-from-Insights while the old Billing page still works (safe). Phase 2 removes the old path only after the new one is proven. Phase 3 (fixed) can land with or just after Phase 1's card. Phase 4 is cosmetic/low-risk.

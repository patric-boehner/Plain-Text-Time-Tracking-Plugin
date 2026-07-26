# Billing State Architecture — Review

**Date:** 2026-07-25 · **Scope:** billing and invoice state only · **Method:** code read, no changes made

---

## 1. Verdict

**Your instinct is half right, and it's pointed at the wrong layer.** The *persisted* billing state is lean — two tables, twelve columns, and every one of them is a decision or a snapshot that can't be recalculated. It is very close to what the design document says it should be, and in one respect it's better. Nothing needs to be torn out.

The confusion risk you're sensing is real but lives elsewhere: **hourly and retainer use two different reconciliation mechanics, and the file that implements them opens with a comment insisting there is only one.** That mismatch already produces one silent wrong-number bug (§3.1), and it's the single thing most likely to cost you an afternoon in 2028.

Everything else I found is housekeeping.

---

## 2. Current architecture — what's actually there

### 2.1 Persisted billing state

Three places, and only three:

**`pltt_time_entries`** ([class-pltt-database.php:160-187](includes/database/class-pltt-database.php#L160-L187))
- `billable` tinyint — the chargeable decision. Written at review-time verification and by the inline toggle.
- `billable_rate` / `billable_amount` decimal — rate snapshot + amount, frozen at verification ([class-pltt-review.php:506-530](includes/admin/class-pltt-review.php#L506-L530)).
- `billed` tinyint — **fossil.** Nothing writes it any more; the AJAX write path was explicitly closed ([class-pltt-ajax.php:298-301](includes/api/class-pltt-ajax.php#L298-L301)). Column, two indexes, and a filter branch ([class-pltt-entries.php:935](includes/database/class-pltt-entries.php#L935)) remain.

**`pltt_billing_records`** ([class-pltt-database.php:274-295](includes/database/class-pltt-database.php#L274-L295)) — one row per commit. `project_id`, `period_start/end`, `billing_type`, `rate`, `calculated_amount`, `billed_amount`, `absorbed_amount`, `billed_minutes`, `allocation_minutes`, `description`, `marked_at`. `absorbed = calculated − billed` by construction ([class-pltt-billing-records.php:81-87](includes/database/class-pltt-billing-records.php#L81-L87)) — there is no status column, and a full write-off is just `billed_amount = 0`.

**`pltt_billing_record_entries`** ([class-pltt-database.php:301-308](includes/database/class-pltt-database.php#L301-L308)) — `(record_id, entry_id)`. The frozen coverage snapshot: exactly which entries a committed record captured.

That's it. No invoice status, no paid/unpaid, no totals cached anywhere.

### 2.2 Derived at read time

Everything else. Hours, amounts, "unbilled", overage, allocation consumption, effective rate, billed-to-date — all recomputed on every page render through `PLTT_Billing` ([class-pltt-billing.php](includes/class-pltt-billing.php)) and `pltt_compute_overage_threshold()` ([helpers.php:3041-3184](includes/helpers.php#L3041-L3184)).

The read model is genuinely well centralized. Outside the engine and its two DB classes, I found **four** call sites that touch billing state directly ([class-pltt-reports.php:263](includes/admin/class-pltt-reports.php#L263), [class-pltt-ajax.php:329](includes/api/class-pltt-ajax.php#L329), [helpers.php:1417](includes/helpers.php#L1417), [helpers.php:1758](includes/helpers.php#L1758)). That is a good number.

### 2.3 Lifecycle of an entry

1. Plain-text line → parser → unverified entry.
2. Review/finalize → client + project + tags + `billable` set; `billable_rate` and `billable_amount` snapshotted; `verified = 1`.
3. Entry appears in a scope: hourly = "billable + verified + in range + not covered"; retainer = "inside a period that went over allocation".
4. Commit ([class-pltt-billing.php:180-289](includes/class-pltt-billing.php#L180-L289)) → one `billing_records` row + N `billing_record_entries` rows, in one transaction.
5. Entry now displays "Invoiced · record #N" ([class-pltt-billing.php:119-138](includes/class-pltt-billing.php#L119-L138)) and its `billable` flag is locked ([class-pltt-ajax.php:323-332](includes/api/class-pltt-ajax.php#L323-L332)).

Two commit entry points (form handler at [class-pltt-form-handlers.php:465](includes/api/class-pltt-form-handlers.php#L465), AJAX at [class-pltt-ajax.php:781](includes/api/class-pltt-ajax.php#L781)) — both funnel through `PLTT_Billing::commit()`. **One write path.** Good.

### 2.4 How the system knows a span is billed — two answers

This is the important part.

| | Hourly | Retainer |
|---|---|---|
| Question asked | "which entries are uncovered?" | "does the dollar remainder exceed epsilon?" |
| Mechanic | set difference against frozen coverage ([class-pltt-billing.php:505-516](includes/class-pltt-billing.php#L505-L516)) | `calculated_live − Σbilled − Σabsorbed` ([class-pltt-billing.php:711-717](includes/class-pltt-billing.php#L711-L717)) |
| Late entry inside a billed window | stays Unbilled — record is immutable | **reopens the period** for the delta |
| Rate change afterward | no effect (entry carries its own snapshot) | **re-values every past period** |

Both are defensible policies. They are opposite policies. And the engine's file header ([class-pltt-billing.php:13-15](includes/class-pltt-billing.php#L13-L15)) states: *"One remainder rule for every type: unbilled(scope) = calculated(scope) − Σ billed − Σ absorbed"* — which is now true of only one of the two, as a comment 480 lines further down concedes ([class-pltt-billing.php:489-491](includes/class-pltt-billing.php#L489-L491)).

### 2.5 Three billing models, three paths

Yes — the code already routes by scope, and did so before the document proposed it. `pltt_get_billing_type()` ([helpers.php:2786-2795](includes/helpers.php#L2786-L2795)) returns `hourly | recurring | fixed | none`; `get_ready_to_invoice()` ([class-pltt-billing.php:41-55](includes/class-pltt-billing.php#L41-L55)) dispatches entry-scope / period-scope / nothing. Fixed fee and internal produce no records at all. The per-entry billable flag is hidden for retainer and fixed ([helpers.php:2811-2824](includes/helpers.php#L2811-L2824)).

### 2.6 What can go internally inconsistent

- **Retainer periods can silently reopen** (§3.1). Nothing reconciles this.
- **Orphaned coverage rows.** `PLTT_Entries::delete()` ([class-pltt-entries.php:364](includes/database/class-pltt-entries.php#L364)) doesn't touch `billing_record_entries`; no FK. Harmless in the joins, but the rows persist.
- **No record can be deleted** (§3.3), so there is no repair mechanism for a wrong commit short of SQL.

---

## 3. Findings, ordered by future pain

### 3.1 — Retainer reconciliation is recomputed live; hourly is frozen

**What it is.** `build_retainer_scopes()` recomputes `calculated` from *today's* rate and *today's* entry set ([class-pltt-billing.php:712-715](includes/class-pltt-billing.php#L712-L715)), then subtracts what records already account for. Because `absorbed = calculated − billed` at write time, `Σbilled + Σabsorbed` equals the sum of the records' *stored* `calculated_amount`. So:

> `unbilled(period) = calculated_now − calculated_when_billed`

Any change to either input reopens a closed month.

**Failure scenario.** Capital Adv, 8h/mo at $150. June ran 12h → 4h over → $600, invoiced, record committed, period reads "Billed · Record #14". In September you raise the project rate to $175. June's `calculated` becomes $700, `unbilled` becomes $100, and June reappears on the Invoicing queue and in the "Not yet billed" figure as $100 owed. Same thing happens if you back-fill one forgotten 30-minute June entry. Neither is flagged as a correction — it presents identically to money you never billed.

The hourly path is immune to both by design.

**What it costs.** A wrong number in the one place the tool exists to be right about, presented with no indication it's a restatement. You'd probably catch the rate case (you'd remember raising the rate); the back-filled-entry case is the one that gets invoiced twice.

**What it would take.** The fix is a policy change, not new state — `calculated_amount` is *already stored on the record* ([class-pltt-database.php:281](includes/database/class-pltt-database.php#L281)) and simply isn't read on this path. Treating a period with any record as settled makes retainer match hourly's stated rule ("a record is immutable; a late entry stays Unbilled"). The retainer coverage snapshot already exists, so a "3 uncovered entries in a billed period" nudge is available later if you ever want one.

**Effort:** small — the branch at [class-pltt-billing.php:714-717](includes/class-pltt-billing.php#L714-L717), the mirror at [class-pltt-billing.php:634-639](includes/class-pltt-billing.php#L634-L639), and [helpers.php:1758-1761](includes/helpers.php#L1758-L1761). **Risk:** medium — it changes what closed periods report, so check your real data before and after. **Worth doing.**

### 3.2 — ~~The engine's documentation describes a model it no longer implements~~ — FIXED 2026-07-25

**What it was.** Four artifacts asserted the superseded "one remainder rule for every type" — the engine header, the record class header, the `billing_records` schema comment, and `billing-record-spec.md` (whose §"Hourly manifest = the cutoff" also described a rolling-cutoff mechanic that had been removed). All of them pointed the reader at a spec that was wrong about half the engine.

**What it cost.** The concentrated "code I'd have to re-read from scratch" risk. It's also what made §3.1 hard to see: the comment asserted the very invariant the code breaks.

**What was done.** `billing-record-spec.md` deleted. The three code comments rewritten to describe both mechanics honestly — hourly reconciles on coverage, retainer on a live-recomputed dollar remainder — with the §3.1 drift consequence stated in the engine header rather than left for a reader to discover. Also deleted as part of the same sweep: `surface-rethink.md` (its companion), `phase-1-prediction-build-spec.md` (shipped), `time-tracker-billable-changes.md` (shipped in 1.9.5), `design-note-project-phases.md` (undecided proposal), and `time-tracker-project-plan-v2.md` (the comparison doc — see §10).

### 3.3 — There is no way to delete or void a billing record

**What it is.** `PLTT_Billing_Record_Entries::delete_for_record()` ([class-pltt-billing-record-entries.php:148](includes/database/class-pltt-billing-record-entries.php#L148)) exists and is called by nothing. There is no delete handler, no UI, no AJAX action. Meanwhile the entry lock tells the user *"Delete the record to change whether it is billable"* ([class-pltt-ajax.php:330](includes/api/class-pltt-ajax.php#L330)) — instructing them to do something the application cannot do.

**Failure scenario.** You commit a bill for the wrong range, or with the wrong amount typed in. The entries are now locked, the record is on the ledger, "Billed to date" is wrong, and the only path back is two DELETE statements against the live database.

**What it costs.** Not correctness — recoverability. For a system whose whole premise is "capture first, fix later," the billing layer having no undo is the sharpest inconsistency with the design philosophy in the codebase.

**What it would take.** One delete handler (record + coverage rows, in the existing transaction wrapper), one confirm button on the billing history table. **Effort:** half a day. **Risk:** low. Recommended, though "I'll do SQL the once it happens" is a legitimate call for a single-user tool.

### 3.4 — `pltt_compute_overage_threshold()` computes five outputs nobody reads

**What it is.** `overage_amount`, `marked_billable_minutes`, `marked_billable_amount`, `marker_entry_id`, and `boundary_time` ([helpers.php:3168-3177](includes/helpers.php#L3168-L3177)) have no consumers anywhere in the plugin. Only `state`, `overage_minutes`, `allocation_minutes`, `used_minutes`, `remaining_minutes`, `period_*` and `overage_entry_ids` are used.

`overage_amount` is the one carrying the documented straddle-boundary defect — the engine header spends a paragraph warning you never to use it ([class-pltt-billing.php:7-11](includes/class-pltt-billing.php#L7-L11)), and [helpers.php:3152-3159](includes/helpers.php#L3152-L3159) carries a further nine-line comment explaining why it's wrong. It's a loaded gun in a drawer, with a sign on the drawer, and no bullets in it.

**What it costs.** Every future reader pays the tax of understanding a defect in a value that doesn't matter. `boundary_time` also does string formatting work on every call.

**What it would take.** Delete five fields and their comments. **Effort:** twenty minutes. **Risk:** near zero (grep-verified: no consumers). Do it while you're in there for §3.2.

### 3.5 — The `billed` column on `time_entries` is a fossil with live plumbing

**What it is.** Column ([class-pltt-database.php:174](includes/database/class-pltt-database.php#L174)), two indexes ([:184](includes/database/class-pltt-database.php#L184)), a write format in `Entries::update()` ([class-pltt-entries.php:282, :300](includes/database/class-pltt-entries.php#L282)), a filter branch ([class-pltt-entries.php:935](includes/database/class-pltt-entries.php#L935)). Nothing writes it; four separate comments explain that it's dead.

**What it costs.** Low but real: it's a second, stale answer to "has this been billed," reachable via `PLTT_Entries::update(['billed' => 1])`. The AJAX door was closed deliberately; the data-layer door is still open.

**What it would take.** Drop from the `$allowed_fields` map, drop the filter branch, migration to drop the column. **Effort:** an hour plus a DB version bump. **Risk:** low. Worth doing on the next schema change, not on its own.

### 3.6 — Type branching in the presentation layer is where the volume actually is

**What it is.** Roughly 700 lines across five functions in `helpers.php` branch on billing type to build figure sets: `pltt_retainer_period_status_figure()` ([1757](includes/helpers.php#L1757)), `pltt_build_retainer_partial_figures()` ([1841](includes/helpers.php#L1841)), `pltt_build_retainer_span_figures()` ([1942](includes/helpers.php#L1942)), `pltt_build_single_project_scope_figures()` ([2088-2443](includes/helpers.php#L2088-L2443), ~355 lines with four nested type branches), `pltt_build_billing_scope_view()` ([2461](includes/helpers.php#L2461)). `pltt_get_billing_type()` has 25 call sites across 11 files.

**What it costs.** This is the honest answer to "did I build something more complex than the problem requires" — but it's *display* complexity, not state complexity. It's driven by a genuine product decision (four project types each get a purpose-built four-figure readout, per `ui/pltt-scope-by-type.html`), and the comments in it are unusually good at explaining *why* each frame is what it is. It won't produce wrong numbers. It will make UI changes slow.

**What it would take.** Nothing clean. A per-type strategy object would move the branching rather than remove it. **My recommendation: leave it.** Revisit only if you change the figure spec again.

### 3.7 — The invoicing queue is an expensive read, and it runs on the Overview

**What it is.** `get_invoicing_queue()` ([class-pltt-billing.php:427-472](includes/class-pltt-billing.php#L427-L472)) loops every active project. Hourly costs 2 queries. **Retainer costs one full entries query per allocation period since the project's first entry** — `pltt_compute_overage_threshold()` re-queries inside the loop at [class-pltt-billing.php:703](includes/class-pltt-billing.php#L703), guarded only by a 500-iteration cap. A three-year monthly retainer is ~36 queries, plus a `sum_billed` per over-period.

It's called by the Billing page (fair), by the Overview summary's "Unbilled so far" card ([class-pltt-reports.php:205](includes/admin/class-pltt-reports.php#L205)), and *again from inside* `pltt_retainer_period_status_figure()`'s backlog count ([helpers.php:1797](includes/helpers.php#L1797)) — which is itself called per rendered period.

**What it costs.** Page-load latency that grows with your history, forever, on a screen you open daily. Not a correctness issue. At your data volume it's likely tens of milliseconds today.

**What it would take.** Batch the period scan into one entries query per project. **Effort:** medium. **Risk:** low. **Don't do it until you can feel it** — this is exactly the "invented improvement" the brief warns against.

### 3.8 — Housekeeping

- **`drop_tables()` misses three tables** ([class-pltt-database.php:689-698](includes/database/class-pltt-database.php#L689-L698)): `billing_records`, `billing_record_entries`, `tag_aliases` survive uninstall. Real bug, two-line fix, near-zero consequence for you.
- **`EPSILON` is duplicated** as a literal `0.005` in four places ([helpers.php:1761, :1787, :2279](includes/helpers.php#L1761), [billing-card.php:54](templates/partials/billing-card.php#L54)) while the constant lives at [class-pltt-billing.php:30](includes/class-pltt-billing.php#L30). Trivial.
- **`billing_record_entries` has no FK and no cleanup on entry delete** (§2.6).

---

## 4. Where the code is right and the document is wrong

This section has real content — the document is naive about several things this code learned the hard way.

**4.1 — The document's central store-vs-derive rule is the direct cause of this codebase's one real bug.**

§8 states, emphatically: *"hours-worked is **not** stored on the period record, even though it's tempting… storing it creates a second copy that drifts the moment you fix a miscategorized entry from three weeks ago. Store the decision, derive the fact."*

The code obeyed that rule for retainers, and §3.1 is the result. The document has the drift direction backwards: deriving the fact doesn't prevent drift, it *relocates* it — from "the stored copy disagrees with the entries" to "the recomputed answer disagrees with the invoice you already sent." Only one of those two is money. The document never considers that an invoice is a **fact about the outside world**, and that once it exists, the entries are no longer authoritative about what was billed.

The fix for §3.1 is to store *more* than the document permits. The code already has the column.

**4.2 — "You never need to know which individual entries caused the overage. The month owns that calculation. Entries stay untouched. This kills an entire category of design difficulty."** (§8)

It doesn't kill it; it hides it. The month's overage is itself derived by walking entries in date order and finding the crossing point ([helpers.php:3116-3166](includes/helpers.php#L3116-L3166)). "The month owns the calculation" is true only if the month's entry set is stable, and it isn't — that's the whole premise of "capture first, categorize later." The code freezes the overage entry set into `billing_record_entries` at commit even for retainers, which the document says is unnecessary. It earns its keep: it's what lets an individual January entry display "Invoiced · record #14." The document has no answer to "was this specific entry ever accounted for?" on a retainer, which is the question you'll actually ask when reconciling against Zoho.

**4.3 — Partial retainer write-offs: the code handles them, the document's proposed schema doesn't.**

The document's stress case (worked 14 against 10, invoiced 12, wrote off 2) works today: commit the period, trim the posted amount, `billed_amount = $300`, `absorbed_amount = $300` ([class-pltt-billing.php:255](includes/class-pltt-billing.php#L255), [class-pltt-billing-records.php:81-87](includes/database/class-pltt-billing-records.php#L81-L87)).

More importantly, the code stores that split in **dollars** where the document specifies **hours** (§8: *"Stored: period id, project, month, hours invoiced, hours written off"*). Dollars is correct and hours is a latent bug — hours re-multiplied by a later rate gives a different write-off amount than the one you actually granted. The document's own §5 (*"rate is snapshotted onto the entry… without this, raising a rate silently rewrites last year's reports"*) contradicts its own §8 here, and the code picked the right side.

**4.4 — The code generalizes absorption to hourly; the document restricts it to retainers.** Trimming an hourly bill down is the same gesture as a retainer write-off, and there's no reason the courtesy discount should only be expressible on one project type. The code's version is more useful and costs no extra state.

**4.5 — On the platform argument (§12, "Not a WordPress plugin"):** the document is arguing against a decision made years ago, with no knowledge of what already runs. It's not a finding. Ignore it.

**Where the document is right and worth keeping:** its §6 distinction between *chargeable* and *billed* is exactly the model this code converged on independently — `billable` is the decision, coverage is the state, and they are separate. Your 1.9.5 migration was the moment that got sorted out. Nothing to change; it's confirmation you got it right.

---

## 5. What I'd leave alone

- **`billing_record_entries`.** Two columns doing three jobs (immutability, the per-entry "Invoiced · record #N" label, and double-bill prevention). It looks like it duplicates something derivable. It doesn't — it's the record of a decision, and it's the piece the design document under-specifies. Load-bearing.
- **The stored `billed_minutes` / `allocation_minutes` / `rate` / `calculated_amount` on records.** All four are derivable *today* and none will be derivable *correctly* after a rate change. They are the reason a two-year-old record still renders honestly. Textbook right call.
- **`billable_rate` / `billable_amount` on the entry.** Same argument, endorsed by the document's §5. Keep.
- **The four-type read model** (§3.6). Complex, but the complexity is in the product, not the implementation.
- **The nesting-aware transaction wrapper** ([class-pltt-database.php:61-107](includes/database/class-pltt-database.php#L61-L107)). Looks like over-engineering for a single-user tool; it's scar tissue from a real MySQL/MariaDB bug (TRC-DB23) and the reason record + coverage commit atomically. Don't touch.
- **The commit-path defense in depth** — server-side scope recompute, posted amount can only lower the bill, include-set intersected with the eligible set ([class-pltt-billing.php:196-255](includes/class-pltt-billing.php#L196-L255)). Over-cautious for one user who is also the developer, but it's the code that makes "I can invoice from this with confidence" true. Leave it.

---

## 6. Open questions

1. **Is a late entry inside a closed retainer month supposed to bill?** §3.1 assumes no, matching the hourly policy. If you'd rather it *did* bill — as a visible supplemental, not a silent reopen — the fix is different: keep the recompute, but show the delta as "additional since Record #14" rather than folding it into "Not yet billed." I can't tell from the code which you intended; the two paths look like they drifted apart rather than being chosen.
2. **Has a rate change already resurrected a billed retainer period on your live data?** Worth one query before changing anything — it tells you whether §3.1 is theoretical or already happened. Compare each retainer record's stored `calculated_amount` against a live recompute for the same period.
3. **Does anything outside the plugin read `time_entries.billed`?** I only searched this plugin. If a snippet, export, or old report touches it, §3.5 gets more expensive.
4. **Are `budget_hours` / `recurring_period` ever edited on a project that already has records?** `handle_update_project()` ([class-pltt-form-handlers.php:214-236](includes/api/class-pltt-form-handlers.php#L214-L236)) permits it with no billing-history guard. Changing an allocation retroactively re-derives every past period's overage — the same mechanism as §3.1, with a bigger blast radius. If you've never done it, it's a non-issue; if you do it when a retainer is renegotiated, it needs a guard.

---

## 7. Considered and deferred — taking the money off the record

**Date parked: 2026-07-25. Revisit: after a few months of real billing use.**

### What was proposed

Reduce the billing record to a pure coverage claim — *these entries went out, on this date, against this invoice reference* — and drop `calculated_amount`, `billed_amount`, and `absorbed_amount`. Dollar figures would all become live-derived estimates, and the record would carry an `invoice_ref` pointing at Zoho rather than a copy of the money.

### Why it was raised

The trigger was a real failure: backfilling historical invoices doesn't work, because what was actually invoiced doesn't match what the system calculates for a period — real invoices pull in time across month boundaries. That looked like evidence the model was over-built, and the store-vs-derive test seemed to kill `billed_amount`: it can only be *computed* (which just broke) or *typed* (a hand copy of a number Zoho owns).

### Why it was parked

**Zoho cannot answer "what did I write off."** A write-off never appears on an invoice — there is no line item for work you chose not to charge for. That figure exists only in this tool, so `absorbed_amount` is not duplicate data the way `billed_amount` is. The symmetry argument that swept it out with the others was wrong.

More broadly: the point of a bespoke tool is access to the information that's actually useful to its one user. Slashing a figure on architectural-purity grounds, before knowing whether it gets used, inverts that. The decision needs usage evidence, not reasoning.

### The evidence to collect

Over the next few months: **is the absorbed figure ever looked at, and when it's non-zero, does it prompt anything?** If yes, it stays and this was a false alarm. If it sits at zero or goes unopened, that's the answer without further argument.

**Caveat on the evaluation:** absorbed is currently structurally incapable of telling the truth. The posted amount is clamped to `≤ calculated` ([class-pltt-billing.php:255](includes/class-pltt-billing.php#L255), [class-pltt-billing-records.php:86](includes/database/class-pltt-billing-records.php#L86), plus `max=` on the inputs), so a month where more was invoiced than calculated — exactly the cross-month case — records as an exact match and contributes nothing. The figure has to be able to move in both directions before it's worth judging.

### Model-neutral work that unblocks the evaluation

None of these remove anything or commit to an outcome:

1. **Fix the retainer live-recompute (§3.1).** Priority, because it corrupts the data being evaluated: a rate change or one back-filled entry silently reopens a closed, invoiced month.
2. ~~**Make `marked_at` settable.**~~ **DONE 2026-07-25 (1.9.49).** An "Invoice date" field on all three commit surfaces, defaulting to today; blank or invalid falls back to now in the data layer. This alone unblocks *hourly* backfill — arbitrary cross-month membership already worked there via the range filter plus `included_entry_ids`. Records committed before this still date to their entry day and need SQL to correct.
3. **Unclamp the posted amount** — needed for *retainer* backfill, where entry selection isn't available and the period's arithmetic can't be widened. Makes absorbed a signed variance; see the naming question below.
4. Docblock rot (§3.2) and the dead threshold outputs (§3.4) — free wins, unrelated to the model.

### Left open if unclamping happens

Once the amount can exceed calculated, `absorbed = calculated − billed` can go negative, and "absorbed" stops being the right word for it. Two options, and they are different business facts:

- **One signed variance** — simplest, but conflates generosity with "I rolled in prior-month time."
- **Two named figures** — *written off* (charged less than estimated) and *added* (charged more). More honest, more display work.

Worth deciding at the same time as the display changes for negative values: the absorbed columns, the "Absorbed this month" card, and the record-inspection line all currently assume a non-negative number.

---

## Summary

| Finding | Pain | Effort | Do it? |
|---|---|---|---|
| 3.1 Retainer reopens on rate/entry change | High — silent wrong number | Small | Yes |
| ~~3.2 Docblocks describe a superseded model~~ | — | — | **DONE 2026-07-25** |
| 3.3 No delete path for a record | Medium — no undo | ~½ day | Probably |
| 3.4 Five dead outputs incl. the defective one | Medium — reader tax | 20m | Yes |
| 3.5 `billed` fossil column | Low | ~1h + migration | Next schema change |
| 3.6 Type branching in figure builders | Low — slow, not wrong | Large | No |
| 3.7 Queue read cost | Low today, grows | Medium | Not yet |
| 3.8 `drop_tables`, epsilon, orphans | Trivial | Minutes | Whenever |

Also shipped 2026-07-25 (1.9.49): settable invoice date on billing records — see §7, item 2.

With 3.2 done, item 3.1 is the only correctness bug left and 3.4 is the remaining twenty-minute win. Everything below that line is optional.

**Your billing state model is not over-built. One of its two reconciliation policies is the wrong one.**

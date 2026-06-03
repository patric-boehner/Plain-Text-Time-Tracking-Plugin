# Time Tracker: Billable Amount & Retainer Overage Changes

## Problem

The current model treats most client work as billable, including time on fixed-fee projects and within-allocation retainer hours. This creates two issues:

1. **The Billable Amount card overstates revenue.** It includes imputed dollars (hours × rate) from fixed-fee and retainer work that isn't actually invoiced from time. A month with significant retainer or fixed-fee work shows a number much larger than what will actually be invoiced from those hours.
2. **The invoiced flag is doing duty as a muting mechanism.** Retainer entries get marked billable, which puts them in the "needs invoicing" filter every month, which forces marking them invoiced to clear the queue — even though nothing is being invoiced from those entries individually. The flag is lying about what it means.

## Goal

Make the billable flag mean one consistent thing across all project types: *this entry generates invoiceable dollars from time × rate*. Anything billed via a flat fee (retainer, fixed-fee) isn't billable in this sense, because the dollars don't come from the entry × rate.

This makes the Billable Amount card honest — it shows only revenue that will actually be invoiced from time. The needs-invoicing filter becomes accurate without the muting workaround.

## Approach

The billable-model foundation **shipped in 1.9.5** and has been removed from this doc. Implemented: no-schema-change data model (the flag's meaning shifted), parse-time billable defaults by project type (hourly = billable; fixed-fee / retainer / internal = non-billable), the `Amount = sum(billable hours × rate)` column rule with natural em-dashes, the Billable Amount card, the Billable Hours card with its "hrs/day avg" sub-line, the Total Hours card with its client-vs-internal breakdown, removal of the utilization metric, and the retainer allocation bar's amber over-allocation treatment ("X over · Y%" past 100%).

## Overage decision support (detailed report view)

*Status: shipped and removed from this doc* — the single-project gating (retainer/fixed-fee only), the threshold-marker band with its "Allocation reached · time · used" label, marker placement at the chronological crossing point, and per-entry overage row tinting (with the billable-indicator feedback loop) are all implemented for the **over** state. The remaining work is below.

### Design (remaining)

**When no overage exists.** The threshold marker still appears, sitting at the position where the running cumulative would equal the allocation. The label changes to "X left of allocation" rather than "Allocation reached." This keeps the marker as a consistent boundary signal — always present, always meaningful — rather than something that only appears in overage months.

### Multi-month range behavior

When the date range spans more than one calendar month, hide the inline threshold marker. Retainer allocations reset monthly, so a multi-month view has multiple thresholds and trying to display all of them inline would clutter the entry stream.

Per-entry amber tinting still applies — each entry's overage status is a fact about that entry (relative to its own month's allocation), independent of the current view. So even without markers, overage entries remain visually distinguishable from within-allocation entries.

The stat cards continue to aggregate correctly across the range: Billable Hours and Billable Amount sum the overage from all months in view. The right-side client context card can optionally show aggregate stats ("3h 4m total overage across selected range") if useful.

Start simple. If multi-month retainer review becomes a common workflow, revisit with a richer treatment (e.g., per-month summary headers above each month's entries).

### Stat card behavior under single-project filter

The summary cards adapt their captions to the filtered context, since meaning is unambiguous when only one project is in view:

- **Billable Hours**: shows overage hours only, with a "overage only" sub-label *(remaining)*
- **Billable Amount**: shows overage dollars only, with a "retainer overage" sub-label *(remaining)*
- **Top Projects card**: hidden, since the project being viewed is already named in the right-side client context card *(done)*

### What we're not doing

- Auto-flipping entries to billable based on chronological order past the threshold
- Splitting entries into partial billable/non-billable child records at the exact allocation boundary
- Creating separate "overage charge" billing line items disconnected from the entries themselves
- Showing the threshold on multi-project views

The per-entry billable flag preserves the per-entry decision, which keeps the door open for future "billed overage vs. swallowed overage" reports computed directly from entry data.

## Unified entry form (add and edit)

*Status: the edit half shipped and is removed from this doc.* The expandable per-row edit form (`templates/partials/entry-form-row.php`, `review-edit-existing.php`), per-row Save via the `pltt_save_entry` AJAX handler, the field set, the blue inline-expansion treatment, and strict overlap validation are all implemented. **What remains is ADD mode** — the entry-point buttons, the add-mode title/defaults, and creating entries on days that have no parsed notes yet.

### Problem (remaining)

**Entries can only be created through journal parsing.** When work happens after a day's notes have been processed, or when something needs to be added that wasn't captured in the notes, there's no clean way to add it. The unified form already serves as the editor for existing entries; it now also needs to serve as the creator for new ones.

### Two entry points for adding

**From the daily log screen (post-processing):** A "+ Add entry" button at the top right of the Recorded Entries section. Clicking it navigates to the review/edit screen with the manual entry form expanded.

**From the review/edit screen directly:** A "+ Add entry" button at the top of the entries list. Clicking it expands the form inline at the top.

### Form title (add mode)

The shared form already varies its chrome by context for edit mode. Add mode needs its own title — "New manual entry" with a plus icon (edit mode shows "Editing entry" with a pencil icon). The Add entry button de-emphasizes (fades) while the form opened from it is open. Everything else about the form is identical between the two modes.

### Smart defaults (add mode only)

- **Date**: the day being viewed
- **Start time**: end of the most recent entry on that day, or current time if no entries exist yet
- **End time**: empty (filled when user enters one, or auto-calculated if duration is entered first)
- **Client / Project**: last one used on that day (most recent entry's project)
- **Billable**: defaults based on project type when Project is selected — hourly projects default checked, fixed-fee and retainer projects default unchecked (matching the journal parser's defaults)
- **Tags**: empty

Defaults aim to make the common case ("I forgot a small task right after the last logged one") nearly one-click.

In edit mode, fields are populated with the entry's existing values.

### Review/edit screen states

The screen handles four contexts using the same form pattern. Contexts 1 and 2 are **done**; contexts 3 and 4 are the remaining work:

1. ~~**Post-parse review**: new unsaved entries from parsing; "Save All Entries" commits them as a batch~~ *(done)*
2. ~~**Editing existing entries**: per-row hover reveals Edit/Delete actions; clicking Edit expands the row into the unified form; per-row Save commits~~ *(done)*
3. **Adding to an existing day** (new): "+ Add entry" button expands the form at the top of the list; Save commits the new entry
4. **Adding to a day with no notes yet** (new): same as above, just on a day that has no journal notes — the manual entry form is the only way entries get created for that day

### What we're not doing

- Inline form on the daily log screen (the button there is a shortcut to the review/edit screen; no duplicate form logic)
- Modal forms (inline expansion preserves context)
- Multiple rows expanded at once (one at a time, with auto-save on switching)
- Soft-block or override-allowed overlap validation (strict only, simpler)
- Invoiced field in the form (managed from the report view, not relevant at entry creation or routine editing)
- Keeping the old cramped inline-fields-in-columns editing pattern (replaced entirely by the expandable form)

## Day view timeline

### What we're trying to achieve

At week and month zoom levels, the daily bar chart works well — each day is a bar, and the bars are useful for comparison. At single-day zoom, the bar chart becomes a degenerate case: a single bar showing a single number. It's not broken, but it's not telling you anything the cards above don't already say.

A timeline visualization is the natural shape for a single day. It uses the start/end information already encoded in journal entries to show *when* work happened, not just how much. This makes visible:

- Gaps and breaks in the day (focused mornings vs. scattered afternoons)
- Context-switching between projects
- The actual span of the working day vs. tracked time within it
- Which time was billable vs. non-billable, at a glance

The timeline answers different questions than the bar chart — pattern and shape, rather than total. Both views have their place at different zoom levels.

### Where it appears

Two places, using the same component:

- **Log entry view (single day).** Primary use case. The timeline sits above or beside the entry list and gives shape to the day being reviewed.
- **Report screen at single-day zoom.** Replaces the daily bar chart in its existing slot when the date range is one day. Same visual language, same component, just used in a different context.

### Design

A horizontal timeline spanning working hours (default 8am–6pm, but extending dynamically if entries fall outside that window). Entries render as colored blocks proportional to their duration, positioned at their actual start time. Gaps within the working day are left visually blank — untracked time stays honest.

**Color** encodes project identity. Each project has a consistent color across the day, so context-switching between clients reads at a glance.

**Pattern** encodes billable status. Solid blocks are billable; diagonally striped blocks are non-billable. The color reads first (whose work is this), the stripe reads as overlay (does this generate per-hour invoice dollars). This works consistently with the new billable model: retainer overage that's been flipped to billable shows as solid, within-allocation retainer time shows as striped, fixed-fee work shows as striped — the visual encoding maps directly to whether the entry contributes to the Amount column.

**Hover** on a block reveals entry details — project, time range, duration, and the reason for non-billable status when applicable ("non-billable: within allocation", "non-billable: fixed-fee project").

**Legend** below the timeline lists each project with its color and total time for the day, plus a single non-billable swatch explaining the stripe pattern. Doubles as a per-project breakdown.

**Header** shows the day's summary in one line: total tracked time, project count, and the working span (first entry to last entry).

### Build sequencing

This is the largest new piece of UI in the overall change set. Most other changes are logic and defaults; this is a real new component. Worth its own implementation pass after the billable cleanup ships and has been used for a bit. The simpler version of the report (chart shows a single bar at day zoom) isn't broken, just suboptimal — there's no urgency.

## Project context card (detailed view)

### Problem

The right-side project context card on the detailed view was carrying minimal information (client name, View client link, project name, rate). Two specific gaps surfaced:

1. **No archive status indication.** Archived projects look identical to active ones in the detailed view, even though the summary view tints them gray with an "Archived" label.
2. **Inconsistent information density.** The card had room to communicate project-level context but wasn't using it.

### Approach

Redesign the card to communicate the at-a-glance project identity and state — what the project is, how it's structured, what state it's in, and (where applicable) where things stand against the project's budget or allocation.

Keep the card minimal. Period-specific activity (hours worked, billable amounts) lives in the summary stat cards at the top of the page. Per-entry invoicing status lives in the entries themselves. The card focuses on project-level facts that the rest of the page doesn't communicate.

### Universal content

Every card shows:

- **Client name** as a link (replaces the previous "View client" link — the name itself is the link target)
- **Project name** as subtitle (unlinked, since the user is already viewing the project)
- **Project info line**: type, plus the relevant financial detail for that type, joined by middots

### Type-specific content

The project info line varies:

- **Hourly**: "Hourly · $150/hr"
- **Retainer**: "Retainer · $300/mo · 3 hrs · $150/hr over"
- **Fixed Fee**: "Fixed Fee · $5,800 · 38h 53m budget"
- **Internal**: "Internal"

Retainers and fixed-fee projects also show an allocation/budget bar with a one-line caption beneath:

- **Retainer within allocation**: green bar at consumption %, caption "X used · Y remaining"
- **Retainer with overage**: green bar to 100% with amber overage extension past, caption "Xh Ym over · Z%" in amber text
- **Fixed Fee**: purple bar at budget consumption %, caption "X of Y used · Z%"

Hourly and Internal projects don't show bars (no budget concept applies).

### Status badge

A small status pill appears in the top-right corner of the card header, but only when the status is non-default. An active project shows no badge — the absence is the signal. Archived projects show an "Archived" badge plus a subtle muted background treatment on the whole card.

### What we're not showing

Deliberately excluded to keep the card minimal:

- **Period activity** (hours, billable amount for the date range): already shown in the summary stat cards at the top of the page
- **Invoicing status / breakdown**: per-entry invoiced state is visible in the entries list via the existing blue tint; the card doesn't need to summarize it
- **"View client" link**: redundant with making the client name itself a link

If invoicing-summary or period-activity-summary become genuinely useful later, they can be added as conditional sections — but the default should stay this minimal.

### Overage notification bar

When viewing a retainer with overage where no entries have been marked billable yet (the "haven't started the decision" state), show a notification bar above the entries table prompting the user to auto-mark the overage.

**Trigger conditions.** All must be true:

- The view is filtered to a single retainer project
- Calculated overage exists and is greater than 15 minutes (smaller overages aren't worth notifying about — likely to be absorbed anyway)
- No entries have been marked billable yet for the period

**Initial notification (no entries marked):**

- Amber background (consistent with other "needs attention" notifications)
- Alert icon
- Message: "2h 15m of overage to bill on this retainer"
- Action link: "Mark overage automatically"
- Dismiss × on the right side

Clicking the action runs the smart selection logic (described below) and transitions the notification to its post-action state.

Clicking the × dismisses the notification for the current view without taking action. The notification doesn't reappear in this view session, but will reappear if conditions are met again in a future session.

**Smart selection logic.**

When auto-marking overage entries, prefer slightly under rather than slightly over. The asymmetry matters:

- Over-selecting means overbilling the client (real consequence, requires manual correction)
- Under-selecting means absorbing a few minutes that could have been billed (small consequence, easy to adjust)

Algorithm: working from the chronologically-latest overage entry backwards, include entries until adding the next one would push the total past the calculated overage. The selection lands at or just under the calculated overage amount. The card's overage section will then show the "absorbing Xm" note for any small remaining gap, which the user can resolve manually if desired.

**Post-action notification (entries marked, now needs invoicing):**

After clicking "Mark overage automatically," the notification transitions to a confirmation state:

- Same amber background and styling
- Check icon (green, indicating success)
- Message: "Marked 2h 10m billable · absorbing 5m"
- Action link: "Mark as invoiced"
- Dismiss × still present

The "Mark as invoiced" link sets the invoiced flag on all the newly-marked billable entries. After clicking, the notification dismisses itself (the work is done — both decisions are recorded).

**When the notification doesn't apply.**

- Hourly projects (no allocation concept — different workflow, different surfaces)
- Fixed-fee projects (no per-entry invoicing)
- Retainers within allocation (no overage to make decisions about)
- Retainers where overage is ≤ 15 minutes (not worth the prompt)
- Retainers where the user has already started marking entries (the card's note carries guidance from there)
- Multi-project views (no single allocation to reference)

### Calculated overage vs. marked billable display

Because allocation boundaries don't respect entry boundaries (overage usually happens mid-entry), there can be a small gap between the mathematically calculated overage and what the user has marked as billable. A 133-minute entry that contains the allocation crossing has only ~5 minutes that are truly overage — but the billable flag is a single boolean for the whole entry. Marking it billable overbills by ~128 minutes; not marking it underbills by 5 minutes.

To make this gap visible and decidable, the retainer-with-overage variant of the context card shows the calculated overage as the headline financial figure, with a small inline note when the user's marked-billable selection differs from the calculated amount.

**Display rules:**

- Primary line: "Overage: 2h 15m · $337.50" (the math-correct figure based on cumulative consumption past allocation)
- Conditional note when there's a discrepancy:
  - If marked billable > calculated overage: "You've marked 2h 20m (5m more than overage — adjust invoice down)"
  - If marked billable < calculated overage: "You've marked 1h 45m (30m less — you're absorbing the difference)"
  - If marked billable = calculated overage: no note (the common clean case)

The note is informational. The user decides what to do about the gap — adjust invoice amount, change which entries are marked, or accept the difference as deliberate (absorbing some overage, billing some).

**Summary view stays simpler.** The summary view's Amount column continues to show what's marked billable × rate (the user's selection). The summary is "based on my current decisions, here's what's invoiceable." The detailed view's context card is where the math-vs-selection comparison happens because that's where decisions get made.

**Threshold marker placement unchanged.** The marker continues to land at the precise crossing minute, even when that's mid-entry. The card communicates the financial reality; the marker shows the visual boundary. Together they tell the full story — "here's where you crossed, here's what to bill, here's the gap if any."

## Monthly usage card (multi-month retainer views)
 
### Problem
 
Single-month retainer views show whether the current month went over or under allocation. But the bigger business question — "is this retainer right-sized for this client?" — needs longitudinal data. Right now there's no surface that helps the user walk into a retainer-renegotiation conversation prepared, with concrete numbers about how consumption has actually trended over time.
 
### Trigger conditions
 
The card appears when all of these are true:
 
- The report is filtered to a single retainer project
- The date range spans 3 or more months
- The range includes at least 2 complete months of data (partial months can be excluded or noted)
Below 3 months, averages aren't meaningful enough to drive an allocation conversation. The single-month overage card is the right surface at shorter ranges.
 
### What the card shows
 
Five pieces of information, each with its own clear job:
 
1. **Period** — small label naming the range being averaged ("Last 6 months · Nov 2025 – Apr 2026")
2. **Average vs. Allocation** — side-by-side comparison of the average monthly hours used against the set allocation
3. **Gap** — the numeric and percent difference, colored by direction (amber over, sage under, neutral matched)
4. **Consistency** — "X of N months over allocation" + range (min and max month)
5. **Anomaly note** — small note that adapts based on whether outliers exist
### Anomaly handling
 
The card always includes all data in the average — no automatic exclusion. The anomaly note carries the interpretation:
 
- **Significant outlier exists** (e.g., a single month at 2x or more the median): note names the month and value, and shows the median as an alternative metric ("Jan 2026 (8h 15m) may be skewing the average. Median: 3h 45m.")
- **No outliers**: note confirms steadiness ("Usage is consistent month-to-month. No outliers." or "No significant outliers in this period.")
The user decides what to do with the information. Software doesn't filter the data; it just flags when the headline number might not represent the typical pattern.
 
### Color and tone
 
- **Gap over allocation**: amber ("+1h 12m · 40% over") — consistent with overage signaling elsewhere
- **Gap under allocation**: sage green ("−1h 12m · 40% under") — positive signal, has headroom
- **Gap matched**: neutral ("−2m · on target") — no action implied
The card is reference material for a business conversation, not an alert. It doesn't recommend an action (e.g., "consider increasing allocation") — it just shows the data and lets the user make the call.
 
### What the card doesn't do
 
- Doesn't recommend specific allocation changes (judgment call, not software call)
- Doesn't replace the existing bar chart's monthly view (chart shows texture, card shows headline — both are useful, doing different jobs)
- Doesn't appear on single-month views (the overage decision card handles that case)
- Doesn't appear on hourly, fixed-fee, or internal projects (no allocation concept)


## Bulk billing actions

### Problem

At end of month, marking each billable hourly entry as invoiced individually is repetitive when invoicing the whole month at once. A single bulk action covers the common case.

(The related retainer-overage flow — marking entries billable and invoiced in one action — is handled by the overage notification bar rather than a separate bulk action, since the retainer case benefits from smart selection that a plain bulk action wouldn't provide.)

### The bulk action

**"Mark all billable as invoiced"** — appears above the entry list when the filtered view contains billable entries that haven't been invoiced yet. The button is contextual; its absence is itself a signal that no bulk action is needed in the current view.

Clicking the button does not immediately commit the change. Instead, it surfaces a confirmation notification (using the same notification component as the overage prompt) showing the specifics of what's about to happen. The user clicks the action link in the notification to confirm, or dismisses with X to cancel.

### Confirmation via notification

When the user clicks "Mark all billable as invoiced," the notification appears at the top of the entries area:

- Amber background, alert-style icon
- Message: "Marking 12 billable entries (8h 30m, $1,275.00) as invoiced"
- Action link: "Confirm"
- Dismiss × on the right

Clicking Confirm transitions the notification to a green success state summarizing the action (e.g., "12 entries marked as invoiced"). Clicking × dismisses without applying changes.

This replaces a traditional modal confirmation dialog with the same notification pattern used elsewhere in the tool. Visual consistency, less interruption, and the user reviews the action in the same focused area where they'd see automatic prompts.

### Scope

"All" means all entries currently visible in the filtered view, not all entries in the database. If the user has filtered to March 2026, the bulk action only affects March 2026 entries that match the criteria.

### Individual workflow preserved

The bulk action is an addition, not a replacement. Individual invoiced toggles still work as before.

### Shared notification component

The notification component used here is the same one used for the overage notification bar. Both surface action-required information with the same visual treatment (amber initial state, green success state, persistent dismiss option). The notification system serves three flavors:

1. **Auto-prompts** — system surfaces something the user might want to do ("X minutes of overage to bill · Mark overage automatically")
2. **Confirmation requests** — user requested an action via button click; notification confirms specifics before committing ("Marking X entries as invoiced · Confirm")
3. **Success states** — action completed; optional follow-up action available ("Marked X minutes billable · Mark as invoiced")

Visual differentiation between flavors comes from icon and grammar rather than fundamentally different layouts. The user learns the notification pattern once and reuses that understanding everywhere bulk-ish actions happen.

### What we're not doing

- Modal dialogs for confirmation (notification pattern serves the same purpose with less interruption)
- Generic multi-select with checkboxes (targeted button + notification confirmation is simpler)
- A separate "Mark all overage as billed" bulk action (handled by the overage notification bar with smart selection)
- Auto-flipping billable to true when invoiced is clicked on a single non-billable entry (explicit per-entry control is fine; bulk action covers the high-volume case)
- Persistent always-visible bulk action button (contextual visibility keeps the surface quiet most of the time)

## Sortable columns on the summary project table

### Problem

Under the new billable model, many project rows have em-dashed Amount columns (fixed-fee, retainer within-allocation, internal). This isn't a scannability disaster — em-dashes are visually quiet — but it does mean the rows with actual invoiceable amounts are mixed in with rows that don't have any, in whatever order the table happens to default to.

### Approach

Add column sorting rather than restructuring the table. Sorting is a familiar WordPress admin pattern, low-cost to implement, and gives the user agency over how rows are ordered without locking in any single structure.

### Initial scope

Two sortable columns to start:

- **Project**: alphabetical sort, useful for finding a specific client or scanning by name
- **Type**: clusters by project type, so all Hourly rows group together, then Monthly (retainer), then Fixed Fee, then Internal — which naturally separates rows with billable amounts from rows without

Other columns (Hours, Budget, Amount) are not initially sortable. Add them later if a clear need surfaces in actual use rather than anticipating.

### Sort behavior

Standard WordPress admin convention: click a column header once to sort ascending, click again to toggle descending. The small arrow indicator in the header shows current sort direction. Only one column is sorted at a time.

Default sort on first load: whatever the current default is. No change needed.

### Em-dash handling

Not directly relevant for the initial two sortable columns (Project and Type both contain real values for every row). When sorting is extended to Amount or Budget later, em-dashed cells should sort to the bottom in descending order regardless of direction — they're "no value" rather than "zero," and treating them as zeros at the top of an ascending sort would be confusing.

### What we're not doing

- Grouping or sectioning the table by project type (sorting accomplishes the practical goal more simply)
- Collapsing non-billable project sections by default (table is short enough that em-dashes aren't a real problem)
- Multi-column sort (one column at a time is sufficient)
- Sortable Hours, Budget, or Amount columns in the initial build (add later if needed)

## Explicitly out of scope

These came up during the design conversation but are deferred:

- **Expense entries as a separate entry type.** Cleaner generalization of the entry model for handling pass-through costs and flat charges, but not on the critical path for the current problem. Build if/when there's a concrete need.
- **Revenue reporting.** Fixed-fee invoice revenue, retainer revenue, and total invoiced amounts live in Zoho Books. Cross-system reporting is a future concern, separate from time tracking. For now, revenue questions get answered in Zoho directly.
- **Annual "billed overage vs. eaten overage" reports.** Computable from entry data under the new model, but doesn't need to be built as part of this change.
- **Utilization metric redefinition.** If a different productivity signal becomes useful later, design it then with a specific question in mind. Don't preemptively replace what's being removed.
- **Per-project-type stat card layouts.** When the report is filtered to a single retainer or fixed-fee project, the existing Billable Hours and Billable Amount cards mostly read zero (correct, but uninformative). The right long-term fix is different card lineups per project type — retainers showing allocation/fee/billed overage, fixed-fee showing budget consumption and effective rate, hourly keeping the current cards. Deferred until there's enough lived experience with the new model to know which metrics actually matter for each project type. The current "overage only" / "retainer overage" captions are the minimum-viable explanation in the meantime.
- **Invoiced status indicator on summary view project rows.** Summary view doesn't communicate which projects have outstanding billable work. In practice, invoicing happens in the detailed view filtered to a specific project, so the summary's role is more "how did the month go" than "what do I still need to bill." If lived experience surfaces a real need (e.g., losing track of which projects need invoicing across a quarter), revisit with a small indicator in the existing alert column — a blue/billing icon meaning "there's unbilled billable time within this range." Could coexist with the existing amber "unbilled outside range" indicator since they answer related but distinct questions.

- **Billing unit records (first-class invoice events).** The current model uses two per-entry flags (billable, invoiced) to express what was billed. This works but has known limitations:
  - Entry-level flags can't express mid-entry allocation splits (the ~5-minute-of-a-133-minute-entry overage problem) without overbilling or underbilling
  - Partial billing of overage ("I billed 1h 45m of the 2h 15m overage, absorbed the rest") loses information — entries get marked as either billable or not, and the "this was deliberately absorbed" intent isn't recorded anywhere
  - The waffling-about-billing tension applies to any project that produces invoiceable time (not just retainers — ad-hoc research hours on hourly projects have the same dynamic)
  - There's no consolidated "billing history" view; what got billed lives in Zoho, what got logged lives in the time tracker, no record connects them

  A future redesign would introduce billing unit records as first-class entities — one record per invoice event, with fields like project, period covered, amount actually invoiced, optional invoice number reference, and a manifest of which time entries the unit represents (the work being billed for, not necessarily a one-to-one match with the time amount). Fixed-fee projects stay outside this since their invoicing is fee-scheduled.

  Under this model, the per-entry invoiced flag would become either a derived visual state (computed from manifest membership) or removed entirely. The billable flag stays manual as a user editorial judgment. Billing units become the source of truth for "what was actually billed."

  Deferred because: it's a significant architectural shift that adds a new domain concept (billing/invoicing) to a tool whose original ethos is "low-friction time tracker, billing happens in Zoho." The immediate friction (entry-boundary inaccuracy, two-click bill-then-invoice) is bounded and addressable with smaller fixes (the calculated-vs-marked display, bulk billing actions). Worth revisiting after 6+ months of using the simpler design to validate whether the friction is real and persistent, or whether the simpler model is actually sufficient.

  If pursued later: would also enable an AI-generated invoice description feature (using the WordPress 7.0 AI connector system to summarize entry descriptions into invoice line item text). That's a separate downstream possibility, not part of the billing unit concept itself.
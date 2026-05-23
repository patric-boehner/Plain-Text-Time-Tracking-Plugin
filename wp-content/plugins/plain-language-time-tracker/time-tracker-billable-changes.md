# Time Tracker: Billable Amount & Retainer Overage Changes

## Problem

The current model treats most client work as billable, including time on fixed-fee projects and within-allocation retainer hours. This creates two issues:

1. **The Billable Amount card overstates revenue.** It includes imputed dollars (hours × rate) from fixed-fee and retainer work that isn't actually invoiced from time. A month with significant retainer or fixed-fee work shows a number much larger than what will actually be invoiced from those hours.
2. **The invoiced flag is doing duty as a muting mechanism.** Retainer entries get marked billable, which puts them in the "needs invoicing" filter every month, which forces marking them invoiced to clear the queue — even though nothing is being invoiced from those entries individually. The flag is lying about what it means.

## Goal

Make the billable flag mean one consistent thing across all project types: *this entry generates invoiceable dollars from time × rate*. Anything billed via a flat fee (retainer, fixed-fee) isn't billable in this sense, because the dollars don't come from the entry × rate.

This makes the Billable Amount card honest — it shows only revenue that will actually be invoiced from time. The needs-invoicing filter becomes accurate without the muting workaround.

## Approach

### Data model

No schema changes. The billable flag's meaning shifts; how it's used downstream shifts with it.

### Entry defaults at journal parse time

| Project type | Billable default |
| --- | --- |
| Hourly | billable |
| Fixed-fee | non-billable |
| Retainer | non-billable |
| Internal | non-billable |

For retainer projects, overage gets marked billable manually when reviewing the month's entries and deciding what to invoice separately. Within-allocation work stays non-billable since it's covered by the flat retainer fee.

### Amount column logic (summary view project table)

One rule across all project types:

```
Amount = sum(billable hours × rate)
```

Em-dashes appear naturally for any project where no entries are billable (fixed-fee, within-allocation retainers, internal). No per-project-type special cases.

### Billable Amount card

Sum of the Amount column. Real invoiceable dollars only. No caption needed; the data model is self-consistent.

### Billable Hours card

The number drops significantly under the new model, since retainer hours no longer count. That's correct — billable hours now means hours that generate per-hour invoices. A simple "hrs/day avg" sub-line keeps parity with the Total Hours card's existing treatment.

### Total Hours card

Adds a client vs. internal breakdown beneath the total. This is the more useful "share" metric than utilization for this business model:

- Client share = hours on any non-internal project (retainers, fixed-fee, hourly, including overage)
- Internal share = hours on internal projects

Overage hours count as client work because they are client work — the split is about *where the time went*, not how it was billed.

### Utilization metric

Remove. Utilization is a consultancy-culture metric that assumes a clean "billable hours / available work hours" relationship. Most of this business's client work is paid via flat fees and is non-billable under the new definition, so a utilization percentage would read low and misleadingly suggest under-working. The client vs. internal split on the Total Hours card answers a more relevant question.

### Retainer project allocation bar

When the bar exceeds 100%, the over-allocation portion renders in amber rather than green. Label changes from "X left · Y%" to "X over · Y%" when past 100%.

The bar continues to count *all* client work hours on the project (both within-allocation non-billable and overage billable). It's tracking consumption against allocation, not billability.

## Overage decision support (detailed report view)

### When this feature appears

Only on the detailed report view, and only when the report is filtered to a single retainer or fixed-fee project. The threshold concept doesn't apply when multiple projects are visible — there's no single allocation to compare against. Outside that filtered context, the detailed view behaves as it does today, with no markers and no row tinting.

### What we're trying to achieve

When reviewing a retainer's monthly entries to decide what overage to invoice, the user needs to see where allocation was crossed. This is the moment when the billable flag gets flipped on selected entries. The view should make the boundary visually unmistakable while preserving the user's control over which specific entries get marked billable.

### Design

**Threshold marker.** A horizontal band, clearly distinct from entry rows, that sits between the last within-allocation entry and the first overage entry in chronological cumulative order. Dashed amber borders top and bottom, soft warm gradient background, flag icon, and a two-part label:

- Primary: "Allocation reached · 3h 0m used"
- Secondary: "Entries below are overage candidates"

The marker is chrome, not data — it doesn't look like an entry row and isn't selectable.

**Placement.** The marker lives inside the day card where the crossing happened, between the two specific entries that straddle the boundary. Since entries are listed newest-first within and across days, the marker visually anchors the day where you ran out of allocation, with overage entries above it (more recent) and within-allocation entries below it (earlier in the month).

**Overage row tinting.** Entries past the threshold get a subtle warm cream background, carrying the amber visual language consistently from the threshold marker. This means overage status is visible per-entry even when scrolling away from the threshold marker — you don't have to remember which side of the line a given entry was on.

**Visual feedback loop.** The amber tint and the billable indicator should travel together. A non-billable entry in an amber row signals "this is overage you may be eating"; a billable entry in a white row signals "this might be flagged incorrectly." The user can scan for inconsistencies between the two visual signals.

**When no overage exists.** The threshold marker still appears, sitting at the position where the running cumulative would equal the allocation. The label changes to "X left of allocation" rather than "Allocation reached." This keeps the marker as a consistent boundary signal — always present, always meaningful — rather than something that only appears in overage months.

### Stat card behavior under single-project filter

The summary cards adapt their captions to the filtered context, since meaning is unambiguous when only one project is in view:

- **Billable Hours**: shows overage hours only, with a "overage only" sub-label
- **Billable Amount**: shows overage dollars only, with a "retainer overage" sub-label
- **Top Projects card**: hidden, since the project being viewed is already named in the right-side client context card

### What we're not doing

- Auto-flipping entries to billable based on chronological order past the threshold
- Splitting entries into partial billable/non-billable child records at the exact allocation boundary
- Creating separate "overage charge" billing line items disconnected from the entries themselves
- Showing the threshold on multi-project views

The per-entry billable flag preserves the per-entry decision, which keeps the door open for future "billed overage vs. swallowed overage" reports computed directly from entry data.

## Unbilled time outside the current range

### Problem

When reviewing reports at end-of-month to decide what to invoice, billable uninvoiced time from previous months can be invisible. The most common reasons time rolls over from a previous month: forgetting to bill it at the time, or back-and-forth with the client extending the timeline (initial work, weeks of email, client follow-up, more work). The current view shows only what's in the date range, so previous-month entries are stranded unless the user remembers they exist and manually widens the range.

### Goal

Surface the existence of stranded unbilled time on a project without forcing the user to remember it's there or manually expand the date range to check. One click expands the range to cover everything.

### Two surfaces

**Summary view project table.** A new narrow column at the end of the row, after Amount. Most rows are empty in this column. Rows with unbilled time outside the current date range show an amber alert icon (filled circle, 22px circular amber background, neutral header label). The empty space across most rows is itself the signal — exceptions stand out without visual noise.

Hovering the icon reveals the specifics. Clicking navigates to the detailed view, filtered to that project, with the date range expanded to encompass all unbilled time on the project.

**Detailed view when filtered to a single project.** A notice strip above the entry list, before any day cards. Amber background, alert icon, message describing the stranded time, and a button to expand the range. The strip persists until the user expands the range or navigates away.

### Notice strip wording

One consistent message structure regardless of whether stranded time is before, after, or both sides of the current range:

- Primary: "X of unbilled time on this project outside your date range"
- Secondary: "Earliest unbilled entry: [date]" (or similar — show the boundary that matters)
- Button: "Expand range to show all unbilled"

Keeping the wording uniform means one design, one set of strings, and no branching logic for which side the stranded time is on.

### What counts as "unbilled"

An entry is unbilled if it is marked billable and not marked invoiced. Under the new billable model this means:

- Hourly project entries marked billable and not invoiced
- Retainer project entries marked billable (i.e., flagged as overage) and not invoiced
- Fixed-fee entries don't generate this state — fixed-fee work is invoiced separately on its own schedule

The indicator only appears for projects that have at least one billable uninvoiced entry outside the current date range.

### Range expansion behavior

Clicking either the icon or the button expands the date range to span from the earliest unbilled entry to the latest unbilled entry (or to the current range's far boundary, whichever is wider). The intent is "show me everything I might want to invoice on this project right now" — not preserve the user's previous range structure.

After expansion, the notice strip disappears or transforms into a brief confirmation state, since the stranded time is no longer outside the range.

### What we're not doing

- Showing the indicator on projects with no stranded unbilled time (most rows will be empty in the alert column)
- Showing a "+X hrs" pill or other quantitative summary inline on the summary row (an icon is sufficient; specifics live in the tooltip and the destination view)
- Branching the notice strip wording or button by stranded-time direction (one universal pattern)

## Daily bar chart: three-color split

### What we're trying to achieve

The current chart uses two colors — green for billable, gray for non-billable. Under the new billable model, most retainer and fixed-fee work shifts from green to gray, which creates a visual problem: the chart will look like a lot less work is getting done, when really the same amount of client work is happening, it's just paid via flat fees instead of per-hour.

A three-color split solves this by separating two questions the chart was conflating:

1. **Was I doing client work?** (productivity question — both green categories combined)
2. **How much of that generated per-hour invoices?** (billable question — green alone)

Both answers are visible at the same time without either fighting the other.

### Color mapping

- **Billable** (full green): hourly project work, retainer overage flipped to billable. Generates per-hour invoice dollars.
- **Client (flat-fee)** (muted sage-green): within-allocation retainer time, fixed-fee project time. Client-facing work paid via flat fees.
- **Internal** (warm gray): admin, business development, internal care plan work, anything not client-attributable.

The two greens share a color family so they read as related categories at a glance — your eye groups them as "client work" while still distinguishing billable from flat-fee. The internal gray sits visibly apart from both.

### Stack order

Bottom to top: billable, flat-fee, internal. This anchors the billable slice at the baseline so it's always visually grounded, even when small. Each bar reads as "real revenue work first, then other client work, then internal" — the categories build up in order of how directly they contribute to per-hour revenue.

### Amber stays reserved

Amber is used elsewhere to mean "crossed a threshold" — overage past retainer allocation, the project bar exceeding 100%. Reusing it as a third category color in the chart would dilute that meaning. Keep amber for threshold-crossing specifically; don't fold it into the chart's category system.

### Legend labels

"Billable / Client (flat-fee) / Internal" reads more clearly than "Billable / Non-billable / Internal" because it names what each category *is* rather than what it isn't. Future-you reading the chart cold should be able to interpret it without remembering the data model.

## Unified entry form (add and edit)

### Problem

Two related problems share a solution:

1. **Entries can only be created through journal parsing.** When work happens after a day's notes have been processed, or when something needs to be added that wasn't captured in the notes, there's no clean way to add it.
2. **The existing inline editing on the review/edit screen is cramped and awkward.** The current pattern replaces column text with inputs in place, which works but produces small inputs, hard-to-use dropdowns, and no room for the tag picker or validation messages.

A unified expandable-row form solves both: the same form serves as the editor for existing entries and the creator for new ones.

### Architecture

The review/edit screen is the canonical place where entries are created, edited, and committed. The daily log screen remains a viewing surface for the day's notes and resulting entries, with a shortcut that routes to the review/edit screen when modification is needed.

One canonical edit surface, two entry points for adding. One form component for editing and adding.

### Two entry points for adding

**From the daily log screen (post-processing):** A "+ Add entry" button at the top right of the Recorded Entries section. Clicking it navigates to the review/edit screen with the manual entry form expanded.

**From the review/edit screen directly:** A "+ Add entry" button at the top of the entries list. Clicking it expands the form inline at the top.

### Editing existing entries

Each entry row uses WordPress's row-actions convention: a reserved-but-invisible area beneath the date/time cell holds Edit and Delete links that fade in on hover. Click Edit to expand that row into the same form layout used for adding new entries.

Only one row is expanded at a time. If the user clicks Edit on another row while one is already expanded, the open form auto-saves (if valid) before the new row expands. This keeps commit boundaries predictable without forcing extra confirmations.

### Form behavior

The form expands inline in place of the compact row, with a blue tinted background and blue borders to clearly mark the editing region. The Add entry button de-emphasizes (fades) while the form is open if the open form came from that button.

Form title varies by context:

- **Add mode**: "New manual entry" with a plus icon
- **Edit mode**: "Editing entry" with a pencil icon

Everything else about the form is identical between the two modes.

### Fields

- **Description** (text input, full width)
- **Date** (date picker — defaults to the day being viewed, editable in case an entry needs to be moved to a different day)
- **Start time** and **End time** (small time inputs, with auto-calculated **Duration** field beside them; Duration is also editable, and editing it manually breaks the auto-calc relationship)
- **Billable** (checkbox, inline with the time row)
- **Client** (select)
- **Project** (select, filtered by selected client)
- **Tags** (tag picker matching the one used elsewhere in the tool)

Invoiced status is not included. That field is managed from the detailed report view; it's not a property the user interacts with at entry creation or routine editing.

### Smart defaults (add mode only)

- **Date**: the day being viewed
- **Start time**: end of the most recent entry on that day, or current time if no entries exist yet
- **End time**: empty (filled when user enters one, or auto-calculated if duration is entered first)
- **Client / Project**: last one used on that day (most recent entry's project)
- **Billable**: defaults based on project type when Project is selected — hourly projects default checked, fixed-fee and retainer projects default unchecked (matching the journal parser's defaults)
- **Tags**: empty

Defaults aim to make the common case ("I forgot a small task right after the last logged one") nearly one-click.

In edit mode, fields are populated with the entry's existing values.

### Save behavior

Per-row Save commits that entry independently. No need to use "Save All Entries" for routine editing — each edit commits when its form is saved (or auto-saved on navigating to another row). Cancel reverts that row's form to its pre-edit state without affecting other rows.

"Save All Entries" remains on the post-parse review state where it makes sense as a bulk commit of newly parsed entries. Outside that flow, per-row save is sufficient.

### Validation

Strict overlap validation. If the entered times overlap with another existing entry on that day (excluding the entry being edited), the form does not submit. An inline error appears under the time fields naming the conflicting entry and its time range, and the form stays open until the conflict is resolved.

No "save anyway" override. Time overlaps are almost always typos, and forcing a clean state keeps the entries list internally consistent.

### Review/edit screen states

The screen now handles four contexts using the same form pattern:

1. **Post-parse review** (existing): new unsaved entries from parsing; "Save All Entries" commits them as a batch
2. **Editing existing entries** (existing, redesigned): per-row hover reveals Edit/Delete actions; clicking Edit expands the row into the unified form; per-row Save commits
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

## Migration

One-time backfill of existing entries:

- **Retainer projects**: flip currently-billable entries to non-billable, *except* entries that were genuinely overage and got invoiced separately. Identifying overage entries probably means cross-referencing against past Zoho invoices, or reviewing each retainer's history manually since overage cases should be relatively rare.
- **Fixed-fee projects**: flip currently-billable entries to non-billable. No exceptions — fixed-fee dollars never came from time × rate.
- **Invoiced flag on flipped entries**: leave as-is or backfill to un-invoiced. Either is fine; pick the option that requires less effort. No downstream behavior depends on this.

A dry-run script that lists what would change before the migration runs is worth the small extra effort.

## Explicitly out of scope

These came up during the design conversation but are deferred:

- **Expense entries as a separate entry type.** Cleaner generalization of the entry model for handling pass-through costs and flat charges, but not on the critical path for the current problem. Build if/when there's a concrete need.
- **Revenue reporting.** Fixed-fee invoice revenue, retainer revenue, and total invoiced amounts live in Zoho Books. Cross-system reporting is a future concern, separate from time tracking. For now, revenue questions get answered in Zoho directly.
- **Annual "billed overage vs. eaten overage" reports.** Computable from entry data under the new model, but doesn't need to be built as part of this change.
- **Utilization metric redefinition.** If a different productivity signal becomes useful later, design it then with a specific question in mind. Don't preemptively replace what's being removed.

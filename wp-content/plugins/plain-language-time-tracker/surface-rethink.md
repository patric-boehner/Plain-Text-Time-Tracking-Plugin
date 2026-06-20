# The clean-slate surface

*Companion to `billing-record-spec.md`. That doc defines the billing record's data model; this one is the surface and flow layer that sits on top of it — the six screens, how billing moves through them, and how an invoice line gets composed and resolved.*

## The framing

The tool runs as **two modes across three stages**: a frictionless capture mode and a deliberate mode (everything else), with a hard wall right after capture. The three stages are Capture → Process → Report.

Two organizing moves shape the whole surface:

- **Everything *about a project* gathers on the project's own page** — health, billing, history, its entries — which lets the Reports screen stay purely about spans of time across projects.
- **Billing happens in exactly one place.** A single billing surface, reached from a few entry points. Nothing commits a charge from a stray button click; it always passes through a verify step.

## The surface — six screens, three groups

| Group | Screen | Job |
|---|---|---|
| Daily | Today | Capture log + the day's processed entries + day timeline |
| Daily | Log history | Navigate back to any past day |
| Looking back | Reports | Where time went + the invoicing queue (period, cross-project) |
| Looking back | Project page | One project's health, billing, history, entries |
| Looking back | Client page | A client's projects + rolled-up billing |
| Managing | Clients · projects · tags | Roster lists + the type-driven create/edit form |

---

## Today

The landing screen is the log itself — you open the tool already in today's capture field, no dashboard in the way. `@` drops a timestamp; it saves as you type. The log is the only capture surface and cannot ask you to categorize anything — that's the wall, made structural.

**Process** is the explicit seam (a button, not live parsing) that turns the text into structured entries. Below the log, the day reads top to bottom as capture → shape → detail: the log, then a single-day timeline (when work happened, gaps, context-switches), then the entries. The timeline sits above the entries (full width reads better than a half-width strip); in the real build it carries an hour axis (≈8a–6p) so blocks sit at true times. The billable/non-billable stripe on the timeline is optional — keep it if a glance-level "how much of today bills" is useful, drop it if it's noise.

**Editing, adding, reprocessing** all run through one unified expandable form:

- *Reprocessing* reconciles by timestamp: any log line whose time falls inside an already-committed entry's span is skipped; only lines in the uncovered gaps become new drafts. Committed entries lock and are never duplicated, so the day is safe to re-run any number of times.
- *Manual add* stays available as the easier path when re-running the log is more friction than it's worth. The add form pre-fills the date and starts where the last entry left off.
- *Editing* expands a committed entry in place into the same form.

Edit and add are the same form; only the header (pencil vs. plus) and the starting values differ.

---

## Processing — the finalize step

Finalizing is as load-bearing as capture, and it earns its place by **costing attention in proportion to uncertainty, not to entry count.** A clean day commits in a glance and a click; a messy day pulls your eye only to the ambiguous rows.

The review screen is a quiet list with a loud header. The header summarizes the day and carries counts that match the rows beneath it — "1 needs assigning · 1 to confirm · 3 untagged" — so the size of the job is legible up front and every count points at something visible. Each parsed entry is a row: an inline-editable time range, the description, a project picker, a tag picker, and a billable indicator.

**Client and project are one control, not two.** An entry is really keyed to a project; the client is that project's parent, shown for context. So the row carries a single project picker — opened, it's a searchable list of active projects grouped by client (Internal is just another group with its several projects), with the resolved client's siblings floated to the top so a wrong-project fix is one tap. This removes the old two-field dance where client and project predicted separately and the project always needed a second check.

The project resolves into one of three states, and the visual says which:

- **Confident** — a direct alias hit resolved both client and project. Settled, no check needed.
- **Guessed** — client resolved, project filled by the most-recent-active-project heuristic. Filled and recorded as-is if left, but softly flagged (dashed, amber dot) and counted under "to confirm." A glance, not a blocker: the row itself stays calm because the client is right and the time is logged — only the project control whispers.
- **Unset** — nothing resolved. The loudest outstanding item: amber-tinted row, counted under "needs assigning." It still saves (committing as unassigned, to fix later) rather than hard-blocking — unless assignment is later chosen as the one true requirement to commit.

Guessed projects *nudge, don't block*: Save all stays available, but the header won't read clear (green) while any guess is unconfirmed. Confirming is a tap; ignoring records the guess. This sheds the per-entry friction while still surfacing the one prediction that's a real guess.

Tags predict the same way — a high-confidence prediction shows pre-filled and dashed (records if left); below that bar the chip stays empty rather than rubber-stamping a guess. But tags don't gate the commit: an entry can save with no tag at all, and the "untagged" count is an informational nudge for your own habit, not a blocker — it never withholds the clear (green) state the way an unset project or unconfirmed guess does. Activity and Flag stay collapsed into the one picker — an entry may carry one or two tags, with no UI split between the groups. Tagging is row-by-row here; bulk tagging and multi-select editing live on the all-entries screen, not on a single day's finalize.

Other row mechanics:

- **Time ranges edit inline** — the real correction need (a late-added timestamp occasionally lands between two others and splits an entry wrong). *Open:* whether the right affordance is plain start/end editing or a dedicated split/merge action aimed at that failure mode.
- **Descriptions edit inline** too, but secondary — for a typo or a touch of added context.
- **Billable defaults once, from the project type at parse**, and is rarely touched at this stage. Changing the project later does *not* re-apply the default (no clobbering a manual choice); an unset entry keeps the global default until toggled. The inline toggle stays for the exception.
- **Commit is one batch action** — "Save all."
- **No timeline here.** The review screen stays single-task; the day timeline (gaps, shape) appears post-save on the day view and on the report at day-zoom, where you're looking *at* the day rather than committing it.

**Reprocessing a day** locks what's already finalized and brings in only what's new: committed entries are untouched, and only newly-detected timestamps appear as fresh drafts to review. Re-running the parser after adding a late note is safe — it never disturbs or duplicates settled work. The screen shows locked rows distinctly from fresh ones so the difference is visible.

*Calibration:* the defaults aren't carrying the load yet — in practice ~half of entries need a client/project fix and effectively all need a tag set by hand. So the screen is designed to be fast at *that* hit rate, with prediction (alias seeding, the resolver, the tag learner) lowering the volume over time rather than being assumed. See "Matching, tagging & prediction."

---

## Matching, tagging & prediction

The deterministic prediction layer is the lever to improve first — make the auto-fill genuinely good before reaching into capture. In rough order of payoff:

- **Seed aliases at creation.** The Settings form's aliases field is a small manager, not a plain input: add the shorthand you know you'll use (as chips), see the learner's discovered aliases alongside (marked, with use-counts), and prune the junk. Manual aliases match at full confidence immediately, closing the day-one misses the learner is blind to. This resolves an old "bootstrap problem" — the alias learner only gets good around month six, so it needs a deterministic seed layer underneath it. Cleanup is the minor, occasional secondary use.
- **The resolver keeps learning** from your review corrections, closing more of the ~50% client/project misses over time.
- **A deterministic tag learner** — the same alias-learner pattern pointed at tags: it maps words and phrases in descriptions to the tag you tend to apply, with confidence, improving as you correct it. Seedable like aliases, silent until it has signal, fully deterministic. It turns the 100%-manual tagging toward confirm-or-swap without an AI call.

**The AI boundary.** The tool stays deterministic everywhere except one spot: the invoice-description composer, which is genuine language generation (shorthand → client-facing prose) and can't be done deterministically. Classification — which client, which tag — is pattern-matching, handled by the learners, not a model. AI only where the task is generative.

**Capture-time autocomplete is parked.** Tagging client/project/tag directly in the journal via keystrokes would move categorization cost into capture and trade against capture-first. It stays the last lever — pulled only if seeding plus the learners plus fast-manual review don't get the friction low enough — not the first.

---

## Log history

A pure navigator: its job is getting you back to a day to review, re-process, or edit. Only days that have logs appear — no empty weekends to account for. Days group by week with a weekly subtotal (matching Reports), under a month filter. Clicking a day opens it in the Today view at that date.

Each row carries an **intensity bar** so a light or fragmented week reads at a glance — this does most of what a calendar view promised, so the calendar stays deferred. The **flag** is a day-level *journal* bookmark: you mark a day because of something in the notes worth returning to, not because of its time entries; flagged days are filterable.

*Open:* a processed-vs-unprocessed status is a real signal worth adding (a logged-but-unprocessed day has notes but no entries, so it can't show hours the same way) — noted, not yet designed.

---

## Reports

Period-scoped and cross-project. A **Time / Invoicing** toggle puts both surfaces on one screen. The period filter at top governs everything below it — every card, bar, and row reflects the current range, so nothing on the screen means anything outside the filter.

**Time view:**
- Summary cards, deliberately pared: total hours, billable hours, billable amount. Secondary lines only where they inform something.
- EHR appears conditionally — only when the filter makes it meaningful, hidden when it wouldn't be (e.g., viewing only retainer or internal work).
- A daily chart (solid = billable, light = non-billable) at week/month zoom; at single-day zoom it swaps to the Today timeline, same component.
- A by-project breakdown — the literal "where did the time go" — sortable by project or type, with em-dashes where a project type doesn't bill from time.

**Invoicing view:** the cross-project queue of everything outstanding this period — see "The billing flow" below.

---

## Project page

The home for everything about one project. Reached by clicking a project in the Reports breakdown. **Period-scoped with a range selector** (not just a month-stepper). Two tabs: Report and Settings.

While **active**, the Report tab is a *condensed summary*, not an entry dump:
- **Identity:** name, client (as a link), the type line with its financial detail.
- **Health** for the scoped period: a retainer shows an allocation bar (green within, amber over); a fixed-fee shows a budget bar; hourly shows none (no budget concept).
- **The billing decision:** when a retainer is over and unbilled, a notice with a **Review & bill** action (opens the billing surface, pre-filtered to this overage).
- **Time summary** — a one-line "X this month, N entries" with a **View entries** link that expands the entry list *inline*, scoped to the selected period (widen the range to see all-time). It's the same entry-list component as Reports/Today; it adapts to type (a retainer shows no per-entry billable column, since retainer billing is aggregate; hourly shows billable per entry).
- **Billing history:** the full cross-period ledger of records (read-only). Unlike the rest of the page, it is *not* bound to the selected period.

**Multi-month retainer view:** set the range to several months and the single-month allocation bar gives way to a per-month usage-vs-allocation breakdown plus a summary (average vs. allocation, how many months ran over). This is the "should I resize this retainer" data — shown as numbers, not a recommendation. It's a *live* retainer lens, distinct from the postmortem.

When **archived**, the top half becomes the postmortem — the home of reporting decision #4, *was this profitable in hindsight*. Review & bill disappears (nothing live to decide); billing history and View entries stay. Archived is the *one* place EHR belongs: the project is closed, so total hours and revenue are both final and the ratio is a retrospective fact rather than a gauge ticking up while you work — which is exactly why it can't become a pressure meter. Each element has to answer #4 or tell you what to price/scope differently next time.

Universal content (every type):
- **Final EHR** as the headline — total received ÷ total hours — shown as *just the number*, with the target rate beside it as the only reference. No "profitable / not" verdict; you read the gap yourself.
- **The two inputs that make the ratio legible:** total hours, total revenue, and the span (start → end). The post-mortem question is really *which input surprised me*, so showing both stops the EHR being a black box.
- **Where the time went** — the activity (and phase, for builds) breakdown scoped to this one project. The most actionable piece: revisions or comms eating a big slice is next quarter's pricing lesson.
- **Effort-over-life sparkline** — front-loaded vs. long-tail vs. ballooned-at-launch.

Type-specific read (EHR means something different each time):
- **Fixed-fee** (richest): fee, hours, EHR, plus *implied vs. actual hours* (the fee implied X hours at target; you used Y) — the over/under-scope verdict, since this is where overage is eaten.
- **Retainer:** lifetime use vs. allocation across all months, total billed overage, EHR-with-overage. The final "was it right-sized."
- **Hourly:** EHR ≈ rate, so the read is realization — billable vs. non-billable, total billed, anything absorbed.
- **Internal:** no EHR; just total time and where it went.

The one seam to name: the revenue figure comes from what the *tool* knows — project fee (settings) plus billed overage/hourly (records) — not Zoho collections. So it's a configured-and-billed EHR, reflecting what you meant to bill, not what cleared. The right tradeoff for a self-calibration post-mortem, and it keeps Zoho out of it — shown as a quiet caption under the number, not a disclaimer block.

---

## Client page

Deliberately spare — identity, what's outstanding, the projects:

- **Header:** name, default rate, aliases.
- **Ready-to-invoice rollup:** sums what's outstanding across all the client's projects into one figure, with Review & bill opening the billing surface scoped to the client — where each project shows up as its own line card. This is where a one-client / several-lines invoice originates, and it's why grouping the Reports queue by client holds up.
- **Projects list:** active and archived, each linking down to its project page.

No contact-recency or lifetime-value cards (same call as contact gaps). A client-level billing history is a clean future add if "what have I billed this client this year" ever becomes a real question.

---

## Managing — clients, projects, tags

The roster layer sits beneath the entity pages: three direct list pages — clients, projects, tags — each a roster plus an add button. Kept as three separate pages, not tabs: they're already directly reachable, and tabs would only bury two of them behind a click. The layering is roster → entity page → edit (the type-driven form below). The Tags page curates the taxonomy itself — Activity tags with their description fields, and the rare Flag — to add, edit, or archive. Phase isn't a tag (it's derived from project dates), so it has no row here.

---

## Settings (create/edit)

**Type is the load-bearing field**, so the form reveals only the fields that type needs — no generic field soup:

| Type | Fields shown |
|---|---|
| Hourly | rate |
| Retainer | monthly fee · included hours · over-rate |
| Fixed fee | total fee (budget hours derived from fee ÷ target rate, not entered) |
| Internal | none — nothing to bill |

Plus status (active/archived). The client form is the simple sibling: name, default rate, aliases. The same forms are reached from the management list, an entity's Settings tab, or the quick on-the-fly create during entry review.

---

## The billing flow

One billing surface (the ready-to-invoice cards), reached from entry points:

```
Reports · invoicing queue   ─┐
                              ├─→  Billing surface  ─→  Billing record  ─→  Project billing history
Project page · Review & bill ─┘    (verify, adjust,        (written           (read-only)
Client page · Review & bill        commit)                 on commit)
```

The entry points only differ in scope: the Reports queue is everything outstanding; Review & bill on a project is that project pre-filtered; the client rollup is the client's projects together. They all land on the same verify screen, where you adjust the amount, edit the description, and only then commit. Nothing writes a record from a single click. Committing writes one billing record (see `billing-record-spec.md`), which then appears read-only in the project's billing history.

---

## The invoice line, resolved

### The card

Each line is one card: client · project with a type badge; the **amount** (editable — that edit *is* the absorption lever) over its derivation; an **Included** manifest; a **description**; and two actions, **Bill** and **Write off**.

### Scope and cutoff

- **Retainer:** scope is the month, by definition (allocation resets monthly). No range to adjust.
- **Hourly:** work is pooled by *unbilled status*, not by month — so forgetting to invoice for two months simply leaves it all sitting in one pile, already grouped, ready as a single line. The default scope is "all unbilled billable work." A **bill-through cutoff** is the only range control, and it's a single end-bound (not a two-ended range), because the start is always "oldest unbilled" — a start bound would orphan older work. Held-back work stays in the pool for next time.

The **manifest is self-documenting**: with a cutoff applied, expanding it shows the included entries and then the held-back ones below a divider (greyed). The boundary lives in the list, so you never have to remember when the cutoff was.

You don't curate entries one by one on the card — two upstream levers already handle exclusion: the **billable flag** (set at processing) keeps non-billable work out of scope entirely, and the **amount** handles any write-down. The manifest is for confidence, not curation.

### Description composer

It's **translation, not summarization**: your entries are terse capture shorthand written for yourself; an invoice line has to read for the client. The model turns internal shorthand into professional, grouped, client-facing language — which is also why you always review it (the one place private phrasing could leak onto a client's invoice). It runs on demand at billing time, never at capture, and its input is just the entries in scope.

- **Detail levels:** one-line (the default, ~90% case) and itemized (semantic grouping — it folds related entries into themes but keeps distinct work separate; optional hours per line).
- **Steering** is thin on purpose: edit the text directly, flip the level, or regenerate for alternate phrasing. No tone knobs — professional-neutral default, edit for a specific register.
- **Type framing** shifts on its own: hourly describes the deliverables; a retainer reads as "support beyond your plan."
- The finalized text saves onto the billing record (snapshot). This is the one feature that needs a model call (the WordPress AI connector); everything else in the tool is deterministic.

### The three outcomes

Any scope ends one of three ways:

1. **Bill** — full, or partial with the remainder absorbed → an *invoiced* record (absorbed amount noted if trimmed).
2. **Write off** — the $0 / fully-absorbed end of the same spectrum → a *written-off* record.
3. **Leave open** — do neither; it stays in the unbilled queue until you decide.

Absorption isn't a special case — it's the whole continuum from billing full to billing nothing, and every point on it gets recorded. Billing or writing off both clear the entries from the unbilled queue and land in history with their status. This is what makes write-offs visible: the fifteen-minute general-support months get one Write-off click, and later you can actually see "wrote off six months of general support for this client" — time that today just evaporates.

---

## Principles carried through

- **Capture first, categorize later** — the wall after capture is structural, not a guideline.
- **Everything about a project gathers on its page** — so Reports stays about spans of time.
- **One billing surface** — many doors, one verify-and-commit screen.
- **Type drives behavior** — billing, budget display, defaults, and which fields exist all follow project type.
- **Descriptive, not evaluative** — the data shows the pattern; you make the call.
- **Defaults over options** — single-user software encodes decisions as defaults rather than configuration.
- **Only build what has a pain point** — deferred items stay deferred until real friction shows.
- **Absorption is a recorded continuum** — from full bill to full write-off, every point captured.
- **Deterministic by default, AI only where generative** — classification uses the learners; only the invoice description (true language generation) calls a model.

## Open / deferred

- Processed-vs-unprocessed status in Log history (real, not yet designed).
- Calendar view for Log history (intensity bars suffice for now).
- Timeline hour-axis (real build) and the optional billable stripe (your call).
- Client-level billing history (future add if a real need surfaces).
- Capture-time autocomplete (keystroke tagging in the journal) — the last-resort friction lever, deferred unless prediction and fast-manual review fall short, since it trades against capture-first.
- The billing-record open items already tracked in `billing-record-spec.md`.

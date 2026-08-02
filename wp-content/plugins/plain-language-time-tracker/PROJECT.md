# Plain Language Time Tracker

## Overview

A WordPress plugin for tracking time with minimal friction. Instead of stopping work to fill out forms and dropdowns, you jot down what you're doing throughout the day in plain text, then process and categorize entries at the end of the day.

**Core Principle:** Capture first, categorize later.

### How It Works

1. **Capture** - Write plain text notes with timestamps throughout the day
2. **Process** - Click a button to parse notes into structured time entries
3. **Review** - Verify predictions, correct client/project assignments, save
4. **Learn** - The system remembers your patterns and gets smarter over time

---

## Core Features

### Plain Text Entry
Type natural language notes throughout the day. Press `@` to auto-insert the current timestamp. No forms, no dropdowns during capture.

**Supported formats:**
- `@2:50pm - Task description`
- `@14:50 - Task description` (24-hour)
- `@2:50pm - 4:20pm - Task` (explicit range)
- `@4:20pm - done` (end marker)

### Smart Parsing
Automatically calculates durations between consecutive entries. Supports end markers ("done", "finished", "end"). Gaps between entries are visible during review.

### Alias Learning System
Detects acronyms (2-5 uppercase letters) and capitalized words in notes. Builds a mapping table linking aliases to clients and projects. Tracks confidence scores, use counts, and last-used dates. Learns from corrections over time.

### Contextual Project Prediction
When a client is identified, suggests the most recently used active project from the last 30 days. Archived projects are excluded from predictions but preserved for reporting.

### Client & Project Management
Two-column admin screen for managing clients and projects. Add, edit, or delete from a dedicated screen. Create new clients/projects on the fly during entry review.

### Project Lifecycle
Projects can be Active (appear in predictions) or Archived (excluded from predictions, retained for reporting). Archived projects show "(Archived)" label in dropdowns when editing old entries.

### Tag System
Tags are pre-filled during parsing: a tag whose own name appears in the description, plus any seeded keyword→tag matches (`pltt_tag_aliases`). Nothing is typed into the log to tag an entry — the pre-fill is a suggestion you accept or change on review. Searchable and filterable. Tags are optional metadata, and don't feed client/project prediction.

### Review & Verify Workflow
Processed entries appear in a structured table with time ranges, durations, client/project dropdowns, and tag inputs. Inline editing with WordPress-native Edit/Update/Cancel pattern. Confidence indicators show prediction certainty.

### Billable Rates & Financial Tracking
Three-tier rate hierarchy: Project rate > Client rate > System default ($100/hr). Rates are snapshotted when entries are verified (historical accuracy preserved even if rates change later). Billable hours and amounts tracked in summary cards.

### Reports
Filter by date range, client, project, tags, billable status. Summary and detailed views. Summary view defaults on load and shows totals grouped by project. Detailed view groups entries by day. Summary cards: Active Projects (with client count), Total Hours (with daily average), Billable Hours (with utilization %), Billable Amount (with period-over-period comparison), and Overall EHR (effective hourly rate vs. target). Pagination for large result sets.

In the detailed view, Tags, Billable, and Invoiced are always editable inline — no mode toggle required. Changes save immediately via AJAX. Invoiced status is only available on billable entries.

### Daily Log & Log History
Auto-saving notes persist across sessions. Log History screen provides chronological archive of all daily logs, filterable by month. Navigate to any past day to review or re-process.

### Inline Entry Editing
Two levels of inline editing on Reports:
- **Field toggles** — Tags, Billable, and Invoiced are always editable directly in the detailed view table. AJAX saves, no page reload.
- **Full entry modal** — Click the Edit button on a day header to open a modal for editing date, time, description, client, project, tags, and billing status on any entry in that day.

---

## Data Model

### Entities

**Clients** - Name, aliases, description, hourly rate
**Projects** - Name, client (FK), status (active/archived), hourly rate, description
**Time Entries** - Date, start/end time, duration, raw text, description, client (FK), project (FK), verified, tags, billable, billable_rate, billable_amount, billed
**Aliases** - Alias text, client (FK), project (FK), confidence score, use count, last used

### Relationships

```
Client (1) --> (many) Projects
Client (1) --> (many) Time Entries
Project (1) --> (many) Time Entries
Time Entry (many) --> (many) Tags
```

---

## Status

All planned features are complete. Use the tool. See what's actually missing.

---

## Notes

These are loose notes that I have thought about for features or fixes. Whether or not they become features is to be decided.

- **Bulk Editing** - Tags, Billable, and Invoiced are now always editable inline on the Reports detailed view. What's still missing: bulk selection across multiple entries to apply a change to all at once (e.g., mark an entire day's entries as invoiced in one click).
- **Calendar View for Log History** - Not an immediate need by any stretch but it might be nice to visually be able to see when I was working in a calendar view. Its easier to spot when I am having a bad week in a monthly calendar view. Doesn't need to be anything fancy. Just a month view where you can see what days of the week were logged, maybe how many hours worked.
- **Review Entires Screen Date/Time Editing** - This could be improved. Maybe by splitting date and time again into their own columns. Times could be inline fields and date could just be a button that opens a date picker (like Clockify does and like we do on tag selection).
- **Tag Select Dropdown** - The add new tag button should be moved out of the list items, that way its under the list but is static like the search filed. This could use a little style updating to provide a little spacing and division to make it look better. More importantly this is a pattern that we should turn into a component and used elsewhere like in the Clients and Projects dropdown (without the checkbox), and in reports for multi select filtering.
- **Block/Stop Words** Expand the list of block and stop words.
- **Local Storage** - It might be nice to explore using local storage to save the journal in addition to being saved tot he DB so it could be possible work offline.
- **Project Defaults** - Project defaults around bilability don't carry through to the entry review screen. So you still have to manually set things. Its work going back through and understainf where those default states are and aren't being used.
- **Log History View** - I am thinking we should breakup and group the log history table by week like we do in reports.
- **Log Flag** - Since this is a tool thats a combination of interstitial journaling/logging and time tracking it might be nice to have a flag feature for a log. Just a way of quickly identifying and filtering a log you want to return to maybe because it has an important note or something you want to followup on. Easy to turn on and off like the billable feature.
- **Reports Time Card** - Change the time card to show hours and minutes by default, maybe with a hint text with the decimal numbers. Decimals are great for math but its easier for me to read just straight forward time.
- **Client Report Cards** - On the clients list page i am thinking how we could add metrics that would be valuable. Since I tend to track meetings and emails I could have a card that lets me know which clients I haven't had contact with recently, that way I can make sure to get in touch and see how things are going. I don't really have a place in any of my tools to do that right now. If we add filtering to that page in the future we could show metrics like past project, open projects, value.
- **Alerts** - When we implement tracking retainer projects it would be nice to implement an alert notice options, to have a noice set in the admin and maybe in the future an email when a certain performance is reached. That way I can respond proactively to client hours.
- **Daily Log** - Maybe have a presistent message about when it was last saved? This  may not all that helpful but sometimes I notice it saves so quickly i'm not sure its saved. Or maybe some sort of status that its saving is running.
- **Reports Cards** - We maybe should remove the secondary information from the report cards. Unless its the end of the month, most of this information isn't useful. Not unless they are further refined and I'm not sure if thats useful. Example, billing period amount, unless its comparing the exact number of days (12 days into the month vs 12 days into last month), its just more confusing information. That breaks our framework for report, and anything on the reports page needs to be reflective of the current date filter.
- Should the report cards, in details view update dynamically as you switch things to billable or not.
- **Budget Bar** - Should the total under the graph represent only non billable hours or also include billable hours? Right now it only represents non-billable hours. If hours are marked as billable because they are over, they are subtracted from that count. It seems like it should include all hours?



## Ideas & Wishlist

These are backburnered. Only build if a genuine need emerges from actual use.

### Committed — needs building

- **Finalize-screen consumption indicator** — see
  `finalize-consumption-indicator-spec.md`. Show "Democrats of Rossmoor — 2.1 of
  3h, 5 days in" beside the project picker while assigning entries, climbing live
  as they are assigned. **Build this first**: no dependencies, no migration,
  reuses the `data-billable-flag` pipe, and it surfaces every day at the end of
  work rather than once a month at invoicing.

- **Cross-project profitability overview** — effective rate per project, ranked,
  banded against two thresholds: **ideal $100/hr** and **minimum $90/hr**. At or
  above ideal = good; between = ok; below minimum = bad.

  Right now profitability is per-project, so it is only seen on a project already
  being worried about. Ranked across everything, the pattern is visible: of 27
  computable projects, **15 fall below the $90 minimum**, and the split is not
  random — the worst are large fixed projects (Robin Mohr $39.34/hr, Postie
  Accessibility Remediation $75.54/hr), the best are small bounded pieces (NCJW's
  event forms $100–115/hr, BTSA's June ad $117/hr).

  Two known data problems to solve first, or the numbers mislead:
  1. **Retainers cannot be computed at all** — the monthly plan fee is recorded
     nowhere. Four retainers, 76 hours, no answer. Needs first-class budgets.
  2. **Fixed-project revenue reads `budget_fee`, which is sometimes stale** —
     Sparq's says $1,500 against $6,550 actually invoiced, which is why its
     $21.13/hr is almost certainly wrong. Hourly figures are sound (revenue =
     what was actually billed).

- **Overage threshold notifications** — tell me when a retainer crosses its
  allocation, at the time it happens, not when I sit down to invoice.

  The need emerged from the Aug 2026 Zoho reconciliation, which is the "genuine
  need from actual use" bar: Democrats of Rossmoor went over its 3h allocation in
  **all six** tracked months (Feb–Jul), and across Feb–Jun calculated $1,839.00 of
  overage against $810.40 invoiced — **$1,028.60 absorbed, 56% of it**. Postie went
  over in 3 of 6 months. None of that was visible until invoicing time, by which
  point the month is closed and the choice is bill-late or absorb.

  The point is to move the decision from *after* the period to *during* it, so
  absorbing is a choice rather than a default. Scope is unsettled — a nudge on the
  Overview, a notice on the project, or something at capture time — but the
  trigger is clear: crossing `pltt_budgeted_minutes()` for the current period.

  Interacts with the parked recurring-fixed-fee type: a project whose hours are a
  monitoring reference rather than a billing threshold must NOT notify.

### From Original Planning
- **CSV Export** - Export time entry data to CSV for spreadsheets or invoicing tools
- **Settings Page** - Configure plugin behavior, preferences, defaults
- **Calendar View** - Visual calendar showing logged time, patterns, and gaps
- **Gap Detection** - Flag large gaps between entries during review
- **Multi-Day Task Support** - Tasks spanning multiple days
- **Entry Auditing** - Flag unusual entries (excessive duration, missing assignments)
- **Bulk Entry Updates** - Select multiple entries, apply changes in bulk
- **Advanced Report Filtering** - Richer filtering, negative filters ("not tagged with: X")
- **Reporting Visualizations** - Charts and graphs for time data

### From Planning Sessions (Feb 2026)
- **Task Type categorization** - Centralized categories (Implementation, Planning, Communication, Admin, Support, Learning) for analyzing where time goes. Tried it, added friction. May revisit if tags prove insufficient.
- **Project billing model** - Track whether projects are Hourly, Fixed, or Retainer. Useful for filtering and future EHR calculations. Can add the field later without losing data.
- **Fixed fee + EHR** - Store project cost, calculate Effective Hourly Rate (Fixed Fee / Total Hours) for post-project profitability analysis. Only meaningful for archived fixed-fee projects.
- **@ auto-suggest shortcuts** - Extend @ system: `@now` for timestamp, `@client:` and `@project:` with auto-suggest dropdowns. Inspired by TallyHo.app's prediction system.
- **TallyHo-style entry display** - Rich multi-line rows showing Client, Project, Category, Duration, Amount, Description, Time Range, Rate Source all at a glance
- **Daily reflection metrics** - Longest focus block, context switches, average task length, fragmentation visualization
- **Log history calendar** - Monthly calendar view showing focused vs. fragmented days, total hours, flagged days
- **Project detail view** - Per-project analysis: total time, billable/non-billable split, EHR, task breakdown
- **Non-Billable Reason tracking** - Categorize why time was non-billable (admin, sales, learning, etc.)
- **Manual entry creation** - Add entries without processing from plain text

### Explicitly Rejected
- **Annual financial goal tracking** - Creates pressure and anxiety, conflicts with non-judgmental philosophy
- **Process-specific task types** (Sitemapping, Discovery, etc.) - Leads to category creep over time
- **Profitability thresholds** (Profitable/Not Profitable labels) - Just show the EHR number, interpret it yourself
- **Per-project phases** (like TallyHo) - Too rigid, have to recreate for each project
- **Live EHR tracking** - Only show for archived projects (retrospective, not real-time pressure)
- **Paused project status** - Just Active or Archived. "Paused" is just Active you're not working on.

---

## Design Principles

- **Capture first, categorize later** - Never interrupt the flow of work
- **Minimal friction above all** - If it slows down entry, it's wrong
- **Descriptive, not evaluative** - "You worked on 4 projects today" not "Good productivity!"
- **If it creates pressure, it doesn't belong** - No goals, no scores, no "are you on track?"
- **If you don't use it weekly, don't build it** - Stop building for theoretical future needs
- **"What decisions does this enable?" is the filter** — The pattern keeps repeating: build something, realize it's doing too much, strip it back, and the simpler version is always better. If the answer to "what decisions does someone make when looking at this?" is none, the data doesn't belong there.

See `design-philosophy.md` for the full philosophy document.

---

## Technical Reference

- **WordPress Plugin** - Standard WP admin screens, hooks, and conventions
- **Vanilla JS + CSS** - No frameworks, no preprocessors
- **PHP with static classes** - Each domain area (Entries, Clients, Projects, Tags, Aliases, etc.) is a static class; all public logic is procedural-style `Class::method()` calls; no instantiation or inheritance
- **Rate snapshots** - Billable rate/amount locked at verification time for historical accuracy
- **Transient caching** - Clients, projects, and aliases cached for performance
- **Shared rate helper** - `pltt_resolve_billable_rate()` in `helpers.php` is the canonical billable rate resolver; use it everywhere instead of inline rate logic (project rate → client rate → default → 0)
- **Bulk loaders** - Prefer `get_multiple($ids)` and `PLTT_Projects::get_for_clients($client_ids)` over per-record queries in any loop

See `development-notes.md` for full development preferences and WordPress patterns.

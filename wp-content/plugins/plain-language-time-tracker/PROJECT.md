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
Use hashtags anywhere in descriptions (`#dev #wordpress #urgent`). Tags are extracted automatically during parsing. Searchable and filterable. Tags are optional metadata, not part of the prediction system.

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
- **Clients and Project** - The model shouldn't have a delete button unless there is nothing assigned to it, and Clients should be archivable.
- **Tag Select Dropdown** - The add new tag button should be moved out of the list items, that way its under the list but is static like the search filed. This could use a little style updating to provide a little spacing and division to make it look better. More importantly this is a pattern that we should turn into a component and used elsewhere like in the Clients and Projects dropdown (without the checkbox), and in reports for multi select filtering.
- **Block/Stop Words** Expand the list of block and stop words.
- **Local Storage** - It might be nice to explore using local storage to save the journal in addition to being saved tot he DB so it could be possible work offline.
- **Project Billing** - Projects have a billing type field that defaults to hourly, with options for fixed budget and recurring — care plans default to monthly recurring. A separate default billability field on the project automatically sets whether entries are billable or non-billable on creation, with the entry-level flag still available for exceptions. Both patterns follow the standard cascade approach used across all major time tracking tools.
- **Log History View** - I am thinking we should breakup and group the log history table by week like we do in reports.
- **Log Flag** - Since this is a tool thats a combination of interstitial journaling/logging and time tracking it might be nice to have a flag feature for a log. Just a way of quickly identifying and filtering a log you want to return to maybe because it has an important note or something you want to followup on. Easy to turn on and off like the billable feature.
- **Reports Time Card** - Change the time card to show hours and minutes by default, maybe with a hint text with the decimal numbers. Decimals are great for math but its easier for me to read just straight forward time.
- **Client Report Cards** - On the clients list page i am thinking how we could add metrics that would be valuable. Since I tend to track meetings and emails I could have a card that lets me know which clients I haven't had contact with recently, that way I can make sure to get in touch and see how things are going. I don't really have a place in any of my tools to do that right now. If we add filtering to that page in the future we could show metrics like past project, open projects, value.
- **Alerts** - When we implement tracking retainer projects it would be nice to implement an alert notice options, to have a noice set in the admin and maybe in the future an email when a certain performance is reached. That way I can respond proactively to client hours.
- **Daily Log** - Maybe have a presistant message about when it was last saved? This  may not all that helpful but sometimes I notice it saves so quickly i'm not sure its saved. Or maybe some sort of status that its saving is running.


## Ideas & Wishlist

These are backburnered. Only build if a genuine need emerges from actual use.

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

See `design-philosophy.md` for the full philosophy document.

---

## Technical Reference

- **WordPress Plugin** - Standard WP admin screens, hooks, and conventions
- **Vanilla JS + CSS** - No frameworks, no preprocessors
- **Procedural PHP** - No classes (WordPress coding standards)
- **Rate snapshots** - Billable rate/amount locked at verification time for historical accuracy
- **Transient caching** - Clients, projects, and aliases cached for performance

See `development-notes.md` for full development preferences and WordPress patterns.

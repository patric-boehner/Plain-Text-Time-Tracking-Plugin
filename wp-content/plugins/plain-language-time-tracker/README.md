# Plain Language Time Tracker

**Stop interrupting your work to fill out time tracking forms.**

A WordPress plugin that reduces monthly time entry from 1-2 hours of catch-up to 2-3 minutes daily—a 95% time reduction.

---

## The Problem

Traditional time tracking forces you to:
- Stop work to categorize tasks in real-time
- Remember what you did at the end of the day/week/month
- Spend hours reconstructing your work from memory

This plugin flips that model: **capture first, categorize later.**

---

## How It Works

**Throughout the day:** Jot notes with timestamps

```
@9:15am - Kickoff meeting with ABC Corp about website redesign
@10:30am - Started wireframes for homepage
@12:00pm - Lunch
@1:00pm - Continued wireframes - product pages
@2:50pm - Client call - approved mockups #client-communication
@4:20pm - done
```

**End of day:** Click "Process Notes" → Review predictions → Save

The plugin:
- Calculates durations automatically (9:15am–10:30am = 1.25 hours)
- Detects "ABC Corp" as a client alias
- Predicts the project based on your history
- Extracts any hashtags as tags
- Shows confidence scores for predictions

---

## Key Features

### Capture Without Friction
- **Plain text entry** - Type naturally, no forms during work
- **@ shortcut** - Press `@` to insert current timestamp
- **Multiple formats** - `@2:50pm`, `@14:50`, time ranges, end markers
- **Auto-saving** - Notes persist across browser sessions

### Smart Processing
- **One-click parsing** - Convert notes to structured entries instantly
- **Duration calculation** - Automatically figures out how long each task took
- **Alias learning** - Detects acronyms and capitalized words, builds client/project mappings
- **Contextual prediction** - Suggests projects based on recent activity (last 30 days)
- **Confidence scoring** - Shows how certain the system is about predictions

### Financial Tracking
- **Three-tier rates** - Project rate → Client rate → System default ($100/hr)
- **Rate snapshots** - Locks rates at verification time for historical accuracy
- **Project types** - Hourly, Retainer (recurring allocation), Fixed Fee (budget), and Internal
- **Honest billable flag** - "Billable" means *generates invoiceable dollars from time × rate*; retainer-within-allocation, fixed-fee, and internal work default to non-billable
- **Retainer & budget tracking** - Allocation/budget bars with amber over-allocation treatment
- **Billable hours & amounts** - Automatic calculations in reports, with effective-hourly-rate (EHR)

### Organization & Reporting
- **Client & Project management** - Two-column admin screen with inline editing
- **Project lifecycle** - Active (appears in predictions) vs Archived (reporting only)
- **Project Detail page** - Per-project Report + Settings tabs, stat cards, "where the time went" bars, and a CSS swimlane timeline
- **Tag system** - Use #hashtags anywhere in descriptions; tags can be organized into groups
- **Flexible filtering** - Reports by date range, client, project, tags, billable status
- **Charts** - Hours-by-day volume chart with billable/non-billable/internal encoding
- **Log history** - Chronological archive of all daily logs

### Dual-Purpose Tool
- **Time tracking + journaling** - Freeform notes preserved alongside timestamped entries
- **Interstitial journaling** - Process thoughts between tasks without tracking
- **Historical reference** - Review old logs via calendar navigation

---

## Screenshots

_Screenshots coming soon - plugin is functionally complete and in active use_

---

## Installation

### From GitHub
1. Download the latest release from [Releases](https://github.com/patrickb/plain-language-time-tracker/releases)
2. Upload to `/wp-content/plugins/`
3. Activate through the WordPress admin
4. Find "Time Tracker" in your admin menu

### From Source
```bash
git clone https://github.com/patrickb/plain-language-time-tracker.git
cd plain-language-time-tracker
# No build step required - pure vanilla PHP/JS/CSS
```

---

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Modern browser (for admin interface)

**No dependencies, no build process, no frameworks.**

---

## Usage Guide

### 1. Daily Capture
Navigate to **Time Tracker → Daily Log**

- Press `@` to insert current timestamp
- Write what you're working on in plain language
- Notes auto-save every 1.5 seconds
- Non-timestamped text is preserved as journal notes

### 2. Process & Review
Click **Process Notes** button

- System parses timestamps and calculates durations
- Client/project predictions appear in dropdowns
- Confidence scores show prediction certainty
- Verify/correct assignments as needed
- Save entries to database

### 3. Manage Clients & Projects
**Time Tracker → Clients & Projects**

- Add clients with optional hourly rates
- Create projects under clients (Hourly, Retainer, Fixed Fee, or Internal)
- Archive completed projects (excluded from predictions)
- System learns aliases from your corrections
- Open a project to its detail page for per-project reporting and settings

### 4. View Reports
**Time Tracker → Reports**

- Filter by date range, client, project, tags, billable status
- Summary and detailed views, with an hours-by-day chart
- Summary cards: hours, billable hours/amount, and effective hourly rate
- Edit Tags, Billable, and Invoiced inline (saves immediately via AJAX)
- Export to CSV _(planned)_

---

## Understanding the Alias Learning System

One of the plugin's unique features is **automatic alias detection and learning**.

### How it works:
1. You write: "ABC Corp meeting about Q1 planning"
2. Plugin detects "ABC Corp" (capitalized words) and "ABC" (acronym)
3. You verify the entry → Plugin records "ABC Corp" = Acme Business Corp (Client)
4. Next time you write "ABC anything", it auto-predicts Acme Business Corp
5. Over time, confidence scores increase with repeated use

### What gets detected:
- **Acronyms** (2-5 uppercase letters): "XYZ", "ACME"
- **Capitalized phrases**: "Blue Sky Design", "Tech Innovations"
- **Case-sensitive**: "abc" won't trigger, "ABC" will

### Confidence scoring:
- **0.0–0.69**: Low confidence (yellow indicator)
- **0.70+**: High confidence (green indicator)
- Based on use count, recency, and consistency

---

## Supported Timestamp Formats

```
@2:50pm - Task description          (12-hour with AM/PM)
@14:50 - Task description           (24-hour format)
@2:50pm - 4:20pm - Task            (explicit time range)
@4:20pm - done                      (end marker - uses previous entry's start)
```

### Tag Usage

```
@9:00am - Sprint planning meeting #planning #dev #urgent
@10:00am - Code review for feature X #code-review #wordpress
```

Tags are automatically extracted and can be used for filtering in reports.

---

## Design Philosophy

This plugin is built around a core set of principles:

- **Capture first, categorize later** - Never interrupt the flow of work
- **Minimal friction above all** - If it slows down entry, it's wrong
- **Descriptive, not evaluative** - No productivity scores or pressure
- **Dual-purpose by design** - Time tracking + interstitial journaling

Read the full philosophy document: [design-philosophy.md](design-philosophy.md)

---

## Technical Details

- **Pure vanilla stack** - No frameworks, no build process
- **Procedural PHP** - WordPress coding standards, no classes
- **Vanilla JavaScript** - No jQuery, no React (except WordPress native components)
- **Plain CSS** - Separate files, no preprocessors
- **Transient caching** - Clients, projects, aliases cached for performance
- **Rate snapshots** - Historical accuracy preserved even if rates change

### Database Schema:
- `pltt_clients` - Client information, default rates, internal flag
- `pltt_projects` - Projects with status (active/archived), rates, recurring period, and budget (hours/fee)
- `pltt_time_entries` - Time entries with snapshots of billable rates
- `pltt_aliases` - Learned alias mappings with confidence scores
- `pltt_daily_logs` - Raw daily logs for history/reference
- `pltt_tags` / `pltt_entry_tags` - Tags (optionally grouped) and their entry associations

See [development-notes.md](development-notes.md) for development preferences and patterns.

For detailed feature documentation and data model: [PROJECT.md](PROJECT.md)

---

## Status

**Current Version:** 1.9.27 (DB Version: 1.9.5)

The plugin is **functionally complete** for its core use case and in active daily use. All planned features are implemented and working.

### Backburnered Ideas
Features that might be added if genuine need emerges from actual use:

- CSV export for billing/spreadsheet integration
- Calendar view showing patterns and gaps
- Bulk entry updates across multiple entries
- Advanced filtering options (negative filters, etc.)

See [PROJECT.md](PROJECT.md) for full feature list and wishlist.

**Philosophy:** _"If you don't use it weekly, don't build it"_

---

## Contributing

This is a personal project built to solve a specific workflow problem. That said:

- **Bug reports** are welcome - open an issue with details
- **Feature requests** will be evaluated against the design philosophy
- **Pull requests** should align with vanilla stack and minimal friction principles

Please read [design-philosophy.md](design-philosophy.md) before proposing features.

---

## License

GPL v2 or later - [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Credits

Built by [Patrick Boehner](https://github.com/patrickb)

Inspired by frustration with traditional time tracking tools and the interstitial journaling workflow.

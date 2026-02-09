# Plain Language Time Tracker - Features

## Implemented

### Plain Text Note Input
Type natural language notes throughout the day. Press `@` to auto-insert the current timestamp. No forms, no dropdowns — just a text area that works like a notepad.

### Smart Time Parsing
Parses timestamps in multiple formats: `@2:50pm`, `@14:50`, `@2:50pm - 4:20pm` (explicit range). Calculates durations between consecutive entries automatically. Supports end markers like "done", "finished", and "end" to close out a task.

### Review & Verify Workflow
After processing daily notes, entries appear in a structured review table with time ranges, calculated durations, client/project dropdowns, and tag inputs. Confidence indicators show how sure the system is about its predictions. Date and time fields use a WordPress-native Edit/Update/Cancel inline editing pattern — click "Edit" to reveal inputs, "Update" to apply, or "Cancel" (or Escape) to revert. Save all entries at once when everything looks right.

### Client & Project Management
Manage clients and projects from a dedicated admin screen. Two-column layout with client list and their associated projects. Add, edit, or delete clients and projects. Create new clients or projects on the fly during entry review without leaving the screen.

### Alias Learning System
Automatically detects acronyms (2-5 uppercase letters like "DOR") and capitalized words (like "Rossmoor") in your notes. Builds a mapping table linking aliases to clients and projects. Tracks confidence scores (0-1), use counts, and last-used dates. When you correct a wrong prediction, the system updates its mappings and learns from corrections over time.

### Contextual Project Prediction
When a client is identified, the system looks at the last 30 days of work for that client and suggests the most recently used active project. Archived projects are excluded from predictions, keeping suggestions relevant to current work.

### Project Lifecycle
Projects can be marked as active or archived. Active projects appear in prediction suggestions. Archived projects are excluded from predictions but preserved for historical reporting. When editing a time entry that references an archived project, the project still appears in the dropdown with an "(Archived)" label so users can see what was originally assigned.

### Tag System
Use hashtags anywhere in descriptions (`#dev #wordpress #urgent`). Tags are extracted automatically during parsing and are searchable and filterable in reports. Tags are metadata only — they don't influence the prediction system.

### Reports
View time entries with date range filtering in detailed or summary views. Summary cards show total hours, entry counts, and other statistics. Detailed view supports pagination (50 entries per page).

### Daily Log Auto-Saving
Notes are automatically saved as you type. Persistent across sessions so you never lose your work.

### Log History / Archive
Browse all daily logs chronologically. View the raw plain text notes from any past day. See which days have logs recorded. Delete entire daily logs (and their associated time entries) when needed.

### Inline Entry Editing
Edit individual time entries directly from the reports list. Click the edit icon on any entry to open a modal where you can update the date, description, times, duration, client, project, tags, or billing status — without navigating away from the report view.

### Billable Rates
Store hourly rates per client or per project. Automatically calculate billable totals in reports. Would enable the plugin to serve as a lightweight invoicing reference.

---

## Planned

### Archived Project Visual Indicators
Visually distinguish time entries attached to archived projects on the review screen — such as a subtle row highlight or badge — so users can quickly spot entries tied to projects that are no longer active.

### CSV Export
Export time entry data to CSV format for use in spreadsheets, invoicing tools, or external reporting systems.

### Calendar View
Visual calendar-based view of logged time, making it easy to spot patterns, gaps, and workload distribution across days and weeks.

### Settings & Preferences
Expanded settings page for configuring plugin behavior — time format preferences, default views, prediction thresholds, and other user preferences.

---

## Ideas / Wishlist

### Gap Detection
Identify and flag large gaps between time entries during review. Could prompt the user to account for the missing time or allow marking gaps as breaks/lunch.

### Overlapping Entry Handling
Detect when two entries have overlapping time ranges and either flag them for correction or allow them (for multitasking scenarios).

### Multi-Day Task Support
Handle tasks that span across multiple days — starting on one day and ending on the next. Would require changes to parsing and entry storage.

### Entry Auditing
Flag entries that look unusual — excessively long durations, unprocessed daily logs that haven't been reviewed, or entries sitting without client/project assignments. Helps catch mistakes and keep data clean.

### Billable vs Non-Billable Reporting
Track and report the percentage of time spent on billable vs non-billable work. Would add a billable flag to entries (or inherit from client/project settings) and show the split in reports as a percentage breakdown.

### Time Utilization / Capacity Tracking
See how much of your available time is being used and how much is free. Define your working hours or weekly capacity, then compare against logged time to understand your actual availability and workload.

### Service Time Allotments
Some clients have recurring service agreements with monthly hour allotments. Track how much time has been used against each allotment so you can see how many hours remain for the month. Could extend to project-level budgets as well — tracking whether a project is on pace, running low, or over budget.

### Invoice Tracking
Mark time entries as invoiced so they can be filtered out when assembling the next month's invoices. Could be as simple as a "Billed" tag applied in bulk, combined with negative filtering in reports (e.g., "Not tagged with: Billed") to show only entries that haven't be billed.

### Extended @ Shortcuts
Expand the `@` autocomplete beyond timestamps. `@now` would insert the current time (same as `@` but more explicit). `@clientname` would insert a known client name inline, giving the alias system a head start during parsing and making it easier to tag entries to the right client as you type.

### Bulk Entry Updates
Select multiple entries in the reports list and apply changes in bulk — reassign a client or project, add/remove tags, mark as billed, or delete. Useful for end-of-month cleanup or correcting a batch of entries at once.

### Advanced Report Filtering
Richer filtering options in reports beyond date range. Filter by client, project, tags, billing status, or search within descriptions. Support for negative filters (e.g., "Not tagged with: Billed") to easily find entries that match — or don't match — specific criteria.

### Reporting Visualizations
Charts and graphs for time data — bar charts for daily/weekly hours, pie charts for client distribution, trend lines for workload over time.

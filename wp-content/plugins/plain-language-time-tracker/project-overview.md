# Plain Language Time Tracker - Product Overview

## The Problem

Traditional time tracking tools require too much structure upfront. You need to:
- Stop what you're doing to categorize work
- Choose from dropdowns and forms
- Remember exact project names
- Break your flow to track time

This friction means people either:
- Don't track time at all
- Track inconsistently 
- Spend too much time on the tracking itself

## The Solution

A time tracker that works the way you naturally think about your day. Just jot down what you're doing and when, using plain text notes throughout the day. At the end of the day, the system helps you quickly categorize and structure those notes into proper time entries.

**Core Principle:** Capture first, categorize later.

## How It Works

### Step 1: Capture (Throughout the day)

You maintain a simple text log of your activities with timestamps:

```
@9:15am - Email catchup
@10:30am - DOR website updates
@12:00pm - done
@1:30pm - Coastal Alliance meeting #dev #planning
@3:00pm - Proposal for new client
@4:45pm
```

**Key features:**
- Type `@` to auto-insert current timestamp
- Use natural language descriptions
- Optional hashtags for categories
- No need to stop and think about structure
- Works like a notepad/journal

### Step 2: Process (End of day)

Click a button to process your notes. The system:

1. **Parses** your text into individual time entries
2. **Calculates durations** between timestamps
3. **Detects clients** from your descriptions (recognizes abbreviations)
4. **Suggests projects** based on recent work patterns
5. **Extracts tags** from hashtags

### Step 3: Review & Verify (Quick cleanup)

You see a structured view:

```
Entry                    Duration  Client                  Project          Tags
─────────────────────────────────────────────────────────────────────────────────
Email catchup            1.25h     [Admin ▼]              [General ▼]      +tag
DOR website updates      1.5h      Democrats of Rossmoor  [Website ▼]      +tag
Coastal Alliance meeting 1.5h      Coastal Alliance       [?Suggest?]      #dev #planning
```

- Quickly scan for wrong predictions
- Use autocomplete dropdowns to correct
- Add new clients/projects on the fly
- Click "Save All" when done

### Step 4: Learn (Automatic)

The system gets smarter over time:
- Remembers that "DOR" means "Democrats of Rossmoor"
- Learns project patterns (last 30 days of work)
- Reduces manual corrections over time

## Core Features

### 1. Smart Time Parsing

Handles multiple formats:
- `@2:50pm - Task description`
- `@14:50 - Task description` (24-hour)
- `@2:50pm - 4:20pm - Task` (explicit range)
- `@4:20pm - done` (end marker for previous task)

Automatically calculates durations:
- Sequential tasks: Next timestamp ends the previous task
- Explicit markers: "done", "finished", "end" close the current task
- Gaps are visible and can be reviewed

### 2. Alias Learning System

**Automatic detection:**
- Extracts acronyms (2-5 uppercase letters): "DOR", "PMPro"
- Identifies capitalized words: "Rossmoor", "Coastal"
- Builds a mapping table: `alias → client/project`

**Confidence scoring:**
- Tracks how often each alias is used correctly
- Higher confidence = more likely to auto-select
- Low confidence = still suggests but flags for review

**User corrections:**
- When you fix a wrong prediction, it updates the mapping
- System learns which aliases are most reliable
- Makes it easy to add or edit clients or projects without having to navigate to another screen.

### 3. Contextual Project Prediction

When a client is identified, the system:
1. Looks at last 30 days of work for that client
2. Suggests the most recently used ACTIVE project
3. Ignores archived/completed projects

This means if you're currently working on "Website Redesign" for a client, that's what gets suggested - not their old "Logo Design" project from 6 months ago.

### 4. Project Lifecycle

Projects can be marked as:
- **Active** - Currently being worked on, appears in predictions
- **Archived** - Completed, excluded from predictions but retained for reporting

This keeps the prediction system focused on current work while preserving historical data.

### 5. Tag System

Use hashtags anywhere in descriptions: `#dev #wordpress #urgent`

Tags are:
- Extracted automatically during parsing
- Used for additional categorization
- Searchable/filterable in reports
- NOT part of the prediction system (optional metadata)

## Data Model

### Entities

**Clients**
- Name
- Aliases (comma-separated shortcuts)
- Description/notes

**Projects**
- Name
- Associated client
- Status (active/archived)
- Description/notes

**Time Entries**
- Date
- Start time
- End time
- Duration (minutes)
- Raw text (original note)
- Description (cleaned)
- Client (FK)
- Project (FK)
- Verified (boolean)
- Tags

**Aliases** (learned mappings)
- Alias text
- Client (FK)
- Project (FK, optional)
- Confidence score (0-1)
- Use count
- Last used date

### Relationships

```
Client (1) ──┬── (many) Projects
             │
             └── (many) Time Entries

Project (1) ──── (many) Time Entries

Time Entry (many) ──── (many) Tags
```

## Reporting Needs

### Daily Summary
- Total hours worked
- Breakdown by client
- Breakdown by project
- List of all entries with times

### Monthly Summary
- Total hours for the month
- Days worked
- Client breakdown with totals
- Project breakdown with totals
- Daily chart/visualization

### Project Report
- Total time on project (all time or date range)
- Client associated
- Breakdown by day
- List of all entries

### Client Report
- Total time for client (all time or date range)
- Breakdown by project
- List of all entries

### Export/Integration (Future)
- CSV export
- Calendar view

## User Interface

### Screen 1: Daily Log (Default View)

```
┌─────────────────────────────────────────────────────┐
│ Plain Language Time Tracker                         │
│ Tuesday, January 29, 2024                           │
├─────────────────────────────────────────────────────┤
│                                                      │
│  [Large text area for notes]                        │
│  Type @ to insert timestamp                         │
│  Use #tags for categories                           │
│                                                      │
│  Auto-saving...                                     │
│                                                      │
│  [Process Today's Time] ───────────────────────→    │
│                                                      │
└─────────────────────────────────────────────────────┘
```

### Screen 2: Review & Verify

```
┌─────────────────────────────────────────────────────┐
│ Review Today's Time                                  │
│ Tuesday, January 29, 2024                           │
├─────────────────────────────────────────────────────┤
│                                                      │
│  [Table of parsed entries]                          │
│  - Time ranges + durations                          │
│  - Client dropdowns (with predictions)              │
│  - Project dropdowns (filtered by client)           │
│  - Tag inputs                                       │
│  - Confidence indicators                            │
│                                                      │
│  ← [Back to Notes]              [Save All] →        │
│                                                      │
└─────────────────────────────────────────────────────┘
```

### Screen 3: Reports

```
┌─────────────────────────────────────────────────────┐
│ Time Reports                                         │
├─────────────────────────────────────────────────────┤
│                                                      │
│  View: [Daily ▼] [Monthly] [Client] [Project]      │
│  Date Range: [Start Date] to [End Date]            │
│                                                      │
│  Summary Cards:                                     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐              │
│  │ 42.5 hrs│ │ 8 days  │ │ 5 clients│             │
│  └─────────┘ └─────────┘ └─────────┘              │
│                                                      │
│  [Detailed breakdown tables/charts]                │
│                                                      │
│  [Export CSV]                                        │
│                                                      │
└─────────────────────────────────────────────────────┘
```

## Technical Considerations

**Learning:**
- When user verifies/corrects an entry
- Extract potential aliases (acronyms, names)
- Save to aliases table with initial confidence
- Update confidence on subsequent uses

**WordPress Plugin** (as explored)
- Integrated with existing site
- Leverage WP admin UI
- Good for consultants/agencies

## Development Phases

### Phase 1: MVP (Minimum Viable Product)
- ✅ Plain text note input with @ autocomplete
- ✅ Basic time parsing (sequential entries)
- ✅ Manual client/project selection
- ✅ Daily summary report
- ✅ Local storage (single device)

### Phase 2: Intelligence
- ✅ Alias learning system
- ✅ Contextual predictions
- ✅ Confidence indicators
- ✅ Monthly/project reports

### Phase 3: Polish
- Archive projects
- Tag system
- Better reporting/visualization
- CSV export
- Settings/preferences

## Open Questions

1. **Gaps in time** - How should we handle large gaps between entries? Flag them? Assume breaks?

2. **Overlapping entries** - What if someone logs two tasks at overlapping times? Error or allow?

3. **Multi-day tasks** - How to handle work that spans multiple days?

4. **Editing history** - Should processed entries be editable later? Full history?

5. **Billable rates** - Store rates per client/project? Calculate totals?

6. **Recurring tasks** - Templates for common entry patterns?

7. **Reminders** - Notify user to log time if forgotten?

## Future Feature Ideas

1. **Daily Logs Archive** - Admin screen listing all daily logs chronologically. Would allow reviewing the raw plain text notes from any past day, seeing which days have logs, and potentially re-processing old entries.

---

## Summary

This is a **time tracking tool that meets you where you are**. It doesn't force rigid structure upfront. Instead, it lets you capture work naturally, then helps you organize it efficiently. Over time, it learns your patterns and requires less manual work.

The key innovation is the **two-phase capture model**: Quick notes during the day, structured categorization at day's end. Combined with smart learning, this creates a time tracker that's both easy to use and powerful enough for professional needs.
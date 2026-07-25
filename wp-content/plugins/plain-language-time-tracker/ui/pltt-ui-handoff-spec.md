# PLTT — UI handoff spec

**Status:** supersedes `pltt-consistency-system.html` entirely. That document contains several approaches that were rejected in later review — do not implement from it.

**Scope of this spec:** presentation only. No data model changes, no new calculations, no renamed database fields. Every value referenced here already exists.

---

## Reference mockups

Build against these, in this order of authority:

| File | Covers |
|---|---|
| `pltt-scope-block-reinstated.html` | Scope block, light header, the rule for which applies |
| `pltt-ordering-billing.html` | Vertical order, billing elements on filtered Entries, billing mode |
| `pltt-control-placement.html` | Title-row control grouping (§01 and Option A only — ignore Option B) |
| `pltt-panel-controls.html` | Panel-level controls, sorting, grouping |
| `pltt-row-actions.html` | Row action system — links, kebab menus, when a button is right |
| `pltt-charts.html` | Bar chart and meter, all states |
| `pltt-period-states.html` | Period states — open / unbilled / billed, bar and notice conditions |
| `pltt-figure-four.html` | Figure 4 — every state, stripped |
| `pltt-backlog-indicator.html` | Backlog count placement (dates shown are superseded by `pltt-figure-four.html`) |
| `pltt-frames-in-labels.html` | Period vs lifetime — frame stated in the label |
| `pltt-budget-figure.html` | Budget figure value and label, under and over |
| `pltt-billing-by-type.html` | Billing scope figures for hourly vs retainer (§03 fixed-budget block is moot — do not build) |
| `pltt-colour-layout.html` | Palette, layout changes, before/after comparisons |
| `pltt-visual-style.html` | Type scale, mono/sans rule, neutrals |
| `pltt-today-states.html` | Today's three states, status column values |
| `pltt-editing-and-day.html` | Row menu, day navigation (ignore any joined-table rendering — day cards stay separate, §2) |
| `pltt-history.html` | History list (calendar section is **not** being built) |

Where two mockups disagree, the higher row wins.

**All mockups render in IBM Plex.** That is demonstration only — substitute WordPress's font stacks per §1. Sizes, weights, and the mono/sans split all carry over; only the typeface changes.

---

## Explicitly rejected — do not build

These appear in earlier artifacts. All were reviewed and turned down.

- **Renamed vocabulary.** Keep Total Hours, Billable Hours, Billable Amount, Internal, Effective Rate. Do not rename to "Time logged", "Own time", "Earned", "Rate achieved".
- **Basis lines on every figure everywhere.** Scoped — see §3.
- **A separate "Bill" screen.** Billing happens in Entries. Billing page remains an index.
- **One merged billing column with five states.** Three columns stay.
- **Filter chips.** Existing dropdowns stay.
- **A redesigned date control.** Existing control stays — see §8.
- **Removing charts.** They stay.
- **Calendar view for History.** Deferred indefinitely.
- **"Waiting" as a billing state.** Not a real state.
- **Absorption shown on entry rows.** Record-level only.
- **Collapsing or locking the Today log.** No read-only mode, no expand/edit toggle, no reduced height. The log stays an ordinary editable textarea in every state.
- **Webfonts.** No IBM Plex, no Google Fonts. WordPress's own stacks only — see §1.
- **Date control in the filter bar.** Title row only — see §3a. `pltt-control-placement.html` Option B shows this; it was reviewed and turned down.

Note: several mockups show the view toggle clustered with the date control at the right of the title row. That grouping is **superseded** — the toggle moves left against the title per §3a.

- **Consolidating entries into one table with day bands.** Keep separate per-day cards — see §2. Too dense when joined.
- **WordPress hover row actions.** Replaced by an always-visible kebab — see §6. Native, but invisible until hover, shifts the row, and unusable on touch.
- **Trailing "View record" / "Open" columns.** The row's identifier is the link — see §6.
- **Any billing treatment for fixed-fee projects.** They never enter billing at all — see §3c.
- **Lifetime-framed billing figures on project detail.** `pltt-billing-state-lifetime.html` — superseded.
- **A stacked out-of-range notice bar.** `pltt-out-of-range-notice.html` — superseded. The backlog is one line on figure 4 (§3).
- **`THIS PERIOD` / `WHOLE PROJECT` frame lines and frame tints.** `pltt-period-vs-lifetime.html` — superseded. The frame goes in the label (§3).

---

## 1. Design tokens

Build first — everything else depends on these.

```
--paper:    #ffffff
--canvas:   #f4f3f1
--sunk:     #faf9f7
--rule:     #e4e2de
--rule-2:   #efedea
--ink:      #1a1c1e
--ink-2:    #55595e
--ink-3:    #8a8d91
--blue:     #2271b1   /* unchanged, WP action colour */

--c-hourly:  #146b42   --c-hourly-bg:  #e0f0e7
--c-monthly: #175d87   --c-monthly-bg: #e0edf7
--c-fixed:   #5540a5   --c-fixed-bg:   #eae5f9
--c-own:     #666360   --c-own-bg:     #eeedea
--c-over:    #9a6a10   --c-over-bg:    #fbefd8
--c-tag:     #1d5f8a   --c-tag-bg:     #e4eef7
```

Rules:
- **Nothing is red anywhere in the app.** Over-budget, over-allocation, and unbilled all use `--c-over`. This is deliberate: an overrun is a fact, not an error.
- The four billing-model colours sit at equal visual weight. None is styled as better or worse.
- Colour never carries meaning alone — always paired with a label.

### Type

**No webfonts. Use WordPress's existing stacks.**

```
/* UI — WP admin default */
--font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
             "Helvetica Neue", sans-serif;

/* Measured values — WP's own monospace stack */
--font-mono: Menlo, Consolas, monaco, monospace;
```

Two stacks, one rule:

- **Mono** — what the clock measured or you typed. Times, durations, dates, money, record numbers, the raw daily log. Always `font-variant-numeric: tabular-nums`.
- **Sans** — what the app inferred or you named. Descriptions, client and project names, tags, labels, interface text.

The mockups use IBM Plex to demonstrate the distinction. **Do not load Plex** — substitute the stacks above. The rule is which family, not which typeface; system fonts carry it adequately.

Because the system sans is less tightly set than Plex, reduce negative letter-spacing on large text by roughly half (e.g. page title `-0.25px` rather than `-0.5px`) and re-check optical sizes at 26px.

Scale:

| Use | Size / weight |
|---|---|
| Page title | 26px / 450 / -0.5px |
| Scope block title | 24px / 600 / -0.45px |
| Large figure (mono) | 26–27px / 450 / -0.9px |
| Section heading | 18px / 600 |
| Panel heading | 14px / 600 |
| Body, table cells | 14px / 400 |
| Mono inline | 13.5px / 400 |
| Secondary line | 12.5px / --ink-3 |
| Column header | 11.5px / 450 / 0.5px / uppercase / --ink-3 |

### Spacing

- Table cell padding: `15px 20px` (up from ~10px)
- Rules lighten to `--rule-2` inside tables, `--rule` for structural boundaries
- Panel radius 6px, control radius 4px

---

## 2. Layout changes

Applies app-wide. See `pltt-colour-layout.html` §02–04 for before/after.

1. **Joined stat strip.** The four separate stat cards become one bordered container with hairline dividers between figures. Same figures, same labels, same qualifier lines — including the period-over-period comparisons. Only change: unbilled figure moves off red onto `--c-over`.

2. **Keep separate day cards — do not consolidate into one table.** Each day stays its own bordered card with its own header. The single-table-with-day-bands approach shown in some mockups is **rejected**: it aligns columns across days but reads too dense. Separated cards were nearly as aligned once the day total and removed edit button tightened them, and the breathing room between days matters more than cross-day column alignment on a screen you read within a day, not down a column.

   If cross-day alignment is ever wanted, set explicit column widths so separate tables still line up — same effect, no density cost. Not needed now.

3. **Day card header contents.** Date (linked — see §6), weekday, and day totals on the right. **The Edit button is removed** and does not return with the separated layout — row editing is the kebab menu (§6).

4. **Page header block.** Title, model badge, and subline as one unit; action buttons drop to small so they stop competing with the page name. Dates in sublines are mono.

---

## 3. The scope block

Two treatments. **The rule is mechanical — do not decide case by case.**

### Full block — when the screen is a scope with agreed terms

Applies to: **project detail**, **Entries when filtered to a single client + project**, **bill flow**.

Three-line identity, then a figure row:

```
Line 1   Name + billing model badge
Line 2   Client · terms in plain language
Line 3   "Showing [literal dates] · [count] entries"   (tinted inset)
─────────────────────────────────────────────────────
Figure   Label / value / basis line   × 4
```

Terms line by model:
- Retainer — `Democrats of Rossmor · 3 hours included each month at $90/hr`
- Fixed fee — `Robin Mohr · $3,870 agreed, budgeted as 38h 42m at $100/hr`
- Hourly — `Daniel Mintie · $100/hr`

Basis lines are the point of this treatment. `$244.50` alone requires the reader to remember that retainers bill overage only; `Overage only · 2h 43m × $90/hr` does not.

Figure slots are parallel across models — a retainer's *Against the 3h included / 2h 43m over* and a fixed fee's *Against the 38h 42m budgeted / 57h 43m over* occupy the same position with the same phrasing.

### Light header — everything else

Applies to: **Today**, **unfiltered Entries**, **Projects**, **Clients**, **Billing**, **History**.

Same three lines, no panel, no figures inside, no basis lines. Line 2 carries whatever is true about the scope instead of terms (e.g. Today: `7 entries recorded · 6h 00m logged`).

### Period figures vs lifetime figures

See `pltt-frames-in-labels.html`. **Supersedes the frame-label approach in `pltt-period-vs-lifetime.html`** — no `THIS PERIOD` / `WHOLE PROJECT` line, no frame tint. Those were designed when four figures mixed frames; only fixed budget does now, and only in one figure.

**The rule, unchanged:** never divide across frames. A fixed-fee budget is a lifetime quantity; the date filter selects a period. Filtered to June, `21h 51m ÷ 38h 53m budgeted = 56%` describes nothing — the real figure is 84%.

**How it's communicated:** the label names the span, in ordinary words.

| Label | Frame |
|---|---|
| `Hours in June` | the filtered period |
| `Budget left` / `Budget overrun` | lifetime |
| `Average per month` | per allocation period |
| `Total hours so far` | open period |
| `Total hours`, `Budget used` | frames agree — no qualifier |

Labels simplify when the frames agree: filter = whole project, or filter = one month on a monthly retainer.

**Budget figure — see `pltt-budget-figure.html`.** The value is the actionable number, not the percentage:

| | Under budget | Over budget |
|---|---|---|
| Label | `Budget left` | `Budget overrun` |
| Value | `47h 34m` | `57h 43m`, amber |
| Basis | `28h 26m of 76h used · 37%` | `96h 25m of 38h 42m used · 249%` |

The percentage stays on the basis line after the ratio, separated by `·` — free to include, and it serves people who scan percents without taking the value slot.

**Omit zero minutes.** `76h`, not `76h 00m`. Budgets set in hours are round; budgets derived from fee ÷ rate are not (`$3,500 ÷ $90 = 38h 53m`) — one rule covers both. Column alignment is handled by tabular figures, not by padding.

`84%` as a *value* pushes everything concrete into the basis line where two mono figures compete for attention. "Left" and "overrun" also carry the lifetime frame on their own — neither can mean a single month.

**The meter is the one place both frames appear together.** It reads lifetime, says so in its header (`Budget used · to date`), and marks where the selected period ends as a tick on the track. Two marks, never a division.

**Never sum a recurring allocation.** Five months at 3h is not a 15h budget. Report the average per month against the monthly allocation, plus how many months were over.

### Fixed budget — effective rate is conditional

Effective rate appears **only when the project is archived**. Mid-project it falls monotonically from an absurd high — two hours into a $3,870 project it reads $1,935/hr — so it flatters early, accuses late, and is only true at the moment work stops. This matches the existing decision in `PROJECT.md` (*Live EHR tracking — only show for archived projects*).

- **Active fixed-fee** — three figures: total hours (this period), budget used (whole project), fixed fee.
- **Archived fixed-fee** — effective rate joins as the fourth.

### Billing figures follow the filter; a backlog shows as one line

See `pltt-backlog-indicator.html`. **Supersedes both `pltt-billing-state-lifetime.html` and the stacked-bar notice in `pltt-out-of-range-notice.html` — build neither.**

Retainers are normally billed month to month, so figures stay period-scoped. When earlier unbilled work exists, it appears as **a count and a link on figure 4's basis line** — not as a second bar, and not as a lifetime figure.

`No bill for June · 4 more unbilled ›`

- **Count and link only.** No total, no age. The link shows those.
- **Appended to whatever the basis line already says**, separated by `·`.
- **Faint warm tint on that cell** (`#fdfaf3`) as the only other signal.
- **Independent of the period's own state** — appears on open, unbilled, and billed periods alike.
- **Links to Billing, Ready tab, pre-filtered** to this client and project.
- Hourly wording: `N unbilled entries outside this range ›`.

**Retainer, project detail — four period-scoped figures:**

| | |
|---|---|
| 1 | Total hours — % against allocation on basis |
| 2 | Over allocation — the chargeable portion |
| 3 | Billable amount — overage only |
| 4 | Not yet billed / Billed — plus backlog count if any |

`Billed to date` stays hourly-only. On a retainer billed monthly it's a lifetime revenue total, not a working figure.

**No second bar.** One action bar per screen, maximum.

### Period states — what appears when

See `pltt-period-states.html`. **Correction: figure 4 does not carry a `Review & bill` link.** The bar carries that action. The block holds information, the bar holds action (§3c).

| Period | Label | Value | Basis | Ready bar |
|---|---|---|---|---|
| **Open** | Not yet billed | `—` | *empty* | Absent |
| **Closed, unbilled** | Not yet billed | amount, amber | *empty* | Present, with button |
| **Closed, billed** | Billed | amount, grey | `Record #21 ›` | Absent |
| **Closed, nothing over** | Not yet billed | `$0.00`, grey | *empty* | Absent |

**Figure 4's basis line holds only links.** No close date, no bill date — the when-line already says `still open`, and the bill date is on the record you're one click from. Three of four states therefore have no basis line at all; label, value, and colour carry the meaning.

This is narrower than the basis rule on figures 1–3, which explain a calculation. Figure 4 has no calculation to explain.

Backlog count appends to whatever the basis line holds, in any of the four states.

**Open period** additionally:
- When-line reads `still open`
- Figure labels take "so far" — *Total hours so far*, *Billable so far*
- Figure 4 is a **dash, not a zero**, with no basis line. `$0.00` claims nothing is owed; a dash says the question isn't answerable yet.
- No ready bar until the period closes. A bar that's present most of the time you visit trains you to ignore it. Structurally, a retainer period is also the billing unit — billing mid-period means either a second record or a partial one, breaking the one-record-per-period assumption the model rests on.

**Period boundaries come from the project.** The retainer project type carries its own definition of when a period ends. Every "period" behaviour reads that, never the calendar: the ready bar's trigger, the `still open` state, the backlog count, and the period stepper on project detail. A project on a non-calendar cycle must not have one of these fall back to month-end.

**Two invariants:**
- The backlog indicator is independent of the period's own state — a billed month can still have one behind it.
- `Review & bill` never appears more than once on a screen. If the bar has it, no figure links to it.

### Entries switches treatment based on filter state
Filtered to one client + project → full block, terms stated, figures with basis.
Any other filter state → light header + existing stat strip.

---

## 3a. Vertical order

See `pltt-ordering-billing.html` §01. Same on every screen that filters.

| | Element | Note |
|---|---|---|
| 1 | **Title row** | Page name, then view toggle immediately beside it. Date control and page-level actions grouped right. |
| 2 | **Filters** | Labelled dropdowns, apply on selection. Omitted where there are none. |
| 3 | **Scope block** *or* light header + stat strip | The result of 1 and 2, so always below them. |
| 4 | **Action bar** | Only when there's something to do with this scope. |
| 5 | **Charts** | Where they exist today. |
| 6 | **Table** | |

### Control grouping in the title row

Group by kind, not by edge:

- **Left, against the title** — view toggle (Summary / Entries, Full / By month). This is navigation: it changes which page you're on and survives every filter change. Sitting it against the title makes it read as a tab pair belonging to the page, which is also WP convention.
- **Right** — date control, then page-level actions (Settings, Back, Add…).

Do not cluster the view toggle with the date control. They are different classes of control and grouping them implies they do the same kind of work.

### Date control placement — decided

The date control sits in the **title row**, not in the filter bar, on every screen.

It is conceptually a filter, and this separates it from the other filters. That's a known and accepted tradeoff. The reason: screens like Today and project detail have a period but no filters, and putting the date in a filter bar would give those screens a bar containing a single control (~70px of chrome for a date stepper) and make the page body start at a different height depending on the screen. Consistent position beats consistent category.

Consequence: `Clear filters` clears the dropdowns only, never the period. Label it `Clear filters`, not `Clear all`.

### Why controls precede the block

The block's third line — `Showing Feb 17 – May 19, 2026` — is the readout of the date control, so the control must come first. **Never place filters between the block's identity lines and its figures**; that splits one object into two panels that read as unrelated.

---

## 3b. Panel-level controls

See `pltt-panel-controls.html`. Charts with view options, tables with sort and group.

**Rule: a control lives where its effect ends.** Changes everything below it → page level. Changes one panel → that panel's header, right-aligned.

**The test when unclear:** operate the control and watch the stat figures. If they change, it's page level. If only the panel redraws, it's panel level. A chart switching day/week/month shows the same data at different granularity — figures don't move, so panel level. A control that changes *which period* the page covers moves the figures, so it's page level regardless of where it currently sits.

### Three tiers

| Tier | Where | What |
|---|---|---|
| Page · navigation | Title row, left, against the title | View toggle (Summary/Entries, Report/Settings) |
| Page · scope | Title row right, and the filter bar | Date control, filter dropdowns |
| **Panel** | Right end of the panel header | Chart granularity, group-by, panel-specific view options |

### Panel control styling

Deliberately lighter than page-level, so the visual weight signals reach before you click:

| | Page level | Panel level |
|---|---|---|
| Size | 13.5px, 6–7px padding | 12.5px, 3px padding |
| Active state | Solid `--ink` fill, white text | `--sunk` fill, ink text, 2px blue underline |
| Resting colour | `--ink-2` | `--ink-3` |
| Label | Standalone | Prefixed — `Group by`, `Show` |

Panel controls do **not** persist across navigation. Page-level scope (filters, period) should.

### Sorting vs grouping — different mechanisms

**Sorting** is a property of a column → clickable column header with a caret. WP-native. Muted caret on sortable columns; the active column darkens, its caret turns blue and indicates direction, and it is the only column showing direction. Not every column needs to be sortable — only ones you'd plausibly order by.

**Grouping** restructures the table → panel-header dropdown (`Group by · Billing model`). With grouping off, bands disappear and the table is flat; sorting then applies across the whole table. With grouping on, sorting applies within each band.

**Group bands** carry name, count, and total — same component as the day-card headers in Entries (§2.3).

### One thing to check in the code

Project detail currently has a **Full / By month** toggle above the chart at full weight. If it changes only the chart's granularity, move it into the chart's panel header at panel weight. If it changes the period the whole page covers, it isn't a chart control — fold it into the date control's presets. Verify which before placing it.

---

## 3c. Billing elements on filtered Entries

Currently unaccounted for in the mockups. See `pltt-ordering-billing.html` §02–03.

**Rule: the scope block holds information, the bar beneath holds action.** This is what stops the block growing buttons.

### Existing bill records → a figure

`Billed to date · $60.00 · 2 bill records · View records ›`

The link lives on the basis line. No panel — you're checking these exist, not acting on them.

### Ready to bill → a figure *and* a bar

Figure: `Ready to bill · $793.32 · 15 unbilled entries · oldest Feb 17`

Bar, attached directly to the bottom of the block with no gap, tinted `#f4faf6` / border `#cfe4d6`:

```
● Ready to bill — $793.32 across 15 unbilled entries    [Review & bill →]
```

When nothing is ready the bar is **absent**, not present-and-empty. The figure still reads `$0.00`.

### Billing mode

`Review & bill` does not navigate. It switches the same screen into billing mode:

| | |
|---|---|
| **Appears** | Checkbox column, sticky selection bar with running totals and `Record a bill →` |
| **Disappears** | Billable, Status, and row-menu columns — not editing here, and Status is redundant when the filter is already "unbilled" |
| **Changes** | Billing filter set to `Unbilled`; bar switches to `Billing — uncheck anything you're not charging for` with Cancel |
| **Unchanged** | Title row, filters, scope block, day cards |

Unchecking a row greys it and removes it from the running total, but keeps the amount visible so you can see what you're leaving off. Cancel restores the previous Billing filter value.

This is the same flow reached from Billing's "Review & bill" — one destination, two entry points.

### No meter in billing context

Retainer and fixed-budget scopes previously carried an allocation or budget bar in the context card. **Do not carry it into the scope block, and do not move it into the chart region on this screen.** The block's figures and basis lines state the same information exactly and in words; a meter restates it as proportion, which isn't what you act on while deciding what to charge.

The meter stays on project report, where proportion *is* the question. Rule: **the meter belongs where you're assessing, not where you're transacting.**

### Figure sets by project type

See `pltt-billing-by-type.html`. Only hourly was mocked previously.

| | Hourly | Retainer |
|---|---|---|
| Scope is | A run of unbilled entries | One closed period |
| 1 | Total hours | Total hours *(% on basis line)* |
| 2 | Billable amount | Over allocation |
| 3 | Sitting unbilled | Billable amount |
| 4 | Billed to date | Sitting unbilled |
| "Sitting" counts from | Oldest unbilled entry | Period close |

Retainer needs both *Total hours* and *Over allocation* because they answer different questions — how much work happened, and how much of it is chargeable. In an hourly scope those are the same number, which is why hourly needs only one.

The allocation percentage lives on the first figure's basis line. That's where the dropped meter's information goes.

**Fixed budget never enters billing.** The fee is agreed up front and invoiced from Zoho on its own schedule, so a fixed-budget project:

- never appears in the Billing index (Ready to bill or Billed)
- never produces a bill record
- has no billing scope block, no `Review & bill`, no selection mode
- shows no `Billed to date` figure anywhere

Going over budget on a fixed fee is **not** an absorption. Absorption is a property of a bill record, and there is no record. The overrun surfaces only as awareness on project report — effective rate, hours against budget — which is its correct and only home.

Entries on a fixed-fee project therefore always read `—` in the Status and Amount columns and have no Billable toggle (§4).

---

## 4. Billing columns

**Three columns stay: Billable, Status, Amount.** Do not merge.

Status column has exactly three values plus a dash:

| Status | Billable | Amount | When |
|---|---|---|---|
| `Unbilled` (green chip) | `$` | amount | Chargeable, no bill record. Only coloured chip. |
| `Billed` (grey chip) | `$` | amount | Covered by a bill record. |
| `Not charged` (amber chip) | `—` | struck-through amount | Billable was switched off. |
| `—` | `—` | `—` | Retainer within allocation, fixed fee, or internal. |

Do **not** add "In allocation" / "In fixed fee" / "Own time" chips. Those repeat what the project name and dashed Billable column already say.

`Not charged` exists because an excluded entry and a fixed-fee entry currently look identical — both a dash — and one is a decision you made.

The Billable `$` toggle keeps its current behaviour. It should gain a column header and read clearly as on/off. Once a bill record covers an entry, the toggle locks (greyed, still readable) — undoing requires deleting the record.

---

## 5. Today — three states

See `pltt-today-states.html`.

**State 1 · Capturing** (no entries processed)
Log at full height, active. Nothing below it — no stat cards, no empty table.

**State 2 · Confirming** (parsed, not saved)
Own titled view — `Review entries` with a "Not saved yet" badge. A banner names anything needing attention (`1 entry needs a client and project · 1 has no tag`) with a jump link. Footer: back, running totals, Save all entries.

> **Check before building:** confirm whether this is currently a separate route or the same page scrolled. If the latter, this is a routing change, not just visual.

**State 3 · Recorded** (saved)
Log stays a normal editable textarea with its existing Save and Process controls. **Do not collapse it, do not make it read-only, do not add expand/edit affordances.** The entries table sits below it as now.

`Re-process…` keeps its ellipsis and confirms — it can produce duplicates.

> Earlier drafts proposed a collapsed read-only log with Expand and Edit buttons. **Rejected — do not build.** `pltt-today-states.html` §04–05 shows that approach; ignore those sections. Sections §02–03 (capturing, confirming) still apply.

---

## 6. Row actions

See `pltt-row-actions.html`. Replaces four overlapping patterns — WP hover row actions, trailing "View" links, in-cell buttons, and the kebab — with one system.

### Three rules

**1 · The row's name is the link.** Navigation to what a row represents happens by clicking its identifier: client name, project name, record number, date. Never a trailing `View` / `Open` column. If a row has no obvious identifier to link, the columns are ordered wrong.

**2 · Actions live in one always-visible kebab** in the last column, on every row. Always visible rather than hover-revealed — no layout shift, works on touch, and the presence of actions is discoverable without hovering. This replaces WP's native hover row actions on Clients and Projects.

**3 · A button only when the action is the row's purpose.** Test: *"Would I open this screen in order to do this to a row?"* Yes → button. No → menu. Billing's ready list qualifies; the clients list does not. At most one button-per-row action per screen; everything else stays in the menu.

### Menu contents and order

Primary edit first (highlighted), then navigation, then contextual actions, then destructive below a divider.

| Row type | Menu |
|---|---|
| Time entry | Edit entry… · Change tags… · Mark not billable · Go to [date] log · — · Delete entry |
| Client | Edit client… · View entries · Add a project · — · Delete client |
| Project | Edit project… · View entries · Archive project · — · Delete project |
| Bill record | Copy invoice text · View entries covered · — · Delete record |
| Daily log | Open log · View entries · Re-process… |

Conventions:
- Ellipsis means a dialog follows. `Edit client…` opens a modal; `Archive project` acts immediately.
- Destructive items sit below a divider, in red, and always confirm. **This is the only place red appears in the app** (see §1).
- A one-item menu isn't a menu — make it a link in the row. A menu over six items means the row wants a detail page.

### Specific removals

- Clients — hover row actions → name link + kebab
- Billing history — `View record` column → record number is the link
- Project detail billing history — same
- Entries — day-header Edit button → row kebab + linked date (§7)

---

## 7. Editing and day navigation

The day-header Edit button was doing two jobs. Split them:

**Row menu** (vertical dots, end of row) — Edit entry, Change tags, Mark not billable, Go to [date] log, Delete entry. Tags and billable stay inline-editable as they are now; the menu is for heavier changes.

**The day date is the link** → opens Today at that date. This gives Entries a route to the raw log and the journal lines that never became entries, currently only reachable via History.

No separate "Open day log" link beside the title — that's the trailing-link pattern removed elsewhere (§6), relocated. The date itself is the identifier and carries the navigation, same rule as row names one level up. No day-level kebab either: there's exactly one day action, and a one-item menu is a link. Add a hover underline so the date reads as clickable.

Two routes to the day is intentional: the band for scanning by date, the menu for when one entry reads oddly and you want the line that produced it.

---

## 8. Date control

**Keep the existing control unchanged.** Face shows literal dates, arrows step, dropdown holds presets and a custom range, Apply correctly scoped to the custom range only.

Two changes:
1. Add **All time** to the preset list. Unbilled work older than a month currently falls outside every preset.
2. **Reuse this component** on Today (stepping by day), History (by month), and project detail. Replace the three bespoke pickers.

Filter dropdowns lose their Apply button and apply on selection. `Clear filters` appears only when something is set. (The date control keeps its Apply — it guards a two-field range, not a single choice.)

---

## 9. Charts

See `pltt-charts.html`. Two types only — bar chart (quantity over time) and meter (one value against a limit). Composition views stay tables with inline meters.

### Meter — the limit is a position, not the end of the track

Current behaviour is wrong: an over-budget bar fills the track and shows the excess in a lighter tint, which reads as *remaining*. Capping also means 249% and 500% look nearly identical.

| State | Behaviour |
|---|---|
| Under / at limit | Track scales so the limit is full width. Marker at the right edge. Fill blue. |
| **Over** | Track scales so the **total** is full width. Marker moves left to where the limit falls. Everything past it amber. Never cap. |
| No limit set | Bar, no marker, no percentage — or omit the meter. Do not invent a limit. |

The marker carries a label naming what it is (`budget`, `3h included`), so the meter reads without its caption.

### Bar chart

| Change | Reason |
|---|---|
| Solid tints, no hatching | Hatching is noisy below ~20px and reads as texture, not category |
| Days with nothing logged → 2px baseline tick | Currently a full-height grey column: the heaviest mark on the chart representing absence of data |
| Remove per-bar value labels | Duplicates the axis; collides at 30 bars. Axis + hover instead |
| Round-number axis, two gridlines | `45h / 23h / 0` — 23 is arbitrary |
| Sparse x labels | Every 5th day for a month; every month for a year |
| Keep the average line | Descriptive and useful. Label on a paper-coloured chip at the right edge so it never overlaps a bar |
| Total moves into the legend row | `95h 39m across 16 days` — the chart's own summary, no caption needed |

### Fixed series colours

```
--s-bill:    #146b42   billable client work
--s-nonbill: #7fb094   non-billable client work
--s-own:     #c5c2bd   internal
--s-over:    #c08f2a   past a limit
--track:     #e9e7e3
```

Never reassigned per chart. Same colour means the same thing everywhere.

### Shared rules

- **Never red.** Over a budget is amber. Nothing in a chart is an error state.
- **Absence is not a mark.** Baseline tick, never a filled column.
- **Y axis always starts at zero.** Truncating exaggerates variation, which is editorialising.
- **Every chart states its period** in the panel header, same wording as everywhere else.
- **Granularity is a panel control** (§3b) — never changes page scope.
- **Exact values on hover.** Chart shows shape, tooltip carries precision.
- **No chart without a decision.** If you can't name what someone does differently after looking, use a table.

---

## 10. History

See `pltt-history.html` §01. Calendar view is **not** being built.

- Remove the "Daily Logs 16" card → into the header subline
- Remove the Status column → chip on the day itself for the exception only
- Hours in `h/m`, not decimals (only place in the app still using them)
- Week totals in the week band — days and hours
- Add to header subline: **`2 days not processed — Jun 9, Jun 17`** as a link that filters to them. This is the real need: finding a day you forgot to process, not recognising one you're already looking at.

### Preview column

Currently shows the first line of the log, which is almost always the first task of the morning and therefore rarely distinctive. Replace with **clients worked that day**, ordered by time spent:

```
NCJW of Rossmoor · Postie · Behind The Scenes Adventures · Internal
```

Optional second line: the description of the longest entry that day. Both are existing fields.

---

## 11. Questions for the code — answer before building

These emerged from design and are behaviour questions, not visual ones. Please verify against the current implementation and report back rather than assuming:

1. **Can an excluded entry inside a billed date range resurface as outstanding?** If entry membership in a billing record is derived from time-range rather than stored, an entry with billable switched off may fall inside a billed range but never be marked billed — and reappear in "ready to bill" indefinitely.

2. **Does a bill record reconcile hours when an entry was switched off?** If a record covers a range containing a non-billable entry, *time logged in the period* and *time charged* differ. Confirm both are available; the record view needs to show which is which.

---

## Build order

1. Tokens, type scale, spacing (§1)
2. Layout changes — stat strip, separate day cards, page header (§2)
3. Scope block + light header, with the mechanical rule (§3), vertical order (§3a), panel controls (§3b)
4. Billing columns (§4), billing elements and billing mode (§3c)
5. Today's three states (§5)
6. Row menu and day link (§6)
7. Date control reuse + All time (§8)
8. Charts (§9)
9. History (§10)

1–2 are prerequisites for everything. 3–8 are independent of each other and can land in any order.

# Finalize-screen consumption indicator — spec

**Status:** agreed 2026-08-01, not built. **Build this before the notification
work** — cheapest change with the highest return.

Show how much of a project's ceiling is used, on the screen where entries get
assigned to projects, every day.

## Why here

The absorption diagnosed in the Aug 2026 reconciliation ($1,248.33, 20.9% of
everything calculated) comes from meeting the number **once**, at invoicing, under
time pressure, seeing it for the first time. Finalize happens most days. Seeing
"3.2 of 3 hours" on the 9th and "4.1 of 3" on the 14th makes the month-end figure
familiar instead of shocking — which is the whole mechanism behind billing half
and calling it done.

Patrick, 2026-08-01: *"the changes to the finalize screen are the cheapest highest
value since they surface every day at the end of work."*

It also catches the single-session case no cron can reach. BTSA's whole 5-hour
budget went in one 5.43-hour sitting; at finalize that entry can be shown as
**108% of the project's budget** — too late to prevent, but known that day rather
than eight days later.

## What it shows

Deliberately simpler than the Overview version. **No projection here** — you are
mid-flow assigning entries and a forecast is too much. (Pace/"tracking to 7.4h,
crossing about the 11th" belongs on the Overview; see
`budget-threshold-notifications-spec.md`.)

```
Democrats of Rossmoor — 2.1 of 3h, 5 days in
```

- **retainer** — minutes in the current allocation period vs `pltt_budgeted_minutes()`,
  plus how far into the period we are ("5 days in")
- **fixed-fee** — total minutes on the project vs the budget, cumulative, no period
  fragment
- **hourly** — nothing. No ceiling, nothing to be near.
- **internal** — nothing.
- **recurring fixed-fee** (once that type exists) — nothing. Its hours are a
  monitoring reference, not a ceiling.

Escalate the styling past 100%, but keep it a statement, not a warning. The point
is familiarity, not alarm.

## Live, not static

The figure must **climb as entries are assigned**. Putting three entries on DoR in
one session should read 3.2 → 3.5 → 3.9.

This is the difference between useful and decorative: a batch of small entries is
exactly how the ceiling gets crossed (DoR averages ~25 communication entries a
month at ~6 minutes each). A static figure rendered at page load would miss the
whole session that is being finalized.

Implementation: server renders each project's *starting* consumption; the client
accumulates the durations of rows assigned to that project as the user works.

## How the data gets there

**No new model, no migration, no new query pattern.** Reuse the existing pipe:

- `pltt_compute_overage_threshold()` already computes consumption for a period.
- `pltt_budgeted_minutes()` already gives the ceiling.
- Project `<option>` elements already carry computed per-project values —
  `data-billable-flag` and `data-billability-default` are emitted exactly this way
  today (PHP render plus the four option builders in `review.js`).

So: emit consumed + ceiling as two more data attributes, and render the line next
to the picker. The pattern is already proven by `billable_flag_applies`.

Watch the same trap that bit `applyBillableVisibility()`: the four option builders
in `review.js` all need updating, not just the PHP render.

## Not this

- **Not on the Daily Log.** That is capture, and capture-first is protected.
- **Not a blocking prompt.** It never interrupts; it is a figure on screen.
- **Not a projection.** That lives on the Overview.

## Depends on

Nothing. This is the one piece of forward-looking work with no dependency on
first-class budgets or the recurring-fixed-fee type — retainer allocations and
fixed budgets both already exist. That is why it goes first.

---

# BUILD PLAN

Written 2026-08-01 to be executed cold in a fresh session. Verified against the
code that day; re-check line numbers, they drift.

## Ground rules for the build

- **New branch** off current HEAD (suggested `feature/finalize-consumption`).
- **No database writes.** Display only. Nothing touches entries, records,
  projects or options.
- **Additive only.** Do not change existing finalize behaviour. Capture and
  finalize must keep working even if the new figure is wrong.
- **Commit, do not push.**
- **If a decision arises that this plan does not cover, STOP and write it down**
  in a handover note rather than guessing. Three parked questions beat three
  unrequested product decisions.

## The existing pattern to copy

`billable_flag_applies` is the exact precedent — a computed per-project value that
reaches the finalize screen. Follow it through all 8 touchpoints or the figure
will be missing wherever a project list is rebuilt client-side. This is the trap
that made the billable-flag bug: the PHP render was fixed, the JS builders were
not.

| # | Where | File | What it does today |
|---|---|---|---|
| 1 | compute | `includes/helpers.php` | `pltt_billable_flag_applies()` |
| 2 | stamp (AJAX `get_projects`) | `includes/api/class-pltt-ajax.php` ~482 | `$project->billable_flag_applies = …` |
| 3 | stamp (AJAX `create_project`) | `includes/api/class-pltt-ajax.php` ~576 | same |
| 4 | render (finalize row) | `templates/partials/review-post-parse.php` ~234 | `data-billable-flag="…"` |
| 5 | render (entry form row) | `templates/partials/entry-form-row.php` ~154 | same |
| 6 | JS builder (string) | `assets/js/review.js` ~348 | builds `<option …>` |
| 7 | JS builder (dataset) | `assets/js/review.js` ~647 | `option.dataset.billableFlag = …` |
| 8 | JS builder (string, form) | `assets/js/review.js` ~1354 | builds `<option …>` |
| 9 | JS builder (dataset, form) | `assets/js/review.js` ~1468 | `option.dataset.billableFlag = …` |

## Step 1 — the helper

New in `helpers.php`, beside `pltt_billable_flag_applies()`:

```
pltt_project_consumption( $project ) : array|null
```

Returns `null` when the project has no ceiling (hourly, internal, or a recurring
project with no allocation — the S3NSE shape, which must never show a figure).

Otherwise:

| key | meaning |
|---|---|
| `consumed_minutes` | retainer: minutes in the CURRENT allocation period · fixed: lifetime minutes on the project |
| `ceiling_minutes` | `pltt_budgeted_minutes( $project )` |
| `period_day` | retainer only: days elapsed in the current period (1-based) |
| `period_days` | retainer only: length of the current period in days |
| `type` | `recurring` or `fixed` |

Reuse `pltt_get_allocation_period_bounds()` for the period and
`pltt_budgeted_minutes()` for the ceiling. Do **not** re-derive either.

**Performance:** compute only for projects that have a ceiling — roughly 13 of 49
today, not all of them. A naive loop calling `pltt_compute_overage_threshold()`
per project would add ~13 queries per finalize render. Prefer a single grouped
query for minutes-by-project over the relevant date windows if that proves slow;
measure before optimising.

## Step 2 — server side

Stamp the computed values onto project objects at the two AJAX endpoints
(touchpoints 2 and 3), exactly as `billable_flag_applies` is stamped. Emit as
plain integers so the JS needs no parsing beyond `parseInt`.

## Step 3 — markup

Add to the option elements at touchpoints 4 and 5:

- `data-ceiling-minutes` — `0` when there is no ceiling
- `data-consumed-minutes`
- `data-period-day`, `data-period-days` — retainer only, omit otherwise

Add a container next to the project select in the entry row for the rendered
line, e.g. `<span class="pltt-consumption"></span>`. Empty when the selected
project has no ceiling.

## Step 4 — the four JS builders

Touchpoints 6–9. Each must carry the new attributes through. **Verify all four**;
a missed builder means the figure silently disappears after the project list is
refreshed, which is exactly how the billable-flag bug hid.

## Step 5 — render and accumulate

On project change, and on initial render, write the line into the container:

```
Democrats of Rossmoor — 2.1 of 3h, 5 days in
```

Fixed-fee omits the period fragment: `BTSA Registration Form — 5.4 of 5h`.

**Live accumulation.** The server figure is the baseline *before this session's
entries*. As rows are assigned, add the durations of all rows currently pointing
at that project. Three entries on DoR should read 3.2 → 3.5 → 3.9.

**TO VERIFY FIRST:** whether the finalize row exposes its duration in minutes to
JS. If not, that attribute has to be added — check
`templates/partials/review-post-parse.php` before assuming.

## Step 6 — styling

`assets/css/review.css`. Muted by default; escalate past 100% but keep it a
statement, not an alarm. Reuse existing tokens from `pltt-system.css` rather than
new colours.

## Verification (headless where possible)

1. `php -l` on every touched PHP file; `node --check` on `review.js`.
2. Render the finalize screen headlessly (`ob_start()` + the review render, see
   [[reference-headless-wp-bootstrap]]) and grep for `data-ceiling-minutes` on the
   options.
3. Hand-check one figure: Democrats of Rossmoor (project #1, 3h allocation) —
   compare the emitted `consumed_minutes` against a direct SUM for the current
   period.
4. Confirm an hourly project emits `data-ceiling-minutes="0"` and renders no line.
5. Confirm S3NSE's Content Plan Time (project #5, recurring, NO allocation)
   renders **no** figure. This is the regression that matters most — it is the
   project whose empty allocation is deliberate.
6. Confirm the existing billable-flag behaviour still works — the toggle still
   hides on retainer rows.

## Needs a browser pass before merging

The live accumulation cannot be verified headlessly. Check by hand:

- Assign three entries to the same retainer project; the figure should climb.
- Switch a row from one project to another; both figures should adjust.
- A project with no ceiling should show nothing at all.

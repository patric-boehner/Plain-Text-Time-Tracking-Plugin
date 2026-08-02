# Consumption indicator — handover

Built 2026-08-01 on `feature/finalize-consumption`. Display only, no database
writes, no schema change.

**The surface changed during the build.** The spec put the figure under the
project picker on the finalize screen, live-updating as entries were assigned.
Built that way first (commit `b92df00`), reviewed, and rejected:

> "Not while I am actually confirming an entry. It doesn't do anything for me to
> have it under the drop down exactly when I set it, in fact it's distracting —
> now I have something to think about while I am trying to do another task
> exactly when I can't do anything about it." — Patrick, 2026-08-01

It is now a dismissible notice under the day's summary cards, shown **after
Save All Entries**. The per-row version and everything that fed it were reverted
in the follow-up commit; `b92df00` still has it if it is ever wanted back.

---

## What it does now

After a save, if that day's work took a project **to or past** its ceiling:

```
This day's work took one project to its ceiling

  Democrats of Rossmoor · Website Care Plan Time    3.2 of 3h    8 days in
```

Retainers get the period fragment; fixed budgets are cumulative and omit it.
Hourly, internal, and retainers with a deliberately empty allocation (S3NSE's
Content Plan Time) never appear. Dismissible for the page view only — nothing
is stored anywhere.

## The one decision that overrode a stated answer

You chose **"only at or over the ceiling"**, with the reasoning that the notice
should be silent on a normal day so its presence is itself the signal.

Implemented literally, that rule **fired on 107 of the last 120 days** in the
dev database. The reason is structural: a retainer that crosses on the 5th is
still over on the 6th, the 7th and the 28th, so a state-based test re-reports the
same crossing on every save for the rest of the month. That is the wallpaper you
were trying to get away from.

So it reports the **crossing**, not the state: a project qualifies only when it
was under its ceiling before that day's entries and is at or over once they
count. Same 120 days, **14 firings** — about one in nine. Days that merely pile
more onto an already-broken ceiling stay silent.

**If you would rather have the literal rule**, it is a two-line change in
`pltt_consumption_alerts()` — drop the `$before` computation and the
`$before >= ceiling` clause.

## Things worth knowing

**The figure is "as of that day", not the whole period.** `3.2 of 3h` on 8 July,
not July's eventual 10.9. This required a `$through_reference` flag on
`pltt_project_consumption()`; without it the full-period total also contains
work dated *after* the day in question, which makes "before today" look
already-over and swallows the crossing entirely. That was a real bug mid-build —
it cut firings to 2 of 120 for the wrong reason. The flag defaults to off, so the
plain full-period call still agrees with `pltt_compute_overage_threshold()` on
all 14 ceiling-bearing projects.

**Only on the Daily Log.** `handle_save_entries()` normally redirects to
`daily-log?date=…&pltt_message=entries_saved`, which is where the notice renders.
If a `return_to` was set (Reports → Edit → Back), the redirect goes there instead
and the notice is not shown. Not wired up for that path — say the word if it
should be.

**Ordering is by share of ceiling**, so a 3-hour retainer at double sorts above a
40-hour budget a nudge over.

## Parked

- **No projection / pace.** Per spec, that belongs on the Overview with the
  budget-threshold notification work.
- **Nothing on the Daily Log capture side.** Capture-first is protected.
- **Recurring fixed-fee**, once that type exists, must return `null` from
  `pltt_project_consumption()` — it will need an explicit branch rather than
  falling through to `recurring`.
- **A day can only be reported once.** Re-saving the same day re-evaluates from
  scratch, so editing a finalized day and saving again will re-show the notice
  if the crossing still computes. Harmless, but it is not idempotent in the
  "you've already seen this" sense — that would need stored state.

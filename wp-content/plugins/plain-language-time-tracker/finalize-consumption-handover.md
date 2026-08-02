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

After **Save All Entries**, a dismissible notice under the day's summary cards
lists every retainer and fixed budget that day's entries touched — under and
over, not just the ones that broke.

```
Retainers and budgets this day touched

  Capital Advantage · Website Care Plan Time  [Monthly]        1.8h left of 3h    31 days in
  Diablo View · Website Hack Cleanup  [Fixed Budget]           0.8h left of 3.3h
```

and on a day that broke one:

```
This day's work reached one project's ceiling

  Democrats of Rossmoor · Website Care Plan Time  [Monthly]    3.2 of 3h          8 days in
  Behind The Scenes · Registration Form  [Fixed Budget]        5.4 of 5h          9% over
```

- **Under the ceiling it leads with what is LEFT.** "1.8h left of 3h" is a
  balance you can spend against; "1.2 of 3h" makes you subtract to get the
  number that decides anything.
- **Past it, it flips to what was used**, because remaining is no longer the
  question. Fixed budgets add how far past; retainers keep how far into the
  period, since a balance means nothing without it — 1.5h left on the 7th is
  comfortable, on the 28th it is not.
- **"Reached"** covers landing exactly on the ceiling and going past it. Across
  139 days no crossing ever landed exactly, so a separate "went past" variant
  would have been the only one anyone saw.
- The type badge is `pltt_render_billing_type_badge()`, the same helper Projects
  and Billing use. Only Monthly and Fixed Budget can appear; both are exercised
  by real data.
- Hourly, internal, and retainers with a deliberately empty allocation (S3NSE's
  Content Plan Time) never appear at all.
- Dismissible for the page view only — nothing is stored anywhere.

**Amber marks the event, not the state.** Notice chrome goes amber only when
this day's work broke a ceiling (15 of 139 days). Being over persists — a
retainer broken on the 8th is still broken on the 31st — so coloring the whole
notice for it would put 89 of 123 days in amber and train you to dismiss it
unread. The standing still shows, in the color of that row's figure.

**Ordering:** anything at or past its ceiling first (worst overrun leading, as a
share of the ceiling, so a 3-hour retainer at double outranks a 40-hour budget a
nudge over), then whatever has least left to spend.

## Things worth knowing

**The figure is "as of that day", not the whole period.** `3.2 of 3h` on 8 July,
not July's eventual 10.9. This required a `$through_reference` flag on
`pltt_project_consumption()`; without it the full-period total also contains
work dated *after* the day in question, which makes "before today" look
already-over and swallows the crossing entirely. That was a real bug mid-build —
it cut firings to 2 of 120 for the wrong reason. The flag defaults to off, so the
plain full-period call still agrees with `pltt_compute_overage_threshold()` on
all 14 ceiling-bearing projects.

**Both landing screens.** `handle_save_entries()` normally redirects to the
Daily Log, but a `return_to` (Reports → Edit → Back) lands on Reports instead.
Both now render it, through one shared gate
(`pltt_maybe_render_saved_consumption_notice()`) so they cannot drift. The
redirect carries `pltt_saved_date`, because Reports has no notion of which day
was saved; the Daily Log passes its own date as a fallback.

## It now shows every capped project, not only crossings

Second correction, and the important one:

> "Doesn't that defeat the purpose of this? Yes, overage was helpful, but the
> idea was to know when I am approaching going over so I can try and address
> it." — Patrick, 2026-08-01

Right, and `8b0de4a` says the same thing in the notifications spec: firing on
overage only "recreates the exact failure this feature exists to fix — finding
out after the money is spent."

So the notice now lists **every retainer and budget the day touched**, and leads
with what is LEFT while a project is still inside its ceiling:

```
Retainers and budgets this day touched

  Capital Advantage · Website Care Plan Time  [Monthly]      1.8h left of 3h   31 days in
  Diablo View · Website Hack Cleanup  [Fixed Budget]         0.8h left of 3.3h
```

"1.5h left" is a balance you can spend against; "1.5 of 3h" makes you subtract to
get the number that decides anything.

DoR's July now reads, save by save: **2.8h left → 1.8h → 1.7h → 1.5h left** →
then the crossing on the 8th. That fourth line is the warning that was missing.
It was measured, not assumed: on the last save before each of the 15 crossings a
balance was visible and shrinking, median 1.5h left.

**Volume:** 2.3 lines on the 88% of working days that touch a capped project;
16 of 139 days silent. Amber chrome is reserved for the 15 days where a ceiling
actually broke — being over is a state that persists, and coloring the notice
for it would put 89 of 123 days in amber. Row color carries the standing,
notice chrome carries the event.

## Measured: a fixed PERCENTAGE band would not work

Measured against every ceiling-bearing project-period in the dev database —
26 periods, 15 of which crossed, over ~6 months. Script:
`band-analysis.php` (scratchpad; re-runnable).

| band | alerts | warned before the crossing | fired same day (no lead) |
|---|---|---|---|
| 50% | 21 | 7 of 15 (47%) | 8 (38% of alerts) |
| 70% | 19 | 6 of 15 (40%) | 9 (47%) |
| 80% | 18 | 5 of 15 (33%) | 10 (56%) |
| 90% | 17 | 2 of 15 (13%) | 13 (76%) |

**No band earns its place.** The best of them warns ahead of fewer than half the
crossings, and at 80% more than half of all alerts fire on the very day the
ceiling breaks — a "you're getting close" that arrives simultaneously with "you
went past" is just noise with an extra step. Volume roughly doubles, from 2.5
notices a month to 5.5.

The reason is in the data, not the threshold:

```
project                     day before   after
Website Care Plan Time            0%      109%
Website Care Plan Time            0%      112%
Website Health Report             0%      154%
Registration Form System U        0%      109%
Website Care Plan Time           49%      105%
```

**10 of 15 crossings jumped from under 80% straight past the ceiling in a single
day, and 5 of those started the period at zero.** These overruns are not a slope
anyone could watch approaching — they are one sitting that consumes the whole
allocation. A band can only warn on a day that exists, and for a third of these
there was no earlier day.

That is why this surface shows the running balance instead: continuous
visibility needs no trigger day at all.

## For the notifications spec: the ⅓ pace gate blocks half the crossings

Not this feature — this is `budget-threshold-notifications-spec.md` (`8b0de4a`),
flagged here because the finding is measured and the spec says "do not revert to
one level". Script: `gate-sweep.php`.

The pace rule is right. The **⅓-elapsed gate on it is not**, against this data:

| rule | warned in time | too late | false alarms | median lead |
|---|---|---|---|---|
| pace, gate ⅓ *(the spec)* | **1 of 10** | 9 | 3 | 16d |
| pace, gate ⅙ | 5 of 10 | 5 | 5 | 5d |
| pace, no gate | **7 of 10** | 3 | 5 | 7d |
| 75% level only | 2 of 10 | 8 | 3 | 8d |

The reason is that these retainers break early — **5 of 10 crossings happen
before the ⅓ gate opens at all**:

```
Postie        Apr    crossed day 1   (3% elapsed)
Postie        Jul    crossed day 3   (10%)
DoR           Mar    crossed day 5   (16%)
DoR           Jul    crossed day 8   (26%)
DoR           Feb    crossed day 9   (32%)
```

Waiting for the projection to stabilize means waiting until the money is gone.
Dropping the gate takes warnings from 1 to 7 of 10 and costs 2 more false alarms
(3 → 5) — and a "false alarm" here is a projection that was true when made and
later corrected, which the spec's own dedupe and acknowledgement already absorb.

**Suggested:** drop the time gate, and if early volatility is still a worry, gate
on *consumption* instead — don't project until, say, 25% of the allocation is
used. That avoids projecting from a six-minute entry on the 1st without waiting
for the calendar. Not measured yet.

Also worth correcting in that spec: it says BTSA "would have fired at 3.75h,
roughly two-thirds through that first 5.43-hour session." True arithmetically,
but nothing evaluates mid-session — a daily cron sees the day whole, so BTSA
fires the same day it crosses. 75%-on-fixed still warns in time for 3 of 5
crossings; the two it misses are both single-session blowouts.

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

# Budget threshold notifications — spec

**Status:** agreed 2026-08-01, not built.

Tell me I'm approaching a project's ceiling **while I can still do something about
it** — not at invoicing, when the money is already spent.

## Why

From the Aug 2026 Zoho reconciliation of Feb–Jul 2026:

- **$1,248.33 absorbed** across 38 billing records — 20.9% of everything calculated.
- Retainer overage absorbs **51%**; hourly absorbs **2%**. The problem is entirely
  work with a ceiling.
- Democrats of Rossmoor alone is **$1,028.60 — 82% of all absorption.** Over its
  3h allocation in all six tracked months, averaging 7.6h.
- Fixed-price projects overran their recorded budget by **119%–473%**.
- Behind The Scenes Adventures' Registration Form project: **$500 quoted, 10.78h
  tracked ($1,078.33), 216%.**

The absorption has a shape, and it isn't a policy: **under ~$150 nothing gets
billed; over ~$400 about half does.** That is a decision made alone, at month end,
under time pressure, about a number seen for the first time. Two months on DoR
(March $100.50, April $121.50) were written off entirely because each felt too
small to raise — $222 gone to that alone.

Patrick's own framing, and it sets the design: *"I need to be notified early so I
know and can respond"*, and separately, *"part of me knows that and is avoiding
it."* A signal you have to go and look for will not work.

## Two failure modes, and only one is easy

**Slow drift.** DoR accumulates ~25 communication entries a month at ~6 minutes
each, plus support. A twice-daily email catches this comfortably — by 70% it has
been days.

**Single-session burn.** BTSA's entire 5-hour budget went in **one 5.43-hour
sitting**. No cron catches that. Worse, under capture-first the entry may not
exist until the session ends, so the tracker cannot warn about time it has not
been told about.

**ACCEPTED, not solved** (Patrick, 2026-08-01): *"we can't notify on unprocessed
time. That's just a reality of my system, I'm ok with."* Do not try to close this
with capture-time hooks — it would fight capture-first for a case the data can't
support anyway.

**Consequence for the default:** the threshold must be low enough that a delayed
signal still lands before the next big session. At 90% BTSA gets no warning at
all. **Default 70%**, not 80–90%.

## Settings — per project

Added to the project modal so it can be set when the project is created.

| Field | Type | Notes |
|---|---|---|
| `notify_threshold_percent` | int, nullable | Blank = site default. **Means different things per type** — see Firing. Retainer: projected % of allocation (default 100). Fixed: % of budget consumed (default 75). |
| `notify_enabled` | bool | On by default for types with a ceiling |

**Shown only for retainer and fixed-fee.** Hidden for:
- **hourly** — no cap, nothing to cross
- **internal** — never billed
- **recurring fixed-fee** (the S3NSE shape, not yet built) — **must never fire.**
  Its hours are a monitoring reference, not a billing threshold. This is why that
  type has to exist before this feature ships, or S3NSE will nag every month
  about something deliberately not billed.

## What it measures

- **Retainer** — minutes in the current allocation period vs
  `pltt_budgeted_minutes()`. Resets each period.
- **Fixed-fee** — total minutes on the project vs the budget. Cumulative, never
  resets.

Both are duration-based, so they are unaffected by the billable-flag clamp (entries
on retainer/fixed projects are all `billable = 0`).

## Firing

A **once-daily cron** — agreed 2026-08-01 as sufficient.

**Cron is not a concern:** the live server runs its own system cron (confirmed
2026-08-01), so this does not depend on WP-Cron's page-load trigger. Set
`DISABLE_WP_CRON` and hook the daily job normally.

### A single level threshold does NOT work — do not revert to one

Tested against Feb–Jul 2026. The three retainers behave completely differently,
so one percentage cannot serve them:

| Project | Allocation | Behaviour | A 70% level would… |
|---|---|---|---|
| **Postie** | 1h | bimodal — months are 0.22/0.40h **or** 2.12/3.13/4.93h | fire only in the over-months. **Correct.** |
| **Capital Advantage** | 3h | typically lands 2.45–2.78h and stays **under** | fire in 4 of 6 months, 3 of them **false alarms** |
| **Democrats of Rossmoor** | 3h | averages **7.6h**, over every single month | fire in week 1, every month, forever. **No level helps.** |

This is the objection raised during the first build attempt ("threshold is
reached on too many projects too quickly"). The observation is right; the
conclusion to fire on **overage only** is wrong, because that recreates the exact
failure this feature exists to fix — finding out after the money is spent.

### Retainers fire on PACE, not level

A retainer has a clock, so the question is not "how much have you used" but "how
much for this point in the period."

> Fire when **projected consumption exceeds the allocation**, and at least **⅓ of
> the period has elapsed** so the projection is stable.

Projected = `consumed ÷ elapsed_fraction_of_period`.

This removes the false alarms outright. Capital Advantage at 2.1h on the 25th is
on pace — silent. At 2.1h on the 8th it projects to ~7h — fires. Same number,
different meaning.

Below ⅓ elapsed, stay silent regardless: the projection is noise.

### Fixed projects fire on LEVEL

No clock, so level is all there is. **Default 75% of budget.** Noise is not a
concern — a fixed project crosses once in its life. BTSA's Registration Form
would have fired at 3.75h, roughly two-thirds through that first 5.43-hour
session.

### Four layers that stop repeats

1. **Once per project per period per level.** Needs a record of what has been
   sent — project + period + level — as a small table or per-project meta.
   Without it the job re-sends every run.
2. **One digest, not one email per project.** A single daily message listing what
   crossed. Nothing crossed, no email. Caps the volume at one a day regardless of
   how many projects trip.
3. **Chronic detection — the important one.** If a project fires in **3
   consecutive periods**, stop repeating and change the message:

   > Democrats of Rossmoor has exceeded its 3h allocation for 3 periods running —
   > averaging 7.6h. This looks like a pricing problem, not a monthly surprise.

   Then suppress until the allocation changes or it exceeds by a materially
   larger margin. This is the escalation DoR actually needs; repeating the same
   alert monthly would bury the finding instead of surfacing it.
4. **Acknowledgement.** Dismissing suppresses that project for the current
   period, so a known and accepted overage does not nag.

### What this design would have produced on real data

Six months: **Capital Advantage once** (June, genuinely unexpected), **Postie
three times** (its real over-months), **DoR twice** before converting to the
pricing message, **BTSA once** mid-session. Roughly **seven messages in six
months**, every one about something worth knowing.

A flat 70% level would have produced closer to twenty, most of them wrong.

## What the email says

The **composition**, not just the number — that is what makes it actionable:

- hours used, the budget/allocation, percentage
- the amount at the resolved rate (`pltt_resolve_billable_rate`)
- the **tag breakdown for the period**

For DoR that reads *"4.2 of 3 hours, $108 over, 40% communication and support"* —
a message that can be forwarded to a client. *"You're over"* is only something to
feel bad about.

The forwarding matters: both clients had already agreed to overage in principle.
What makes billing hard is presenting a number they have not watched accumulate.
An early figure turns the invoice into a confirmation rather than an announcement.

## On-page

Secondary surface, same computation: a persistent notice on the **Overview** —
where the day starts, and the one screen that can carry several clients at once
without being sought out. Not the project page; that has to be gone looking for.

## Not this

- Not a nag. Once per threshold per period.
- Not at capture time on the Daily Log — that interrupts capture-first.
- Not on hourly projects.

## Depends on

1. **The recurring-fixed-fee type** — or S3NSE fires wrongly every month.
2. **Trustworthy budget figures.** Sparq's recorded budget implies $1,500 against
   $6,550 invoiced; Robin Mohr's implies $3,870 against $1,845. Some budgets are
   stale, partial or phase-level. A notification against a wrong budget is worse
   than none.

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
| `notify_threshold_percent` | int, nullable | Blank = site default (70) |
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

A **once-daily cron** — agreed 2026-08-01 as sufficient, given the threshold is
low enough to absorb the lag. Two events per project per period: crossing the
**threshold**, and crossing **100%**. Each fires once.

Needs a record of what has already been sent — project + period + level — so a
small table or per-project meta. Without it the job re-sends on every run.

**Cron is not a concern:** the live server runs its own system cron (confirmed
2026-08-01), so this does not depend on WP-Cron's page-load trigger. Set
`DISABLE_WP_CRON` and hook the daily job normally.

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

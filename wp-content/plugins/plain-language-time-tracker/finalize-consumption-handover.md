# Finalize consumption indicator — handover

Built 2026-08-01 on `feature/finalize-consumption` from the BUILD PLAN in
`finalize-consumption-indicator-spec.md`. All 9 touchpoints done, display only,
no database writes, no schema change.

This note records the decisions the plan did not cover. Three of them changed
what got built, so read them before reviewing the diff.

---

## 1. DECIDED (needs your sign-off): the period is anchored to the day being finalized, not to today

The spec says a retainer shows "minutes in the **current** allocation period".
That is unambiguous when you finalize today's log, which is the daily flow. It
is ambiguous when you finalize an older day — reviewing 31 July on 1 August, is
"current" July or August?

**Chosen: the day being finalized.** So reviewing 31 July shows July's
consumption and "31 days in", not August's empty slate.

Why:

- It matches the existing idiom. `pltt_compute_overage_threshold()` already
  takes its period anchor from `$filter_args['date_from']` rather than assuming
  today, and this reuses that.
- It is the only version where the arithmetic in §2 below is correct. Anchored
  to today, the rows on screen can sit outside the period the baseline covers,
  and netting them out would produce a wrong figure.
- In the common case the two readings are identical.

The anchor travels as a `reference_date` POST parameter on `pltt_get_projects`
and `pltt_create_project`, sanitized with `pltt_sanitize_date_strict()` so
anything invalid falls back to today rather than failing.

**If you want "always today" instead**, it is a one-line change in each of the
two AJAX handlers plus dropping the `reference_date` sends in `review.js` — but
the netting-out in §2 then needs a period-membership check per row.

## 2. The baseline had to be adjusted, or the day's own work would count twice

The plan says "server renders each project's *starting* consumption; the client
accumulates the durations of rows assigned to that project." Taken literally
that double-counts, and the plan does not mention it.

The reason: **entries on the finalize screen are already in the database.** They
are created at parse time, not at "Save All" (`PLTT_Review::save_entries` —
"Entries are created during processing, this just updates them"). So a plain
`SUM(duration_minutes)` baseline already contains every row you are looking at.
Adding those rows on top would have shown a 3-hour retainer at 6 hours the
instant the page rendered.

**Fix:** each row publishes what it currently contributes to that SUM —
`data-db-project-id` and `data-db-duration-minutes` — and the client subtracts
those **once** at load, before it starts accumulating live. The figure is:

    consumed − whatTheseRowsAlreadyContributed + whatTheyContributeNow

With nothing touched the two correction terms cancel and the figure equals the
database exactly. Verified in `test-consumption.js`.

Sealing the subtraction at load is also what makes deletion work: removing a row
drops its live contribution, and the database row it deleted is already netted
out. Nothing is recomputed.

Note `data-db-project-id` is deliberately **not** the existing
`data-original-project-id`, which carries the *predicted* project (filled from
recency for guessed rows) and so does not match what is stored.

## 3. The line omits the project name

The spec's example line is:

    Democrats of Rossmoor — 2.1 of 3h, 5 days in

The container sits directly under the project picker, which already shows the
name, and the finalize table's project column is narrow. Rendering
`2.1 of 3h, 5 days in` on its own avoids repeating the name and wrapping the
cell. The spec's line reads as illustrating the *content*, not mandating the
name in situ — but it is a presentation call you may want to overrule.

## 4. Over 100% escalates colour and weight only — no percentage shown

The spec says "escalate the styling past 100%, but keep it a statement, not a
warning," and separately mentions BTSA's entry being showable as "108% of the
project's budget."

Built: `.is-over` switches to the plugin's existing overage amber
(`--pltt-c-over`) at weight 600. No percentage, no badge, no icon, no background.
A percentage would have been a second number competing with the one that matters.
Easy to add if you want it — it is one line in `describe()`.

## 5. The edit form got the indicator too, without cross-row accumulation

Touchpoints 5, 8 and 9 are the entry edit form, so it is in scope. But a form row
edits one persisted entry at a time, so "accumulate the rows assigned to that
project" has no cross-row meaning there. It renders the same line with the same
formula restricted to a single row: swap what that entry currently contributes
for what the open form would make it contribute.

The two surfaces never co-render (`review.php` and `daily-log.php` both branch
`if ( $is_post_parse ) … else …`), so their bookkeeping cannot collide.

---

## Parked — not built, not decided

- **Nothing shows on the Daily Log.** Correct per spec ("Not on the Daily Log"),
  noting only that the inline editor there does get the form-row version, since
  it shares `entry-form-row.php`.
- **No projection / pace.** Correct per spec — that belongs on the Overview with
  the budget-threshold notification work.
- **Archived projects** carry the figure like any other. Nobody asked; it fell
  out of following the `billable_flag_applies` pattern exactly.
- **Recurring fixed-fee**, once that type exists, must return `null` from
  `pltt_project_consumption()`. There is a comment marking the spot; it will need
  an explicit branch rather than falling through to `recurring`.

# When billing is offered, and over what span — rules spec

**Status:** agreed 2026-08-01, not built.

Three rules that make the billing action predictable. Today each surface answers
"can I bill this?" differently, and every answer is locally defensible — which is
exactly why the whole thing feels inconsistent.

## The problem, in Patrick's words

> *"How inconsistent the whole experience felt… It was the 31st and I still
> couldn't bill. Other times I would go to an hourly project with unbilled time
> and while the info card would tell me there was billable time, the bar for
> invoking the billing module wasn't there. It wasn't really clear if this was an
> error or the intended case."*

These are not three bugs. There is no stated rule for **when the bill action
appears** or **what span it covers**, so each surface invented one:

- the retainer path optimises for one-record-per-period
- the hourly card optimises for figure / button / table agreement
- archived projects optimise for not offering actions on dead work

Each is reasonable alone. Together they are unpredictable.

---

## Rule 1 — The action appears whenever there is uncovered billable value. No time gate.

**Today:** closure is computed as `period_end < today` in two places —
`PLTT_Billing::get_retainer_summary()` (~line 651, `$is_open`) and
`pltt_build_single_project_scope_figures()` (~helpers.php:2153, `$is_closed`).

On 31 July with a period ending 31 July that evaluates false, so **July's overage
first becomes billable on 1 August.** You can never bill a retainer on the last
day of its period — which is when invoicing actually happens.

**Change:** treat the final day as billable — `period_end <= today`.

**Why this is safe NOW and was not before.** The original gate existed because
billing before the period ended meant a later entry silently reopened the period
(the §3.1 live-recompute defect). **1.9.53 fixed that.** A record now freezes its
basis, a late entry stays unbilled and resurfaces next period, and delete-and-
recommit is the repair path. **The gate is protecting against a failure that no
longer exists.**

Consider a soft confirmation when billing a period whose last day is today
("today is still open — bill anyway?"), but do not block.

Applies to both `$is_open` and `$is_closed`; they must agree or the figure and
the bar will disagree.

---

## Rule 2 — If there is value but no action, say why. Never silent.

**Today:** `pltt_build_single_project_scope_figures()` (~helpers.php:2259):

```php
$ready_scopes = $is_active
    ? PLTT_Billing::get_ready_to_invoice( $project, true, $date_from, $date_to )
    : array();
```

On an **archived** project the billing scope is never computed. Every other
figure still renders from live data, so the card shows money and the bar vanishes
**with no explanation.** This is the most likely cause of the reported "info card
says billable, no bar."

A second path to the same confusion, working as designed: **"Amount" counts every
entry in range; "To bill" counts only uncovered + billable + verified ones.** A
range full of already-covered work legitimately shows money in one figure and
nothing to bill in the other — indistinguishable from a fault.

**Change:** whenever the bar is absent but a money figure is present, state the
reason in its place. One line each:

| Condition | Message |
|---|---|
| Project archived | Archived — restore it to bill. |
| Nothing uncovered in range | Nothing outstanding *(already exists)* |
| Retainer period not yet started | Period hasn't started. |
| Retainer under allocation | Nothing over allocation *(already exists)* |
| Fixed-fee project | Fixed fee — billed outside the tracker. |

**Build this first.** The damage is not the missing button; it is not knowing
whether you are looking at a fault or a rule.

---

## Rule 3 — The span is explicit and switchable in place.

**Today:** the bar bills exactly the filter range (`from` = `$date_from`,
`to` = `$date_to`), deliberately — the code comment records that using the
all-time span "made the number, the button and the table disagree about what was
being billed." That constraint is right and must be preserved.

Work outside the range becomes a `backlog_count` rendered as "N outside this
range", linking to `admin.php?page=pltt-invoicing#pltt-bill-proj-N` — **a
different screen.** So the escape hatch exists but it is a navigation, not a
choice.

**Change:** state the span on the bar and offer the alternative in place.

> Bill **1–31 Jul** · $420 across 6 entries — *4 more outside this range ·
> **bill everything***

Toggling re-scopes the range **in place**, so the figure, the button and the
table stay in agreement — the existing constraint holds — while giving a
one-click path to the whole set.

Keep the invoicing-page link as a secondary route; do not make it the only one.

---

## Build order

1. **Rule 2** — cheapest, removes most of the confusion.
2. **Rule 1** — one comparison operator in two places, plus an optional
   confirmation. Verify both `$is_open` and `$is_closed` change together.
3. **Rule 3** — real UI work; can wait.

## Verification

- A retainer whose period ends today offers the bar (Rule 1).
- An archived project with uncovered value shows a reason, not a blank (Rule 2).
- A range with only covered entries shows "Nothing outstanding", not a missing
  bar (Rule 2).
- Toggling the span updates figure, button and table together — never one without
  the others (Rule 3).
- **Regression:** billing a period on its final day, then logging more time to
  that period, must leave the new entry **unbilled** and resurfacing — not
  reopening the record. This is the 1.9.53 behaviour Rule 1 depends on.

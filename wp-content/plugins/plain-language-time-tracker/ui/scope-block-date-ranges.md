# PLTT — Scope block and the date range

**Status:** amendment to §3 / §3c of `pltt-ui-handoff-spec.md`. Everything in those sections stands; this resolves what they left undefined — what the block does when the date range does not line up with a billing unit.

**Scope:** presentation only. No new calculations. Every figure referenced here is either already specified or already mocked.

---

## Authority table — one addition

Add to the reference mockup table in the spec, directly below `pltt-scope-block-reinstated.html`:

| File | Covers |
|---|---|
| `pltt-scope-by-type.html` | Retainer multi-period figure set (§02). Ignore §03 — fixed budget billing is moot. |

This file was omitted from the original table. §02 is the multi-period retainer view and is the reason that state currently has nowhere to go.

---

## 1. The governing rule

**The scope block always renders when the filter is a single client + project. The action bar is the conditional element, not the block.**

The current implementation drops the block when it cannot determine a billing action. That conflates two questions:

- *Can I bill from this view?* — often no
- *Can I describe this scope?* — always yes, the terms are true regardless of period

A project with agreed terms has a full block at every date range. Only the figure set and the bar change.

### When the bar appears

**The bar appears only when the filter selects exactly one billable unit that is closed and unbilled.**

Mechanical. No case-by-case judgement.

| Type | Its billable unit | Effect of the date range |
|---|---|---|
| **Hourly** | A run of unbilled entries — defined by selection, not by calendar | **None.** Any range containing unbilled entries can produce a record. |
| **Retainer** | One closed period, as defined by the project | **Total.** Only an aligned single closed period can produce a record. |
| **Fixed fee** | None, ever (§3c) | **None.** No bar at any range. |

Hourly and fixed fee are already range-invariant and need no work. Retainer is the only type where the date range can put the user outside a billing unit, and the only type this amendment changes.

### When the bar is absent

Figure 4 carries a **count and a link** in its place. Never a button in the block — §3c's rule holds: the block holds information, the bar holds action.

---

## 2. Range classes

Four classes. Determined by comparing the filter range against the project's own period definition (§3 — *period boundaries come from the project*, never the calendar).

| Class | Definition |
|---|---|
| **Aligned single** | Range boundaries match exactly one period |
| **Multi-period** | Range covers two or more whole periods |
| **Partial** | Range is a subset of one period |
| **Ragged** | Range spans multiple periods with a partial period at one or both ends |

For hourly and fixed fee there is no period, so every range is one class and the distinction never arises.

---

## 3. Retainer figure sets by range class

### Aligned single period

Unchanged. §3 as written — period states table, ready bar when closed and unbilled, backlog line on figure 4's basis where earlier unbilled work exists.

### Multi-period

Build from `pltt-scope-by-type.html` §02.

```
Showing Jan 1 – Jul 24, 2026 · 7 monthly periods · 71 entries
─────────────────────────────────────────────────────────────
Total hours          Average per month     Overage billable      Not yet billed
31h 41m              6h 20m  (amber)       $1,501.50             $1,410.00  (amber)
Across 7 periods     211% of the 3h        Sum of 7 monthly      4 periods ·
                     included · 5 of 7     overages              July still open ›
                     over
```

| Slot | Label | Basis |
|---|---|---|
| 1 | `Total hours` | `Across N monthly periods` |
| 2 | `Average per month` | `N% of the [allocation] included · N of N over` |
| 3 | `Overage billable` | `Sum of N monthly overages` |
| 4 | `Not yet billed` | `N periods ›` — link only, per §3 |

**Bar absent.** A multi-period range cannot produce one record, and producing several silently is not an action a bar should offer.

*Average per month* replaces *Over allocation* because §3's rule holds: **never sum a recurring allocation.** Seven months at 3h is not a 21h budget. The average against the monthly allocation, plus how many months ran over, is the figure that carries a decision — resize the retainer, or don't.

### Partial period

The range is smaller than the billing unit, so neither allocation status nor billing status is answerable from it. Two range figures, two period figures, with the period named in the label:

| Slot | Label | Frame |
|---|---|---|
| 1 | `Total hours` | the range |
| 2 | `Against June's 3h included` | the whole period |
| 3 | `Billable amount in June` | the whole period |
| 4 | `Not yet billed` | the whole period |

Naming the month in the label does the frame work. This is the existing §3 rule — *the label names the span, in ordinary words* — applied to a case §3 did not enumerate. No new mechanism.

**Bar absent.** Billing a partial period breaks the one-record-per-period assumption the model rests on (§3).

### Ragged

Treat as multi-period, with one rule for the mismatch:

- **Figure 1 follows the literal range.** It is the readout of what the table below contains.
- **Figures 2–4 snap to whole periods** and say so in the basis line: `Across 6 whole periods · Feb – Jul`.

The two will not reconcile, and that is correct. The alternative is prorating a monthly allocation across a partial month, which is the frame division §3 forbids. Do not attempt it.

---

## 4. The open period

With any preset ending at today — `This year`, `Last 6 months`, `All time` — the final period is open.

- **Figure 3 (Overage billable)** includes the open period's overage so far.
- **Figure 4 (Not yet billed)** counts **closed periods only.** Its basis names the exclusion: `4 periods · July still open ›`.

This follows §3's open-period rule, where figure 4 is a dash rather than a zero because the question is not yet answerable. A multi-period figure 4 that silently included open-period overage would report money that cannot be billed as though it were waiting to be.

If every period in the range is open — a range covering only the current month, unaligned — figure 4 reads `—` with no basis line, same as §3.

---

## 5. Backlog and multi-period count are one component

The backlog indicator (§3) and the multi-period count are the same primitive with different arguments. Build once.

**What it says:** billable units exist that this filter cannot act on — here is how many, and here is where they are.

| | Backlog | Multi-period |
|---|---|---|
| Units are | Outside the range | Inside the range |
| Retainer wording | `N more unbilled ›` | `N periods ›` |
| Hourly wording | `N unbilled entries outside this range ›` | *(n/a — hourly can always act)* |
| Placement | Appended to figure 4's basis, separated by `·` | Figure 4's basis |
| Tint | `#fdfaf3` on that cell | `#fdfaf3` on that cell |
| Destination | Billing → Ready tab, pre-filtered to client + project | Same |

Both link to the same place. The Billing index is where you pick a unit to act on; the block's job is to tell you units are waiting and route you there.

Both can appear at once — a multi-period range starting in March, with unbilled work from January behind it: `4 periods · 2 more before Mar 1 ›`.

---

## 6. Hourly and fixed fee — stated for completeness

Neither changes. Recorded here so the implementation does not add range logic where none belongs.

**Hourly.** Figure set per §3c, unchanged at every range. Bar present whenever the range contains unbilled entries. Backlog line when unbilled entries exist outside it. One clarification: *Sitting unbilled* counts from the **oldest unbilled entry inside the range**, not the oldest overall — the figure describes what is on screen, and the backlog line covers the rest. At `All time` the backlog line is always absent, because nothing is outside.

**Fixed fee.** No billing elements at any range (§3c). Only figure 1 responds to the date control; budget figures are lifetime and their labels already say so. `Budget left` / `Budget overrun` never change with the range.

---

## 7. Figure 4's label — decided

**`Not yet billed`, everywhere, every type, every range.**

The existing spec carries two names for one slot: §3's period states table says `Not yet billed`, §3c's figure-set table says `Sitting unbilled`. This resolves it.

`Not yet billed` names the state. `Sitting unbilled` names the age of the state, which is what the basis line already carries (`oldest Feb 17`) — so the label was duplicating the basis and losing the state to do it. It also failed on retainers, where a closed period unbilled for two days isn't sitting on anything.

**Amend §3c's figure-set table:** hourly row 3 and retainer row 4 both read `Not yet billed`. The "Sitting counts from" row stays as written — it now describes the basis line, not the label.

The `Billed` variant is unchanged (§3, closed and billed): label flips to `Billed`, value greys, basis carries the record link.

---

## 8. Empty range

A range containing no entries for the project still gets a full block. The terms are true whether or not work happened in the window.

- Identity lines render normally
- When-line reads `Showing [dates] · no entries`
- Range-framed figures read `0h` / `$0.00`
- Lifetime-framed figures (fixed-fee budget) render normally — they do not depend on the range
- Backlog line still appears if unbilled work exists outside the range. This is the case where it matters most: an empty window with a backlog behind it is otherwise a blank screen with no route out.

---

## Do not build

- **A bar on any multi-period or partial range.** No "bill all periods" action, no multi-record flow. If several periods need billing, they are billed one at a time from the Billing index.
- **A period breakdown table inside the block.** The counts link out; project detail's `Full / By month` view is where per-period detail lives.
- **Prorated allocations.** No fraction of a monthly allocation for a partial month, in any figure or basis line.
- **A second bar, or a bar plus a figure link to the same action.** §3's invariant holds — `Review & bill` appears at most once per screen.
- **Dropping the block.** The current behaviour. This amendment exists to remove it.

---

## Questions for the code

Answer before building; do not assume.

1. **Does the period definition read from the project in every path?** §3 requires it. Confirm the range-classification logic added here reads the project's period definition rather than falling back to calendar month. A project on a non-calendar cycle must classify correctly.

2. **Confirm what figure 4 currently renders.** The label is now fixed as `Not yet billed` everywhere (see *Figure 4's label* below). Report which string the implementation uses today so the change is a rename and not a new figure.

3. **Can an open period be identified without recomputing?** Figure 4 needs to exclude open periods from its count and total. Confirm whether period open/closed state is available cheaply, or whether this forces a per-period pass on every render of a wide range.

4. **What is the cost of classifying a wide range?** `All time` on a long-running retainer means classifying and summing every period. Confirm this is acceptable at current data volume, and flag it if figures 2–4 require iterating periods rather than aggregating entries.

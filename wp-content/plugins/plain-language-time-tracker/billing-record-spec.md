# Billing Record — spec

*Final shape. The four open questions from the draft are resolved — see "Resolved decisions" at the end.*

---

## Update (June 2026) — simplified model, supersedes the detail below

A later design pass simplified this. In one line: **a billing record is a per-scope summary — billed amount, absorbed amount, the numbers and dates — and entries no longer carry per-entry billing state.**

- **No per-entry billing link.** Drop `billing_record_id` on entries and the per-entry `invoiced` / `billed` flags. The record is the single source of truth for what's been billed. (The `billable` flag stays on hourly entries only — it defines the chargeable base for `calculated_amount = billable hours × rate`. It's set once at processing, not toggled monthly.)
- **One record shape, scoped.** A record covers a scope: a *month* for retainers (allocation resets monthly), the *whole project* for hourly and fixed (no allocation, so no period start needed). Snapshot fields: project, `period_start` / `period_end`, `billing_type`, `rate`, `calculated_amount`, `billed_amount`, `absorbed_amount`, `billed_hours`, `allocation_hours`, `marked_at`, `description`. Hourly keeps `period_end` as a **billing cutoff** even though it has no `period_start` or allocation; `allocation_hours` and `billed_hours` stay so a record renders "over 3h · $325" without recomputing (`allocation_hours` is retainer-only, null otherwise).
- **One remainder rule for every type:** `unbilled = calculated(scope) − billed − absorbed`. This replaces the two-mechanic split (hourly entry-pool vs. retainer recompute) and makes the absorption-resurrection bug structurally impossible.

**What this removes downstream** — all of it was scaffolding for per-entry billing around the allocation line:

- the smart overage-entry selection algorithm
- the calculated-vs-marked reconciliation notes ("adjust invoice down")
- the "mark all billable as invoiced" bulk action
- most of the three-flavor notification system (keep a plain "this retainer is $X over — record a bill?" prompt)

**Reporting-side trims from the same pass:** the effort-over-life sparkline (on the NO list — answers no live decision on a closed project); gold-plating the archived post-mortem into four polished type-reads (ship the universal EHR + where-time-went, plus fixed-fee's scope read; let the rest earn in); and the multi-month retainer card's anomaly/median detection (show the numbers, read them yourself).

**Open seams this retires:** the simplified model moots the hourly freeze-on-claim rule and the partial-bill/absorb entry bookkeeping, and makes the absorption arithmetic correct by construction. The two gating items that survive before any billing build: the **migration spec** (legacy `billed` entries → seed per-scope totals, or clean-break and accept pre-cutoff lives in Zoho — note the entry-pool double-bill risk is gone since there's no entry pool) and the **overage / straddle-boundary fix** in the engine so the period-level `(used − allocation) × rate` is what records snapshot.

**Pin when the billing surface is specced:**

- **Hourly manifest = the cutoff.** With no entry link and no monthly period, a sequential or partial hourly bill needs a boundary for the description composer. Use the prior record's `period_end` as the cutoff: the next bill describes billable entries after it. The dollar remainder (`calculated − billed − absorbed`) stays exact regardless; the cutoff only bounds the *description*, and it's approximate in one corner — a late entry dated before a prior cutoff is caught by the dollar remainder but missed by a cutoff-based manifest. Acceptable since the composer is review-and-edit, but decide it consciously. Principle: don't let "no period for hourly" harden into "no cutoff for hourly."
- **Fold the model up before implementing.** The lower half of this doc still describes the entry-linkage model. Before any billing build, promote this note into a canonical fields/paths section that replaces it, so no one implements the stale `billing_record_id` version.

---

The detail below describes the earlier, more elaborate entry-linkage model and is kept for reference.

## Purpose

One record per billing event — one project, one period, snapshotted at the moment you mark it invoiced. It's the durable answer to four questions: what did I bill, for which project, covering what span, and what did I absorb.

It replaces per-entry billing flags for retainer overage, and connects the tracker to what actually got invoiced — the link that lives nowhere today, because the work sits in the tracker and the dollars sit in Zoho with nothing joining them.

In practice the per-period side collapses to a single case: **the retainer overage record.** Fixed-fee base fees aren't billed from time (Zoho invoices them on a schedule), and hourly work bills per entry. So the only record-producing paths are hourly and retainer overage; fixed-fee produces no record from time.

## Relationships

```
Project (1) ──< (many) Billing Records
Billing Record (1) ──< (many) Time Entries    [hourly only, via entry.billing_record_id]

Retainer records reference no entries — the period defines them.
Fixed-fee projects produce no record from time — the fee lives in Zoho.
```

## Fields

| Field | Holds | Notes |
|---|---|---|
| `id` | Record identifier | |
| `project_id` | The project billed | FK |
| `period_start`, `period_end` | The date span covered | Explicit dates for both types. Retainer fills them with the month's first and last day; display shows "March 2026" when the range is a full month |
| `marked_at` | When you marked it invoiced | The snapshot moment |
| `billing_type` | `hourly` \| `retainer_overage` | Determines how the amount was derived and how to read the rest |
| `rate` | Hourly rate applied | Snapshot — the rate may change later |
| `calculated_amount` | What the math produced | The honest figure |
| `billed_amount` | What you actually invoiced | Defaults to `calculated_amount`, editable down |
| `absorbed_amount` | `calculated − billed` when positive | The recorded absorption decision |
| `billed_hours` | Hours behind `billed_amount` | Overage hours (retainer) / billable hours (hourly) |
| `allocation_hours` | The period's allocation | Retainer only; null for hourly. Snapshot, so the record can render "over 3h" without recomputing |
| `description` | The finalized line-item text copied to Zoho | Snapshot |

## The two record-producing paths

Both write the same record; they differ only in how the amount is derived and whether entries are referenced.

### Retainer overage — aggregate, period-scoped

- `billing_type = retainer_overage`
- `calculated_amount = (used_hours − allocation_hours) × rate`, computed across the period's entries
- `billed_amount` defaults to `calculated_amount`; lowering it is the absorption decision, stored in `absorbed_amount`
- **No entry links.** The manifest is implicit: project + period. Retainer entries carry no billing flags — the record is the only billing artifact for that work
- Normally one record per retainer per month. A late correction can add a supplemental (see "The unbilled remainder")
- `description` summarizes the period's work

### Hourly — per-entry sum

- `billing_type = hourly`
- The per-entry `billable` flag stays meaningful (this call billable, that internal task not)
- `calculated_amount = sum(billable hours in the invoiced set) × rate`
- Each included entry gets `billing_record_id` set to this record. That FK is the manifest, and it stops an entry being billed twice across records
- Can be multiple per period (a milestone now, the remainder later)
- `description` summarizes those entries' work

### Fixed-fee — no record from time

- The base fee is invoiced on a schedule in Zoho, not derived from time
- Out-of-scope work billed hourly creates an ordinary `hourly` record

## The unbilled remainder

One rule the *ready to invoice* surface runs on: it always shows **what's billable now minus what's already recorded as billed.** Records are the ledger; the surface shows the remainder. This single rule covers normal billing, partial billing, absorption, and late entries:

- **Hourly:** an entry with no `billing_record_id` is unbilled — it sits in the remainder until a record claims it. A late-added entry is therefore unambiguously unbilled; bill it as a new record.
- **Retainer:** recompute the month's overage and subtract the sum of what existing records for that month already billed. A positive gap (e.g., a late entry pushed you further over) surfaces as additional unbilled overage — bill a supplemental record or absorb it.

So "one record per period" is the normal case, not a hard constraint: a retainer has one billing *event* per month in the common case, and a second only when a late correction is genuinely billed. The supplemental is still aggregate and period-scoped — no entry links.

## Snapshot principle

Everything money- and context-related freezes at `marked_at` — amount, rate, hours, allocation, description. The record is a historical fact, not a live recomputation: editing a March entry in June must not rewrite what you billed in March. This mirrors how billable rates already snapshot at verification time.

## Out of scope (for now)

- **Fixed-fee base fees** — scheduled in Zoho, not derived from time.
- **Revenue totals and reporting** — stays in Zoho.
- **`invoice_ref` (Zoho invoice number).** Deferred. It would tie a record to its actual invoice, but it's manual entry (paste the number back after invoicing), adds friction to a tool built to avoid it, and there's no current need. Clean future add — an optional nullable field, no migration risk — if cross-referencing ever becomes real.
- **The ready-to-invoice surface itself** — a separate spec. The record is what its *Mark invoiced* button writes.

## Resolved decisions

1. **Manifest:** retainer = implicit (project + period, no entry links); hourly = explicit (`entry.billing_record_id`); fixed = no record. The "split" is just "entries link to records only when billing is per entry."
2. **Period storage:** explicit `period_start` / `period_end` for both types. No separate month field.
3. **Late entries:** handled by the unbilled-remainder rule (billable now − already recorded). Retainers allow a supplemental record for the period; no per-entry linking is added to retainers.
4. **`invoice_ref`:** deferred (see Out of scope).

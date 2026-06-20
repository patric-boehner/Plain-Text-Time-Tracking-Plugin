# Phase 1 — Prediction engine + finalize screen

The first build phase of the clean-slate rethink. Scoped deliberately to the daily-friction area: prediction quality and the finalize/processing screen. It touches only aliases, the client/project FKs on time entries, and tags. **It excludes the billing-record rework entirely** — that's deferred (see `billing-record-spec.md`).

Why here first: prediction is the highest-frequency friction (about half of entries need a client/project fix today, effectively all need a tag by hand), it's independent of the billing model, and it's reorg-safe. Most of it extends existing code rather than greenfield — alias seeding and project-learning build on `PLTT_Aliases` and the existing resolver; the one new piece is a small keyword→tag table for tag seeding.

---

## Scope

**In:** alias seeding, resolver project-learning, deterministic tag seeding, the new finalize screen.
**Out:** the billing-record model and everything downstream (records, overage, invoicing surfaces, EHR), the tag learner, capture-time autocomplete, bulk/multi-select editing.

---

## 1. Prediction engine

### Alias seeding
A chip manager on the **client and project settings forms**:
- Add aliases at creation time (chips you type in).
- See the aliases the system has already learned, each with its use-count and last-used date.
- Prune bad ones.

Deterministic — a seeded alias matches at full confidence. Extends the existing `PLTT_Aliases` rather than replacing it. This fixes the bootstrap problem: the learner only matures around month six, and seeding closes the day-one misses.

### Resolver project-learning
Today the resolver learns **client** only. Extend it to also learn **project** from review corrections — so a correction on the finalize screen teaches both the client and the project mapping.

Resolution flow is unchanged: alias → client, then project via a direct alias-to-project hit, falling back to the most-recent-active-project recency guess. A direct alias-to-project hit skips the recency guess.

---

## 2. Tags

Ship **seed-tags-at-creation**: deterministic keyword/phrase → tag seeding, seedable when a tag is created. Same *pattern* as alias seeding (keyword → target, deterministic, silent until there's signal), but it needs a small new keyword→tag mapping table — `PLTT_Aliases` only maps text → client/project, so there's no tag equivalent to extend. That one table is the only genuinely new storage in this phase.

**Defer the tag learner.** There's no training signal on a fully-manual corpus yet. Revisit only once a corpus has built up and tagging still feels like friction.

---

## 3. The new finalize screen

Reference artifacts: `processing-screen-wireframe.html` (interactive mock) and the "Processing" section of `surface-rethink.md`. Key behaviors:

- **One project picker, not two.** An entry is keyed to a *project*; client is the project's parent, shown for context. The picker is a searchable list of active projects grouped by client, with the resolved client's sibling projects floated to the top. Collapses to a "Client · Project" display.
- **Three resolution states**, shown visually: confident (solid, no check), guessed (dashed border + amber dot — records as-is if left, a nudge not a block), unset (amber-tinted row, "needs assigning" — still saves as unassigned rather than hard-blocking).
- **Header is nudge-don't-block.** Save all is never hard-blocked. The pill names the most-important outstanding item; the clear/green state requires no unset **and** no unconfirmed guess. Untagged never withholds green.
- **Tags collapsed and non-gating.** One picker, one or two tags per entry, no Activity/Flag UI split. A high-confidence prediction pre-fills dashed (records if left); below threshold stays empty rather than rubber-stamping.
- **Billable defaults once**, from the project type at parse. Changing the project later does **not** re-apply the default. Manual toggle stays for exceptions.
- **Time ranges and descriptions edit inline.**
- **Reprocessing locks finalized entries.** Re-running the parser leaves committed entries untouched and brings in only newly-detected timestamps as fresh drafts.

---

## 4. Build order

1. **Engine logic first** — seeding, resolver project-learning, seed-tags. This improves prediction quality immediately, even on the current screen.
2. **Then the presentation** — the guessed / unset / confident treatment and the to-confirm header counts go on the **new** finalize screen, not the current one, so confidence thresholds and visuals don't get tuned twice.

---

## Out of scope (this phase)

- The billing-record model and all downstream billing/reporting (deferred; see `billing-record-spec.md`).
- The tag learner (seed-tags only for now).
- Capture-time autocomplete (parked — trades against capture-first).
- Bulk tagging and multi-select editing (belong on a future all-entries screen, not the single-day finalize).

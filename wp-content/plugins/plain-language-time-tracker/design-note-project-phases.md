# Design Note: Project Phases

Status: **proposal / undecided** (June 2026)
Author context: written during the June 2026 tag-taxonomy cleanup, after phase-tagging Robin's redesign exposed a structural mismatch.

## The problem

Every time entry carries two independent facts we care about for project review:

- **What** it was — the *activity* (design, development, communication, meeting, support…), captured via tags.
- **When** it happened — the *phase* (kickoff → onboarding → strategy → design → development → launch → outbound-care).

These are two separate dimensions. For most activities they're orthogonal — you do communication, meetings, and support in *every* phase. The trouble is **`design` and `development` are names that appear in both dimensions**: they're activities *and* phases.

The data model can't represent that cleanly:
- A tag name is globally **unique** (`UNIQUE KEY name` on the tags table) and belongs to **one** group (`group_name` is a single column). So `design` can be an Activity tag *or* a Project-Phase tag, not both.
- They're our two heaviest activity tags, and the design+development span is the *bulk* of a build project.

### Symptoms observed
1. **Swimlane gap.** The Project Detail timeline draws phase lanes from the Project Phases group. `design`/`development` aren't in it, so the busy center of a build project renders empty.
2. **Activity pollution.** Interim fix was to tag every entry in the design/development *date window* with the `design`/`development` tag so the phase reads. That inflates activity totals — a client email during the design window now counts as "design hours." (On Robin, ~22 entries were real design work but ~53 carried the design tag.)

The root cause: **two facts per entry, one tag can only store one of them.**

## Options considered

### A. Per-entry phase tags, design/dev reused as phase markers — *current interim*
Tag each entry by its phase date-window; `design`/`development` double as both.
- ✅ No schema change; uses existing tag infra.
- ❌ Activity totals polluted (above). ❌ Requires hand-tagging phases on every entry, forever. ❌ Conflates "what" and "when" on the same tag.
- Verdict: fine as a stopgap to *look at* an arc; not trustworthy for measurement.

### B. Multi-group tags (a tag belongs to >1 group)
Let `design`/`development` live in **both** Activity and Project Phases (junction table `tag_group(tag_id, group_name)` instead of a single `group_name`).
- ✅ Cheapest way to fill the swimlane gap — the timeline already reads the Project Phases group; the tags just appear there too. No rendering change.
- ✅ Keeps the existing tagging model.
- ❌ **Doesn't fix measurement.** Multi-group changes how the single tag is *displayed/grouped*, not how many facts are *stored*. On an entry, `design` still can't distinguish "design work" from "happened in the design phase." Pollution (or, if un-polluted, incomplete by-phase totals) remains.
- ❌ Still requires manual per-entry phase tagging forever.
- ❌ Ripples into everything that assumes one group per tag (tag picker grouping, alias logic that matches on name, etc.).
- Verdict: a legitimate **display patch** for the swimlane gap; does not reach clean reporting.

### C. Phases as per-project date ranges — *recommended*
Phases stop being entry tags. Each project stores its phase boundaries (dates); an entry's phase is **derived** from `entry_date`.
- ✅ Activity tags stay clean — `design` = real design work, accurate hours.
- ✅ Exact, effortless phases — set once per project; every entry (past and future) gets its phase for free.
- ✅ No collision, no duplication, no pollution. Two real dimensions: activity (tagged) + phase (derived).
- ✅ Unlocks a **phase × activity cross-tab** ("in the design phase: 22h design, 6h meetings, 3h comms") — impossible with the tag approach.
- ✅ Fits the app's "capture first, categorize later" principle: zero decision at capture; phase boundaries set later, during review.
- ⚠️ Friction cost (see below): you must remember to set/update each project's phase dates. It's *once per project* (set during review), vs. *per entry forever* — but it's not zero.
- Verdict: the clean destination.

### D. Structural / PM-style phases — *future, only with a real PM app*
Phases as milestones containing tasks; time logs against tasks roll up to phases (how Harvest/Asana/ClickUp/Teamwork work).
- Most precise, but forces a categorization decision *at capture time* → violates capture-first. Only earns its friction once there are real PM needs (deliverables, dependencies, team). Out of scope until/unless the PM app happens; per-project date ranges migrate cleanly into it.

## How other tools do it (for context)
Two schools:
- **Structural / task-based** (Harvest, Toggl, Asana, ClickUp, Teamwork, monday): pick a task when logging; phases = task groupings. Decision at capture time.
- **Date-range / schedule-based** (Harvest Forecast, Float, agency resourcing tools): phases are time-boxed; an entry's phase is inferred from when it happened. No capture decision.

This app's capture-first identity makes the date-range school the natural fit, and gives us a second clean axis (activity + phase) that most single-axis tools don't have.

## The friction question (honest)
Date-range phases still ask you to remember to set/update boundaries per project. Mitigations worth building:
- **Default template** — seed the standard 7 phases (kickoff…outbound-care) on new projects; you just nudge the dates.
- **Auto-suggest boundaries** — activity tags already cluster by date (design appears Mar–May, development May). The system can *propose* phase boundaries from where the dominant activity shifts; you confirm/adjust. Turns "remember to fill this in" into "review a suggestion."
- **Optional** — projects with no phases just don't render bands. No obligation, graceful.

Net: friction drops from per-entry-forever to a one-time, suggestible, optional per-project setup.

## Build scope (Option C)

### Data
- New table `pltt_project_phases`: `id`, `project_id`, `phase_name varchar(100)`, `start_date date`, `sort_order int`. End date = next phase's `start_date` (last phase open-ended / clamps to project end). Created in `PLTT_Database::create_tables()` + a version-gated migration in `maybe_upgrade()`; bump `DB_VERSION`.
- Optional global default template in an option (`pltt_default_phases`) seeding new projects.
- Helper `pltt_get_entry_phase( $entry_date, array $phases ): ?string` — returns the phase whose window contains the date (null if before first / unphased).

### UI (Project Detail → Settings tab)
- A "Phases" editor: rows of `[phase name | start date]`, add/remove/reorder, "Apply standard template" button. Read-only derived end dates shown.
- Save via the existing form-handler pattern (`admin_post_` / nonce / `wp_safe_redirect`) or AJAX, consistent with current Settings handling.

### Reporting (Project Detail → Report tab)
- **Swimlane**: draw phase bands as background regions spanning each phase's date range across the timeline x-axis; entries render on top. Fills the center gap by construction.
- **Time-by-phase**: bucket each entry's minutes by derived phase; sum. Clean.
- **Phase × activity cross-tab**: optional follow-on; group by (phase, activity tag).

### Migration off the interim tags
- Remove phase-purpose tags from entries: the 5 phase-only tags (kickoff, onboarding, strategy, launch, outbound-care) and the `design`/`development` tags that were added to *non-design/dev* entries purely to mark phase. (Keep `design`/`development` on entries that are genuinely that activity.)
- Seed `pltt_project_phases` for Robin from the known boundaries (kickoff Jan20–27 [pre-system], onboarding Jan27–Feb26, strategy Feb26–Mar6, design Mar6–May4, development May5–29, launch May29–Jun9, outbound-care Jun10–).
- Retire the 5 phase-only *tags* once nothing references them (they become phase *names*, not tags). `wp_pltt_*_bak_tagcleanup` backups cover rollback.

### Edge cases
- Entries before first phase / after last → "unphased" bucket or clamp; surface, don't hide.
- Projects without phases → no bands, no by-phase section (graceful).
- Retroactive boundary edits → all reporting recomputes automatically (a feature, not a bug).
- Parallel/overlapping phases → table can store them but rendering gets complex; out of scope (assume sequential).

## Recommendation
Ship **Option C (per-project date ranges)** when phase reporting is worth a small build. If the *only* near-term itch is seeing the swimlane's middle, Option B (or just teaching the timeline to treat `design`/`development` as phase lanes) is an acceptable throwaway patch — but don't invest in it, since C deletes the per-entry phase-tagging work entirely. Avoid relaxing the unique-tag-name constraint (Option B's deeper form) — alias learning matches on tag name.

## Open decisions
- Table vs. serialized column for phase storage (table preferred for the PM future).
- Build the auto-suggest-boundaries helper now, or ship manual-entry first?
- Do care-plan / retainer (non-build) projects use phases at all, or is this build-projects-only? (VR audit + Postie remediation were deliberately left phase-less.)

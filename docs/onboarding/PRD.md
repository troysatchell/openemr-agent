# PRD — Clinical Co-Pilot (v1)

> **What this is.** The scope contract for v1: the problem, the user, what we're
> building, what we're deliberately *not*, and how we'll know it worked. It sits
> **before** [`ARCHITECTURE.md`](../../ARCHITECTURE.md) (what / who / why → then
> how) and points to [`USERS.md`](../../USERS.md) (repo root) for the full
> profile rather than restating it.
>
> **Deliberately thin.** The expensive thinking lives in the companion docs; this
> page exists to make scope and success *explicit*, not to repeat them.
>
> **Week 2 note (added 2026-07-15, reconciled with `W2_ARCHITECTURE.md`).**
> This page scopes v1 (Week 1: read-only orientation). Week 2 — the
> document-shaped week, `USERS.md` §5 UC6/UC7 — has its own
> acceptance-criteria-shaped seeds at
> [`docs/W2_PRD_SEEDS.md`](../W2_PRD_SEEDS.md), with rationale in
> [`W2_ARCHITECTURE.md`](../../W2_ARCHITECTURE.md): same "deliberately thin"
> split this page uses, not restated here. The one change to this page's own
> scope statements below: the write-back non-goal is narrowed by a
> founder-approved, two-write-wide amendment (2026-07-13) — see the
> non-goals and acceptance-criteria sections.

---

## Problem

A physician has ~90 seconds between patients to re-orient to the next one — who
they are, what's changed, what he must not miss, what was left open. He isn't
short on data; he's buried in it. Today that reconstruction is a cold, error-prone
re-read under time pressure — and the misses are invisible.

## User (and buyer)

**User:** Dr. Ellis Tran, general internist — see [`USERS.md`](../../USERS.md).
A **hypothesis, not yet validated.**
**Buyer:** likely *not* Ellis — a medical director / administrator who wants
different things (throughput, quality scores, liability, compliance), some of
which pull *against* what wins the user. **Which metrics we're held to is
unresolved — the top open question.**

## In scope — v1

Read-only **orientation** for the physician's own established patients
(use-case IDs per `USERS.md` §5):

- Overnight **pre-chart** of the day's panel *(UC3)*.
- A **glanceable between-patient snapshot**: who & why, what's changed since last
  visit, must-not-miss, the thread from last time *(UC1, UC4)*.
- **On-demand retrieval** during the visit; quiet otherwise *(UC2)*.
- Every surfaced item **traceable to its source** in the chart *(UC4)*.

## Explicitly NOT v1 (non-goals)

- **No write-back** — no notes, no orders, no meds. Highest risk; separately
  gated later (Phase 5). **Scoped Week 2 amendment (founder-approved
  2026-07-13):** attaching an uploaded source document to its patient, and
  persisting VLM-extracted facts as observations provenance-linked back to
  that document, are the two permitted writes — see `W2_ARCHITECTURE.md` §1
  and `CLAUDE.md` "Bright lines." Clinical write-back (notes, meds, orders)
  stays out.
- **No autonomous action.** It orients; the physician decides.
- **No external / cross-system records** — local EHR only. *Known limitation:*
  partially blinds the "what changed elsewhere" signal. Accepted for v1.
- **Not the covering / new-patient states** in the MVP — established-patient
  continuity first; those are a fast-follow (they matter more, and are harder).

## Acceptance criteria — *"v1 is built correctly"*

Binary / testable:

- **Zero writes** to the record, other than the two Week 2 writes named
  above (document-attach + provenance-linked derived observation) — both
  audit-logged and round-trip-verified, never clinical content.
- **Provenance on every surfaced item** (links to source).
- **Zero false negatives** on the deterministic critical subset — panic labs,
  drug–drug, drug–allergy, open follow-ups — across the golden-chart set
  (`ARCHITECTURE.md` §6).
- Judgment-based must-not-miss items meet the governed **recall** floor; flagged
  items meet the governed **precision** floor (no alert-fatigue storm).
- Snapshot renders inside the between-patient window (**p95 target owned
  in-house — set by the Phase 0 validation pass**).
- **Honest degraded mode:** when data is stale/missing or the model is unsure, it
  says so — never a silent wrong answer.
- Operates **only within the physician's ACL scope**.

## Success metrics — *"it worked in the world"*

Behavioral, not self-report (`USERS.md` §9):

- Walks into the room **without the laptop**.
- **Pajama time shrinks** (fewer notes closed after hours).
- Stops reflexively opening chart tabs.
- Comes back **unprompted** — including on stable patients, not just the scary ones.
- Trusts its **silence** (doesn't re-check "nothing changed").

*(Buyer metrics — throughput, quality-score capture — are different and partly in
tension; see open questions.)*

## Sequencing

**Validation gates the build — run in-house.** Phase 0 (decided 2026-07-07: no
external internist; the design-partner function is created in-house) is a
founder-run structured pass over the 90-second moment and the four needs *before*
we build UI (`ARCHITECTURE.md` §7). It keeps us from building unexamined for a
physician who may not exist — without pretending it equals real-clinician review.

## Open questions (blocking)

1. ~~Do we have a real **design-partner internist**?~~ **Decided 2026-07-07: no —
   Phase 0 is run in-house; real-clinician review is a post-MVP upgrade, not a
   gate.**
2. **User vs. buyer** — who signs off, on what value, and where do their metrics
   conflict? *(Owns the definition of success.)*
3. Who owns **clinical governance** of the critical-subset definition and the
   recall / precision floors?
4. What **latency** does the between-patient moment actually tolerate? *(p95, set
   during in-house Phase 0; revisit with a real clinician.)*

---

*Companion to [`USERS.md`](../../USERS.md) and [`ARCHITECTURE.md`](../../ARCHITECTURE.md).
Acceptance criteria are verified by the accuracy gate (`ARCHITECTURE.md` §6); the
user profile behind the success metrics is `USERS.md`.*

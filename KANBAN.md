# KANBAN.md — Clinical Co-Pilot Phase Board

> Source of truth for phase progression. Phases are `ARCHITECTURE.md` §7 —
> foundations first, **each phase gates the next**. Cards carry the audit
> finding IDs (`S#`/`D#`/`C#`, see `AUDIT.md`) and use cases (`UC#`,
> `USERS.md` §5) they answer to.
>
> Statuses: **Backlog → Up Next → In Progress → Done.** Move whole cards
> between sections; tick sub-items in place. Keep this file honest — a card is
> Done only when its gate is green in the main session, not when a subagent
> says so.

**🎯 Milestone — Early Submission:** Phases 0–2 defensible on paper + a
Phase 3 read-only snapshot demo.

---

## In Progress

### Phase 1 — Compliance & security *(TDD run 2026-07-08 — all coding tickets green; awaiting human review of flagged items)*
- [ ] LLM procurement decision: BAA + zero data retention (Anthropic direct vs. cloud BAA e.g. Bedrock) (C5) — **human decision, only open coding-run blocker for Phase 1 Done**
- [x] Minimum-necessary disclosure policy — enforcement done `bcfa44b` (payload + Disclosure born together; undisclosed sends unrepresentable). **Review:** `DraftPolicies::v1()` field lists are DRAFT pending human sign-off
- [x] Disclosure logging: `EventAuditLogger` external-AI category (C1/C5) — done `d761ad3` (12/12); production `forEventAuditLogger()` sink pending live-stack verification
- [x] Close S1 (error leak), S2/S3 (cookie hardening) — done `a5f298c`; live-verified over HTTP+HTTPS incl. full login smoke. **Review:** S2 knowingly degrades legacy multi-window re-login restore (restoreSession.php reads document.cookie); single-login unaffected
- [x] `oe-module-copilot` skeleton: module registration, event subscriptions, routes via `RestApiCreateEvent`, default-deny authz wrapper on every route (S5) — done `551eafa`; module not yet DB-registered, live route dispatch untested until enablement
- [x] FHIR/REST read path as the physician's own session (S4/S6) — done `bcfa44b` (typed PhysicianContext; anonymous reads unrepresentable); DB-backed adapter pending live-stack smoke

### Phase 2 — Data-trust substrate *(all coding tickets green; Done gate waits on Phase 1 review + detector-table sign-off)*
- [x] Identity resolution / dedupe (D7/D8) — green `551eafa`; conservative unknown-component rule
- [x] Activity/deleted filters (D10) — green `551eafa`; three-state currency, Unknown surfaced
- [x] Normalizers: empty-string (D1), booleans (D4), dates (D0/D6) — green `551eafa`
- [x] One-pass synthesis (D9) — done `d761ad3` (9/9); provenance SourceRefs on every item
- [x] Deterministic critical-subset detectors — done `3ec7d05` (35/35; unknown → unevaluable, never silent). **Review:** PanicThresholds/InteractionPairs/AllergyClassMap `draftV1()` clinical tables are DRAFT pending human sign-off
- [x] Golden-chart harness + CI accuracy gate — done `d51e546` (21/21 + `clinical-accuracy-gate.yml`, auto-required via all-checks-passed). Gate reports **NOT ARMED** until Phase 0 delivers adjudicated labels; synthetic fixtures never arm or fail it

**Run notes (2026-07-08):** frozen-test TDD loop — orchestrator authored+froze all tests (`af7d708`, `08d0f91`, `435b9c3`), agents/orchestrator implemented to green. Full isolated suite: 3694 tests, 0 failures, 0 regressions. PHPStan full-codebase run environmentally blocked locally (container OOM) — php -l + phpcs clean; PHPStan rides CI. One frozen-test transcription bug (ChartReaderTest spy plumbing) found by an engineer agent, fixed + documented `bcfa44b`.

---

## Up Next

### Phase 0 — Validate the user *(gates all phases)*
Founder-run, in-house (decided 2026-07-07); no external clinician — limitation stays named.
- [ ] Structured pass over the 90-second moment, the four needs, and the state mix (`USERS.md` §3) using the "→ Test by" prompts as the protocol
- [ ] Set the p95 latency tolerance target for the between-patient moment
- [ ] Produce adjudicated seed labels for the golden-chart set (feeds Phase 2)
- [ ] Write down residual risk R12 (persona unvalidated by a real clinician) in the output

---

## Backlog

*(Phase 1 and Phase 2 moved to In Progress — 2026-07-08.)*

### Phase 3 — Orientation MVP *(gated on the accuracy gate passing)*
Read-only, established patients only.
- [ ] Session-bound pre-chart: kicked from the live evening session or at-login warm-up (§4) — no offline grant in v1
- [ ] Glanceable between-patient snapshot — the opening turn (UC1)
- [ ] Multi-turn grounded follow-up Q&A — every turn re-grounds against the live chart (UC2)
- [ ] Provenance on every surfaced claim; unattributable claims not stated as fact (R6/R10)
- [ ] Module-injected chat panel UI with preserve-distrust UX: must-not-miss visually distinct, honest uncertainty, silence when nothing changed (R5/R11)
- [ ] Session-bound pre-chart of the day's schedule (UC3)

### Phase 4 — Covering / new-patient states + on-demand *(post-MVP)*
- [ ] Cold orientation for covering / new-patient states (UC5, S2/S3 states) — where degraded-mode matters most

### Phase 5 — Write-back *(deferred, separately gated — not v1)*
- [ ] Scope TBD; requires its own gate and explicit human sign-off

### Deferred (named, off the critical path)
- [ ] Unattended overnight batch via per-physician read-only offline grant (§4; blocked on OPEN_QUESTIONS #25 — does OpenEMR's SMART server support it as-is?)
- [ ] Real-clinician review of persona + golden-chart labels (highest-leverage upgrade when available)

---

## Done

- [x] Data-quality & security audit (`AUDIT.md`) — the evidence base
- [x] User & use-case definition (`USERS.md`, UC1–UC5, Dr. Ellis Tran persona — hypothesis, unvalidated)
- [x] Architecture & decisions to defend (`ARCHITECTURE.md`)
- [x] Fork deployed on Railway (openemr + mariadb)

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
- [x] LLM procurement decision (C5) — **decided 2026-07-09: Anthropic direct**; compliance terms stay program-stipulated. Doc correction shipped with T15 (`352b0a8`): "BAA + ZDR" is not a package on the first-party API (ZDR and HIPAA-ready orgs are mutually exclusive) — real-deployment posture is BAA + HIPAA-ready org + minimum-necessary + disclosure logging (ARCHITECTURE §4)
- [x] Minimum-necessary disclosure policy — enforcement done `892db37` (payload + Disclosure born together; undisclosed sends unrepresentable). **Review:** `DraftPolicies::v1()` field lists are DRAFT pending human sign-off
- [x] Disclosure logging: `EventAuditLogger` external-AI category (C1/C5) — done `9781399` (12/12); production `forEventAuditLogger()` sink pending live-stack verification
- [x] Close S1 (error leak), S2/S3 (cookie hardening) — done `87bd9b3`; live-verified over HTTP+HTTPS incl. full login smoke. **Review:** S2 knowingly degrades legacy multi-window re-login restore (restoreSession.php reads document.cookie); single-login unaffected
- [x] `oe-module-copilot` skeleton: module registration, event subscriptions, routes via `RestApiCreateEvent`, default-deny authz wrapper on every route (S5) — done `2a242a6`; module not yet DB-registered, live route dispatch untested until enablement
- [x] FHIR/REST read path as the physician's own session (S4/S6) — done `e3cbab2` (typed PhysicianContext; anonymous reads unrepresentable); DB-backed adapter pending live-stack smoke

### Phase 2 — Data-trust substrate *(all coding tickets green; Done gate waits on Phase 1 review + detector-table sign-off)*
- [x] Identity resolution / dedupe (D7/D8) — green `3065256`; conservative unknown-component rule
- [x] Activity/deleted filters (D10) — green `a644256`; three-state currency, Unknown surfaced
- [x] Normalizers: empty-string (D1), booleans (D4), dates (D0/D6) — green `27a6fc6`
- [x] One-pass synthesis (D9) — done `001eaa0` (9/9); provenance SourceRefs on every item
- [x] Deterministic critical-subset detectors — done `faeb658` (35/35; unknown → unevaluable, never silent). **Review 2026-07-08:** `draftV1()` tables re-based on cited references (ARUP Rev.46, drug labels — PHASE0.md §3a) and signed off by the acting clinical-governance owner as ONE decision with the §3a labels (PHASE0.md §3c). The sulfonamides grouping stays UNSOURCED (DA-4) — not signed off, never gated
- [x] Golden-chart harness + CI accuracy gate — done `b74cddf` (21/21 + `clinical-accuracy-gate.yml`, auto-required via all-checks-passed). **Gate is ARMED (2026-07-08)** on the §3a reference-grounded adjudicated set (`GoldenChart/adjudicated/`, `CriticalSubsetGateTest`); §3b judgment items stay PROVISIONAL and never gate; synthetic fixtures never arm or fail it. **Reworked 2026-07-09 to the two-track model (T15 `352b0a8`):** hard zeros gate (any miss, any false flag, any incorrect stated fact); precision/factual rates are monitors only; judgment rates are provisional regression thresholds, dormant until §3b adjudication (`HardZeroGateTest` frozen, 12 new tests; 275 copilot isolated green)

**Run notes (2026-07-08):** frozen-test TDD loop — orchestrator authored+froze all tests (one freeze commit per ticket branch), agents/orchestrator implemented to green; history structured as per-ticket feature branches merged --no-ff. Full isolated suite: 3694 tests, 0 failures, 0 regressions. PHPStan full-codebase run environmentally blocked locally (container OOM) — php -l + phpcs clean; PHPStan rides CI. One frozen-test transcription bug (ChartReaderTest spy plumbing) found by an engineer agent, fixed + documented in `fd71c60`.

### Phase 3 — Orientation MVP *(started 2026-07-08 evening — the accuracy gate it is gated on is ARMED and passing)*
Read-only, established patients only. Logic layer first (Wave 1, Sonnet-5 agent relay against orchestrator-frozen tests); wiring + UI are Wave 2.
- [ ] Glanceable between-patient snapshot — the opening turn (UC1). **Composer logic green** `feat/t13-snapshot-composer` (12 frozen tests: findings verbatim, honest-uncertainty sections, unknown-delta ≠ quiet, earned silence R5/R7). Remaining: route + panel rendering
- [ ] Multi-turn grounded follow-up Q&A — every turn re-grounds against the live chart (UC2). **Turn-loop core green** `feat/t12-turn-orchestrator` (13 frozen tests: fresh read per turn, disclosure-before-send C1, detector bypass R13, honest degradation R11; LlmClient is a port). Remaining: real LLM adapter (blocked on C5 endpoint decision), DB-backed ChartSnapshotProvider adapter
- [ ] Provenance on every surfaced claim; unattributable claims not stated as fact (R6/R10). **Verification layer green** `feat/t14-verification-layer` (13 frozen tests: all-or-nothing grounding, exact-match ReferenceIndex, one canonical token mint). Remaining: UI rendering of grounded vs rejected
- [ ] Module-injected chat panel UI with preserve-distrust UX: must-not-miss visually distinct, honest uncertainty, silence when nothing changed (R5/R11)
- [ ] Session-bound pre-chart: kicked from the live evening session or at-login warm-up (§4) — no offline grant in v1
- [ ] Session-bound pre-chart of the day's schedule (UC3)
- [ ] Latency instrumentation per `PHASE0.md` §2.4 (per-step timing, cache-hit flag, first-paint vs full-render, fallback signal)

**Escalation resolved 2026-07-09:** founder approved the `ref` citation-token field (opaque `sourceType:sourceId` row pointer, no PHI content) for each data class in `DraftPolicies::v1()` — implementation in Wave 2 (T16). Observability decision also locked: in-repo PHI-free JSONL trace, correlation ID passed explicitly (never ambient — S4's lesson), disclosure log joins on the correlation ID; no third-party trace sink (C4).

### Graded deliverables not yet started *(named 2026-07-09 so they are not discovered Saturday night — Sunday final)*
- [ ] Strict tool I/O schemas as the contract — every tool input/output validated against a declared schema; the contract, not the implementation, is the source of truth
- [ ] Runnable API collection (Postman/Bruno) covering the core agent endpoints — graders must be able to run any workflow without reading source
- [ ] Load/stress tests at 10 and 50 concurrent users against the deployed agent; p50/p95/p99 + error rate at each level
- [ ] Baseline CPU/memory/latency/throughput captured under those load scenarios
- [ ] AI cost analysis at 100 / 1K / 10K / 100K users — architecture shifts per tier (extractable inference service §2, P3 pooling, P5 async audit sink, P6 index, provider-by-PHI-residency), not cost-per-token × n
- [ ] Verify domain-constraint enforcement is truly covered by `ClaimVerifier` — source attribution (claim traces to a record) vs contradiction rejection (response conflicts with the record/detector findings) are different requirements; if only the former exists, the latter is unbuilt and graded

---

## Up Next

### Phase 0 — Validate the user *(gates Phase 3 and the arming of the accuracy gate — not the audit-driven Phases 1–2)*
Founder-run, in-house (decided 2026-07-07); no external clinician — limitation stays named. H1/H2/H10 are program-stipulated (case-study PDF), so Phase 0 no longer gates anything in-project; R12 stays open for the real world.
- [x] Structured pass over the 90-second moment, the four needs, and the state mix (`USERS.md` §3) using the "→ Test by" prompts as the protocol — `PHASE0.md` §1
- [x] Set the p95 latency tolerance target for the between-patient moment — `PHASE0.md` §2 (STIPULATED, not validated)
- [x] Produce adjudicated seed labels for the golden-chart set — `PHASE0.md` §3a candidates signed off 2026-07-08 by the acting clinical-governance owner; fixtures in `GoldenChart/adjudicated/`
- [x] Write down residual risk R12 (persona unvalidated by a real clinician) in the output — `PHASE0.md` §4 (stays OPEN)

---

## Backlog

*(Phase 1 and Phase 2 moved to In Progress — 2026-07-08. Phase 3 moved to In
Progress the same evening — the accuracy gate it was gated on is ARMED and
passing.)*

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

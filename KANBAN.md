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
- [x] Glanceable between-patient snapshot — the opening turn (UC1). **Composer logic green** `feat/t13-snapshot-composer` (12 frozen tests: findings verbatim, honest-uncertainty sections, unknown-delta ≠ quiet, earned silence R5/R7). **Session snapshot endpoint + in-panel rendering done 2026-07-09 (T21 `feat/copilot-session-panel`):** `SnapshotEndpoint` wire-shapes the composer output (TurnEndpoint conventions, refs via the one token mint; FhirReadFailedException → explicit degraded shape, never a fabricated quiet), rendered on patient selection in the in-EMR panel
- [ ] Multi-turn grounded follow-up Q&A — every turn re-grounds against the live chart (UC2). **Turn-loop core green** `feat/t12-turn-orchestrator` (13 frozen tests: fresh read per turn, disclosure-before-send C1, detector bypass R13, honest degradation R11; LlmClient is a port). **Anthropic-direct adapter green 2026-07-09 (T18 `b9730ad`,** 10 frozen tests: schema-constrained claims, served-model + token/cost capture, unavailable-vs-config error split, injected transport so the wire contract tests without a network; live Guzzle factory pending live-stack smoke). **DB-backed read path green 2026-07-09 (T20 `10e98c7`,** 10 frozen tests: FHIR→data-trust mapping with unmappable rows counted never silent, pid from the uuid registry never FHIR content D7, fail-loud on patient conflation D8, live-path citability into the verifier index; real OpenEMR FHIR shapes pending Wave-3 smoke)
- [x] Provenance on every surfaced claim; unattributable claims not stated as fact (R6/R10). **Verification layer green** `feat/t14-verification-layer` (13 frozen tests: all-or-nothing grounding, exact-match ReferenceIndex, one canonical token mint). **UI rendering done (T20):** grounded claims show surviving citations; rejected claims render explicitly unverified, text only — invented citations never forwarded as provenance
- [x] Module-injected chat panel UI with preserve-distrust UX — done 2026-07-09 (T20 `10e98c7`): guarded `POST /api/copilot/turn` (`patients`/`med` ACL, S5 wrapper) + self-contained `public/panel.html` (must-not-miss loud with citations, honest-uncertainty section, grounded vs rejected distinct, degraded banner, explicit earned-silence state R5, correlation-id footer). Live route dispatch + real FHIR shapes pending Wave-3 smoke
- [ ] Session-bound pre-chart: kicked from the live evening session or at-login warm-up (§4) — no offline grant in v1
- [x] Session-bound pre-chart of the day's schedule (UC3) — done 2026-07-09 (T21): **in-EMR "Co-Pilot" menu tab** (MenuEvent seam, ACL-gated) opens a session-bound panel — no bearer token, no manual uuid; `SessionGate` (default-deny session analogue of the S5 route wrapper: CSRF → ACL → named principal, fail closed) fronts every AJAX action; `TodayScheduleEndpoint` shapes the provider's day from `AppointmentService::search` under D0/D1/D6/D7 (nameless/uuid-less/untimed rows carried honestly, never dropped); picking a patient renders the UC1 snapshot, then free-text turns reuse `TurnEndpoint` unchanged. 67 frozen tests (copilot suite 316 → 383); live Selenium smoke green (tab → panel → schedule AJAX in session; bogus CSRF 403)
- [ ] Latency instrumentation per `PHASE0.md` §2.4 (per-step timing, cache-hit flag, first-paint vs full-render, fallback signal). **Per-step timing + failure/degraded tracing green 2026-07-09 (T17 `cf343d7`):** PHI-free JSONL trace, correlation ID minted at the orchestrator choke point and passed explicitly (never ambient — S4), disclosure log joins on the ID; guarded `/health` + `/ready` (real db/trace-sink probes, 503 when not ready). Remaining: cache-hit flag (lands with UC3 pre-chart), first-paint vs full-render (needs the UI)
- [x] Observability dashboard + alert definitions — done 2026-07-09 (T19 `7c9e319`): `TraceDashboard` aggregates the JSONL alone (turns, error/degraded rates, nearest-rank p50/p95, per-step counts, verifier verdict counts via new `grounded_count`/`rejected_count` on the ground step, token/cost rollups; malformed lines counted; retry/queue-depth reported N/A with reasons); CLI `bin/trace-dashboard.php`; `docs/OBSERVABILITY.md` runbook with 3 alert definitions (provisional thresholds, UNSOURCED pending load-test baselines)

**Escalation resolved 2026-07-09:** founder approved the `ref` citation-token field (opaque `sourceType:sourceId` row pointer, no PHI content) for each data class in `DraftPolicies::v1()` — **implemented same day (T16 `87b9426`;** `DraftPoliciesRefFieldTest` pins the signed-off lists). Observability decision also locked and implemented (T17/T19): in-repo PHI-free JSONL trace, correlation ID passed explicitly (never ambient — S4's lesson), disclosure log joins on the correlation ID; no third-party trace sink (C4).

**Run notes (2026-07-09, Wave 2):** same relay protocol as Wave 1 (orchestrator freezes tests red → Sonnet-5 agent implements → orchestrator re-verifies frozen diff + suite + phpcs → --no-ff merge); T15 gate rework, T17 observability, T18 adapter via agents; T16 ref (governance file) and T19 dashboard inline by the orchestrator; T20 live read path + turn endpoint via agent with Bootstrap wiring + panel inline. Copilot isolated suite: 263 → 316 tests. **Wave-3 live smoke PASSED on production Railway (2026-07-09 evening):** scope-registration bug found+fixed (`2142ecb` — module routes 401ed because core derives required OAuth scopes from route segments and rejects unregistered ones); after fix: /health + /ready green (db/trace_sink/llm all true), demo patient seeded via standard API, FHIR read path returned the 3 seeded meds, and a full POST /api/copilot/turn returned HTTP 200 in 6.7s — DDI must-not-miss fired (warfarin+aspirin), 4 grounded claims with resolvable MedicationRequest/AllergyIntolerance refs, zero rejected, correlation id present ⇒ production disclosure sink + trace + Anthropic adapter all verified live. Known data quirk: title-only allergies surface as FHIR data-absent-reason "unknown" so the drug-allergy detector needs a CODED allergy to fire live (system honestly reported the unknown substance — R11 working as designed). PHPStan L10 spot-checked in-container with the 2G memory workaround on new/changed files; full run rides CI.

**Run notes (2026-07-09 late, T22 demo polish):** founder-driven UX wave. `docs/DESIGN.md` added — named design persona (Priya Desai) + fixed interaction-state vocabulary (idle/working/quiet/degraded/denied), DRAFT pending founder validation. Panel: working states everywhere (Ask → "Asking…", snapshot → "Reading the live chart…"), turn output collapses findings identical to the snapshot's into one "re-checked — unchanged" line (R13 visible, alert fatigue budgeted). Demo made self-sufficient: appointments seeded 14 days out (idempotent top-up via `DAYS=`), per-patient showcases (Reyes: panic lab + DDI + new-labs delta; Mendoza: coded drug–allergy conflict + undated-lab unevaluable; Park: earned quiet), synthetic labs via clearly-labeled direct SQL in setup tooling (`labs` step, `control_id='copilot-demo'`, rerun-safe) because OpenEMR has NO REST/FHIR lab write surface. **Real data-trust gap found by the showcase and fixed:** the T20 mapper dropped ABSENT-dated labs as unmappable; now three-state (parseable → carried; absent → carried undated for the composer's unevaluable path, D0/D6; garbage string → still unmappable) — new frozen pin in `ReadThroughChartSnapshotProviderTest`, suite 383 → 384. All three showcases + turn dedupe + working states verified live on production as dr.tran via Selenium.

**Run notes (2026-07-09, T21 physician panel):** same freeze protocol — orchestrator authored+froze the 3 Panel test files red (`876d799`, 67 tests), Sonnet agent implemented the pure surface to green first-pass (`067541e`), second agent wired the glue (`3b8c95d`: MenuEvent tab, `public/index.php` + `public/ajax.php` session endpoints through SessionGate, demo-seed provider/schedule steps), orchestrator re-verified frozen diff empty + 383-test suite + phpcs in the main session and ran a **live Selenium smoke** on the dev stack: Co-Pilot tab present in the main menu and clickable, panel renders in an EMR tab, schedule AJAX round-trips inside the logged-in session, bogus CSRF token → 403 generic body. The `require globals.php` in the two module entry pages is the sanctioned module-page session bootstrap (cf. faxsms) — named tension with the no-globals bright line, whose intent is CLI/batch reads (S4). Unverified until live seeding: dr.tran demo flow end-to-end (needs the manual provider-creation step + `DR_PASS`), snapshot/turn AJAX against a patient with chart data. PHPStan rides CI.

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

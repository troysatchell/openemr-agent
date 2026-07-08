# Pre-Search Checklist — Clinical Co-Pilot Agent

> **What this is.** The pre-coding checklist, completed *before* writing agent
> code, used as the reference backing our architecture defense. Every answer is
> grounded in work already done — it does not invent new decisions, it records
> and cites them. Sources: [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) (the
> hard-gate deliverable), [`PRD.md`](PRD.md) (scope contract),
> [`USERS.md`](../../USERS.md) (persona — **unvalidated**; repo root),
> [`AUDIT.md`](../../AUDIT.md) (the audit; Part 0 is the AI-ranked
> prioritization; finding IDs
> `S#`/`P#`/`D#`/`C#`), [`CURRENT_ARCHITECTURE.md`](CURRENT_ARCHITECTURE.md)
> (as-found system). Baseline commit `859d6d3`.
>
> **Epistemic honesty carries over.** Where the source docs mark something
> **OPEN** or **unvalidated**, this checklist does too — we defend our decisions
> *and* name what we haven't resolved. Nothing here is implemented yet.

---

## How to read the status tags

- **DECIDED** — committed in `ARCHITECTURE.md` / `PRD.md`; defend it.
- **OPEN** — a real unknown, tracked in the source docs; do not paper over.
- **N/A → real answer** — the checklist's stock option (LangChain, LangSmith,
  GPT-5, etc.) assumes a Python-agent-framework shape that does **not** fit an
  in-repo PHP EHR module. We give the actual decision and why the stock option
  doesn't apply.

---

# Phase 1: Define Your Constraints

## 1. Domain Selection

| Question | Answer | Status |
|---|---|---|
| Which domain? | **Healthcare — outpatient EHR** (OpenEMR). Not a general medical chatbot: a patient-specific **orientation aid** embedded in the record. | DECIDED |
| Specific use cases | v1 read-only orientation for the physician's **own established patients**: (a) overnight **pre-chart** of the day's panel (UC3), (b) glanceable **between-patient snapshot** (who & why · what changed · must-not-miss · thread from last time) (UC1/UC4), (c) **on-demand retrieval** during the visit, quiet otherwise (UC2). Covering / new-patient states (S2/S3 — UC5) and write-back are explicit **non-goals** for v1. | DECIDED (`PRD.md`; use cases `USERS.md` §5) |
| Verification requirements | **Zero false negatives** on a deterministic **critical subset** (panic labs, drug–drug, drug–allergy, open follow-ups) enforced *in code*; **provenance on every surfaced claim**; governed recall/precision floors on judgment items; measured against a human-adjudicated **golden-chart set** (founder in v1; clinician review pending) before delivery. | DECIDED (`ARCHITECTURE.md` §6) |
| Data sources | **Local EHR only**, read **exclusively via the FHIR/REST surface** (the one validated, paginated, uuid-resolved read path) → MySQL/MariaDB. **No** raw legacy tables, **no** `globals.php` bootstrap, **no** external/cross-system records in v1 (accepted blind spot). | DECIDED (`AUDIT.md` "Clean read path"; `PRD.md` non-goals) |

## 2. Scale & Performance

| Question | Answer | Status |
|---|---|---|
| Expected query volume | Per physician ≈ **22 encounters/day**, real prep concentrated in ~5 complex/changed patients. Two workloads: a nightly **batch** pre-chart of **the scheduled day** (the next day's booked encounters incl. likely no-shows — patients he has a treatment relationship with, **not** the full ~2,000 panel) and **interactive** between-patient/on-demand lookups. **v1 runs the batch session-bound** (from his live evening session or an at-login warm-up); true unattended batch is deferred (§12, `ARCHITECTURE.md` §4). Single-clinic scale for v1. | DECIDED (volume from `USERS.md` §7; treat as archetype assumption until Phase 0) |
| Acceptable latency | Interactive snapshot must render inside the **~90-second between-patient window**; **p95 target is OPEN — set during in-house Phase 0 (no external design partner, decided 2026-07-07); revisit with a real clinician**. Batch pre-chart is overnight, latency-insensitive. | OPEN (`PRD.md` acceptance; `ARCHITECTURE.md` §9) |
| Concurrent users | v1 is single-clinic; concurrency bounded by clinic headcount, not internet scale. Inference is coupled to the PHP runtime (accepted v1 tradeoff — see §5). | DECIDED (implication of Decision 1) |
| Cost constraints | The **overnight scheduled-day pre-chart is the single biggest cost *and* exposure driver** (it includes likely no-shows he may never see). Mitigation = **minimum-necessary prompts** + one synthesis pass per patient, not per-source. Absolute per-query budget: OPEN. | DECIDED direction / OPEN budget (`AUDIT.md` C5) |

## 3. Reliability Requirements

| Question | Answer | Status |
|---|---|---|
| Cost of a wrong answer | **High and asymmetric.** Two failure directions: **commission** (a wrong fact shown → one-strike trust loss, R6) and **omission** (a must-not-miss item never surfaced → patient harm, invisible, R13). The physician **stays liable**, so churn is largely one-strike. | DECIDED (`ARCHITECTURE.md` §5; `USERS.md` §8–9) |
| Non-negotiable verification | **Zero misses on the deterministic critical subset**, guaranteed by rules in the data-trust/synthesis layer (not model judgment); **provenance at point of use** on every claim. A build change that misses on the critical subset **fails the build**. | DECIDED (`ARCHITECTURE.md` §6) |
| Human-in-the-loop | **Always.** The agent orients; the **physician decides**. Never autonomous, **no write-back** in v1. This is both the adoption strategy and the regulatory posture (reviewable-basis CDS, off the FDA device line). | DECIDED (`ARCHITECTURE.md` Exec Summary, §1) |
| Audit / compliance | **BAA + Zero-Data-Retention** at the LLM boundary; **minimum-necessary** disclosure; **every disclosure logged** via `EventAuditLogger` under a new *external-AI* category; enable ATNA/SIEM shipping in real deployments (logs aren't tamper-resistant, C1). Close breach precursors **S1/S2/S3** before exposing the surface. | DECIDED (`AUDIT.md` C5/C1/S1–S3; `ARCHITECTURE.md` §3–4, §7) |

## 4. Team & Skill Constraints

| Question | Answer | Status |
|---|---|---|
| Familiarity with agent frameworks | The build deliberately **avoids** heavyweight agent frameworks (see §5) — orchestration is plain in-repo PHP over OpenEMR's module/event system, which the team is working in daily. Lower framework-risk by design. | DECIDED direction |
| Domain / clinical experience | We have the **system** expertise (the as-found audit is deep) but **no validated clinical user** — no physician has been interviewed, and (decided 2026-07-07) none will be recruited this sprint: **Phase 0 is run in-house — the design-partner function is self-created, the no-clinician limitation named.** Clinical governance owner is the **founder by default in v1** (a named gap, not an answer). | OPEN (`USERS.md` epistemic banner; `ARCHITECTURE.md` §9) |
| Comfort with eval/testing | The repo already runs a **golden/snapshot-fixture discipline** (Twig render, layout-field HTML) and PHPStan-level-10 CI — the accuracy gate reuses that muscle (red/green loop applied to clinical output). Golden-set adjudication is **founder-run in v1** (no clinician — the named limitation); real-clinician review of the labels is the pending non-engineering dependency. | DECIDED direction (`CURRENT_ARCHITECTURE.md` §7; `ARCHITECTURE.md` §6) |

---

# Phase 2: Architecture Discovery

## 5. Agent Framework Selection

| Question | Answer | Status |
|---|---|---|
| LangChain / LangGraph / CrewAI / custom | **Custom, in-repo.** Those are Python-agent frameworks; our agent is an **OpenEMR custom module (`oe-module-copilot`)** registered through the sanctioned module/event system (`openemr.bootstrap.php` → subscribe events + add routes via `RestApiCreateEvent`), **no core edits**. Orchestration (retrieval → data-trust → synthesis → prompt assembly) is in-module PHP; **only model inference is external.** Chosen over a standalone service to **reuse OpenEMR's auth/ACL/session/data access** and avoid a second PHI store. | DECIDED (`ARCHITECTURE.md` §2, Decision 1) |
| Single vs multi-agent | **Single agent, single synthesis pass — deliberately not multi-agent.** The dangerous errors (drug–drug, drug–lab) live *between* sources (the seam, D9); isolated "summarize meds" / "summarize labs" sub-agents would let interactions fall through the seam. Reconcile meds+labs+allergies in **one pass**. | DECIDED (`ARCHITECTURE.md` §3.3; `AUDIT.md` D9) |
| State management | **Reuse OpenEMR's** — the agent runs inside the physician's authenticated session and ACL scope; no new session/state store, no new patient-data store. | DECIDED (Decision 1 & 3) |
| Tool integration complexity | Bounded: FHIR/REST read tools + deterministic critical-subset detectors + a provenance resolver. Complexity is in the **data-trust layer** (identity/dedup, activity filtering, empty-string/boolean normalization), not in agent plumbing. | DECIDED (`ARCHITECTURE.md` §3) |

## 6. LLM Selection

| Question | Answer | Status |
|---|---|---|
| GPT-5 / Claude / open source | **Claude** (Anthropic), procured under **BAA + Zero-Data-Retention** (available via Anthropic direct or Bedrock). The gating requirement is *compliance-capable inference*, not a specific model family — any candidate must offer BAA+ZDR + tool use. Reliable **on-prem de-identification is not achievable** here (D7/D8), so we do not architect around a local model as a privacy substitute. | DECIDED direction (`AUDIT.md` C5) |
| Function/tool calling support | **Required** — the model calls read/detector tools and must ground output against provenance. | DECIDED |
| Context window needs | Enough for a **reconciled single-patient chart**, held to **minimum-necessary** fields — not the whole panel per prompt. The batch pre-chart fans out per patient, not one giant context. | DECIDED (`AUDIT.md` C5) |
| Cost per query acceptable | Direction set (minimum-necessary + per-patient synthesis); **absolute budget OPEN**, tied to the p95 latency decision from in-house Phase 0. | OPEN |

## 7. Tool Design

| Question | Answer | Status |
|---|---|---|
| What tools the agent needs | (a) **FHIR/REST read tools** — Patient, Encounter, medications, labs, allergies, problems; (b) **deterministic critical-subset detectors** — panic labs, drug–drug, drug–allergy, open follow-ups (rules, *not* the model); (c) a **provenance resolver** attaching a chart source to every claim. | DECIDED (`ARCHITECTURE.md` §3, §6) |
| External API dependencies | **Exactly one** external dependency: the LLM inference endpoint (outside the trust boundary). No other third-party calls in v1. | DECIDED (`ARCHITECTURE.md` §2 diagram) |
| Mock vs real data for dev | Develop/eval against the **golden-chart fixture set** (curated cases across the states + the audit's interaction landmines). Real reads go through the same FHIR surface in the dev stack. | DECIDED (`ARCHITECTURE.md` §6) |
| Error handling per tool | **Honest degraded mode** — when a read is stale/missing or a detector can't run, say so; **never a silent wrong answer**. Bites hardest in S2 (covering), where the physician has no memory fallback. | DECIDED (`ARCHITECTURE.md` §5 R11; `USERS.md` §8) |

## 8. Observability Strategy

| Question | Answer | Status |
|---|---|---|
| LangSmith / Braintrust / other | **N/A as products** — observability is in-repo. **Disclosure logging** via `EventAuditLogger` (new external-AI category) is the compliance-grade trace; the **golden-chart eval harness** is the correctness trace. A dedicated LLM-tracing tool can be layered later but is not load-bearing for v1. | DECIDED direction (`AUDIT.md` C1) |
| Metrics that matter most | **Recall on must-not-miss** (zero-miss critical subset), **precision on flagged** (alert-fatigue guard), **factual accuracy on shown claims**, **snapshot p95 latency**, **cost/disclosure count**. | DECIDED (`ARCHITECTURE.md` §6 table) |
| Real-time monitoring | Watch **omission leading indicators** in production: click-through to *un-surfaced* data, overrides, "why didn't it show X." These feed the golden set (the ratchet). | DECIDED (`ARCHITECTURE.md` §6 "Online") |
| Cost tracking | Per-disclosure logging already captures the exposure surface; batch pre-chart is the line item to watch (biggest volume). Async/batched audit writes to keep it off the hot path (P5). | DECIDED direction (`AUDIT.md` P5/P6) |

## 9. Eval Approach

| Question | Answer | Status |
|---|---|---|
| How correctness is measured | Scored **in two directions per (chart-state, visit)**: **omission** (R13) and **commission** (R6), against the golden-chart set, **before** output reaches the physician. | DECIDED (`ARCHITECTURE.md` §6) |
| Ground-truth sources | The **golden-chart set**: per case, the human-adjudicated **must-not-miss set** + the **key facts** to state correctly (founder-adjudicated in v1; clinician review pending). Seeded by the in-house Phase 0 pass; grows from curated cases + **production near-misses** (ratchet — once missed, never silently missed again). | DECIDED (`ARCHITECTURE.md` §6) |
| Automated vs human | **Both.** Automated gate runs the golden set on every build; a **human adjudicates** labels and the critical-subset definition — the founder in v1, with real-clinician review the named upgrade. | DECIDED |
| CI integration | **Yes — the gate *is* CI.** Any build change re-runs the golden set; **any critical-subset miss or below-floor metric fails the build.** | DECIDED (`ARCHITECTURE.md` §6 "The gate") |

## 10. Verification Design

| Question | Answer | Status |
|---|---|---|
| Claims that must be verified | **Every surfaced claim** carries provenance to its chart source (defends what *is* shown + satisfies FDA reviewability). Provenance **cannot** reach omission — hence the accuracy gate for what could be *missed*. | DECIDED (`ARCHITECTURE.md` §5–6) |
| Fact-checking data sources | The chart itself, via the **data-trust layer** (identity resolution/dedup D7/D8, `activity` filtering D10, empty-string/boolean normalization D0/D1/D4) — reconciliation happens **before** the model sees anything. | DECIDED (`ARCHITECTURE.md` §3.2) |
| Confidence thresholds | **Governed floors**, set by the in-house clinical-governance owner (founder in v1): zero-miss on the critical subset (hard), recall floor on judgment must-not-miss, precision floor on flagged items. Values TBD in Phase 0; revisit with a real clinician. | DECIDED mechanism / OPEN values |
| Escalation triggers | Honest uncertainty → **degraded mode** ("data is stale/missing / I'm unsure"); a below-floor metric or critical miss escalates to a **build failure**; production omission indicators escalate a case **into the golden set**. | DECIDED (`ARCHITECTURE.md` §6) |

---

# Phase 3: Post-Stack Refinement

## 11. Failure Mode Analysis

| Question | Answer | Status |
|---|---|---|
| When tools fail | Fail **loud, not silent** — honest degraded mode; the physician is told orientation is incomplete rather than shown a confident-but-wrong summary. | DECIDED (`ARCHITECTURE.md` §5 R11) |
| Ambiguous queries | Surface uncertainty and provenance rather than guessing; the physician decides (human-in-the-loop is the resolver). | DECIDED |
| Rate limiting / fallback | Single external dependency (LLM); on inference failure the module degrades to "no summary available, here are the source links," never a fabricated one. | DECIDED direction |
| Graceful degradation | Design principle across states, **worst-case S2 (covering)** where there's no memory fallback — degraded mode must be especially explicit there. | DECIDED (`USERS.md` §3/§8; `ARCHITECTURE.md` §5 R11) |

## 12. Security Considerations

| Question | Answer | Status |
|---|---|---|
| Prompt injection | **The chart is untrusted input** (free-text notes can carry injection). The **LLM sits outside the trust boundary** and its output is **unverified until grounded against provenance**; the deterministic critical subset never depends on model-followed instructions. It never receives credentials or DB access. | DECIDED (`ARCHITECTURE.md` §4) |
| Data leakage | The agent acts as the physician **by delegation, not impersonation** — its authority is always his own, confined to his ACL/facility scope, **never a superuser/population-wide service account** — so it **cannot surface more than he's already entitled to see** (not an exfiltration path). **v1 is session-bound** (auth = his live session); the deferred unattended-batch path uses a **per-physician read-only offline grant** (short-lived tokens, scope re-derived from current ACL), never a batch service identity. Only **minimum-necessary** fields cross to the LLM. | DECIDED (`ARCHITECTURE.md` §4, Decision 3) |
| API key management | LLM endpoint credentials live in server-side config, **never in the DB, never sent to the model**. The deferred offline-grant **refresh secret follows the same rule** — held in a secrets manager, never the DB, revoked at offboarding (`ARCHITECTURE.md` §4). | DECIDED direction |
| Audit logging | Every PHI→LLM disclosure logged (`EventAuditLogger`, external-AI category); **per-route default-deny** — every module route calls `request_authorization_check` (OpenEMR has no default-deny gate, S5); close **S1** (`$e->getMessage()` leak), **S2** (cookie not HttpOnly), **S3** (`cookie_secure` off) before exposure. | DECIDED (`ARCHITECTURE.md` §4, §7 P1; `AUDIT.md` S1–S3/S5/C1) |

## 13. Testing Strategy

| Question | Answer | Status |
|---|---|---|
| Unit tests for tools | Yes — the **deterministic detectors** and the **data-trust normalizers** are pure logic and unit-tested (the highest-stakes items are code, not model, precisely so they're testable). | DECIDED (`ARCHITECTURE.md` §6.2) |
| Integration tests for agent flows | Golden-chart cases exercise retrieval → data-trust → synthesis → provenance end-to-end against the FHIR surface. | DECIDED |
| Adversarial testing | Target the **audit's landmines as attack cases**: duplicate-patient bleed (D8), stale-med-reads-as-active (D10), empty-string-as-complete (D1), chart free-text prompt injection, cross-source seam interactions (D9). | DECIDED (`AUDIT.md` "Rules for the AI"; §5 R2–R4) |
| Regression testing | The **golden-chart set is the regression suite** and it **ratchets** — production near-misses become permanent cases, so a fixed omission can never silently recur. | DECIDED (`ARCHITECTURE.md` §6) |

## 14. Open Source Planning

| Question | Answer | Status |
|---|---|---|
| What to release | **OPEN.** OpenEMR is GPL-3; a module that plugs into it inherits licensing gravity. Whether the co-pilot module is released, and how much, is not yet decided. | OPEN |
| Licensing considerations | Module builds on GPL-3 OpenEMR → derivative-work implications to resolve before any release. PHI, the golden-chart set, and clinical governance content are **not** releasable. | OPEN |
| Documentation requirements | The onboarding doc set (`ARCHITECTURE.md`, `PRD.md`, `USERS.md`, the audit) is the seed; a public release would need install/config + safety-scope docs. | OPEN |
| Community engagement | **OPEN** — depends on the release decision. | OPEN |

## 15. Deployment & Operations

| Question | Answer | Status |
|---|---|---|
| Hosting approach | **Railway**, building **this fork's local tree** (not upstream) via `deploy/railway/Dockerfile` (fork-aware release image; PHP 8.5 / Alpine / Apache) with a separate MariaDB service (`deploy/railway/mariadb/Dockerfile`); wired by [`../../railway.json`](../../railway.json). Persistence via a Railway Volume on `sites/` (no Dockerfile `VOLUME`). | DECIDED (deploy artifacts on disk) |
| CI/CD for updates | Existing **~55 GitHub Actions** workflows (PHPStan L10, Rector, phpcs, Semgrep, hadolint, PHPUnit suites) + **GitLab SAST/Secret Detection** (`.gitlab-ci.yml`). *Which is the merge-gating source of truth is an* **OPEN** *repo question.* The **accuracy gate** is the new, product-specific CI stage (§9). | DECIDED infra / OPEN gating (`OPEN_QUESTIONS.md` #2) |
| Monitoring & alerting | Disclosure logging + omission leading indicators (§8); Railway restart policy. Real deployments ship audit logs to ATNA/SIEM (logs aren't tamper-resistant, C1). | DECIDED direction |
| Rollback strategy | Railway `restartPolicyType: ON_FAILURE` (max 3 retries) + standard image rollback to the prior build; the fork-aware image makes a known-good redeploy deterministic. | DECIDED (`railway.json`) |

## 16. Iteration Planning

| Question | Answer | Status |
|---|---|---|
| Collecting user feedback | **In-house Phase 0 validation** is the v1 channel (no external design partner — decided 2026-07-07; a real clinician is the named upgrade); behavioral success signals from `PRD.md` (walks in without the laptop, pajama-time shrinks, returns unprompted, trusts the silence) — **not self-reported "time saved,"** which he won't perceive. | DECIDED (`PRD.md` success metrics; `USERS.md` §9) |
| Eval-driven improvement | The **golden-set ratchet** is the improvement loop: production near-misses → new labeled cases → gate → no silent recurrence. | DECIDED (`ARCHITECTURE.md` §6) |
| Feature prioritization | By **phase and state**: P0 validate → P1 compliance/security → P2 data-trust → P3 established-patient MVP (**session-bound** pre-chart) → P4 covering/new-patient (S2/S3) + on-demand → P5 write-back (deferred); **unattended overnight batch** also deferred (per-physician offline-grant model, `ARCHITECTURE.md` §4). Established-patient continuity (S1) first; S2/S3 are higher-value-but-harder fast-follow. | DECIDED (`ARCHITECTURE.md` §7; `USERS.md` §3) |
| Long-term maintenance | Keep orchestration a **cleanly extractable service** so the accepted v1 tradeoff (PHP coupling, no independent inference scaling) can be undone later without a rewrite. | DECIDED (`ARCHITECTURE.md` §2 tradeoff) |

---

## The five things a defender must not soft-pedal

Pulled forward so they aren't lost in the grid — these are where the honest
answer is "we haven't resolved this," and pretending otherwise breaks the very
reviewable-basis posture the architecture is built on:

1. **The persona is unvalidated — and validation is self-run.** No physician
   interviewed; the design-partner function is created in-house (decided
   2026-07-07). Phase 0 still gates the build, but its adjudicator is the
   founder, not a clinician — say both halves. (`USERS.md` epistemic banner)
2. **User ≠ buyer.** Success metrics we're held to are unresolved and partly in
   tension (throughput/liability vs. eye-contact/pajama-time). (`PRD.md`)
3. **Clinical governance owner is unnamed** — who defines the critical subset and
   the recall/precision floors. (`ARCHITECTURE.md` §9)
4. **p95 latency for the 90-second moment is unset** — set during in-house
   Phase 0, revisited with a real clinician.
5. **Omission is bounded and monitored, not eliminated** — critical-subset misses
   are a *code guarantee we verify*; judgment-based misses are *monitored, not
   guaranteed*. Say it precisely. (`ARCHITECTURE.md` §6 "Limits")

---

*Companion to [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) and
[`PRD.md`](PRD.md). This checklist records decisions; the reasoning behind each
lives in the doc it cites. Where it says OPEN, the item is tracked in
[`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md) or the source doc's open-questions
section — it is not an oversight here.*

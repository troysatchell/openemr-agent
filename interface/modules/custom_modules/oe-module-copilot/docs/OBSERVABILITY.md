# Clinical Co-Pilot — Observability Runbook (T17/T19)

## Architecture (decided 2026-07-09)

Two logs, one join key:

| Log | Carries PHI | Purpose | Where |
|---|---|---|---|
| Disclosure log (`EventAuditLogger`, category `external-AI-disclosure`) | YES (by design) | Compliance trail: who disclosed which patient's data classes, when, why | `log` table via `EventAuditDisclosureLogger` |
| Trace log (JSONL, `JsonlTraceRecorder`) | NO — schema has no slot for free-form content | Operational trail: step order, latency, failures, degraded paths, model id, tokens, cost | `copilot-trace.jsonl` (path is composition-root config; default `sys_get_temp_dir()`) |

The `correlation_id` (uuid v4, minted per turn in `TurnOrchestrator`, passed
explicitly — never an ambient global, per audit finding S4) appears in both.
Join them to reconstruct who-saw-what. No third-party trace sink: a vendor
sink receiving prompts would be a new PHI processor requiring its own BAA (C4).

## Trace schema (one JSON line per step)

`correlation_id, turn_kind, step, started_at, duration_ms, outcome
(ok|failed|degraded), error_class, model, input_tokens, output_tokens,
cost_usd, grounded_count, rejected_count` (the last two populated on the
`ground` step only — counts, never claim content). Steps per turn, in order:
`retrieve → detect → build_payload → disclose → llm → ground`
(`ground` is absent when the LLM failed — the turn degraded honestly).

## Dashboard

`TraceDashboard::summarize()` (tested in `TraceDashboardTest`) derives every
metric below from the JSONL alone. CLI:

```bash
# in the openemr container
php interface/modules/custom_modules/oe-module-copilot/bin/trace-dashboard.php [/path/to/copilot-trace.jsonl]
```

## Dashboard metrics (from the JSONL alone)

| Metric | Derivation |
|---|---|
| Turn count | distinct `correlation_id` |
| Error rate | turns containing ≥1 `failed` step ÷ turns |
| Degraded-turn rate | turns whose `llm` step failed ÷ turns (findings still delivered — honest degradation, not an outage) |
| Turn latency p50/p95 | per-turn sum of `duration_ms` → percentiles |
| Per-step latency p50/p95 | `duration_ms` grouped by `step` |
| Tool-call counts | line count per `step` |
| Tool failure rate | `failed` per `step` ÷ calls per `step` |
| Verification pass/fail rate | Σ`grounded_count` vs Σ`rejected_count` on `ground` steps |
| Tokens + cost | Σ`input_tokens`/`output_tokens`/`cost_usd` on `llm` steps; per-model breakdown via `model` |

### Reported N/A — honest absences, never invented

| Metric | Status | Reason |
|---|---|---|
| Retry count | Bounded: 2 attempts per vendor call | TRO-47: one retry on transport faults across the Anthropic/Cohere clients, then the typed unavailability exception degrades the turn honestly; circuit-breaker policy in docs/SLOS.md |
| Queue depth | N/A | there is no queue — the pre-chart is session-bound (no offline batch in v1, ARCHITECTURE §4) |
| Cache hit rate | N/A until UC3 | the session-bound pre-chart (Wave 2) adds a `cache_hit` trace field; nothing caches today (every turn re-reads by design, §3.5) |

## Alert definitions (graded deliverable)

Week 2 adds the three rubric-named alerts — **extraction failure rate**,
**RAG retrieval latency**, and **eval regression (>5% category drop)** — on
top of the three operational alerts carried from Week 1 (turn latency, turn
error rate, LLM failure rate). Each entry states what fires, what it means,
and what on-call does — the SHAPE is the deliverable.

> Runtime thresholds below are **provisional regression thresholds**. As of
> 2026-07-16 the turn path has a first measured production baseline
> (`docs/SLOS.md` §0: turn p95 ≈ 6 s against the 15 s alarm — 2.5×
> headroom); extraction and retrieval have no production volume yet, so
> their thresholds are provisional and **PENDING calibration** (`docs/SLOS.md`
> §2). Ratchet: measure, set just below current performance, raise as it
> improves.

### Alert checker (wired)

The runtime alerts below are **evaluated by a committed tool**, not just
defined: `bin/alert-check.php` reads the same JSONL trace every turn
writes, filters to a sliding window (default 15 min, matching the alert
definitions; `--window=0` for the whole file), computes each condition via
the tested `TraceDashboard` aggregator, prints `FIRING`/`ok` per alert with
the measured value against its threshold, and exits non-zero when anything
fires — so a cron line (or any scheduler) plus a notifier IS the alerting
loop. The checker leads with the two runtime rubric alerts (extraction
failure rate, RAG retrieval latency) and names the third rubric alert (eval
regression) as `[ CI ]`-enforced:

```
*/5 * * * * php .../oe-module-copilot/bin/alert-check.php || <notify on-call>
```

Exit codes: `0` ok (including "no traffic in window" — absence of traffic
is `/ready`'s job), `2` at least one alert firing, `1` trace unreadable.
Verified against the real production trace on 2026-07-16 (6 grounded
turns): every runtime alert `ok`, exit 0; and against a synthetic breach
trace the operational alerts fire, exit 2.

### Week 2 alerts (rubric)

#### Extraction failure rate > 20% of extractions (provisional)
- **Means:** the VLM document-extraction path is failing a large share of
  uploads — a scanned lab/intake is being rejected rather than turned into
  provenance-linked observations. Backed by `DashboardReport.ingestionFailureRate`
  (`document-ingestion` failed-step fraction).
- **On-call:** group `document-ingestion` failures by `error_class` in the
  trace. A schema-violation class means the model output failed strict
  validation (parse-don't-validate did its job — the extraction failed whole
  rather than inventing fields); a transport/`LlmUnavailableException` class
  means the vendor path is down (check the VLM circuit breaker + vendor
  status). Uploads degrade honestly — no partial record is written. Threshold
  provisional, PENDING production calibration (`docs/SLOS.md` §2); until then
  the per-dependency breaker is the harder interim signal.

#### RAG retrieval latency p95 > 10s (provisional)
- **Means:** the evidence-retriever's leg (embed + rerank vendor calls) is
  slow; an "include guideline evidence" turn stalls waiting on retrieval.
  Backed by `DashboardReport.retrievalLatencyP95Ms` (p95 of the per-turn
  `embed`+`rerank` duration).
- **On-call:** split `embed` vs `rerank` per-step latency in the trace to
  localize which vendor leg dominates; check Cohere status. Retrieval degrades
  honestly (PS-12 asymmetric degradation) — a slow or absent reranker yields
  worse evidence ordering, never a hung turn, and `/ready`'s `reranker` probe
  reports `degraded` when the key is absent. Threshold provisional, PENDING
  production calibration (`docs/SLOS.md` §2).

#### Eval regression > 5% category drop (CI-enforced)
- **Means:** a change dropped a boolean-rubric category's pass rate by more
  than 5% (or below its floor) against the committed baseline — a real
  clinical-accuracy regression heading for the demo.
- **On-call / dev:** this is a **build-time** alert, not a runtime one: the
  golden-set gate (`GoldenSetGateTest`, `clinical-accuracy-gate.yml`) fails
  the PR. The response is to fix the regression, never to regenerate the
  baseline or a fixture to green it (CLAUDE.md bright line). `alert-check.php`
  names it as `[ CI ]` so the runtime checker still lists all three rubric
  alerts.

### Operational alerts (Week 1, retained)

#### Turn latency p95 > 15s (15-min window)
- **Means:** the between-patient-moment budget (PHASE0 §2 stipulation; first
  production baseline measured 2026-07-16 at p95 ≈ 6 s — `docs/SLOS.md` §0)
  is blown; the physician stops trusting the panel to be "glanceable" long
  before it errors.
- **On-call:** compare per-step p95 to localize — `llm` step dominating ⇒
  vendor latency (check status.anthropic.com; consider lowering max_tokens or
  switching configured model id); `retrieve` dominating ⇒ FHIR/DB path (check
  DB load, P3 connection pooling is a known scale gap). The panel stays
  usable: detectors are code, only prose slows down.

#### Turn error rate > 5% (15-min window)
- **Means:** a dependency is failing. A failed `retrieve`/`detect`/
  `build_payload`/`disclose` step fails the whole turn (exception propagates);
  a failed `llm` step only degrades it.
- **On-call:** group failures by `step` + `error_class` in the trace. `disclose`
  failures are the most serious — an unlogged crossing must never look logged
  (C1), so sends stop when the audit sink is down; check the DB/log table
  first. Cross-check `GET /api/copilot/ready` (`db`, `trace_sink`, `llm`
  probes name what's down).

#### LLM failure rate > 10% of llm steps (15-min window)
- **Means:** the model endpoint is unavailable or refusing — users see honest
  degradation (critical findings intact, answer absent). Safe but eroding:
  the panel's value proposition quietly halves.
- **On-call:** `error_class` will be `LlmUnavailableException` (429/5xx/529/
  transport/refusal/unparseable). A \DomainException in the *app* log instead
  means a config error (bad key/model id) — that is a deploy problem, not a
  vendor problem, and it never shows up as a degraded turn by design. Check
  vendor status; if sustained, announce detector-only mode.

### Not alerted (and why)
- **Verification rejected-rate** is watched on the dashboard, not paged:
  rejections are the system WORKING (R6/R10 — unattributable prose withheld).
  A sudden spike feeds the golden-chart set as candidate cases instead.
- **Cost/token spikes**: reviewed daily against the cost-analysis tiers, not
  paged — no autonomous loop exists that could run away (single turn per
  user action; the only retries are TRO-47's bounded per-vendor-call
  transport retry, max 2 attempts, never a loop).

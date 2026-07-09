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
| Retry count | N/A | v1 has no retry logic anywhere on the turn path — `LlmUnavailableException` degrades the turn immediately |
| Queue depth | N/A | there is no queue — the pre-chart is session-bound (no offline batch in v1, ARCHITECTURE §4) |
| Cache hit rate | N/A until UC3 | the session-bound pre-chart (Wave 2) adds a `cache_hit` trace field; nothing caches today (every turn re-reads by design, §3.5) |

## Alert definitions (graded deliverable — 3 alerts)

> Thresholds below are **provisional regression thresholds — UNSOURCED
> placeholders** pending the load-test baselines (10/50 concurrent users).
> Ratchet: measure, set just below current performance, raise as it improves.
> The SHAPE (what fires, what it means, what on-call does) is the deliverable.

### 1. Turn latency p95 > 15s (15-min window)
- **Means:** the between-patient-moment budget (PHASE0 §2 — p95 stipulated,
  not yet measured) is blown; the physician stops trusting the panel to be
  "glanceable" long before it errors.
- **On-call:** compare per-step p95 to localize — `llm` step dominating ⇒
  vendor latency (check status.anthropic.com; consider lowering max_tokens or
  switching configured model id); `retrieve` dominating ⇒ FHIR/DB path (check
  DB load, P3 connection pooling is a known scale gap). The panel stays
  usable: detectors are code, only prose slows down.

### 2. Turn error rate > 5% (15-min window)
- **Means:** a dependency is failing. A failed `retrieve`/`detect`/
  `build_payload`/`disclose` step fails the whole turn (exception propagates);
  a failed `llm` step only degrades it.
- **On-call:** group failures by `step` + `error_class` in the trace. `disclose`
  failures are the most serious — an unlogged crossing must never look logged
  (C1), so sends stop when the audit sink is down; check the DB/log table
  first. Cross-check `GET /api/copilot/ready` (`db`, `trace_sink`, `llm`
  probes name what's down).

### 3. LLM failure rate > 10% of llm steps (15-min window)
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
  user action, no retries).

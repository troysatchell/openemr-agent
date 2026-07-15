# Clinical Co-Pilot — Service Level Objectives (TRO-47)

Extends the Week 1 observability substrate (`docs/OBSERVABILITY.md`;
`TraceDashboard::summarize()`) with the two Week 2 latency SLOs, their alarm
conditions, and the circuit-breaker resilience policy named in
`W2_ARCHITECTURE.md` §8 ("Observability extensions") and PS-12 (asymmetric
degradation). Every number below is labeled **MEASURED** (with its source —
usually the trace dashboard, sometimes a committed code constant) or
**PENDING MEASUREMENT** — inventing a number is worse than admitting it
doesn't exist yet. This mirrors `docs/COST_MODEL.md` §4's honesty-ledger
convention, applied to latency and reliability instead of cost.

## 1. Latency SLOs

### document-ingestion p95

- **Target: PENDING MEASUREMENT.** No production ingestion volume exists yet
  to set a p95 baseline against (same status `docs/COST_MODEL.md` records for
  the per-document token-profile assumptions — "no production extraction
  volume exists yet to measure it against").
- **Measurement mechanism: MEASURED (source: trace dashboard).** Document
  ingestion already lands as trace steps per `W2_ARCHITECTURE.md` §8's "New
  step types: document ingestion start/complete, per-field extraction
  outcome" — `TraceDashboard`'s existing `Per-step latency p50/p95`
  derivation (`docs/OBSERVABILITY.md`) applies to those steps unchanged.
  Once ingestion traffic exists, `bin/trace-dashboard.php` reports this
  percentile directly from the JSONL trace — no new instrumentation is
  required, only volume.
- **Working interim posture:** ingestion should not visibly stall the
  encounter, in the same spirit as the turn path's existing p95 > 15s alarm
  (`docs/OBSERVABILITY.md` — itself PHASE0 §2-stipulated and PENDING
  MEASUREMENT, not a measured baseline). This is a carried-over stipulation,
  not an ingestion-specific measurement.

### evidence-retrieval p95

- **Target: PENDING MEASUREMENT.** UC7 evidence-on-demand retrieval is new in
  Week 2; `HybridRetrieverTest`/`RetrievalDegradationTest` exercise
  correctness under a fixture-stubbed transport (no network, no load), not
  latency under real vendor round-trips.
- **Measurement mechanism: MEASURED (source: trace dashboard).** The
  `retrieve` step (embed + rerank, `W2_ARCHITECTURE.md` §8 "retrieval
  hit/miss, rerank outcome") already lands in the same JSONL trace
  `TraceDashboard::summarize()` reads; once query volume exists, p50/p95 for
  the retrieval leg reports without new code, the same way `llm`-step
  latency does today.

## 2. Alarm conditions

### Extraction failure rate

- **Mechanism: MEASURED (source: trace dashboard).** Per-field extraction
  outcome is already a named step type (`W2_ARCHITECTURE.md` §8); the
  existing `Tool failure rate` dashboard derivation (`failed` per `step` ÷
  calls per `step`, `docs/OBSERVABILITY.md`) applies to the extraction step
  unchanged.
- **Threshold: PENDING MEASUREMENT.** No production extraction volume exists
  yet to set a baseline (`docs/COST_MODEL.md` records the same absence for
  extraction's token-profile assumptions). Until a baseline exists, the
  interim signal is the per-dependency circuit breaker (§3 below) tripping
  open on the VLM/LLM path — a real, code-enforced signal — rather than an
  invented percentage.

### RAG retrieval latency

- Same measurement mechanism as evidence-retrieval p95 above (**MEASURED**,
  source: trace dashboard, per-step `retrieve` latency). Threshold: **PENDING
  MEASUREMENT**, same reason — no production retrieval volume yet.

### Eval regression (>5% category drop)

- **Threshold: 5% category-pass-rate drop.** This is the one number in this
  document that is neither a load-derived measurement nor an absence — it is
  a **committed policy threshold** (PS-3/PS-11: "at ~10 cases/category, the
  >5% regression clause collapses to any-single-flip-fails — the intended
  clinical bar; the percentage exists for when N grows"), enforced by the
  comparator every `GoldenSetGateTest` PR run exercises. **MEASURED (source:
  `GoldenSetGateTest` / the eval comparator implementation)** in the sense
  that it is an architectural fact read from committed code, not a
  production-load figure — the same "committed constant" sense
  `docs/COST_MODEL.md` uses for vendor prices.

## 3. Circuit-breaker policy

Per `W2_ARCHITECTURE.md` §8: "every outbound LLM/VLM/embed/rerank call has a
timeout and bounded retry; repeated failures trip a per-dependency circuit
breaker that degrades the turn honestly (Week 1's R11 posture) instead of
hanging it." `CircuitBreaker` (`src/Resilience/CircuitBreaker.php`) is a pure,
clock-driven state machine (`CircuitBreakerContractTest`); the composition
root (`Bootstrap.php`) wires one instance per live vendor client:

| Dependency | Failure threshold | Cooldown | Status |
|---|---|---|---|
| `anthropic-llm` (turn-path answer model) | 3 consecutive failures | 60s | **MEASURED (source: `Bootstrap::BREAKER_FAILURE_THRESHOLD`/`BREAKER_COOLDOWN_SECONDS`)** — a committed default, not yet tuned against production failure/latency data — the tuned value itself is **PENDING MEASUREMENT** |
| `cohere-embed` (evidence retrieval, dense leg) | 3 consecutive failures | 60s | same as above |
| `cohere-rerank` (evidence retrieval, rerank leg) | 3 consecutive failures | 60s | same as above |

Bounded retry: each vendor client retries a transport-level `\Throwable`
exactly once (two attempts total) before surfacing its typed unavailability
exception; a non-200 response or an unparseable/malformed body is neither
retried nor fed to the breaker (that failure mode is a vendor contract
violation, not a transient blip — the existing failure mapping in each client
is left exactly as it was before TRO-47). An open breaker fails the call
immediately, before the transport is invoked, naming itself in the exception
message.

**Known limitation — PENDING MEASUREMENT of whether this needs a follow-up:**
as wired in `Bootstrap.php`, each breaker is constructed fresh per PHP
request (no persistent, cross-request store such as APCu/Redis/a DB row).
Within a single request, each vendor client's method is invoked at most once
externally, and bounded retry contributes at most `MAX_TRANSPORT_ATTEMPTS -
1` recorded failures per outer call — below the 3-failure threshold above.
This means the production breakers, as currently wired, cannot reach `open`
from most single requests: the instance lives and dies with one request, so
there is NO cross-request accumulation at all (not even on the same PHP
worker). The only flow that can trip one today is a request making two or
more calls on the same client instance — e.g. a supervised turn's
extraction + answer LLM calls (2 calls x up to 2 recorded failures >= the
3-failure threshold); single-call flows (embed, rerank) can never trip
theirs. Genuine
cross-request circuit breaking — protecting the whole deployment from a
sustained vendor outage, not just one in-flight request — requires a
persistent state store, which is out of this ticket's scope. This is called
out explicitly rather than silently accepted: the state-machine contract
(`CircuitBreakerContractTest`) is correct and unit-tested in isolation; its
production wiring is a known v1 limitation, not a hidden one.

## 4. `/ready` degraded status (not binary up/down)

Per `W2_ARCHITECTURE.md` §8: "`/ready` grows document-storage, vector-index,
and reranker probes and returns per-dependency degraded status rather than
binary up/down." `ReadinessCheck`/`ReadinessReport` (TRO-47) accept a
tri-state probe result — `true` ('ok'), `false` ('failed'), or the literal
string `'degraded'` — and readiness fails only on a genuine `'failed'`
dependency; a `'degraded'` one names itself without tripping the 503 (a
warning with a name, not an outage, per PS-12's "worse results beat no
results, but never silently"). `GET /api/copilot/ready` reports `db`,
`trace_sink`, `llm` (unchanged from Week 1) plus the three new probes:

- `document-storage` — cheap `SHOW TABLES LIKE 'documents'` metadata check
  against core's native document table (no row scan) — `ok`/`failed` only,
  since the table either exists or the deployment is fundamentally broken.
- `vector-index` — same cheap metadata check against the module-owned corpus
  chunk table (`CorpusIndexSchema::CHUNK_TABLE`) — `ok`/`failed` only.
- `reranker` — `COHERE_API_KEY` presence: `ok` when configured, **`degraded`**
  (never `failed`) when absent — evidence retrieval turns degrade honestly
  without it (PS-12's asymmetric-degradation pair), and a missing optional
  vendor key must never take the whole panel offline.

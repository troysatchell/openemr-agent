# Clinical Co-Pilot — Cost & Latency Report (Week 2)

The single consolidated **Cost and Latency Report** deliverable (Week 2
requirements: *"Actual dev spend, projected production cost, p50/p95 latency,
and bottleneck analysis"*). It does not restate the two authoritative in-repo
sources — it pulls their numbers together and adds the measured end-to-end
production run, the development spend, and the bottleneck analysis:

- **Cost source of truth:** [`docs/COST_MODEL.md`](COST_MODEL.md) (TRO-46) —
  per-step / per-behavior cost model and the four MEASURED vendor price
  constants.
- **Latency source of truth:** [`docs/SLOS.md`](SLOS.md) (TRO-47) — latency
  SLOs, alarm thresholds, circuit breakers, and the measured production
  baseline (§0). This report supplies the methodology and per-flow numbers
  that `SLOS.md` §0 points back to; live per-*step* percentiles (`llm`,
  `retrieve`, ingestion) are derived on demand from the JSONL trace via
  `bin/trace-dashboard.php` (`docs/OBSERVABILITY.md`), not tabulated here.

Every number is labeled **MEASURED** (with its source), **ASSUMED** (a volume
or token-profile assumption with no production data behind it yet), or
**ESTIMATED** (derived from measured constants but not yet reconciled against a
vendor invoice). Inventing a number is worse than admitting its absence —
`COST_MODEL.md` §4 and `SLOS.md`'s honesty convention, applied here.

## Measured end-to-end on production

**MEASURED — 2026-07-16, the deployed Railway app.** Exercised through the real
SMART launch chain: scripted login → chart-menu launch → confidential
authorization-code exchange → guarded routes invoked with the launch-bound
token. Two clocks captured for the *same* calls — client-observed `curl`
wall-clock, and the server-side PHI-free JSONL trace
(`bin/trace-dashboard.php`) for the identical requests. Small-n, single
region/day. Percentiles are computed over the per-flow samples shown (n as
tabulated) and are **indicative, not statistically stable production
figures** — a p95 over n=6 sets the alarm ratchet, it does not certify
capacity; a production-window measurement or load test supersedes it.

### Latency (p50 / p95)

| Flow | n | p50 | p95 | Notes |
|---|---|---|---|---|
| turn, client-observed end-to-end | 6 | 3.22 s | ~6.05 s | Live Anthropic call included; all 6 grounded, 0 degraded, 0 errors |
| turn, server-side (trace) | 6 | 2.97 s | 5.82 s | `llm` step dominates; ~0.25 s client↔server network delta |
| snapshot, client-observed end-to-end | 10 | 0.56 s | ~0.61 s | Full live FHIR chart read + detectors + composition, no model call |
| `/ready` probe | 3 | 0.21 s | — | All probes true |
| SMART launch chain (menu click → token) | 1 | 1.41 s | — | launch-from-chart → authorize (skip-flow) → code exchange |

### Bottleneck analysis

- **The turn bottleneck is the `llm` step** (the Anthropic answer-model
  round-trip). The snapshot path does the full live FHIR chart read + the
  deterministic critical-subset detectors + composition with **no** model call
  and finishes sub-second (p50 0.56 s); a turn over the same chart is p50
  2.97 s server-side. The ~2.4 s difference is essentially the one outbound LLM
  call — confirmed by the trace's per-step `llm` p50/p95 dominating every other
  step (`docs/OBSERVABILITY.md`; `TraceDashboard::summarize()`).
- **Client↔server delta ≈ 0.25 s** (turn p95 6.05 s client vs 5.82 s
  server) — TLS + network to Railway, not application work.
- **The snapshot (zero-RAG) path is detector-bound and cheap.** Deterministic
  detectors and composition are pure PHP; the live FHIR read is the largest
  component *within* that sub-second budget.
- **Evidence retrieval (embed + rerank) and document ingestion (VLM):
  PENDING MEASUREMENT** — no production traffic class exists yet (`SLOS.md`
  §1). The measurement mechanism is already in place (the same JSONL trace,
  `retrieve` and ingestion step types); it reports p50/p95 the moment volume
  exists, with no new instrumentation.

**Implication.** p95 headroom is almost entirely a function of vendor
answer-model latency. Against the turn `p95 > 15 s` alarm, the measured ~6 s
clears with **~2.5× headroom** (provisional, at the small n above — a ratchet
for the alarm threshold, not a capacity guarantee), and `bin/alert-check.php`
over the same trace reports all three alerts `ok`. The first latency lever at scale is the same one
`COST_MODEL.md` §3 names for cost — **prompt caching + snapshot reuse** shrinks
the LLM input (and therefore the dominant step's wall-clock), rather than
adding request parallelism.

## Actual development spend

**Mechanism MEASURED; total ESTIMATED (to be reconciled against the vendor
billing dashboards).** Vendor spend during development was not billed to a
separately-metered project account, so no single authoritative invoice figure
is committed here. What *is* authoritative:

- The per-turn / per-document cost model (`COST_MODEL.md`) built on **MEASURED**
  vendor price constants: Anthropic $5.00 / $25.00 per M input/output tokens;
  Cohere embed $0.10 / M tokens; Cohere rerank $2.00 / 1,000 search units.
- **The eval gate and the test suites cost $0 in vendor spend:** all vendor
  calls in the 50-case golden set, the integration tests, and CI are
  fixture-replayed (input-keyed vendor replay; no live keys required). Only
  interactive development and demo rehearsal made live calls.
- Live development usage was low-volume: on the order of **tens of live turns**
  and **a handful of live document extractions** across build + demo rehearsal.

**Order-of-magnitude estimate:** at ~$0.019 / turn and ~$0.058 / document, tens
of turns plus a handful of extractions is **well under $5** in live Anthropic
spend; Cohere embed/rerank over the same handful of evidence turns is
sub-dollar. **Total live development spend ≈ a few dollars (ESTIMATED).**

To replace this estimate with a MEASURED figure: sum `vendorCostUsd` across the
development traces via `bin/trace-dashboard.php`, or read the Anthropic / Cohere
billing dashboards for the development window.

## Projected production cost

Summarized from `COST_MODEL.md` §3 (authoritative — see it for the reasoning
behind each tier and the §4 honesty ledger). Cost separates into two curves
that scale on different variables:

- **Extraction — per-document** (~$0.058 / document; ASSUMED token profile,
  MEASURED price). Scales with document/patient volume, not usage.
- **Retrieval + answer — per-question** (~$0.021 / question). Scales with
  question volume × **Q/encounter**, an explicit *behavioral* variable that
  grows as physicians lean on the tool — not a fixed constant.

| Tier (encounters/mo) | Q/enc | Extraction/mo | Retrieval + answer/mo | Architectural inflection |
|---|---|---|---|---|
| 100 | 1 | ~$5.80 | ~$2.10 | none — direct vendor calls are cheapest |
| 1K | 2 | ~$58 | ~$42 | prompt caching + snapshot reuse |
| 10K | 4 | ~$580 | ~$840 | embedding cache + batched extraction |
| 100K | 6 | ~$5,800 | ~$12,600 | in-house rerank + dedicated capacity |

All volumes and Q/encounter figures are **ASSUMED** projection scaffolding, not
a roadmap commitment; the vendor prices underneath them are MEASURED.

## Cross-references

| For | See |
|---|---|
| Per-step cost model, vendor prices, projection reasoning | [`docs/COST_MODEL.md`](COST_MODEL.md) |
| Latency SLOs, alarm thresholds, circuit breakers, RPO/RTO | [`docs/SLOS.md`](SLOS.md) |
| Trace schema, per-step p50/p95 derivation, wired alerts | [`docs/OBSERVABILITY.md`](OBSERVABILITY.md) |
| Live dashboards (`bin/trace-dashboard.php`, `bin/alert-check.php`) | [`README.md`](../../../../../README.md) grader walkthrough |

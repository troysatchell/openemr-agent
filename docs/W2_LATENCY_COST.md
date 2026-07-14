# Week 2 MVP — Latency & Cost Report

TRO-44 MVP deliverable (W2_ARCHITECTURE.md §4/§8; MVP row 5 "source-grounded
UI, latency/cost report"). Every number below is either **measured** in this
environment, **estimated** from a real measured artifact (labeled), or
**projected** for a worked example (labeled) — never invented. Where a number
genuinely cannot be produced here, it is stated as **N/A — not measured in
this environment**, per the founder's 2026-07-09 decision that metrics are
stated honestly, not fabricated.

## Latency

Measured 2026-07-14, in the project's own dev container (`docker/development-
easy`), against a real MariaDB 11.8.8 instance and PHP 8.5.6. Each flow ran 20
iterations against a real fixture patient; timing via `hrtime(true)` around
the exact call the route/endpoint class makes. The throwaway measurement
script (`tmp/latency-measure.php`, deleted after this report was written) is
not a committed artifact — it reused the same DB-connectivity bootstrap every
DB-backed PHPUnit suite in this repo already uses (`tests/bootstrap.php`), and
the same fixture-patient / fixture-replay-transport patterns as
`SourceResolverEndpointTest`/`DocumentIngestionServiceTest`.

| Flow | p50 | p95 | What's included | What's excluded (and why) |
|---|---|---|---|---|
| `snapshot` | 5.7 ms | 8.3 ms | Real `EncounterService` last-visit DB read, real `CriticalSubsetDetectors`, real `SnapshotComposer`, real wire-shaping (`SnapshotEndpoint`) | The live FHIR chart read (`ChartReader`/`OpenEmrFhirGateway`) — see caveat below |
| `turn` (pipeline) | 0.11 ms | 0.14 ms | Real `CriticalSubsetDetectors`, real `CitationIndex`/`ReferenceIndex`, real `MinimumNecessaryPayloadBuilder`, real `ClaimVerifier` grounding, real `JsonlTraceRecorder` file writes | The live FHIR chart read; the real disclosure-log DB write (measured separately below); the live Anthropic network call (see Cost) |
| `turn.disclose` (DB write only) | 0.46 ms | 0.63 ms | One real `EventAuditDisclosureLogger` write (the audit-log DB insert every turn makes before the model is called, C1) | — |
| `ingestion` | 14.0 ms | 24.7 ms | The **full, real** `DocumentIngestionService::attachAndExtract()`: native `Document::createDocument()` write + content-hash dedupe check, VLM-output parsing (`VlmExtractionParser`), and the derived-procedure-chain persist (`DerivedObservationWriter`, one DB transaction) | The live Anthropic vision network call — the transport is fixture-replayed (no network), matching this repo's eval-gate posture (PS-2): only the vendor wire crossing is stubbed, everything else is production code |

**Known caveat — the FHIR chart-read stage is not measured here.** `snapshot`
and `turn` both depend on `ChartSnapshotProvider` (`ReadThroughChartSnapshotProvider`
→ `ChartReader` → `OpenEmrFhirGateway` → real FHIR resource reads), which its
own docblocks mark "DB-backed and NOT covered by the isolated suite —
verified at live-stack smoke" — there is no existing lightweight harness in
this repo for exercising it outside a fully authenticated HTTP session (real
OAuth2 token, real FHIR-shaped Patient/MedicationRequest/Observation/
AllergyIntolerance resources for a seeded patient). Building one from scratch
was judged disproportionate to this MVP report and risked producing an
unrepresentative one-off fixture. This measurement therefore isolates
everything **downstream of** the chart read (detection, redaction,
verification, tracing, the real disclosure-log write) using the same
fake-`ChartSnapshotProvider` substitution the module's own isolated unit tests
already use for `TurnOrchestrator`/`SnapshotEndpoint` — not a shortcut this
report invented. **The live FHIR read's own latency is honestly stated as N/A
— not measured in this environment**; it is a live-stack smoke-test
measurement per the existing code's own docblocks, not something this
report's harness can produce without a live authenticated session.

The live Anthropic vendor call (the actual network round-trip to
`api.anthropic.com`) is likewise not measured here — it is fixture-replayed
(no network) in every DB-backed test in this repo, by design (PS-2: vendor
stubs are input-keyed replays, never live network in the gate). **Live-vendor
network latency is a deployment measurement** (it depends on region, vendor
load, and payload size) — stated as such, not estimated.

## Cost

### Anthropic (answer generation) — measured payload, estimated tokens

No live vendor call was made anywhere in this environment (consistent with
the eval gate's fixture-only vendor boundary, PS-2), so no vendor-reported
token count exists to report. What follows instead is grounded in a **real**
measured artifact: the exact wire request body `AnthropicLlmClient::complete()`
sends (system prompt + `output_config` JSON schema + the real, real-code
`MinimumNecessaryPayloadBuilder` output for a representative chart: 2
medications, 1 allergy, 3 labs, 1 follow-up) was built and serialized for real
in the measurement run above:

- Representative request body: **2,150 characters** (real, measured)
- Representative response body (one grounded claim): **108 characters** (real, measured)
- Token counts are **estimated** from those real character counts using the
  common ~4-characters-per-token heuristic (not a vendor tokenizer — no live
  call was made to get an exact count):
  - input ≈ 2150 / 4 ≈ **538 tokens**
  - output ≈ 108 / 4 ≈ **27 tokens**
- Price basis: `AnthropicLlmClient`'s own committed, in-repo pricing constants
  for `claude-opus-4-8` (`src/Llm/AnthropicLlmClient.php:89-90`, "this model's
  published per-MTok pricing" as of 2026-06) — **$5.00 / MTok input, $25.00 /
  MTok output**. This is a real, in-repo, cited price basis, not invented.
- Estimated cost per turn-with-an-answer: `(538 / 1,000,000 × $5.00) + (27 /
  1,000,000 × $25.00)` ≈ **$0.0034 / turn**.

This is the **per-question** cost floor for a turn that reaches the answer
step (a degraded turn, or one where the supervisor never engages the model,
costs $0). A turn with more prior context or a larger reconciled chart will
cost more — this number is representative of a small, single-finding
follow-up question, not a ceiling.

### Cohere (embed + rerank) — price-card model, unverified current rate

**GAP CLOSED (Wave K.2, TRO-44, 2026-07-14):** the evidence-retriever path
(`HybridRetriever` + `EvidenceRetrievalService`) is now wired into the live
`/api/copilot/turn` route — an explicit `ask_evidence` request flag composes
`Supervisor` + `SupervisedTurnDispatcher` + the real `EvidenceRetrieverWorkerImpl`
on `CohereHttpTransport`, in addition to the eval-gate harness (`GoldenSetRunner`)
and its own DB-backed test suites this path was previously exercised through
alone. The price-card model below remains a **projection** — no live vendor
call has been made in this environment (PS-2), so the unit costs are still
reference figures, not measured spend.

Unit-cost model: one embed call per question that triggers retrieval (the
physician's free-text question, a handful of tokens) + one rerank call over
the candidate union (`HybridRetriever::CANDIDATE_LIMIT` = 20 per leg, so up to
40 candidates deduped before rerank — one rerank request, priced per "search
unit" by Cohere's own metering).

**Unverified-in-this-environment price basis** (flagged distinctly from
"projected" above): this environment has no live internet access to confirm
Cohere's currently published rates as of 2026-07-14. The figures below are
reference figures from general background knowledge of Cohere's historical
pricing structure and **must be confirmed against Cohere's current pricing
page before being used for real budget planning**:
  - Embed (`embed-english-v3.0`, the model this repo's own
    `bin/index-corpus.php` uses for production corpus indexing): on the order
    of **$0.10 per 1M input tokens** — a single question is a handful of
    tokens, so the per-question embed cost rounds to a small fraction of a
    cent (well under $0.0001/call).
  - Rerank: historically priced per "search unit" (approximately one query
    against up to ~100 documents/chunks) — on the order of **$2.00 per 1,000
    search units**, i.e. ≈ **$0.002 per rerank call** at this module's
    candidate-union size.

### Extraction (VLM ingestion) — per-document, N/A precise vision-token count

Extraction cost scales **per-document**, independent of question volume (a
clinic that ingests 10 documents/day pays that cost 10 times/day regardless
of how many questions get asked that day) — the opposite scaling curve from
retrieval+answer's **per-question** cost (PS-9: the two curves are kept
separate deliberately).

The exact input-token count for a scanned document page sent as a Claude
vision content block is **N/A — not measured in this environment**: it
depends on the page's pixel resolution under Anthropic's image-tokenization
rule, and no live vendor call was made here to observe a real reported count
(consistent with PS-2 — this environment never calls the live vendor). A
commonly cited ballpark for a single moderate-resolution scanned page is on
the order of 1,500–2,000 input tokens; this is stated as an **assumption for
the worked example below**, not a verified figure, and should be replaced with
a real measured value once a live-vendor smoke test is run.

Using that assumption plus a representative ~300 output tokens for a
structured single-analyte extraction, and this repo's own real Anthropic
per-MTok price basis ($5/$25 per MTok, same as above): `(2000/1,000,000 ×
$5.00) + (300/1,000,000 × $25.00)` ≈ **$0.0175/document (projected)**.

### Worked example — clinic of 500 patients, 10 documents/day, 200 questions/day

All figures below are **projected**, combining the measured/estimated
per-unit costs above with illustrative volume assumptions. Assume 30% of
daily questions (60/day) trigger evidence retrieval (a critical finding the
physician engages, per the supervisor's one conditional forward edge).

| Cost driver | Scaling | Volume/day | Cost/unit | Cost/day (projected) | Cost/month (projected, ×30) |
|---|---|---|---|---|---|
| Extraction (VLM) | per-document | 10 | ≈ $0.0175 | ≈ $0.175 | ≈ $5.25 |
| Answer (Anthropic) | per-question | 200 | ≈ $0.0034 | ≈ $0.68 | ≈ $20.40 |
| Retrieval (Cohere embed+rerank) | per-question (retrieval-triggering only) | 60 | ≈ $0.002 | ≈ $0.12 | ≈ $3.60 |
| **Total** | | | | **≈ $0.98/day** | **≈ $29.25/month** |

This is an order-of-magnitude planning number, not a quote: the Anthropic
answer figure is grounded in a real measured payload (tokens estimated), the
extraction figure's vision-token count is an unverified assumption, and the
Cohere unit prices are unverified-current reference figures. Re-run this
report's measurement script against a live vendor smoke test (real API keys,
real network, real vendor-reported `usage` blocks) before using these numbers
for a real budget commitment.

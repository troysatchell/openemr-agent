# Clinical Co-Pilot — Cost Model (TRO-46)

Cost is attributed per step and projected per behavior, not per token
(W2_ARCHITECTURE.md §8). Four vendor price models coexist — **vision**
(dominant), **text**, **embed**, **rerank** — and every vendor call's
`StepRecord` carries the units it consumed plus a versioned unit price
(`TokenUsage` for the text/vision model, `VendorUnits` for embed/rerank —
the same pattern, generalized, PS-9). `TraceDashboard` (TRO-45) rolls this up
per vendor and per turn (correlation id — one id per turn today; a stable encounter key is future work) straight from the trace — cost
is derivable from the trace alone, never recomputed out-of-band.

Every number below is labeled **(MEASURED)** — a committed price constant or
an architectural fact read from the code — or **(ASSUMED)** — a volume or
token-profile assumption with no production usage data behind it yet. A
number with neither label is a bug in this document, not a fact.

## 1. The four vendor price models

### Vision (dominant) — Claude document/vision blocks, per-document extraction

VLM extraction (`lab_pdf`/`intake_form`) rides the same Anthropic transport
and per-token price as the text model below — **(MEASURED)**: $5.00 / 1M
input tokens, $25.00 / 1M output tokens, model `claude-opus-4-8`
(`AnthropicLlmClient::DEFAULT_MODEL`, `$inputPricePerMTokUsd` /
`$outputPricePerMTokUsd`) — but a multi-page source document is tokenized as
one or more image/document blocks, each costing roughly 1,500–3,000 input
tokens per page depending on resolution and page count (Anthropic's
image-tokenization approximation) — **(ASSUMED)**, because no production
extraction volume exists yet to measure it against.

Illustrative per-document cost **(ASSUMED)**: a 3-page lab PDF at ~2,500
input tokens/page for the images plus ~1,500 input tokens of schema/prompt
context (~9,000 input tokens total), ~500 output tokens for the structured
JSON extraction:

```text
9,000 / 1,000,000 × $5.00  =  $0.045
  500 / 1,000,000 × $25.00 =  $0.0125
                              --------
                              ~$0.058 / document  (ASSUMED)
```

Vision extraction dominates per-document cost precisely because it is the
only call on the per-document curve billed on image/page tokens rather than
a short text prompt — the same $/token price as the text model, applied to
an order of magnitude more input tokens per call.

### Text — Claude, follow-up Q&A answer turn

Same Anthropic price as vision — **(MEASURED)**: $5.00 / 1M input tokens,
$25.00 / 1M output tokens (`AnthropicLlmClient` defaults) — applied to a
short per-turn payload instead of a document.

Illustrative per-turn cost **(ASSUMED token profile, MEASURED per-token
price)**: chart snapshot + question + guideline evidence context ≈ 3,200
input tokens; a concise grounded answer ≈ 120 output tokens:

```text
3,200 / 1,000,000 × $5.00  = $0.016
  120 / 1,000,000 × $25.00 = $0.003
                              -------
                              ~$0.019 / turn  (ASSUMED token profile)
```

### Embed — Cohere embed v2

**(MEASURED)** committed price constant, price version `cohere-2026-07`:
**$0.10 per 1,000,000 tokens** (`CohereEmbedClient::USD_PER_MILLION_TOKENS`).

The Cohere embed v2 response body this client parses carries no token count
(`parseVectors()` reads only the float embeddings), so the token count fed
to that price is itself an **(ASSUMED, upper-bound)** estimate: ~4
characters/token over the input text — a conservative heuristic chosen to
over-count rather than under-count, never presented as a vendor-measured
figure. The trace records it under `unit_kind: embed_token_estimated` so a
reader can never mistake it for a billed number. Runs once per query-time
turn that requests evidence (embedding the physician's question text only)
— part of the per-question curve.

### Rerank — Cohere Rerank v2

**(MEASURED)** committed price constant, price version `cohere-2026-07`:
**$2.00 per 1,000 search units → $0.002 / search unit**
(`CohereRerankClient::USD_PER_SEARCH_UNIT`). One search unit is one query
reranked against up to 100 documents
(`CohereRerankClient::DOCUMENTS_PER_SEARCH_UNIT`) — **(MEASURED)**: the
hybrid retriever's per-leg candidate cap (`HybridRetriever::CANDIDATE_LIMIT`
= 20, union of two legs ≤ 40 candidates) keeps every live call inside one
search unit today, an architectural fact read from the code, not an
assumption. Also part of the per-question curve.

## 2. The two scaling curves, separated

Extraction is **per-document**: the vision (and any accompanying text) cost
of reading one uploaded source document is incurred once, at ingestion time,
independent of how many follow-up questions get asked about that patient
afterward. This curve scales ~linearly with **patient/document volume**, not
with usage.

Retrieval + answer is **per-question**: the text (answer model), embed, and
rerank costs are each incurred once per follow-up turn. This curve scales
with **question volume** — and questions-per-encounter (**Q/encounter**) is
an explicit **behavioral variable**, not a fixed constant: it grows as
physicians learn to trust and lean on the co-pilot. A projection that
multiplies a single per-token cost by patient count without naming a
Q/encounter assumption is exactly the token-multiplication projection this
model rejects (W2_ARCHITECTURE.md §8).

## 3. Projection tiers

Each tier states its own **Q/encounter** assumption — **(ASSUMED)**, no
production usage data exists yet to measure it — and names the
architectural **inflection**: the point at which scale forces a different
implementation, not just a bigger instance of the old one.

### Tier: 100 encounters/month
- **Q/encounter: 1** (ASSUMED) — early pilot use; physicians ask at most one
  follow-up question per encounter while learning the tool.
- **Extraction (per-document):** ~100 documents/month × ~$0.058 ≈ **$5.80/month** (ASSUMED).
- **Retrieval + answer (per-question):** 100 encounters × 1 Q/encounter ×
  (~$0.019 text + ~$0.0003 embed + ~$0.002 rerank ≈ $0.021/question) ≈
  **$2.10/month** (ASSUMED).
- **Architectural inflection: none.** Direct vendor API calls, no caching,
  no batching — at this volume the simplest implementation is also the
  cheapest one to operate.

### Tier: 1K encounters/month
- **Q/encounter: 2** (ASSUMED) — trust is building; physicians start asking
  one clarifying follow-up.
- **Extraction (per-document):** ~1,000 documents/month × ~$0.058 ≈ **$58/month** (ASSUMED).
- **Retrieval + answer (per-question):** 1,000 × 2 × ~$0.021 ≈ **$42/month** (ASSUMED).
- **Architectural inflection: prompt caching + snapshot reuse.** The chart
  snapshot dominates the text call's input tokens and is re-sent on every
  turn within an encounter; caching it (and reusing one extraction pass
  across a visit's follow-up questions) is the first structural change that
  keeps the per-question curve from tracking patient volume 1:1.

### Tier: 10K encounters/month
- **Q/encounter: 4** (ASSUMED) — the co-pilot is now a default part of the
  visit; multiple follow-ups per encounter are routine.
- **Extraction (per-document):** ~10,000 documents/month × ~$0.058 ≈ **$580/month** (ASSUMED).
- **Retrieval + answer (per-question):** 10,000 × 4 × ~$0.021 ≈ **$840/month** (ASSUMED).
- **Architectural inflection: embedding cache + batched extraction.** The
  guideline corpus is static and non-PHI, so query embeddings for
  repeated/similar questions are cacheable; extraction moves from
  one-document-at-a-time VLM calls to batched submission, amortizing fixed
  prompt/schema overhead across many documents per vendor call.

### Tier: 100K encounters/month
- **Q/encounter: 6** (ASSUMED) — mature, trusted usage across a health
  system; the co-pilot is consulted repeatedly per encounter.
- **Extraction (per-document):** ~100,000 documents/month × ~$0.058 ≈ **$5,800/month** (ASSUMED).
- **Retrieval + answer (per-question):** 100,000 × 6 × ~$0.021 ≈ **$12,600/month** (ASSUMED).
- **Architectural inflection: in-house rerank + dedicated capacity.** At this
  volume the per-search-unit vendor rerank cost and vendor-side rate limits
  both become the binding constraint; an in-house cross-encoder reranker
  (over the same non-PHI corpus) plus reserved/dedicated inference capacity
  for the answer and vision models replaces pay-per-call vendor pricing with
  fixed capacity cost.

## 4. Honesty ledger — what is measured vs. assumed

| Number | Status | Source |
|---|---|---|
| Anthropic $5.00/$25.00 per M tokens (in/out) | MEASURED | `AnthropicLlmClient` constructor defaults |
| Cohere embed $0.10 per 1M tokens, `cohere-2026-07` | MEASURED | `CohereEmbedClient::USD_PER_MILLION_TOKENS` |
| Cohere rerank $2.00 per 1,000 search units, `cohere-2026-07` | MEASURED | `CohereRerankClient::USD_PER_SEARCH_UNIT` |
| 100 docs/search unit, ≤1 search unit per live call | MEASURED | `CohereRerankClient::DOCUMENTS_PER_SEARCH_UNIT`, `HybridRetriever::CANDIDATE_LIMIT` |
| Embed token count from input text | ASSUMED (upper bound) | `CohereEmbedClient::ESTIMATED_CHARS_PER_TOKEN` heuristic — vendor does not return a token count |
| ~9,000 input / ~500 output tokens per extracted document | ASSUMED | no production extraction volume yet |
| ~3,200 input / ~120 output tokens per Q&A turn | ASSUMED | no production turn volume yet |
| Q/encounter (1 / 2 / 4 / 6 across the four tiers) | ASSUMED | behavioral projection, not measured usage |
| Document and encounter volumes per tier (100 / 1K / 10K / 100K) | ASSUMED | projection scaffolding, not a roadmap commitment |

As real traces accumulate, `TraceDashboard::summarize()`'s `vendorCostUsd`
and `costUsdByCorrelation` (TRO-45; `bin/trace-dashboard.php`) report
*measured* per-vendor and per-turn (correlation id) cost from the trace alone — replacing
the ASSUMED figures above with MEASURED ones is the intended path, not a
rewrite of this document's structure.

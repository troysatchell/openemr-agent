# W2 PRD Seeds — acceptance-criteria-shaped outputs of the design reviews

> Locked decisions and invariants from the Week 2 architecture reviews
> (2026-07-13), captured as **PRD seeds**: each item is a statement, an
> acceptance test, and the failure mode it guards against. The PRD turns
> these into work items; [`W2_ARCHITECTURE.md`](../W2_ARCHITECTURE.md)
> carries their rationale. Same facts, different artifact. IDs are stable —
> reference them from tickets and tests.

## PS-1 — Eval-gate stub seam (three tiers)

- **Statement:** the eval gate stubs ONLY the vendor boundary (Anthropic
  vision/text, Cohere embed, Cohere rerank) at the injectable transport;
  the database is REAL (MariaDB service container, FULLTEXT + VECTOR);
  everything else — route, parse, schema, persist, supervisor routing,
  retrieve, rerank-consume, verify, cite — runs production code.
  Worker-level stubs appear only in supervisor routing unit tests, never in
  the gate.
- **Acceptance:** CI job definition shows the DB service + fixture transport
  wiring; a regression injected into extraction parsing, citation minting,
  candidate-union SQL, or verification turns the gate red (see PS-3).
- **Guards against:** a green gate blind to regressions inside real agent
  code — the exact failure the grader's injected-regression test probes.
- **Stated limits (in reach / out of reach):** in reach — broken candidate
  union, top-k off-by-one, citation minted against wrong chunk, schema
  bypass, verification/routing regressions, input-side corruption (via
  PS-2). Out of reach by construction — embedding/rerank *quality* (vendor
  model properties; live smoke covers them).

## PS-2 — Input-keyed vendor replay; unseen input fails loud

- **Statement:** every vendor stub keys on a content hash of its request and
  returns the recorded response for that input; an unseen key THROWS
  ("unexpected vendor call") — no default fallback, no silent fixture
  regeneration. Recorded fixture embeddings are committed, reproducible
  artifacts.
- **Acceptance:** a test corrupts the input upstream of a vendor call (e.g.
  garbles text handed to the embedder) and asserts the gate fails with the
  unseen-key error, not a green pass.
- **Guards against:** fixed-output doubles masking input-side regressions —
  a data-trust bug that corrupts what we send the vendor while the stub
  keeps returning the canned answer.

## PS-3 — The gate is proven adversarially before it is trusted

- **Statement:** a synthetic regression is committed (branch/patch fixture)
  together with a meta-test asserting the eval gate goes red on it.
- **Acceptance:** running the gate against the synthetic regression exits
  nonzero with the regressed category named; running it on baseline stays
  green.
- **Guards against:** gate theater — a 50-case suite that has never been
  seen to fail.

## PS-4 — Persistence spine (spike SP-1 RETURNED 2026-07-13 — mechanism locked)

- **Statement:** observation-shaped facts (lab values) persist as native,
  stamped derived records via the native procedure chain. List-shaped
  clinical facts (intake meds, allergies, family history) stay module-owned
  reconciliation candidates — never written to native lists (exceeds the
  two-write amendment).
- **SP-1 answers (static analysis; evidence cited):**
  1. **Writability — YES, native chain, not FHIR.** FHIR Observation is
     GET-only (`apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php:493`;
     POST/PUT exist only for Patient/Practitioner/Organization). Write =
     `procedure_order` → `procedure_report` → `procedure_result`, the
     pattern core uses for document-derived results
     (`controllers/C_Document.class.php:1399`; CDA import in
     `src/Services/Cda/CdaTemplateImportDispose.php`). Native stamps:
     `procedure_result.result_status='preliminary'`,
     `procedure_result.document_id` → documents.id (native derivedFrom),
     `procedure_report.date_collected`, `procedure_report.source` =
     delegated physician. Lineage detail (extractor version, page, bbox,
     confidence) → module-owned link table on `procedure_result_id`; no
     core schema edits. `ProcedureService` has no chain-insert methods —
     the module owns a transactional insert following the core precedent.
  2. **Round-trip visibility as derived — YES.**
     `src/Services/ProcedureService.php:464` maps `result_status→status`;
     `src/Services/FHIR/Observation/FhirObservationLaboratoryService.php:263`
     serves `Observation.status=preliminary`; no status filter anywhere in
     the read path; `document_id` selected at
     `src/Services/ObservationLabService.php:95`. (`Observation.derivedFrom`
     not populated by the core mapper — non-blocking; candidate upstream
     contribution.)
  3. **Queryability for supersession — YES.** FHIR search params `patient`
     + `code` (LOINC on result_code) + `date`
     (FhirObservationLaboratoryService::loadSearchParameters); service
     layer filters `result_status` to exclude derived rows from the
     "real observation exists?" check.
- **Residual — CLOSED (TRO-12, 2026-07-13):** the live smoke passed on the
  dev stack. Inserted a preliminary `procedure_order → procedure_order_code →
  procedure_report → procedure_result` chain (`document_id` set) for a test
  patient; `GET /fhir/Observation?patient=…` returned it as
  `Observation.status='preliminary'` (unfiltered, id == inserted result uuid),
  and the `patient + code` query returned it (supersession query shape).
  Mechanism confirmed live; the PRD persistence section can be stamped closed.
- **Guards against:** committing the PRD to a FHIR write surface OpenEMR
  doesn't have; write-back through the side door via intake lists.

## PS-5 — One-directional dedup invariant

- **Statement:** the derived record is the ONLY thing supersession may ever
  suppress. A real observation is never mutated or hidden by dedup. A
  derived observation is superseded by a real one matching patient +
  normalized analyte (+ unit normalization) + collection-date tolerance
  window. An ambiguous match keeps BOTH and flags — never merges.
  (Derived-vs-derived is separate: re-extraction versions, retains priors.)
- **Acceptance:** tests for (a) clean supersession, (b) ambiguous match →
  both retained + flagged, (c) attempted suppression of a real observation
  is impossible by construction (API shape, not runtime check).
- **Guards against:** wrong-merge of two distinct real draws (data loss /
  clinical error) — asymmetrically worse than a visible duplicate.

## PS-6 — No grounding-by-proxy (verifier invariant + golden case)

- **Statement:** a derived observation never terminates a citation chain;
  grounding resolves through to the source document. Source document gone ⇒
  claim citing its derived observation is UNGROUNDED (fail closed).
- **Acceptance:** golden-set case — source deleted, derived record present,
  claim comes back ungrounded. Documented failure mode: "guards against
  grounded-by-proxy / self-laundering extraction."
- **Guards against:** the agent's own prior output re-entering as evidence
  — the Week 1 bright line surviving the write amendment.

## PS-7 — Injection: two surfaces, both graded

- **Statement:** (a) pixels → extraction VLM: hardened extraction prompt +
  schema-as-containment — output constrained to typed fields regardless of
  instruction-like content in the image. (b) extracted free-text → answer
  model on a later turn: extracted free-text inherits Week 1
  untrusted-content treatment on read.
- **Acceptance:** two golden cases — an adversarial fixture document whose
  embedded instructions do not alter extraction behavior (`schema_valid` +
  `safe_refusal` hold), and a steering-via-extracted-field case where a
  benign-looking chief concern fails to steer the answer turn.
- **Guards against:** the injection point moving upstream (a) or laundering
  through the record into a later turn (b) — neither covered by the Week 1
  rule as written.

## PS-8 — PHI-free logs: dumb data, dumb detector

- **Statement:** trace/log events carry references only — chunk ids not
  snippet text, field paths not values — from PHI and non-PHI sources
  alike. The CI detector flags any identifier-shaped or value-with-unit
  pattern regardless of provenance, with one narrow allowlist keyed on
  operational shape (durations, token counts, cost figures, HTTP statuses).
- **Acceptance:** detector runs in CI over emitted traces + eval outputs;
  a fixture trace containing `K 6.8 mmol/L` fails; a trace containing
  `p95: 847ms`, `tokens: 1204`, `cost_usd: 0.0113`, `status: 503` passes.
- **Guards against:** smart-detector discrimination logic that
  false-negatives on real values or red-gates on its own latency metrics;
  guideline text leaking into traces at all.

## PS-9 — Cost model: attribution + behavioral projection

- **Statement:** every vendor call's StepRecord carries units consumed + a
  versioned unit price; per-encounter roll-up by correlation ID; per-vendor
  roll-up on the dashboard. Projections at 100/1K/10K/100K users state an
  explicit questions-per-encounter assumption per tier (extraction scales
  per-document; retrieval/answer per-question) and name each tier's
  architectural inflection (embedding cache, batched extraction, in-house
  rerank).
- **Acceptance:** cost report derivable from traces alone; each projection
  tier shows its Q/encounter assumption and inflection note.
- **Guards against:** the token-multiplication projection the spec
  explicitly rejects; a CTO asking "what happens when physicians ask twice
  as many questions?" and the model having no answer.

## PS-10 — Routing legibility + the one conditional edge

- **Statement:** supervisor routing is state-dependent with exactly one
  conditional forward edge (critical finding + physician engagement →
  evidence-retriever, UC6→UC4); the graph is acyclic and terminates by
  construction. **Firing rule:** the edge fires on the engagement turn
  (question about the flag, or flag-open), never during snapshot/pre-chart
  composition — the snapshot renders flags from detector output alone,
  preserving the zero-RAG-on-snapshot property. **Retrieval mode:** the
  edge resolves its chunk via a deterministic finding-type → chunk map
  (unit-tested pure table), never similarity ranking; `critical.*` chunks
  stay in the hybrid index for free-text asks only. The dashboard renders
  the per-turn route (decisions, stated reasons, child spans).
- **Acceptance:** (a) golden case — a snapshot turn with a critical finding
  present emits zero retrieval steps in its trace; (b) golden case — the
  engagement turn retrieves exactly the mapped chunk for the finding type;
  (c) map-completeness invariant — every `CriticalFindingType` maps to a
  chunk id present in the corpus manifest; (d) a document-bearing turn's
  trace shows a different route than a plain follow-up; no other re-entry
  edges exist.
- **Guards against:** silently re-taxing the 90-second snapshot path the
  design just cleared; fuzzy retrieval surfacing the wrong response
  protocol at the highest-stakes moment; "two functions with logging"
  skepticism on one side, graph theater on the other.

## PS-14 — Mixed-source citation composition (detector + guideline)

- **Statement:** a critical-finding answer grounds in two source classes —
  the detector (`source_type: detector`: flag, threshold, ARUP provenance)
  and the practice protocol (`source_type: guideline`: the response). All
  source classes enter the same `ReferenceIndex` mint (one-mint-one-index
  holds across classes); rendering keeps patient facts, detector flags, and
  guideline evidence visually separate.
- **Acceptance:** golden case — a critical-finding engagement answer
  carries both `detector` and `guideline` citations, both verify, and the
  three registers render separately. Documented failure mode: "guards
  against a composition path the single-source chronic chunks never
  exercise."
- **Guards against:** the verifier or renderer silently mishandling the
  first answer whose halves come from different source classes — discovered
  in front of a grader instead of in CI.

## PS-11 — Quantization of the regression threshold is intentional

- **Statement:** at ~10 cases/category, the >5% regression clause collapses
  to any-single-flip-fails. This is the intended clinical bar; the
  percentage exists for when N grows.
- **Acceptance:** comparator tests cover the N=10 single-flip case and a
  larger-N fractional case; the doc states the intent.
- **Guards against:** a grader doing the arithmetic and reading accident
  instead of intent.

## PS-12 — Degradation pair, asymmetric

- **Statement:** rerank down → hybrid-score fallback, flagged. Embed down at
  query time → keyword-only retrieval, flagged in trace + degraded
  `/ready`. Embed down at index-build time → stale-index alarm,
  operator-facing, never user-facing.
- **Acceptance:** integration tests for each path assert the flag/status;
  `/ready` shows degraded (not down) with the failing dependency named.
- **Guards against:** the unhandled half of the vendor-outage pair, and
  silent quality degradation.

## PS-13 — Gate speed is a blocking property

- **Statement:** the PR-blocking eval job runs with zero network (vendor
  fixtures), 50 cases, small fixture corpus; its time budget is set from
  the first measured run and recorded in `W2_ARCHITECTURE.md` §7; exceeding
  it is a regression.
- **Acceptance:** CI job has a timeout reflecting the budget; the budget
  number is committed after first measurement.
- **Guards against:** "PR-blocking" + slow-enough-to-bypass — a gate people
  route around is not a gate.

<?php

/**
 * Query-time entry point for guideline evidence retrieval — the PS-12
 * degradation pair's query-time half (TRO-28; build-time is TRO-26's
 * stale-index alarm; W2_ARCHITECTURE.md §5 "Degradation — the pair,
 * asymmetrically").
 *
 * Embeds the physician's free-text query via Cohere embed, then delegates to
 * `HybridRetriever::retrieveWithDegradation()` for the candidate union +
 * rerank (or rerank-fallback). Two independent failure axes compose into one
 * `RetrievalOutcome`:
 *
 * - **Embed unreachable at query time** ({@see EmbeddingUnavailableException}):
 *   retrieval proceeds keyword-only (`$queryEmbeddingVecText = null`),
 *   flagged `denseDegraded`. This is *this* class's call — the retriever
 *   itself never decides why its vector argument is null.
 * - **Rerank unreachable:** decided inside `retrieveWithDegradation()` and
 *   surfaced here as `rerankDegraded` on its outcome, carried through
 *   unchanged.
 *
 * Both failures compose independently (PS-12: "the pair, asymmetrically") —
 * worse results beat no results, but never silently: every degraded path
 * still returns chunks when the corpus has candidates, and both flags feed
 * the trace (`StepRecord`) and `/ready` (Stage 6 wiring), never silently
 * absorbed into a single combined signal.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final readonly class EvidenceRetrievalService
{
    public function __construct(
        private CohereEmbedClient $embedder,
        private HybridRetriever $retriever,
    ) {
    }

    /**
     * Retrieves at most `$topK` guideline chunks for the given query,
     * degrading honestly along either or both vendor axes rather than
     * failing the turn.
     *
     * Vendor note (flagged, not fixed here — {@see CohereEmbedClient::embed()}
     * hardcodes `input_type: 'search_document'`; Cohere's own guidance is
     * `search_query` for query-side embeddings, asymmetric from the
     * `search_document` type used at index build. `CohereEmbedClient` is
     * merged, shared vendor-boundary code out of this ticket's scope to
     * edit — using it as-is here is a retrieval-*quality* concern for the
     * vendor boundary, not a gate-blocking correctness issue for this
     * service.
     */
    public function search(string $query, int $topK): RetrievalOutcome
    {
        try {
            // Query-side embeddings use Cohere's asymmetric search_query type
            // (documents were indexed as search_document).
            $vectors = $this->embedder->embed([$query], 'search_query');
            $vecText = '[' . implode(',', $vectors[0] ?? []) . ']';
            $outcome = $this->retriever->retrieveWithDegradation($query, $vecText, $topK);

            return new RetrievalOutcome($outcome->chunks, false, $outcome->rerankDegraded);
        } catch (EmbeddingUnavailableException) {
            $outcome = $this->retriever->retrieveWithDegradation($query, null, $topK);

            return new RetrievalOutcome($outcome->chunks, true, $outcome->rerankDegraded);
        }
    }
}

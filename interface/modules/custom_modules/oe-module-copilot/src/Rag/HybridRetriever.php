<?php

/**
 * Keyword + dense hybrid retriever with rerank (W2_ARCHITECTURE.md §5
 * "Hybrid RAG + rerank"; `evidence-retriever` worker per §6).
 *
 * Runs the keyword (FULLTEXT) and dense (native `VECTOR`) legs against the
 * module-owned corpus index, unions the two candidate sets deduping by
 * chunk id *before* rerank (so a chunk that scores on both legs reaches the
 * reranker exactly once), sends the union to Cohere Rerank, and returns at
 * most `$topK` chunks — highest reranked relevance first. Only reranked,
 * cited snippets ever leave this class (minimum-necessary applies to
 * evidence too): the documents sent to rerank are chunk bodies alone, the
 * corpus being non-PHI curated content, not chart data.
 *
 * An empty candidate union returns an empty list *without* calling the
 * reranker at all — retrieval says so explicitly rather than filling the
 * gap from model weights (§5 "Degradation"), and it never spends a vendor
 * call on nothing to rank.
 *
 * `retrieve()` is the frozen TRO-27 contract: candidate union → rerank →
 * top-k, uncaught rerank failures propagate as `RerankUnavailableException`.
 * `retrieveWithDegradation()` (TRO-28; PS-12) shares the same candidate-union
 * internals but falls back to candidate-union order (flagged) when the
 * reranker is unreachable, instead of throwing — "worse results beat no
 * results, but never silently" (§5 "Degradation"). Acquiring
 * `$queryEmbeddingVecText` itself (calling the embed endpoint for the
 * physician's free-text question) and the query-time embedder-unreachable
 * fallback are `EvidenceRetrievalService`'s wiring (TRO-28) — this class only
 * ever takes the vector as an already-computed, optional precomputed vector
 * literal (MariaDB `VEC_FromText()` argument text) and skips the dense leg
 * entirely when it is null.
 *
 * Zero RAG on the snapshot/pre-chart path (§5): this class is never called
 * from that path — enforcement of that boundary lives in the supervisor
 * (§6), not here.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

use OpenEMR\Common\Database\QueryUtils;

final class HybridRetriever
{
    /** Per-leg candidate cap, applied before union + rerank. */
    private const CANDIDATE_LIMIT = 20;

    public function __construct(
        private readonly CohereRerankClient $reranker,
    ) {
    }

    /**
     * @return list<RetrievedChunk> at most `$topK`, highest reranked relevance first
     */
    public function retrieve(string $query, ?string $queryEmbeddingVecText, int $topK): array
    {
        $this->validateQueryAndTopK($query, $topK);

        $candidates = $this->candidateUnion($query, $queryEmbeddingVecText);
        if ($candidates === []) {
            return [];
        }

        return $this->rerankCandidates($query, $candidates, $topK);
    }

    /**
     * TRO-28/PS-12: same candidate-union internals as `retrieve()`, but a
     * reranker failure falls back to candidate-union order (flagged
     * `rerankDegraded`) instead of letting `RerankUnavailableException`
     * propagate. `denseDegraded` on the returned outcome is always `false`
     * here — this method only ever sees a vector-or-null and has no way to
     * know *why* it is null; `EvidenceRetrievalService` decides that flag
     * from the embed-call outcome and composes the final `RetrievalOutcome`.
     */
    public function retrieveWithDegradation(string $query, ?string $queryEmbeddingVecText, int $topK): RetrievalOutcome
    {
        $this->validateQueryAndTopK($query, $topK);

        $candidates = $this->candidateUnion($query, $queryEmbeddingVecText);
        if ($candidates === []) {
            return new RetrievalOutcome([], false, false);
        }

        try {
            return new RetrievalOutcome($this->rerankCandidates($query, $candidates, $topK), false, false);
        } catch (RerankUnavailableException) {
            return new RetrievalOutcome($this->fallbackOrderedChunks($candidates, $topK), false, true);
        }
    }

    private function validateQueryAndTopK(string $query, int $topK): void
    {
        if (trim($query) === '') {
            throw new \DomainException('HybridRetriever query must be non-blank');
        }

        if ($topK < 1) {
            throw new \DomainException('HybridRetriever topK must be >= 1');
        }
    }

    /**
     * Keyword + dense candidates, deduped by chunk id (keyword leg wins ties,
     * matching the pre-refactor union order) — the shared internals behind
     * both `retrieve()` and `retrieveWithDegradation()`.
     *
     * @return list<array{chunk_id: string, source_id: string, heading: string, body: string}>
     */
    private function candidateUnion(string $query, ?string $queryEmbeddingVecText): array
    {
        $candidatesByChunkId = [];
        foreach ($this->keywordCandidates($query) as $candidate) {
            $candidatesByChunkId[$candidate['chunk_id']] ??= $candidate;
        }

        if ($queryEmbeddingVecText !== null) {
            foreach ($this->denseCandidates($queryEmbeddingVecText) as $candidate) {
                $candidatesByChunkId[$candidate['chunk_id']] ??= $candidate;
            }
        }

        return array_values($candidatesByChunkId);
    }

    /**
     * Reranker-unreachable fallback (PS-12): candidates in union order
     * (keyword leg first, then dense-only additions), sliced to `$topK`, with
     * a deterministic descending score so "highest relevance first" stays a
     * coherent statement about the returned list even without a real rerank
     * score. The frozen degradation suite asserts count + flags only, not
     * these score values.
     *
     * @param list<array{chunk_id: string, source_id: string, heading: string, body: string}> $candidates
     *
     * @return list<RetrievedChunk> at most `$topK`, union order
     */
    private function fallbackOrderedChunks(array $candidates, int $topK): array
    {
        $count = count($candidates);
        $chunks = [];
        foreach (array_slice($candidates, 0, $topK) as $position => $candidate) {
            $chunks[] = new RetrievedChunk(
                $candidate['chunk_id'],
                $candidate['source_id'],
                $candidate['heading'],
                $candidate['body'],
                1.0 - ($position * (1.0 / max($count, 1))),
            );
        }

        return $chunks;
    }

    /**
     * @param list<array{chunk_id: string, source_id: string, heading: string, body: string}> $candidates
     *
     * @return list<RetrievedChunk> at most `$topK`, highest reranked relevance first
     */
    private function rerankCandidates(string $query, array $candidates, int $topK): array
    {
        $documents = array_map(
            static fn (array $candidate): string => $candidate['body'],
            $candidates,
        );

        $topN = min($topK, count($candidates));
        $reranked = $this->reranker->rerank($query, $documents, $topN);

        $chunks = [];
        foreach (array_slice($reranked, 0, $topK) as $result) {
            $candidate = $candidates[$result['index']];
            $chunks[] = new RetrievedChunk(
                $candidate['chunk_id'],
                $candidate['source_id'],
                $candidate['heading'],
                $candidate['body'],
                $result['score'],
            );
        }

        return $chunks;
    }

    /**
     * Keyword leg: FULLTEXT natural-language match over heading + body,
     * relevance-ordered with a chunk_id tiebreak. Without the ORDER BY,
     * LIMIT takes an ARBITRARY subset in an unstable order — the same
     * rebuilt index can return the same logical candidates in a different
     * order across runs, which both ignores relevance and breaks the
     * deterministic-replay property the eval gate's input-keyed vendor
     * fixtures depend on (W2_ARCHITECTURE.md §7; PS-2).
     *
     * @return list<array{chunk_id: string, source_id: string, heading: string, body: string}>
     */
    private function keywordCandidates(string $query): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT chunk_id, source_id, heading, body FROM ' . CorpusIndexSchema::CHUNK_TABLE
                . ' WHERE MATCH(heading, body) AGAINST(? IN NATURAL LANGUAGE MODE)'
                . ' ORDER BY MATCH(heading, body) AGAINST(? IN NATURAL LANGUAGE MODE) DESC, chunk_id ASC'
                . ' LIMIT ' . self::CANDIDATE_LIMIT,
            [$query, $query],
        );

        return $this->narrowCandidateRows($rows);
    }

    /**
     * Dense leg: nearest neighbors by cosine distance over the native
     * `VECTOR` column, joined back to chunk text.
     *
     * @return list<array{chunk_id: string, source_id: string, heading: string, body: string}>
     */
    private function denseCandidates(string $queryEmbeddingVecText): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT c.chunk_id, c.source_id, c.heading, c.body'
                . ' FROM ' . CorpusIndexSchema::EMBEDDING_TABLE . ' e'
                . ' JOIN ' . CorpusIndexSchema::CHUNK_TABLE . ' c ON c.chunk_id = e.chunk_id'
                . ' ORDER BY VEC_DISTANCE_COSINE(e.embedding, VEC_FromText(?))'
                . ' LIMIT ' . self::CANDIDATE_LIMIT,
            [$queryEmbeddingVecText],
        );

        return $this->narrowCandidateRows($rows);
    }

    /**
     * Parses untrusted DB row shapes into the candidate array shape —
     * narrow, don't cast: a non-string column value fails loudly rather
     * than being silently coerced.
     *
     * @param list<array<mixed>> $rows
     *
     * @return list<array{chunk_id: string, source_id: string, heading: string, body: string}>
     */
    private function narrowCandidateRows(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $chunkId = $row['chunk_id'] ?? null;
            $sourceId = $row['source_id'] ?? null;
            $heading = $row['heading'] ?? null;
            $body = $row['body'] ?? null;

            if (!is_string($chunkId) || !is_string($sourceId) || !is_string($heading) || !is_string($body)) {
                throw new \RuntimeException('Corpus index query returned a non-string column value');
            }

            $candidates[] = [
                'chunk_id' => $chunkId,
                'source_id' => $sourceId,
                'heading' => $heading,
                'body' => $body,
            ];
        }

        return $candidates;
    }
}

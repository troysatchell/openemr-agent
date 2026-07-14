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
 * Degradation fallbacks are explicitly NOT this class's scope: a reranker
 * failure (RerankUnavailableException) and a query-time embedding
 * unreachable state (hybrid falling back to keyword-only, flagged in the
 * trace) are TRO-28's wiring. Likewise, acquiring `$queryEmbeddingVecText`
 * itself (calling the embed endpoint for the physician's free-text
 * question) is TRO-28/32's wiring — this class only ever takes it as an
 * already-computed, optional precomputed vector literal (MariaDB
 * `VEC_FromText()` argument text) and skips the dense leg entirely when it
 * is null.
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
        if (trim($query) === '') {
            throw new \DomainException('HybridRetriever query must be non-blank');
        }

        if ($topK < 1) {
            throw new \DomainException('HybridRetriever topK must be >= 1');
        }

        $candidatesByChunkId = [];
        foreach ($this->keywordCandidates($query) as $candidate) {
            $candidatesByChunkId[$candidate['chunk_id']] ??= $candidate;
        }

        if ($queryEmbeddingVecText !== null) {
            foreach ($this->denseCandidates($queryEmbeddingVecText) as $candidate) {
                $candidatesByChunkId[$candidate['chunk_id']] ??= $candidate;
            }
        }

        if ($candidatesByChunkId === []) {
            return [];
        }

        return $this->rerankCandidates($query, array_values($candidatesByChunkId), $topK);
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
     * Keyword leg: FULLTEXT natural-language match over heading + body.
     *
     * @return list<array{chunk_id: string, source_id: string, heading: string, body: string}>
     */
    private function keywordCandidates(string $query): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT chunk_id, source_id, heading, body FROM ' . CorpusIndexSchema::CHUNK_TABLE
                . ' WHERE MATCH(heading, body) AGAINST(? IN NATURAL LANGUAGE MODE)'
                . ' LIMIT ' . self::CANDIDATE_LIMIT,
            [$query],
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

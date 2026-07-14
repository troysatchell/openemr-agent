<?php

/**
 * Outcome of one evidence-retrieval query, carrying the PS-12 degradation
 * flags alongside the reranked (or fallback-ordered) chunks (TRO-28;
 * W2_ARCHITECTURE.md §5 "Degradation — the pair, asymmetrically").
 *
 * `denseDegraded` is true when the dense (embedding) leg was unreachable at
 * query time and retrieval fell back to keyword-only. `rerankDegraded` is
 * true when the reranker was unreachable and the chunks are returned in
 * candidate-union order instead of reranked order. Either, both, or neither
 * may be set independently — the pair degrades asymmetrically, not as a
 * single combined flag, because each names a different failing dependency
 * for the trace and for `/ready`.
 *
 * `chunks` is boundary-validated: every element must be a `RetrievedChunk`,
 * so a caller cannot silently smuggle an untyped or partial row through this
 * DTO the way raw extraction data must never be partially accepted
 * (Decision W2 posture applied here to retrieval).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final readonly class RetrievalOutcome
{
    /**
     * @param list<mixed> $chunks at most `topK`, highest relevance
     *        first (reranked order, or candidate-union order when
     *        `rerankDegraded` is true)
     */
    public function __construct(
        public array $chunks,
        public bool $denseDegraded,
        public bool $rerankDegraded,
    ) {
        foreach ($this->chunks as $chunk) {
            if (!$chunk instanceof RetrievedChunk) {
                throw new \DomainException('RetrievalOutcome chunks must all be RetrievedChunk instances');
            }
        }
    }
}

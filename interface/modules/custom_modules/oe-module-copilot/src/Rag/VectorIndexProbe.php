<?php

/**
 * `/ready` probe for the RAG corpus vector index (TRO-28; PS-12;
 * W2_ARCHITECTURE.md §8 "`/ready` grows document-storage, vector-index, and
 * reranker probes").
 *
 * Counts the module-owned chunk and embedding tables directly and reports
 * per-dependency status — never binary up/down (`ProbeStatus` has no `Down`
 * case): an empty index and a partially-embedded index are both `Degraded`,
 * each with its own named reason, because both still leave keyword-only
 * retrieval usable (PS-12's "worse results beat no results, never
 * silently").
 *
 * Deliberately catches no exceptions: a missing table means
 * {@see \OpenEMR\Common\Database\SqlQueryException} propagates uncaught. That
 * is a real bug (the module install SQL did not run), not a degraded
 * dependency, and `/ready`'s existing fail-closed handling is the correct
 * place to surface it — this class never launders "the table is gone" into
 * a softer "degraded" status.
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

final class VectorIndexProbe
{
    private const DEPENDENCY = 'vector-index';

    public function check(): ProbeResult
    {
        $chunkCount = $this->count(CorpusIndexSchema::CHUNK_TABLE);

        if ($chunkCount === 0) {
            return new ProbeResult(
                self::DEPENDENCY,
                ProbeStatus::Degraded,
                'corpus index is empty — run bin/index-corpus.php',
            );
        }

        $embeddingCount = $this->count(CorpusIndexSchema::EMBEDDING_TABLE);

        if ($embeddingCount < $chunkCount) {
            return new ProbeResult(
                self::DEPENDENCY,
                ProbeStatus::Degraded,
                sprintf(
                    '%d of %d chunks have embeddings — dense leg degraded, keyword-only',
                    $embeddingCount,
                    $chunkCount,
                ),
            );
        }

        return new ProbeResult(self::DEPENDENCY, ProbeStatus::Ok);
    }

    private function count(string $table): int
    {
        $value = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM ' . $table,
            'c',
        );

        if (!is_numeric($value)) {
            throw new \RuntimeException('Corpus index count query did not return a numeric value');
        }

        return (int) $value;
    }
}

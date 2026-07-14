<?php

/**
 * The engaged-finding evidence worker: deterministic by-id chunk fetch, zero
 * vendor calls (TRO-31/TRO-35; W2_ARCHITECTURE.md §6 "Critical-value evidence
 * is mapped, not searched"; corpus README §6).
 *
 * When the supervisor's one conditional edge fires because the physician
 * engaged a present critical finding — never for a free-text evidence
 * question, which stays {@see \OpenEMR\Modules\Copilot\Rag\EvidenceRetrieverWorkerImpl}'s
 * real hybrid-RAG path — the chunk to fetch is already resolved by
 * {@see \OpenEMR\Modules\Copilot\Evidence\CriticalFindingChunkMap} before this
 * worker is even constructed. This implementation's `run()` therefore never
 * consults `$question`/`$topK`: it fetches the one mapped chunk BY ID from
 * the real corpus index — an exact-match `SELECT ... WHERE chunk_id = ?`,
 * never a similarity search — and returns it as the turn's sole evidence.
 * A mapped chunk id absent from the real index (which
 * {@see \OpenEMR\Modules\Copilot\Evidence\CriticalFindingChunkMapTest} asserts
 * can never happen for a declared finding type) fails loud rather than
 * silently returning no evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Orchestration\EvidenceRetrieverWorker;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use OpenEMR\Modules\Copilot\Rag\RetrievalOutcome;
use OpenEMR\Modules\Copilot\Rag\RetrievedChunk;

final readonly class MappedChunkEvidenceWorker implements EvidenceRetrieverWorker
{
    public function __construct(private string $mappedChunkId)
    {
        if (trim($mappedChunkId) === '') {
            throw new \DomainException('MappedChunkEvidenceWorker requires a non-blank mapped chunk id');
        }
    }

    public function run(string $question, int $topK, TraceContext $workerSpan): RetrievalOutcome
    {
        $row = QueryUtils::querySingleRow(
            'SELECT chunk_id, source_id, heading, body FROM ' . CorpusIndexSchema::CHUNK_TABLE . ' WHERE chunk_id = ?',
            [$this->mappedChunkId],
        );

        if (!is_array($row)) {
            throw new \RuntimeException('MappedChunkEvidenceWorker could not resolve its mapped chunk id in the corpus index');
        }

        $chunkId = $row['chunk_id'] ?? null;
        $sourceId = $row['source_id'] ?? null;
        $heading = $row['heading'] ?? null;
        $body = $row['body'] ?? null;
        if (!is_string($chunkId) || !is_string($sourceId) || !is_string($heading) || !is_string($body)) {
            throw new \RuntimeException('MappedChunkEvidenceWorker read a non-string column from the corpus index');
        }

        return new RetrievalOutcome([new RetrievedChunk($chunkId, $sourceId, $heading, $body, 1.0)], false, false);
    }
}

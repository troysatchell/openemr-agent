<?php

/**
 * The evidence-retriever worker's production implementation (TRO-32;
 * W2_ARCHITECTURE.md §6 "evidence-retriever — wraps §5; returns cited
 * snippets").
 *
 * A thin adapter: the real hybrid-RAG + rerank pipeline (embed, candidate
 * union, rerank-or-degrade, PS-12's degradation pair) is entirely
 * `EvidenceRetrievalService`'s job — this class only satisfies the
 * `EvidenceRetrieverWorker` port the supervisor depends on abstractly, so
 * the supervisor's own routing logic can be unit-tested against
 * worker-level stubs while the eval gate always exercises this real
 * implementation (§6: "worker-level stubs ... never appear in the eval
 * gate").
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Orchestration\EvidenceRetrieverWorker;

final readonly class EvidenceRetrieverWorkerImpl implements EvidenceRetrieverWorker
{
    public function __construct(private EvidenceRetrievalService $service)
    {
    }

    /**
     * The `$workerSpan` argument is accepted to satisfy the port and carried
     * for the trace wiring that lands with the gate harness — span-tagged
     * retrieval `StepRecord`s (which sub-call inside `$service->search()`
     * produced which chunks) are Stage 6 dashboard scope, not this
     * ticket's. `EvidenceRetrievalService::search()` does not yet accept a
     * span, so it is not threaded through the delegation below.
     */
    public function run(string $question, int $topK, TraceContext $workerSpan): RetrievalOutcome
    {
        return $this->service->search($question, $topK);
    }
}

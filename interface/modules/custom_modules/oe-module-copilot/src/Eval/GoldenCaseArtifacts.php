<?php

/**
 * One case's execution artifacts from a golden-set run — deep enough to pin
 * the armed golden cases' behavior directly, not merely via rubric booleans
 * (TRO-35; eval/goldenset/README.md; W2_ARCHITECTURE.md §7).
 *
 * A pure record of what {@see GoldenSetRunner} observed while executing one
 * case: the supervisor's plan, how many retrieval steps and vendor calls it
 * cost, which evidence chunks and claim partitions resulted, and the trace
 * surface's step names. `vendorCallCounts` is PER-CASE — corpus indexing at
 * setup (a run()-scoped, one-time embed call) never pollutes an individual
 * case's counts, so a case asserting "zero vendor calls" (the zero-RAG and
 * mapped-chunk cases) is asserting about ITS OWN turn, not the run as a
 * whole.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final readonly class GoldenCaseArtifacts
{
    /**
     * @param list<string> $planStepKinds ordered {@see \OpenEMR\Modules\Copilot\Orchestration\SupervisorStepKind} names
     * @param array<string, int> $vendorCallCounts keys 'embed', 'rerank', 'vlm'
     * @param list<string> $evidenceChunkIds
     * @param list<int> $groundedClaimIndexes
     * @param list<int> $rejectedClaimIndexes
     * @param list<string> $groundedCitationSourceTypes
     * @param list<string> $traceStepNames
     */
    public function __construct(
        public array $planStepKinds,
        public int $retrievalStepCount,
        public array $vendorCallCounts,
        public array $evidenceChunkIds,
        public array $groundedClaimIndexes,
        public array $rejectedClaimIndexes,
        public array $groundedCitationSourceTypes,
        public array $traceStepNames,
        public ?string $extractionStatus,
    ) {
        if ($this->retrievalStepCount < 0) {
            throw new \DomainException('GoldenCaseArtifacts retrievalStepCount must be >= 0');
        }
        foreach ($this->vendorCallCounts as $count) {
            if ($count < 0) {
                throw new \DomainException('GoldenCaseArtifacts vendorCallCounts must all be non-negative integers');
            }
        }
    }
}

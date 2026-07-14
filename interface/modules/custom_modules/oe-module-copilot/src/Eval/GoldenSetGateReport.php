<?php

/**
 * The full report of one golden-set gate run (TRO-35; eval/goldenset/README.md;
 * W2_ARCHITECTURE.md §7).
 *
 * Carries (a) the six-category {@see EvalRunResult} the {@see BaselineComparator}
 * consumes, (b) per-case rubric failures — human-readable, empty on an
 * all-green run, never silently dropped — and (c) per-case
 * {@see GoldenCaseArtifacts} for the armed golden cases' direct behavioral
 * assertions.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final readonly class GoldenSetGateReport
{
    /**
     * @param array<string, list<string>> $caseFailures caseId => rubric failure descriptions ([] when all green)
     * @param array<string, GoldenCaseArtifacts> $artifactsByCaseId
     */
    public function __construct(
        private EvalRunResult $result,
        private array $caseFailures,
        private array $artifactsByCaseId,
    ) {
    }

    public function result(): EvalRunResult
    {
        return $this->result;
    }

    /**
     * @return array<string, list<string>>
     */
    public function caseFailures(): array
    {
        return $this->caseFailures;
    }

    public function artifacts(string $caseId): GoldenCaseArtifacts
    {
        return $this->artifactsByCaseId[$caseId]
            ?? throw new \DomainException(sprintf('GoldenSetGateReport has no artifacts for unknown case id "%s"', $caseId));
    }
}

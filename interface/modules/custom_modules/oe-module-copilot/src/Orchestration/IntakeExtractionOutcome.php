<?php

/**
 * Typed result of one intake-extractor worker run (TRO-32; W2_ARCHITECTURE §6).
 *
 * Counts only — never extracted content: this object rides the trace path
 * (handoff StepRecords report worker outcomes) and the trace is PHI-free by
 * schema, not by discipline.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

final readonly class IntakeExtractionOutcome
{
    public function __construct(
        public int $documentsProcessed,
        public int $observationsPersisted,
        public int $candidatesPersisted,
        public int $extractionFailures,
    ) {
        if ($documentsProcessed < 0 || $observationsPersisted < 0 || $candidatesPersisted < 0 || $extractionFailures < 0) {
            throw new \DomainException('IntakeExtractionOutcome counts must be >= 0');
        }
    }
}

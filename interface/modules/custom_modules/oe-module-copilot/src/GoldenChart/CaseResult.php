<?php

/**
 * The co-pilot's observed output for one golden-chart case, as fed to the scorer
 * (T11; ARCHITECTURE.md §6).
 *
 * surfacedCriticalIds are the critical-subset ids the run actually surfaced;
 * flaggedIds are everything the run flagged (used to score precision, R7); the
 * fact counts are the human-adjudicated correct/incorrect stated facts (R6).
 * Counts are non-negative by construction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\GoldenChart;

final readonly class CaseResult
{
    /**
     * @param list<string> $surfacedCriticalIds
     * @param list<string> $flaggedIds
     */
    public function __construct(
        public array $surfacedCriticalIds,
        public array $flaggedIds,
        public int $correctFactCount,
        public int $incorrectFactCount,
    ) {
        if ($correctFactCount < 0) {
            throw new \DomainException('correctFactCount must not be negative.');
        }
        if ($incorrectFactCount < 0) {
            throw new \DomainException('incorrectFactCount must not be negative.');
        }
    }
}

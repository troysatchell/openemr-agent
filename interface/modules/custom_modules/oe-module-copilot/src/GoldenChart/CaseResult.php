<?php

/**
 * The co-pilot's observed output for one golden-chart case, as fed to the scorer
 * (T11; ARCHITECTURE.md §6; two-track rework T15).
 *
 * surfacedCriticalIds are the critical-subset ids the run actually surfaced;
 * flaggedIds are everything the run flagged on the critical subset — scored as
 * TRACK 1 hard zeros (any miss, any false flag on an adjudicated case fails the
 * build). The fact counts are the human-adjudicated correct/incorrect stated
 * facts — also TRACK 1 (any incorrect fact fails; the rate is monitoring only).
 * judgmentFlaggedIds are everything the run flagged against §3b judgment items
 * (care gaps, trends) — scored separately as TRACK 2 (provisional regression
 * thresholds) and must never feed the critical-subset counts or vice versa.
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
     * @param list<string> $judgmentFlaggedIds
     */
    public function __construct(
        public array $surfacedCriticalIds,
        public array $flaggedIds,
        public int $correctFactCount,
        public int $incorrectFactCount,
        public array $judgmentFlaggedIds = [],
    ) {
        if ($correctFactCount < 0) {
            throw new \DomainException('correctFactCount must not be negative.');
        }
        if ($incorrectFactCount < 0) {
            throw new \DomainException('incorrectFactCount must not be negative.');
        }
    }
}

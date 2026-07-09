<?php

/**
 * The scored result of one golden-chart case (T11; ARCHITECTURE.md §6; two-track
 * rework T15).
 *
 * missedCritical names the must-not-miss labels the run failed to surface — named
 * (not merely counted) so the omission ratchet ("once missed, never silently
 * missed again", R13) can work. truePositiveFlags / falsePositiveFlags are the
 * critical-subset flag counts feeding TRACK 1 (any false positive here is a
 * hard-zero failure, not a precision drag); the fact counts feed the TRACK 1
 * factual hard zero (any incorrectFactCount > 0 fails). judgmentTruePositiveFlags
 * / judgmentFalsePositiveFlags / judgmentLabelCount are the analogous counts for
 * §3b judgment items, scored independently and feeding only TRACK 2's provisional
 * regression thresholds — they must never be mixed with the critical counts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\GoldenChart;

final readonly class CaseScore
{
    /**
     * @param list<string> $missedCritical
     */
    public function __construct(
        public array $missedCritical,
        public int $truePositiveFlags,
        public int $falsePositiveFlags,
        public int $correctFactCount,
        public int $incorrectFactCount,
        public int $judgmentTruePositiveFlags = 0,
        public int $judgmentFalsePositiveFlags = 0,
        public int $judgmentLabelCount = 0,
    ) {
    }
}

<?php

/**
 * The scored result of one golden-chart case (T11; ARCHITECTURE.md §6).
 *
 * missedCritical names the must-not-miss labels the run failed to surface — named
 * (not merely counted) so the omission ratchet ("once missed, never silently
 * missed again", R13) can work. truePositiveFlags / falsePositiveFlags feed the
 * precision floor (R7); the fact counts pass through for the factual-accuracy
 * floor (R6).
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
    ) {
    }
}

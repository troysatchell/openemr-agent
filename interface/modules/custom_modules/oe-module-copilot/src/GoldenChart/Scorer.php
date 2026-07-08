<?php

/**
 * Pure scorer for one golden-chart case (T11; ARCHITECTURE.md §6).
 *
 * Deterministic, side-effect-free: it only compares the run's CaseResult against
 * the human labels. Misses are the must-not-miss labels not surfaced, in label
 * order (R13). Flags present in must-not-miss are true positives; the rest are
 * false positives that feed the precision floor (R7). Fact counts pass through
 * untouched (R6). The scorer never generates or repairs labels.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\GoldenChart;

final readonly class Scorer
{
    public function score(GoldenChartCase $case, CaseResult $result): CaseScore
    {
        $mustNotMiss = $case->labels->mustNotMiss;
        $surfaced = $result->surfacedCriticalIds;

        $missedCritical = [];
        foreach ($mustNotMiss as $labelId) {
            if (!in_array($labelId, $surfaced, true)) {
                $missedCritical[] = $labelId;
            }
        }

        $truePositiveFlags = 0;
        $falsePositiveFlags = 0;
        foreach ($result->flaggedIds as $flaggedId) {
            if (in_array($flaggedId, $mustNotMiss, true)) {
                $truePositiveFlags++;
            } else {
                $falsePositiveFlags++;
            }
        }

        return new CaseScore(
            $missedCritical,
            $truePositiveFlags,
            $falsePositiveFlags,
            $result->correctFactCount,
            $result->incorrectFactCount,
        );
    }
}

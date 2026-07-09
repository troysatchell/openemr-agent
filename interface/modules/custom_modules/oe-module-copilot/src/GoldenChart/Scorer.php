<?php

/**
 * Pure scorer for one golden-chart case (T11; ARCHITECTURE.md §6; two-track
 * rework T15).
 *
 * Deterministic, side-effect-free: it only compares the run's CaseResult against
 * the human labels. Misses are the must-not-miss labels not surfaced, in label
 * order (R13, TRACK 1 hard zero). Critical flags present in must-not-miss are
 * true positives; the rest are false positives — also a TRACK 1 hard zero, never
 * a rate to be excused by a floor. Fact counts pass through untouched (TRACK 1
 * factual hard zero). Judgment flags are scored the same way against
 * judgmentItems, but kept in a wholly separate tally: they feed only TRACK 2's
 * provisional regression thresholds and must never be mixed into the critical
 * counts above, nor vice versa. The scorer never generates or repairs labels.
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

        // Precision is over distinct flagged findings: a repeated id is the
        // same flag, not two, so dedupe before counting true/false positives.
        $truePositiveFlags = 0;
        $falsePositiveFlags = 0;
        foreach (array_unique($result->flaggedIds) as $flaggedId) {
            if (in_array($flaggedId, $mustNotMiss, true)) {
                $truePositiveFlags++;
            } else {
                $falsePositiveFlags++;
            }
        }

        // Judgment items are scored identically but kept fully separate — they
        // must never pollute (or be polluted by) the critical-subset counts.
        $judgmentItems = $case->labels->judgmentItems;
        $judgmentTruePositiveFlags = 0;
        $judgmentFalsePositiveFlags = 0;
        foreach (array_unique($result->judgmentFlaggedIds) as $judgmentFlaggedId) {
            if (in_array($judgmentFlaggedId, $judgmentItems, true)) {
                $judgmentTruePositiveFlags++;
            } else {
                $judgmentFalsePositiveFlags++;
            }
        }

        return new CaseScore(
            $missedCritical,
            $truePositiveFlags,
            $falsePositiveFlags,
            $result->correctFactCount,
            $result->incorrectFactCount,
            $judgmentTruePositiveFlags,
            $judgmentFalsePositiveFlags,
            count($judgmentItems),
        );
    }
}

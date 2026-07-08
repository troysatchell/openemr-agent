<?php

/**
 * The clinical-accuracy CI gate (T11; ARCHITECTURE.md §6 — "any miss on the
 * critical subset or any metric below floor fails the build").
 *
 * Only adjudicated cases count for anything. Zero adjudicated cases => NOT ARMED:
 * loud, but passing, because the labels are a Phase 0 human deliverable and
 * synthetic scaffolding must never gate the build. Once armed, any critical miss
 * fails regardless of floors (R13); precision (R7) and factual accuracy (R6) fail
 * only when measurable and below floor. Unmeasurable metrics (no flags / no facts)
 * are not failures.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\GoldenChart;

final readonly class AccuracyGate
{
    public function __construct(
        private float $precisionFloor,
        private float $factualAccuracyFloor,
    ) {
        if ($precisionFloor < 0.0 || $precisionFloor > 1.0) {
            throw new \DomainException('precisionFloor must be a fraction in [0, 1].');
        }
        if ($factualAccuracyFloor < 0.0 || $factualAccuracyFloor > 1.0) {
            throw new \DomainException('factualAccuracyFloor must be a fraction in [0, 1].');
        }
    }

    /**
     * @param list<array{GoldenChartCase, CaseResult}> $pairs
     */
    public function evaluate(array $pairs): GateReport
    {
        $scorer = new Scorer();

        $adjudicatedCount = 0;
        $criticalMisses = [];
        $totalTruePositives = 0;
        $totalFalsePositives = 0;
        $totalCorrectFacts = 0;
        $totalIncorrectFacts = 0;

        foreach ($pairs as $pair) {
            [$case, $result] = $pair;
            if (!$case->adjudicated) {
                continue;
            }

            $adjudicatedCount++;
            $score = $scorer->score($case, $result);

            foreach ($score->missedCritical as $labelId) {
                $criticalMisses[] = $case->id . ':' . $labelId;
            }
            $totalTruePositives += $score->truePositiveFlags;
            $totalFalsePositives += $score->falsePositiveFlags;
            $totalCorrectFacts += $score->correctFactCount;
            $totalIncorrectFacts += $score->incorrectFactCount;
        }

        if ($adjudicatedCount === 0) {
            return new GateReport(
                armed: false,
                passed: true,
                criticalMisses: [],
                precision: null,
                factualAccuracy: null,
                summary: sprintf(
                    'clinical-accuracy-gate: NOT ARMED — 0 of %d case(s) adjudicated; '
                    . 'labels are a Phase 0 human deliverable, harness ran on synthetic scaffolding only.',
                    count($pairs),
                ),
            );
        }

        $totalFlags = $totalTruePositives + $totalFalsePositives;
        $precision = $totalFlags > 0 ? $totalTruePositives / $totalFlags : null;

        $totalFacts = $totalCorrectFacts + $totalIncorrectFacts;
        $factualAccuracy = $totalFacts > 0 ? $totalCorrectFacts / $totalFacts : null;

        $passed = $criticalMisses === []
            && ($precision === null || $precision >= $this->precisionFloor)
            && ($factualAccuracy === null || $factualAccuracy >= $this->factualAccuracyFloor);

        $summary = sprintf(
            'clinical-accuracy-gate: %s — %d adjudicated case(s), %d critical miss(es); '
            . 'precision %s (floor %.2f), factual accuracy %s (floor %.2f).',
            $passed ? 'PASS' : 'FAIL',
            $adjudicatedCount,
            count($criticalMisses),
            $precision === null ? 'n/a' : sprintf('%.3f', $precision),
            $this->precisionFloor,
            $factualAccuracy === null ? 'n/a' : sprintf('%.3f', $factualAccuracy),
            $this->factualAccuracyFloor,
        );

        return new GateReport(
            $adjudicatedCount > 0,
            $passed,
            $criticalMisses,
            $precision,
            $factualAccuracy,
            $summary,
        );
    }
}

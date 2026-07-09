<?php

/**
 * The clinical-accuracy CI gate (T11; ARCHITECTURE.md §6; two-track rework
 * T15, founder decisions locked 2026-07-09).
 *
 * Only adjudicated cases count for anything. Zero adjudicated cases => NOT ARMED:
 * loud, but passing, because the labels are a Phase 0 human deliverable and
 * synthetic scaffolding must never gate the build.
 *
 * Once armed, the gate is a TWO-TRACK model:
 *
 * TRACK 1 — HARD ZEROS (invariants, never percentages). Any critical-subset
 * miss fails (R13). Any false flag on the critical subset fails — a spurious
 * flag is a data-trust bug, not something a precision floor should excuse.
 * Any incorrect stated fact on an adjudicated case fails — the factual-accuracy
 * *rate* remains a production monitor only and never gates. precision (R7) and
 * factualAccuracy (R6) are still computed and reported for monitoring; they no
 * longer determine `passed`.
 *
 * TRACK 2 — PROVISIONAL REGRESSION THRESHOLDS (judgment items only). Judgment
 * items (§3b care gaps / trends) are the one place a tunable precision/recall
 * tradeoff exists. `judgmentPrecisionThreshold` (the ctor's first float, kept
 * positional for backward compatibility) is a ratcheted "don't get worse"
 * number measured from prior performance, not a clinically-derived floor. The
 * optional `judgmentRecallThreshold` is named per ARCHITECTURE.md §6 but
 * UNSOURCED pending governance — it stays null (non-gating) until a governance
 * process supplies one. No judgment item is adjudicated yet, so this track
 * measures nothing in practice today: it is machinery-in-place, not a gate
 * that fires. A judgment-track failure still fails the build, but is
 * attributed to the judgment track and must never be reported as a
 * critical-subset (TRACK 1) failure — the two are distinct concepts.
 *
 * `factualAccuracyMonitorThreshold` (the ctor's second float) is reported in
 * the summary for visibility but — per the hard-zero rule above — never gates
 * `passed`; only `incorrectFactCount > 0` does.
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
        private float $judgmentPrecisionThreshold,
        private float $factualAccuracyMonitorThreshold,
        private ?float $judgmentRecallThreshold = null,
    ) {
        if ($judgmentPrecisionThreshold < 0.0 || $judgmentPrecisionThreshold > 1.0) {
            throw new \DomainException('judgmentPrecisionThreshold must be a fraction in [0, 1].');
        }
        if ($factualAccuracyMonitorThreshold < 0.0 || $factualAccuracyMonitorThreshold > 1.0) {
            throw new \DomainException('factualAccuracyMonitorThreshold must be a fraction in [0, 1].');
        }
        if ($judgmentRecallThreshold !== null && ($judgmentRecallThreshold < 0.0 || $judgmentRecallThreshold > 1.0)) {
            throw new \DomainException('judgmentRecallThreshold must be a fraction in [0, 1].');
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
        $totalJudgmentTruePositives = 0;
        $totalJudgmentFalsePositives = 0;
        $totalJudgmentLabelCount = 0;

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
            $totalJudgmentTruePositives += $score->judgmentTruePositiveFlags;
            $totalJudgmentFalsePositives += $score->judgmentFalsePositiveFlags;
            $totalJudgmentLabelCount += $score->judgmentLabelCount;
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

        $totalJudgmentFlags = $totalJudgmentTruePositives + $totalJudgmentFalsePositives;
        $judgmentPrecision = $totalJudgmentFlags > 0 ? $totalJudgmentTruePositives / $totalJudgmentFlags : null;
        $judgmentRecall = $totalJudgmentLabelCount > 0 ? $totalJudgmentTruePositives / $totalJudgmentLabelCount : null;

        $falseFlagCount = $totalFalsePositives;
        $incorrectFactCount = $totalIncorrectFacts;

        $criticalTrackPassed = $criticalMisses === [] && $falseFlagCount === 0;
        $factualTrackPassed = $incorrectFactCount === 0;
        $judgmentTrackPassed = ($judgmentPrecision === null || $judgmentPrecision >= $this->judgmentPrecisionThreshold)
            && ($this->judgmentRecallThreshold === null || $judgmentRecall === null || $judgmentRecall >= $this->judgmentRecallThreshold);

        $passed = $criticalTrackPassed && $factualTrackPassed && $judgmentTrackPassed;

        $summary = sprintf(
            'clinical-accuracy-gate: %s — %d adjudicated case(s). '
            . 'TRACK 1 hard zero — critical: %s (%d miss(es), %d false flag(s); precision %s monitor-only); '
            . 'factual: %s (%d incorrect fact(s); rate %s, monitor threshold %.2f, non-gating). '
            . 'TRACK 2 provisional regression threshold — judgment: %s (precision %s vs threshold %.2f, '
            . 'recall %s vs threshold %s).',
            $passed ? 'PASS' : 'FAIL',
            $adjudicatedCount,
            $criticalTrackPassed ? 'pass' : 'FAIL',
            count($criticalMisses),
            $falseFlagCount,
            $precision === null ? 'n/a' : sprintf('%.3f', $precision),
            $factualTrackPassed ? 'pass' : 'FAIL',
            $incorrectFactCount,
            $factualAccuracy === null ? 'n/a' : sprintf('%.3f', $factualAccuracy),
            $this->factualAccuracyMonitorThreshold,
            $totalJudgmentFlags === 0 && $totalJudgmentLabelCount === 0
                ? 'dormant (no judgment items adjudicated yet)'
                : ($judgmentTrackPassed ? 'pass' : 'FAIL'),
            $judgmentPrecision === null ? 'n/a' : sprintf('%.3f', $judgmentPrecision),
            $this->judgmentPrecisionThreshold,
            $judgmentRecall === null ? 'n/a' : sprintf('%.3f', $judgmentRecall),
            $this->judgmentRecallThreshold === null ? 'n/a (UNSOURCED, non-gating)' : sprintf('%.2f', $this->judgmentRecallThreshold),
        );

        return new GateReport(
            armed: true,
            passed: $passed,
            criticalMisses: $criticalMisses,
            precision: $precision,
            factualAccuracy: $factualAccuracy,
            summary: $summary,
            falseFlagCount: $falseFlagCount,
            incorrectFactCount: $incorrectFactCount,
            judgmentPrecision: $judgmentPrecision,
            judgmentRecall: $judgmentRecall,
            criticalTrackPassed: $criticalTrackPassed,
            factualTrackPassed: $factualTrackPassed,
            judgmentTrackPassed: $judgmentTrackPassed,
        );
    }
}

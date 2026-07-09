<?php

/**
 * FROZEN acceptance tests — T15: two-track accuracy-gate rework (R13/R7/R6;
 * ARCHITECTURE.md §6; founder decisions locked 2026-07-09).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test — the two-track metric model:
 *
 * 1. HARD ZEROS (invariants, never percentages):
 *    - Critical subset: any miss FAILS; any FALSE FLAG on an adjudicated case
 *      FAILS. A spurious critical flag is a data-trust bug, not a precision
 *      drag — a rate cannot excuse it.
 *    - Factual accuracy on shown claims: any incorrect stated fact on an
 *      adjudicated case FAILS the build. The *rate* is a production monitor
 *      only; it never excuses a wrong fact on the golden set.
 * 2. PROVISIONAL REGRESSION THRESHOLDS (rates, judgment track ONLY):
 *    - Judgment items are the one place a tunable precision/recall tradeoff
 *      exists. The ctor's first float is the judgment-precision provisional
 *      regression threshold ("don't get worse", ratcheted from measured
 *      performance). The optional third float is the judgment-recall
 *      threshold — named per ARCHITECTURE §6, UNSOURCED pending governance,
 *      NON-GATING while null.
 *    - A judgment-track failure fails the build but must NOT be attributed
 *      to the critical hard-zero track — the tracks are distinct concepts.
 * 3. The gate still REPORTS precision and factualAccuracy (the frozen
 *    AccuracyGateTest pins that), and the ctor's two positional floats keep
 *    their [0,1] validation. Unmeasurable metrics stay null, never failures.
 *    Synthetic (non-adjudicated) cases never arm, gate, or move any metric.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\GoldenChart;

use OpenEMR\Modules\Copilot\GoldenChart\AccuracyGate;
use OpenEMR\Modules\Copilot\GoldenChart\CaseResult;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartCase;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartLabels;
use PHPUnit\Framework\TestCase;

class HardZeroGateTest extends TestCase
{
    private const JUDGMENT_PRECISION_THRESHOLD = 0.8;
    private const FACTUAL_MONITOR_THRESHOLD = 0.95;

    private static function gate(): AccuracyGate
    {
        return new AccuracyGate(self::JUDGMENT_PRECISION_THRESHOLD, self::FACTUAL_MONITOR_THRESHOLD);
    }

    /**
     * @param list<string> $mustNotMiss
     * @param list<string> $judgmentItems
     */
    private static function case(
        string $id,
        bool $adjudicated,
        array $mustNotMiss,
        array $judgmentItems = [],
    ): GoldenChartCase {
        return new GoldenChartCase(
            $id,
            $adjudicated,
            new GoldenChartLabels($mustNotMiss, [], $judgmentItems),
        );
    }

    // ── Track 1: critical-subset hard zeros ────────────────────────────────

    public function testOneSpuriousFlagFailsEvenWithPrecisionAtTheOldFloor(): void
    {
        // 4 true positives + 1 false flag => precision 0.8, exactly the old
        // floor: the OLD gate would have PASSED this run. The hard zero fails
        // it — a spurious critical flag is a data-trust bug, not a rate.
        $labels = ['panic-k', 'panic-na', 'ddi-warfarin-aspirin', 'allergy-pcn-amox'];
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, $labels),
                new CaseResult($labels, [...$labels, 'noise-1'], 10, 0),
            ],
        ]);

        $this->assertTrue($report->armed);
        $this->assertSame([], $report->criticalMisses);
        $this->assertSame(0.8, $report->precision, 'The rate must still be reported — it just must not gate.');
        $this->assertSame(1, $report->falseFlagCount);
        $this->assertFalse($report->criticalTrackPassed, 'One false flag on an adjudicated case is a hard-zero failure.');
        $this->assertFalse($report->passed);
    }

    public function testASpuriousFlagOnACleanQuietChartFails(): void
    {
        // Zero false flags on clean fixtures is the invariant: a flag invented
        // on a chart with nothing to surface is the purest data-trust bug.
        $report = self::gate()->evaluate([
            [
                self::case('case-quiet', true, []),
                new CaseResult([], ['phantom-finding'], 0, 0),
            ],
        ]);

        $this->assertSame([], $report->criticalMisses);
        $this->assertSame(1, $report->falseFlagCount);
        $this->assertFalse($report->criticalTrackPassed);
        $this->assertFalse($report->passed);
    }

    public function testFalseFlagsAggregateAcrossAdjudicatedCasesOnly(): void
    {
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k']),
                new CaseResult(['panic-k'], ['panic-k'], 5, 0),
            ],
            [
                self::case('case-2', true, ['ddi-x']),
                new CaseResult(['ddi-x'], ['ddi-x', 'noise-1'], 5, 0),
            ],
            [
                // Synthetic scaffolding: its garbage must not count anywhere.
                self::case('synthetic-smoke', false, []),
                new CaseResult([], ['garbage-1', 'garbage-2'], 0, 3),
            ],
        ]);

        $this->assertSame(1, $report->falseFlagCount, 'Only adjudicated cases feed the hard zero.');
        $this->assertFalse($report->criticalTrackPassed);
        $this->assertFalse($report->passed);
    }

    // ── Track 1: factual hard zero ─────────────────────────────────────────

    public function testOneIncorrectFactFailsEvenWithRateAboveTheOldFloor(): void
    {
        // 99 correct / 1 incorrect => 0.99, above the old 0.95 floor: the OLD
        // gate would have PASSED. One wrong stated fact on the golden set is
        // a hard-zero failure; the rate is a production monitor only.
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k']),
                new CaseResult(['panic-k'], ['panic-k'], 99, 1),
            ],
        ]);

        $this->assertSame([], $report->criticalMisses);
        $this->assertSame(0.99, $report->factualAccuracy, 'The rate must still be reported — it just must not gate.');
        $this->assertSame(1, $report->incorrectFactCount);
        $this->assertTrue($report->criticalTrackPassed, 'A factual failure is not a critical-track failure.');
        $this->assertFalse($report->factualTrackPassed);
        $this->assertFalse($report->passed);
    }

    // ── Track 2: judgment provisional regression thresholds ───────────────

    public function testJudgmentPrecisionDipFailsTheBuildButNotTheCriticalTrack(): void
    {
        // Critical track is perfect; judgment flags are 1 TP / 4 FP => 0.2,
        // below the 0.8 provisional regression threshold. The build fails as
        // a JUDGMENT regression — the hard-zero tracks stay green, and the
        // judgment noise must not pollute the critical precision number.
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k'], ['j-care-gap']),
                new CaseResult(
                    ['panic-k'],
                    ['panic-k'],
                    10,
                    0,
                    ['j-care-gap', 'j-noise-1', 'j-noise-2', 'j-noise-3', 'j-noise-4'],
                ),
            ],
        ]);

        $this->assertTrue($report->armed);
        $this->assertSame([], $report->criticalMisses);
        $this->assertSame(0, $report->falseFlagCount);
        $this->assertSame(1.0, $report->precision, 'Judgment flags must not pollute critical precision.');
        $this->assertTrue($report->criticalTrackPassed);
        $this->assertTrue($report->factualTrackPassed);
        $this->assertSame(0.2, $report->judgmentPrecision);
        $this->assertFalse($report->judgmentTrackPassed);
        $this->assertFalse($report->passed, 'A judgment regression still fails the build — it is just not a hard-zero failure.');
        $this->assertStringContainsString(
            'provisional regression threshold',
            $report->summary,
            'The judgment rates are ratcheted "don\'t get worse" numbers and must be named as such.',
        );
    }

    public function testDormantJudgmentTrackReportsNullAndNeverGates(): void
    {
        // No judgment labels and no judgment flags exist today: the track is
        // machinery-in-place, evaluating nothing — null metrics, never a fail.
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k']),
                new CaseResult(['panic-k'], ['panic-k'], 10, 0),
            ],
        ]);

        $this->assertNull($report->judgmentPrecision);
        $this->assertNull($report->judgmentRecall);
        $this->assertTrue($report->judgmentTrackPassed);
        $this->assertTrue($report->passed);
    }

    public function testJudgmentRecallIsReportedButNonGatingWhileUnsourced(): void
    {
        // 1 of 2 judgment labels surfaced => recall 0.5. No governance-set
        // recall threshold exists (UNSOURCED, ARCHITECTURE §6) — so it is
        // named and reported but must NOT gate.
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k'], ['j-gap-1', 'j-gap-2']),
                new CaseResult(['panic-k'], ['panic-k'], 10, 0, ['j-gap-1']),
            ],
        ]);

        $this->assertSame(1.0, $report->judgmentPrecision);
        $this->assertSame(0.5, $report->judgmentRecall);
        $this->assertTrue($report->judgmentTrackPassed, 'An unsourced threshold cannot gate.');
        $this->assertTrue($report->passed);
    }

    public function testJudgmentRecallGatesOnceGovernanceSuppliesAThreshold(): void
    {
        $gate = new AccuracyGate(
            self::JUDGMENT_PRECISION_THRESHOLD,
            self::FACTUAL_MONITOR_THRESHOLD,
            0.9,
        );
        $report = $gate->evaluate([
            [
                self::case('case-1', true, ['panic-k'], ['j-gap-1', 'j-gap-2']),
                new CaseResult(['panic-k'], ['panic-k'], 10, 0, ['j-gap-1']),
            ],
        ]);

        $this->assertSame(0.5, $report->judgmentRecall);
        $this->assertFalse($report->judgmentTrackPassed);
        $this->assertTrue($report->criticalTrackPassed, 'A judgment recall miss is not a critical-track failure.');
        $this->assertFalse($report->passed);
    }

    public function testJudgmentRecallThresholdMustBeASaneFraction(): void
    {
        $this->expectException(\DomainException::class);
        new AccuracyGate(0.8, 0.95, 1.5);
    }

    // ── Reporting stays honest across both tracks ──────────────────────────

    public function testACleanRunPassesBothTracksWithFullReporting(): void
    {
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k'], ['j-gap-1']),
                new CaseResult(['panic-k'], ['panic-k'], 10, 0, ['j-gap-1']),
            ],
        ]);

        $this->assertTrue($report->armed);
        $this->assertTrue($report->passed);
        $this->assertTrue($report->criticalTrackPassed);
        $this->assertTrue($report->factualTrackPassed);
        $this->assertTrue($report->judgmentTrackPassed);
        $this->assertSame(0, $report->falseFlagCount);
        $this->assertSame(0, $report->incorrectFactCount);
        $this->assertSame(1.0, $report->precision);
        $this->assertSame(1.0, $report->factualAccuracy);
        $this->assertSame(1.0, $report->judgmentPrecision);
        $this->assertSame(1.0, $report->judgmentRecall);
    }

    public function testBlankJudgmentLabelIdsAreRejected(): void
    {
        $this->expectException(\DomainException::class);
        new GoldenChartLabels(['panic-k'], [], ['j-ok', ' ']);
    }
}

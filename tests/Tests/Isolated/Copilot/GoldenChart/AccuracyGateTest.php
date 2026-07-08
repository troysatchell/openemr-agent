<?php

/**
 * FROZEN acceptance tests — T11: the clinical-accuracy gate (R13/R6/R7;
 * ARCHITECTURE.md §6 — "any miss on the critical subset or any metric below
 * floor fails the build").
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the gate aggregates adjudicated cases only. Zero
 * adjudicated cases => NOT ARMED (loud, but not failing — the labels are a
 * Phase 0 human deliverable). Any critical miss on an adjudicated case fails
 * regardless of floors. Precision and factual-accuracy floors fail when
 * measurable and below; unmeasurable (no flags / no facts) is not a failure.
 * Synthetic (non-adjudicated) cases exercise the harness and never arm or
 * fail the gate.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\GoldenChart;

use OpenEMR\Modules\Copilot\GoldenChart\AccuracyGate;
use OpenEMR\Modules\Copilot\GoldenChart\CaseResult;
use OpenEMR\Modules\Copilot\GoldenChart\GateReport;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartCase;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartLabels;
use PHPUnit\Framework\TestCase;

class AccuracyGateTest extends TestCase
{
    private const PRECISION_FLOOR = 0.8;
    private const FACTUAL_FLOOR = 0.95;

    private static function gate(): AccuracyGate
    {
        return new AccuracyGate(self::PRECISION_FLOOR, self::FACTUAL_FLOOR);
    }

    private static function case(
        string $id,
        bool $adjudicated,
        array $mustNotMiss,
    ): GoldenChartCase {
        return new GoldenChartCase($id, $adjudicated, new GoldenChartLabels($mustNotMiss, []));
    }

    public function testZeroAdjudicatedCasesIsNotArmedAndDoesNotFail(): void
    {
        $report = self::gate()->evaluate([]);

        $this->assertInstanceOf(GateReport::class, $report);
        $this->assertFalse($report->armed);
        $this->assertTrue($report->passed);
        $this->assertStringContainsString('NOT ARMED', $report->summary);
    }

    public function testSyntheticCasesNeverArmTheGateEvenWithMisses(): void
    {
        $report = self::gate()->evaluate([
            [
                self::case('synthetic-smoke', false, ['panic-k']),
                new CaseResult([], [], 0, 0), // total miss — but scaffolding only
            ],
        ]);

        $this->assertFalse($report->armed);
        $this->assertTrue($report->passed);
        $this->assertSame([], $report->criticalMisses);
        $this->assertStringContainsString('NOT ARMED', $report->summary);
    }

    public function testCleanAdjudicatedRunArmsAndPasses(): void
    {
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k']),
                new CaseResult(['panic-k'], ['panic-k'], 10, 0),
            ],
        ]);

        $this->assertTrue($report->armed);
        $this->assertTrue($report->passed);
        $this->assertSame([], $report->criticalMisses);
        $this->assertSame(1.0, $report->precision);
        $this->assertSame(1.0, $report->factualAccuracy);
    }

    public function testOneCriticalMissFailsRegardlessOfPerfectFloors(): void
    {
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k', 'ddi-warfarin-aspirin']),
                new CaseResult(['panic-k'], ['panic-k'], 10, 0),
            ],
        ]);

        $this->assertTrue($report->armed);
        $this->assertFalse($report->passed, 'Zero-miss on the critical subset is the point of the gate (R13).');
        $this->assertSame(['case-1:ddi-warfarin-aspirin'], $report->criticalMisses);
    }

    public function testPrecisionBelowFloorFailsWithoutAnyMiss(): void
    {
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k']),
                // 1 true positive, 3 false positives => precision 0.25 < 0.8
                new CaseResult(['panic-k'], ['panic-k', 'noise-1', 'noise-2', 'noise-3'], 5, 0),
            ],
        ]);

        $this->assertSame([], $report->criticalMisses);
        $this->assertFalse($report->passed, 'Over-flagging is churn (R7) — the floor is load-bearing.');
        $this->assertNotNull($report->precision);
        $this->assertLessThan(self::PRECISION_FLOOR, $report->precision);
    }

    public function testFactualAccuracyBelowFloorFails(): void
    {
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k']),
                // 8 correct / 2 incorrect => 0.8 < 0.95
                new CaseResult(['panic-k'], ['panic-k'], 8, 2),
            ],
        ]);

        $this->assertSame([], $report->criticalMisses);
        $this->assertFalse($report->passed, 'A wrong stated fact is a candidate churn event (R6).');
        $this->assertSame(0.8, $report->factualAccuracy);
    }

    public function testUnmeasurableMetricsAreNotFailures(): void
    {
        $report = self::gate()->evaluate([
            [
                // No labels, no flags, no stated facts: a legitimately quiet chart.
                self::case('case-quiet', true, []),
                new CaseResult([], [], 0, 0),
            ],
        ]);

        $this->assertTrue($report->armed);
        $this->assertTrue($report->passed, 'Silence on a quiet chart is correct behavior (R5/R7), not a failure.');
        $this->assertNull($report->precision);
        $this->assertNull($report->factualAccuracy);
    }

    public function testMetricsAggregateAcrossAdjudicatedCasesOnly(): void
    {
        $report = self::gate()->evaluate([
            [
                self::case('case-1', true, ['panic-k']),
                new CaseResult(['panic-k'], ['panic-k', 'noise-1'], 9, 1),
            ],
            [
                self::case('case-2', true, ['ddi-x']),
                new CaseResult(['ddi-x'], ['ddi-x'], 10, 0),
            ],
            [
                // Synthetic scaffolding must not move the numbers.
                self::case('synthetic-smoke', false, ['panic-k']),
                new CaseResult([], ['garbage-1', 'garbage-2'], 0, 5),
            ],
        ]);

        $this->assertTrue($report->armed);
        // Adjudicated flags: TP 2, FP 1 => precision 2/3; facts: 19/20 = 0.95.
        $this->assertEqualsWithDelta(2 / 3, $report->precision, 1e-9);
        $this->assertEqualsWithDelta(0.95, $report->factualAccuracy, 1e-9);
        $this->assertFalse($report->passed, 'Precision 0.667 is below the 0.8 floor.');
    }

    public function testFloorsMustBeSaneFractions(): void
    {
        $this->expectException(\DomainException::class);
        new AccuracyGate(1.5, 0.9);
    }
}

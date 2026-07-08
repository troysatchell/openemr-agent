<?php

/**
 * FROZEN acceptance tests — T11: golden-chart scorer (R13/R6/R7; ARCHITECTURE.md §6).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the scorer computes, per (chart-state, visit) case,
 * the three §6 metrics' raw ingredients — missed must-not-miss labels
 * (omission, R13), true/false-positive flags (precision, R7), and the
 * human-adjudicated fact counts (commission, R6). Labels are HUMAN inputs;
 * the scorer never generates or repairs them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\GoldenChart;

use OpenEMR\Modules\Copilot\GoldenChart\CaseResult;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartCase;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartLabels;
use OpenEMR\Modules\Copilot\GoldenChart\Scorer;
use PHPUnit\Framework\TestCase;

class GoldenChartScorerTest extends TestCase
{
    private static function case(
        string $id = 'case-1',
        bool $adjudicated = true,
        array $mustNotMiss = ['panic-k', 'ddi-warfarin-aspirin'],
        array $keyFacts = ['fact-a1c'],
    ): GoldenChartCase {
        return new GoldenChartCase($id, $adjudicated, new GoldenChartLabels($mustNotMiss, $keyFacts));
    }

    public function testCaseIdMustNotBeBlank(): void
    {
        $this->expectException(\DomainException::class);
        self::case('  ');
    }

    public function testLabelsRejectBlankIds(): void
    {
        $this->expectException(\DomainException::class);
        new GoldenChartLabels(['panic-k', ''], []);
    }

    public function testEmptyLabelListsAreLegitimate(): void
    {
        $labels = new GoldenChartLabels([], []);
        $this->assertSame([], $labels->mustNotMiss);
        $this->assertSame([], $labels->keyFacts);
    }

    public function testAllCriticalSurfacedMeansNoMisses(): void
    {
        $score = (new Scorer())->score(self::case(), new CaseResult(
            surfacedCriticalIds: ['panic-k', 'ddi-warfarin-aspirin'],
            flaggedIds: ['panic-k', 'ddi-warfarin-aspirin'],
            correctFactCount: 3,
            incorrectFactCount: 0,
        ));

        $this->assertSame([], $score->missedCritical);
        $this->assertSame(2, $score->truePositiveFlags);
        $this->assertSame(0, $score->falsePositiveFlags);
    }

    public function testAMissedLabelIsNamedNotCounted(): void
    {
        $score = (new Scorer())->score(self::case(), new CaseResult(
            surfacedCriticalIds: ['panic-k'],
            flaggedIds: ['panic-k'],
            correctFactCount: 0,
            incorrectFactCount: 0,
        ));

        $this->assertSame(
            ['ddi-warfarin-aspirin'],
            $score->missedCritical,
            'Misses are identified by label id so the ratchet ("once missed, never silently missed again") can work.'
        );
    }

    public function testSurfacingSomethingUnlabeledIsAFalsePositiveFlag(): void
    {
        $score = (new Scorer())->score(self::case(), new CaseResult(
            surfacedCriticalIds: ['panic-k', 'ddi-warfarin-aspirin'],
            flaggedIds: ['panic-k', 'ddi-warfarin-aspirin', 'not-actually-critical'],
            correctFactCount: 0,
            incorrectFactCount: 0,
        ));

        $this->assertSame([], $score->missedCritical);
        $this->assertSame(2, $score->truePositiveFlags);
        $this->assertSame(1, $score->falsePositiveFlags, 'Over-flagging feeds the precision floor (R7).');
    }

    public function testFactCountsPassThroughUntouched(): void
    {
        $score = (new Scorer())->score(self::case(), new CaseResult(
            surfacedCriticalIds: ['panic-k', 'ddi-warfarin-aspirin'],
            flaggedIds: [],
            correctFactCount: 7,
            incorrectFactCount: 2,
        ));

        $this->assertSame(7, $score->correctFactCount);
        $this->assertSame(2, $score->incorrectFactCount);
        $this->assertSame(0, $score->truePositiveFlags);
    }

    public function testNegativeFactCountsCannotExist(): void
    {
        $this->expectException(\DomainException::class);
        new CaseResult([], [], -1, 0);
    }
}

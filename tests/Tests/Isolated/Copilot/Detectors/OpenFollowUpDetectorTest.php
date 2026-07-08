<?php

/**
 * FROZEN acceptance tests — T10: open follow-up detector (R13, UC4;
 * ARCHITECTURE.md §6 — "the thread from last time").
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: every OPEN follow-up is a finding — an open loop is
 * must-not-miss regardless of due date. Findings whose due date has passed
 * are marked overdue in the summary. Closed follow-ups are silent.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Detectors;

use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\OpenFollowUpDetector;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class OpenFollowUpDetectorTest extends TestCase
{
    private const TODAY = '2026-07-08';

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    /**
     * @param list<FollowUpEntry> $followUps
     */
    private static function snapshot(array $followUps): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize([], [], [], $followUps);
    }

    private static function followUp(string $description, ?string $due, bool $open, string $refId): FollowUpEntry
    {
        return new FollowUpEntry(
            $description,
            $due === null ? null : new \DateTimeImmutable($due),
            $open,
            [new SourceRef('open_loops', $refId)],
        );
    }

    public function testEveryOpenFollowUpIsAFindingWithProvenance(): void
    {
        $report = (new OpenFollowUpDetector())->detect(self::snapshot([
            self::followUp('Recheck TSH in 3 months', '2026-04-01', true, 'fu-1'),
            self::followUp('Discuss statin at next visit', null, true, 'fu-2'),
            self::followUp('Colonoscopy completed', null, false, 'fu-3'),
        ]), self::today());

        $this->assertCount(2, $report->findings, 'Open loops are findings; closed ones are silent.');
        foreach ($report->findings as $finding) {
            $this->assertSame(CriticalFindingType::OpenFollowUp, $finding->type);
            $this->assertNotSame([], $finding->sources);
        }
        $this->assertSame([], $report->unevaluable);
    }

    public function testPastDueOpenFollowUpIsMarkedOverdue(): void
    {
        $report = (new OpenFollowUpDetector())->detect(self::snapshot([
            self::followUp('Recheck TSH in 3 months', '2026-04-01', true, 'fu-1'),
        ]), self::today());

        $this->assertCount(1, $report->findings);
        $this->assertStringContainsString('overdue', strtolower($report->findings[0]->summary));
    }

    public function testFutureDueOpenFollowUpIsAFindingButNotOverdue(): void
    {
        $report = (new OpenFollowUpDetector())->detect(self::snapshot([
            self::followUp('Repeat lipid panel', '2026-09-01', true, 'fu-1'),
        ]), self::today());

        $this->assertCount(1, $report->findings);
        $this->assertStringNotContainsString('overdue', strtolower($report->findings[0]->summary));
    }

    public function testDueTodayIsNotOverdue(): void
    {
        $report = (new OpenFollowUpDetector())->detect(self::snapshot([
            self::followUp('Recheck potassium', self::TODAY, true, 'fu-1'),
        ]), self::today());

        $this->assertCount(1, $report->findings);
        $this->assertStringNotContainsString('overdue', strtolower($report->findings[0]->summary));
    }

    public function testUndatedOpenFollowUpIsStillAFinding(): void
    {
        $report = (new OpenFollowUpDetector())->detect(self::snapshot([
            self::followUp('Patient to report home BP readings', null, true, 'fu-1'),
        ]), self::today());

        $this->assertCount(1, $report->findings, 'No due date does not close a loop.');
    }

    public function testNothingOpenMeansSilence(): void
    {
        $report = (new OpenFollowUpDetector())->detect(self::snapshot([
            self::followUp('Done', '2026-01-01', false, 'fu-1'),
        ]), self::today());

        $this->assertSame([], $report->findings, 'Silence when nothing is open (R7).');
    }
}

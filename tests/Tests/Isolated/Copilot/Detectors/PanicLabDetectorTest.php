<?php

/**
 * FROZEN acceptance tests — T10: panic-lab detector (R13, UC4; ARCHITECTURE.md §6).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: panic labs are a code guarantee, not model judgment.
 * The detector compares lab values against a typed threshold table; a value
 * it cannot evaluate (missing value, unit mismatch) is surfaced as
 * unevaluable — never silently passed. Every finding carries provenance.
 * Threshold CONTENTS are founder-adjudicated DRAFT (human sign-off pending);
 * only clinically unambiguous extremes are asserted against the draft table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Detectors;

use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\DetectorReport;
use OpenEMR\Modules\Copilot\Detectors\PanicLabDetector;
use OpenEMR\Modules\Copilot\Detectors\PanicThresholds;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PanicLabDetectorTest extends TestCase
{
    private static function thresholds(): PanicThresholds
    {
        return new PanicThresholds([
            'potassium' => ['low' => 2.5, 'high' => 6.0, 'unit' => 'mmol/L'],
            'hemoglobin' => ['low' => 6.5, 'high' => null, 'unit' => 'g/dL'],
        ]);
    }

    private static function lab(string $analyte, ?float $value, ?string $unit, string $refId = 'lab-1'): LabResultEntry
    {
        return new LabResultEntry(
            $analyte,
            $value,
            $unit,
            new \DateTimeImmutable('2026-07-07 07:00:00'),
            [new SourceRef('procedure_result', $refId)],
        );
    }

    /**
     * @param list<LabResultEntry> $labs
     */
    private static function snapshot(array $labs): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize([], $labs, []);
    }

    /**
     * @return array<string, array{LabResultEntry, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function thresholdCaseProvider(): array
    {
        return [
            'above high bound' => [self::lab('Potassium', 6.8, 'mmol/L'), true],
            'below low bound' => [self::lab('potassium', 2.1, 'mmol/L'), true],
            'exactly at high bound is not panic' => [self::lab('Potassium', 6.0, 'mmol/L'), false],
            'exactly at low bound is not panic' => [self::lab('Potassium', 2.5, 'mmol/L'), false],
            'normal value' => [self::lab('Potassium', 4.2, 'mmol/L'), false],
            'analyte case-insensitive' => [self::lab('POTASSIUM', 7.5, 'mmol/L'), true],
            'unit case-insensitive' => [self::lab('Potassium', 7.5, 'MMOL/L'), true],
            'one-sided threshold low only' => [self::lab('Hemoglobin', 5.9, 'g/dL'), true],
            'one-sided threshold normal' => [self::lab('Hemoglobin', 13.2, 'g/dL'), false],
            'analyte not in the table is not the detector\'s problem' => [self::lab('TSH', 250.0, 'mIU/L'), false],
        ];
    }

    #[DataProvider('thresholdCaseProvider')]
    public function testThresholdEvaluation(LabResultEntry $lab, bool $expectPanic): void
    {
        $report = (new PanicLabDetector(self::thresholds()))->detect(self::snapshot([$lab]));

        $this->assertInstanceOf(DetectorReport::class, $report);
        $this->assertCount($expectPanic ? 1 : 0, $report->findings);
        $this->assertSame([], $report->unevaluable, 'These cases are all evaluable.');

        if ($expectPanic) {
            $finding = $report->findings[0];
            $this->assertInstanceOf(CriticalFinding::class, $finding);
            $this->assertSame(CriticalFindingType::PanicLab, $finding->type);
            $this->assertNotSame([], $finding->sources, 'Provenance is mandatory on every finding.');
        }
    }

    /**
     * @return array<string, array{LabResultEntry}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unevaluableCaseProvider(): array
    {
        return [
            'tracked analyte with missing value' => [self::lab('Potassium', null, 'mmol/L')],
            'tracked analyte with missing unit' => [self::lab('Potassium', 6.8, null)],
            'tracked analyte with mismatched unit' => [self::lab('Potassium', 6.8, 'mg/dL')],
        ];
    }

    #[DataProvider('unevaluableCaseProvider')]
    public function testTrackedAnalytesThatCannotBeEvaluatedAreSurfacedNotSkipped(LabResultEntry $lab): void
    {
        $report = (new PanicLabDetector(self::thresholds()))->detect(self::snapshot([$lab]));

        $this->assertSame([], $report->findings);
        $this->assertCount(
            1,
            $report->unevaluable,
            'A tracked analyte the rule cannot evaluate must surface as unevaluable — silence is the failure mode (R13).'
        );
        $this->assertNotSame([], $report->unevaluable[0]->sources);
    }

    public function testDraftTableFlagsClinicallyUnambiguousExtremes(): void
    {
        $detector = new PanicLabDetector(PanicThresholds::draftV1());

        $panic = $detector->detect(self::snapshot([
            self::lab('Potassium', 7.2, 'mmol/L', 'lab-k'),
            self::lab('Sodium', 112.0, 'mmol/L', 'lab-na'),
            self::lab('Glucose', 25.0, 'mg/dL', 'lab-glu'),
        ]));
        $this->assertCount(3, $panic->findings, 'K 7.2, Na 112, glucose 25 are panic values under any sane table.');

        $normal = $detector->detect(self::snapshot([
            self::lab('Potassium', 4.1, 'mmol/L'),
            self::lab('Sodium', 139.0, 'mmol/L'),
            self::lab('Glucose', 95.0, 'mg/dL'),
        ]));
        $this->assertSame([], $normal->findings, 'Unremarkable values must not fire (alert fatigue, R7).');
    }

    public function testThresholdTableRejectsMalformedEntries(): void
    {
        $this->expectException(\DomainException::class);
        new PanicThresholds(['potassium' => ['low' => null, 'high' => null, 'unit' => 'mmol/L']]);
    }
}

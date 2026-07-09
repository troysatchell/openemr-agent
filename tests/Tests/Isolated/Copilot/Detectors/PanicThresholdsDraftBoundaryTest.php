<?php

/**
 * Boundary regression tests for the ARUP-cited draftV1 panic thresholds
 * (R13, UC4; ARCHITECTURE.md §6; PHASE0.md §3a.1/§3a.5).
 *
 * Not frozen. Each positive case is a ratchet against the pre-ARUP draft
 * table: it FAILS on the old values (K low 2.5, glucose 40/500, Hgb 6.5,
 * platelets <20 strict with no high bound) and PASSES on the values
 * transcribed from the ARUP Critical Values List, CORP-APPEND-0104A,
 * Rev. 46, April 2026, p.1. Each negative case guards the other direction:
 * the bound itself (or the first value inside it) must NOT fire, so the
 * cited strict-vs-inclusive semantics cannot silently widen into
 * over-flagging (R7 alert fatigue).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Detectors;

use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\PanicLabDetector;
use OpenEMR\Modules\Copilot\Detectors\PanicThresholds;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PanicThresholdsDraftBoundaryTest extends TestCase
{
    private static function lab(string $analyte, float $value, string $unit): LabResultEntry
    {
        return new LabResultEntry(
            $analyte,
            $value,
            $unit,
            new \DateTimeImmutable('2026-07-08 07:00:00'),
            [new SourceRef('procedure_result', 'lab-boundary')],
        );
    }

    private static function snapshot(LabResultEntry $lab): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize([], [$lab], []);
    }

    /**
     * Failure mode guarded per case: a critical value the old draft table
     * silently MISSED (R13 — the omission the accuracy gate exists to catch).
     *
     * @return array<string, array{string, float, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function panicFiresProvider(): array
    {
        return [
            // Old K low 2.5 missed hypokalemia 2.5–2.99 that ARUP calls critical.
            'K 2.99 fires under ARUP low <3.0' => ['Potassium', 2.99, 'mmol/L'],
            // 2.5 was the old bound itself (strict: not panic); under low 3.0 it fires.
            'K 2.5 fires under ARUP low <3.0' => ['Potassium', 2.5, 'mmol/L'],
            // Old glucose low 40 missed hypoglycemia 40–54 — the high-harm miss.
            'glucose 54 fires under ARUP low <55' => ['Glucose', 54.0, 'mg/dL'],
            // Old glucose high 500 missed hyperglycemia 451–500.
            'glucose 451 fires under ARUP high >450' => ['Glucose', 451.0, 'mg/dL'],
            // Old Hgb low 6.5 missed anemia 6.5–6.99.
            'Hgb 6.99 fires under ARUP low <7.0' => ['Hemoglobin', 6.99, 'g/dL'],
            // Old strict <20 missed platelets = exactly 20; ARUP prints ≤20 (inclusive).
            'platelets exactly 20 fires under ARUP ≤20 inclusive' => ['Platelets', 20.0, '10*3/uL'],
            // Old table had NO platelet high bound: critical thrombocytosis was never surfaced.
            'platelets 1000 fires under ARUP ≥1000 inclusive' => ['Platelets', 1000.0, '10*3/uL'],
        ];
    }

    #[DataProvider('panicFiresProvider')]
    public function testAruPCriticalValuesFire(string $analyte, float $value, string $unit): void
    {
        $report = (new PanicLabDetector(PanicThresholds::draftV1()))
            ->detect(self::snapshot(self::lab($analyte, $value, $unit)));

        $this->assertCount(1, $report->findings, sprintf(
            '%s %s %s is critical per ARUP Rev.46 p.1 — a silent pass here is exactly the R13 miss.',
            $analyte,
            $value,
            $unit,
        ));
        $this->assertSame(CriticalFindingType::PanicLab, $report->findings[0]->type);
        $this->assertSame([], $report->unevaluable);
    }

    /**
     * Failure mode guarded per case: the bound value itself (or the first
     * value inside it) firing would mean the strict/inclusive semantics
     * drifted — over-flagging non-critical values is the R7 churn path.
     *
     * @return array<string, array{string, float, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function panicDoesNotFireProvider(): array
    {
        return [
            // ARUP prints <3.0 strict: 3.0 itself is not critical.
            'K 3.0 is the bound, not panic' => ['Potassium', 3.0, 'mmol/L'],
            'glucose 55 is the bound, not panic' => ['Glucose', 55.0, 'mg/dL'],
            'glucose 450 is the bound, not panic' => ['Glucose', 450.0, 'mg/dL'],
            'Hgb 7.0 is the bound, not panic' => ['Hemoglobin', 7.0, 'g/dL'],
            // Platelet bounds are inclusive, so the first values INSIDE them are quiet.
            'platelets 21 is inside the inclusive low bound' => ['Platelets', 21.0, '10*3/uL'],
            'platelets 999 is inside the inclusive high bound' => ['Platelets', 999.0, '10*3/uL'],
        ];
    }

    #[DataProvider('panicDoesNotFireProvider')]
    public function testValuesInsideTheAruPBoundsStayQuiet(string $analyte, float $value, string $unit): void
    {
        $report = (new PanicLabDetector(PanicThresholds::draftV1()))
            ->detect(self::snapshot(self::lab($analyte, $value, $unit)));

        $this->assertSame([], $report->findings, sprintf(
            '%s %s %s is not critical per ARUP Rev.46 p.1 — firing here is the R7 over-flag drift.',
            $analyte,
            $value,
            $unit,
        ));
        $this->assertSame([], $report->unevaluable);
    }

    /**
     * Guards the frozen constructor contract after the inclusivity
     * extension: an entry with both bounds null must still be rejected,
     * and the three-key input shape must still normalize (flags default
     * to false = strictly-outside).
     */
    public function testThresholdRepresentationExtensionPreservesTheConstructorContract(): void
    {
        $table = new PanicThresholds([
            'potassium' => ['low' => 2.5, 'high' => 6.0, 'unit' => 'mmol/L'],
        ]);
        $entry = $table->thresholdFor('potassium');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['lowInclusive'], 'Omitted flags must default to strictly-outside.');
        $this->assertFalse($entry['highInclusive'], 'Omitted flags must default to strictly-outside.');

        $this->expectException(\DomainException::class);
        new PanicThresholds(['potassium' => ['low' => null, 'high' => null, 'unit' => 'mmol/L']]);
    }
}

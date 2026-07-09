<?php

/**
 * The ARMED clinical-accuracy gate over the §3a adjudicated label set
 * (R13/R7; ARCHITECTURE.md §6; PHASE0.md §3a).
 *
 * Not frozen (but weakening it un-arms the build gate — treat any edit that
 * removes a case or softens an assertion as an escalation, not a fix).
 * Loads the adjudicated fixtures from adjudicated/ (a sibling of the frozen
 * fixtures/ tree, which stays synthetic scaffolding), runs the four REAL
 * detectors with their draftV1 tables over an inline chart scenario per
 * case, and feeds the results to the real AccuracyGate. The fixtures are
 * §3a reference-grounded labels signed off by the acting clinical-governance
 * owner (founder in v1 — a named limitation, PHASE0.md §3c/§4); §3b
 * judgment items are PROVISIONAL-UNADJUDICATED and never appear here. The
 * sulfonamides grouping is UNSOURCED (DA-4) and is deliberately not gated.
 *
 * Failure modes guarded: (1) a critical-subset regression in any detector or
 * draft table now FAILS the build instead of reporting NOT ARMED; (2) a
 * label whose detector never fires can no longer hide behind the unarmed
 * gate; (3) an adjudicated fixture without a provenance citation is a
 * failure — no adjudicated case without provenance; (4) a spurious detector
 * flag on an adjudicated case is a HARD-ZERO failure (a data-trust bug) —
 * it can no longer hide above the old precision floor (T15, decisions
 * locked 2026-07-09).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\GoldenChart;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Detectors\AllergyClassMap;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\DrugAllergyConflictDetector;
use OpenEMR\Modules\Copilot\Detectors\DrugDrugInteractionDetector;
use OpenEMR\Modules\Copilot\Detectors\InteractionPairs;
use OpenEMR\Modules\Copilot\Detectors\OpenFollowUpDetector;
use OpenEMR\Modules\Copilot\Detectors\PanicLabDetector;
use OpenEMR\Modules\Copilot\Detectors\PanicThresholds;
use OpenEMR\Modules\Copilot\GoldenChart\AccuracyGate;
use OpenEMR\Modules\Copilot\GoldenChart\CaseResult;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartCaseLoader;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class CriticalSubsetGateTest extends TestCase
{
    /** Same floors the frozen AccuracyGateTest exercises the gate logic with. */
    private const PRECISION_FLOOR = 0.8;
    private const FACTUAL_FLOOR = 0.95;

    /** Fixed evaluation date: the followup-open-overdue chart is due 2026-06-30. */
    private const TODAY = '2026-07-08';

    private static function adjudicatedDir(): string
    {
        return __DIR__ . '/adjudicated';
    }

    private static function med(string $name, string $refId): MedicationEntry
    {
        return new MedicationEntry($name, CurrencyStatus::Current, [new SourceRef('lists', $refId)]);
    }

    private static function allergy(string $substance, string $refId): AllergyEntry
    {
        return new AllergyEntry($substance, CurrencyStatus::Current, [new SourceRef('lists', $refId)]);
    }

    private static function lab(string $analyte, float $value, string $unit): LabResultEntry
    {
        return new LabResultEntry(
            $analyte,
            $value,
            $unit,
            new \DateTimeImmutable('2026-07-07 07:00:00'),
            [new SourceRef('procedure_result', 'lab-' . strtolower($analyte))],
        );
    }

    /**
     * @param list<MedicationEntry> $meds
     * @param list<LabResultEntry> $labs
     * @param list<AllergyEntry> $allergies
     * @param list<FollowUpEntry> $followUps
     */
    private static function chart(
        array $meds = [],
        array $labs = [],
        array $allergies = [],
        array $followUps = [],
    ): ChartSnapshot {
        return (new ChartSnapshotSynthesizer())->synthesize($meds, $labs, $allergies, $followUps);
    }

    /**
     * One inline chart scenario per adjudicated case id. Med names are
     * free-text on purpose (IngredientMatcher is word-boundary,
     * case-insensitive); meds/allergies are Current so the detectors fire.
     *
     * @return array<string, ChartSnapshot>
     */
    private static function chartScenarios(): array
    {
        return [
            'panic-potassium-high' => self::chart(labs: [self::lab('Potassium', 6.8, 'mmol/L')]),
            'panic-potassium-low' => self::chart(labs: [self::lab('Potassium', 2.8, 'mmol/L')]),
            'panic-sodium-low' => self::chart(labs: [self::lab('Sodium', 112.0, 'mmol/L')]),
            'panic-glucose-low' => self::chart(labs: [self::lab('Glucose', 50.0, 'mg/dL')]),
            'panic-glucose-high' => self::chart(labs: [self::lab('Glucose', 480.0, 'mg/dL')]),
            'panic-hemoglobin-low' => self::chart(labs: [self::lab('Hemoglobin', 6.8, 'g/dL')]),
            'panic-platelets-low' => self::chart(labs: [self::lab('Platelets', 20.0, '10*3/uL')]),
            'panic-platelets-high' => self::chart(labs: [self::lab('Platelets', 1000.0, '10*3/uL')]),
            'ddi-warfarin-aspirin' => self::chart(meds: [
                self::med('Warfarin 5mg Tablet', 'med-warfarin'),
                self::med('Aspirin 81mg', 'med-aspirin'),
            ]),
            'ddi-warfarin-ibuprofen' => self::chart(meds: [
                self::med('Warfarin 5mg Tablet', 'med-warfarin'),
                self::med('Ibuprofen 400mg', 'med-ibuprofen'),
            ]),
            'ddi-methotrexate-trimethoprim' => self::chart(meds: [
                self::med('Methotrexate 2.5mg Tablet', 'med-mtx'),
                self::med('Trimethoprim 100mg', 'med-tmp'),
            ]),
            'allergy-penicillin-amoxicillin' => self::chart(
                meds: [self::med('Amoxicillin 500mg Capsule', 'med-amox')],
                allergies: [self::allergy('Penicillin', 'all-pcn')],
            ),
            'allergy-penicillin-cephalexin' => self::chart(
                meds: [self::med('Cephalexin 500mg', 'med-ceph')],
                allergies: [self::allergy('Penicillin', 'all-pcn')],
            ),
            'followup-open-overdue' => self::chart(followUps: [
                new FollowUpEntry(
                    'Repeat CBC to recheck platelets',
                    new \DateTimeImmutable('2026-06-30'),
                    true,
                    [new SourceRef('lists', 'fu-cbc')],
                ),
            ]),
        ];
    }

    /**
     * Explicit detector-finding → §3a-label mapping, one predicate per
     * label id. A finding no predicate claims becomes an "unexpected:" flag
     * — a false positive that drags precision below the floor, so scenario
     * noise cannot hide.
     *
     * @return array<string, callable(CriticalFinding): bool>
     */
    private static function labelMatchers(): array
    {
        $panic = static fn (string $analyte, string $bound): callable =>
            static fn (CriticalFinding $f): bool => $f->type === CriticalFindingType::PanicLab
                && str_contains(strtolower($f->summary), $analyte)
                && str_contains($f->summary, $bound . ' panic bound');
        $ddi = static fn (string $a, string $b): callable =>
            static fn (CriticalFinding $f): bool => $f->type === CriticalFindingType::DrugDrugInteraction
                && str_contains(strtolower($f->summary), $a)
                && str_contains(strtolower($f->summary), $b);
        $conflict = static fn (string $med, string $substance): callable =>
            static fn (CriticalFinding $f): bool => $f->type === CriticalFindingType::DrugAllergyConflict
                && str_contains(strtolower($f->summary), $med)
                && str_contains(strtolower($f->summary), $substance);

        return [
            'panic-potassium-high' => $panic('potassium', 'high'),
            'panic-potassium-low' => $panic('potassium', 'low'),
            'panic-sodium-low' => $panic('sodium', 'low'),
            'panic-glucose-low' => $panic('glucose', 'low'),
            'panic-glucose-high' => $panic('glucose', 'high'),
            'panic-hemoglobin-low' => $panic('hemoglobin', 'low'),
            'panic-platelets-low' => $panic('platelets', 'low'),
            'panic-platelets-high' => $panic('platelets', 'high'),
            'ddi-warfarin-aspirin' => $ddi('warfarin', 'aspirin'),
            'ddi-warfarin-ibuprofen' => $ddi('warfarin', 'ibuprofen'),
            'ddi-methotrexate-trimethoprim' => $ddi('methotrexate', 'trimethoprim'),
            'allergy-penicillin-amoxicillin' => $conflict('amoxicillin', 'penicillin'),
            'allergy-penicillin-cephalexin' => $conflict('cephalexin', 'penicillin'),
            'followup-open-overdue' => static fn (CriticalFinding $f): bool =>
                $f->type === CriticalFindingType::OpenFollowUp
                && str_contains($f->summary, 'overdue'),
        ];
    }

    /**
     * Runs the four real detectors with their draftV1 tables over one chart.
     *
     * @return list<CriticalFinding>
     */
    private static function runDetectors(ChartSnapshot $snapshot): array
    {
        $reports = [
            (new PanicLabDetector(PanicThresholds::draftV1()))->detect($snapshot),
            (new DrugDrugInteractionDetector(InteractionPairs::draftV1()))->detect($snapshot),
            (new DrugAllergyConflictDetector(AllergyClassMap::draftV1()))->detect($snapshot),
            (new OpenFollowUpDetector())->detect($snapshot, new \DateTimeImmutable(self::TODAY)),
        ];

        $findings = [];
        foreach ($reports as $report) {
            self::assertSame(
                [],
                $report->unevaluable,
                'An adjudicated scenario chart must be fully evaluable — an unevaluable item here is a broken fixture.',
            );
            $findings = [...$findings, ...$report->findings];
        }

        return $findings;
    }

    /**
     * @param list<CriticalFinding> $findings
     *
     * @return list<string> mapped label ids (or "unexpected:" markers), deduplicated
     */
    private static function mapFindingsToLabels(array $findings): array
    {
        $matchers = self::labelMatchers();
        $flagged = [];
        foreach ($findings as $finding) {
            $matched = null;
            foreach ($matchers as $labelId => $matcher) {
                if ($matcher($finding)) {
                    $matched = $labelId;
                    break;
                }
            }
            $flagged[] = $matched ?? 'unexpected:' . $finding->summary;
        }

        return array_values(array_unique($flagged));
    }

    public function testTheGateIsArmedBySignedOffLabelsAndPasses(): void
    {
        $cases = (new GoldenChartCaseLoader())->loadFromDirectory(self::adjudicatedDir());
        $scenarios = self::chartScenarios();

        $this->assertCount(
            count($scenarios),
            $cases,
            'Every §3a scenario must have exactly one adjudicated fixture (and vice versa).',
        );

        $pairs = [];
        foreach ($cases as $case) {
            $this->assertTrue($case->adjudicated, sprintf('Case "%s" in adjudicated/ must carry adjudicated=true.', $case->id));
            $this->assertArrayHasKey(
                $case->id,
                $scenarios,
                sprintf('Adjudicated case "%s" has no inline chart scenario — the gate cannot evaluate it.', $case->id),
            );

            $flagged = self::mapFindingsToLabels(self::runDetectors($scenarios[$case->id]));
            $pairs[] = [$case, new CaseResult($flagged, $flagged, 0, 0)];
        }

        $report = (new AccuracyGate(self::PRECISION_FLOOR, self::FACTUAL_FLOOR))->evaluate($pairs);

        $this->assertTrue($report->armed, 'Signed-off §3a labels must ARM the gate: ' . $report->summary);
        $this->assertSame([], $report->criticalMisses, 'Zero misses on the critical subset is the point (R13): ' . $report->summary);
        $this->assertTrue($report->passed, $report->summary);
    }

    /**
     * One spurious flag among 14 clean cases puts aggregate precision at
     * 14/15 ≈ 0.93 — comfortably above the old 0.8 floor, so the OLD gate
     * would have passed it. The two-track rework makes any false flag on an
     * adjudicated case a hard-zero failure (a data-trust bug, not a
     * precision drag); this pins that an unexpected detector finding can
     * never again hide above a rate threshold.
     */
    public function testAnUnexpectedDetectorFlagHardFailsTheArmedGate(): void
    {
        $cases = (new GoldenChartCaseLoader())->loadFromDirectory(self::adjudicatedDir());
        $scenarios = self::chartScenarios();

        $noiseInjected = false;
        $pairs = [];
        foreach ($cases as $case) {
            $flagged = self::mapFindingsToLabels(self::runDetectors($scenarios[$case->id]));
            if (!$noiseInjected) {
                $flagged[] = 'unexpected:synthetic-noise-finding';
                $noiseInjected = true;
            }
            $pairs[] = [$case, new CaseResult($flagged, $flagged, 0, 0)];
        }
        $this->assertTrue($noiseInjected);

        $report = (new AccuracyGate(self::PRECISION_FLOOR, self::FACTUAL_FLOOR))->evaluate($pairs);

        $this->assertTrue($report->armed);
        $this->assertSame([], $report->criticalMisses, 'The injected noise is a false flag, not a miss.');
        $this->assertSame(1, $report->falseFlagCount);
        $this->assertFalse($report->criticalTrackPassed, 'A spurious flag on an adjudicated case is a data-trust bug — hard zero, not a rate.');
        $this->assertFalse($report->passed, $report->summary);
    }

    public function testEveryAdjudicatedFixtureCarriesProvenance(): void
    {
        $files = glob(self::adjudicatedDir() . '/*.json');
        $this->assertNotFalse($files);
        $this->assertNotSame([], $files, 'The adjudicated set must not be empty once the gate is armed.');

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            $this->assertNotFalse($raw);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);

            $provenance = $decoded['_provenance'] ?? null;
            $this->assertIsString(
                $provenance,
                sprintf('Adjudicated fixture "%s" must carry a _provenance citation — no adjudicated case without provenance.', basename($file)),
            );
            $this->assertNotSame(
                '',
                trim($provenance),
                sprintf('Adjudicated fixture "%s" has a blank _provenance.', basename($file)),
            );
        }
    }

    /**
     * §3b stays out: no fixture in the adjudicated set may carry a
     * judgment-based (provisional-unadjudicated) or UNSOURCED label. The
     * gate arms on §3a only — this pins that boundary in code.
     */
    public function testNoUnsourcedOrJudgmentLabelIsGated(): void
    {
        $cases = (new GoldenChartCaseLoader())->loadFromDirectory(self::adjudicatedDir());
        $matchers = self::labelMatchers();

        foreach ($cases as $case) {
            foreach ($case->labels->mustNotMiss as $labelId) {
                $this->assertArrayHasKey(
                    $labelId,
                    $matchers,
                    sprintf(
                        'Label "%s" (case "%s") has no explicit detector mapping — only §3a reference-grounded, '
                        . 'detector-evaluable labels may gate; §3b and UNSOURCED items (e.g. sulfa, DA-4) may not.',
                        $labelId,
                        $case->id,
                    ),
                );
                $this->assertStringNotContainsString('sulf', strtolower($labelId), 'Sulfonamide grouping is UNSOURCED (DA-4) and must not gate.');
            }
        }
    }
}

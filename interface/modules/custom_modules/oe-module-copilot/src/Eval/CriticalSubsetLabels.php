<?php

/**
 * Mirrors the Week 1 adjudicated-label chart scenarios and detector-finding
 * matchers for the eval gate's `critical_subset` category and its turn-kind
 * detector-citation minting (TRO-35; eval/goldenset/README.md
 * "`critical_subset` is not one of the 50 files"; the Week 1 frozen
 * `tests/Tests/Isolated/Copilot/GoldenChart/CriticalSubsetGateTest.php`).
 *
 * This class cannot import from a test file (PHPStan/PSR-4 boundary), so the
 * id -> chart-scenario mapping and the finding -> label-id predicates are
 * mirrored here byte-for-byte from that frozen test; the adjudicated LABELS
 * themselves stay loaded from `tests/Tests/Isolated/Copilot/GoldenChart/adjudicated/`
 * via {@see \OpenEMR\Modules\Copilot\GoldenChart\GoldenChartCaseLoader} — never
 * duplicated here. `labelFor()` is the same mapping the golden-set turn
 * cases use to mint `SourceRef('detector', <label id>, ...)` (e.g.
 * `panic-potassium-high`) so a turn case's chart and the Week 1 harness agree
 * on what a detector finding is called.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final class CriticalSubsetLabels
{
    /** Fixed evaluation date mirroring CriticalSubsetGateTest::TODAY. */
    private const TODAY = '2026-07-08';

    private function __construct()
    {
    }

    public static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
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
     * @return array<string, ChartSnapshot>
     */
    public static function chartScenarios(): array
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
     * @return array<string, callable(CriticalFinding): bool>
     */
    public static function labelMatchers(): array
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
     * Maps one detector finding to its stable label id, or an
     * `unexpected:`-prefixed marker when no predicate claims it (mirroring
     * CriticalSubsetGateTest::mapFindingsToLabels()'s per-finding mapping).
     */
    public static function labelFor(CriticalFinding $finding): string
    {
        foreach (self::labelMatchers() as $labelId => $matcher) {
            if ($matcher($finding)) {
                return $labelId;
            }
        }

        return 'unexpected:' . $finding->summary;
    }

    /**
     * @param list<CriticalFinding> $findings
     *
     * @return list<string> mapped label ids (or "unexpected:" markers), deduplicated
     */
    public static function labelsFor(array $findings): array
    {
        $flagged = [];
        foreach ($findings as $finding) {
            $flagged[] = self::labelFor($finding);
        }

        return array_values(array_unique($flagged));
    }
}

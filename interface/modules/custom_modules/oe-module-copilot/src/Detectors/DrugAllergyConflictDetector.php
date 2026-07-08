<?php

/**
 * Deterministic drug-allergy conflict detector (T10; R13, UC4;
 * ARCHITECTURE.md §6).
 *
 * Conflicts are a code guarantee, never model judgment, and they live
 * BETWEEN sources (AUDIT D9) — meds and allergies are evaluated from one
 * synthesized snapshot. A CURRENT medication whose name word-boundary-
 * contains any expanded ingredient of a CURRENT allergy fires a finding
 * with provenance from both sides. A would-be conflict where either side
 * has Unknown currency (AUDIT D10) is surfaced as unevaluable — never
 * silently passed. NotCurrent rows on either side never participate.
 * Pure: no I/O, no clock, no globals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Detectors;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final class DrugAllergyConflictDetector
{
    public function __construct(private readonly AllergyClassMap $classes)
    {
    }

    public function detect(ChartSnapshot $snapshot): DetectorReport
    {
        $medications = array_values(array_filter(
            $snapshot->medications,
            static fn (MedicationEntry $entry): bool => $entry->status !== CurrencyStatus::NotCurrent,
        ));
        $allergies = array_values(array_filter(
            $snapshot->allergies,
            static fn (AllergyEntry $entry): bool => $entry->status !== CurrencyStatus::NotCurrent,
        ));

        $findings = [];
        $unevaluable = [];

        foreach ($allergies as $allergy) {
            $ingredients = $this->classes->expand($allergy->substance);
            foreach ($medications as $medication) {
                $matched = self::firstMatch($medication->name, $ingredients);
                if ($matched === null) {
                    continue;
                }

                $sources = self::mergeSources($medication->sources, $allergy->sources);
                if (
                    $medication->status === CurrencyStatus::Current
                    && $allergy->status === CurrencyStatus::Current
                ) {
                    $findings[] = new CriticalFinding(
                        CriticalFindingType::DrugAllergyConflict,
                        sprintf(
                            'Drug-allergy conflict: current medication "%s" contains %s '
                                . 'and the patient has a current "%s" allergy',
                            $medication->name,
                            $matched,
                            $allergy->substance,
                        ),
                        $sources,
                    );
                    continue;
                }

                $unevaluable[] = new UnevaluableItem(
                    sprintf(
                        'Possible drug-allergy conflict between medication "%s" and allergy "%s" '
                            . 'cannot be confirmed: currency is unknown on at least one side (D10)',
                        $medication->name,
                        $allergy->substance,
                    ),
                    $sources,
                );
            }
        }

        return new DetectorReport($findings, $unevaluable);
    }

    /**
     * @param list<string> $ingredients
     */
    private static function firstMatch(string $medicationName, array $ingredients): ?string
    {
        foreach ($ingredients as $ingredient) {
            if (IngredientMatcher::matches($medicationName, $ingredient)) {
                return $ingredient;
            }
        }

        return null;
    }

    /**
     * @param list<SourceRef> $medicationSources
     * @param list<SourceRef> $allergySources
     *
     * @return list<SourceRef>
     */
    private static function mergeSources(array $medicationSources, array $allergySources): array
    {
        $merged = [];
        foreach ([...$medicationSources, ...$allergySources] as $source) {
            $merged[$source->sourceType . "\0" . $source->sourceId] = $source;
        }

        return array_values($merged);
    }
}

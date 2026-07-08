<?php

/**
 * Deterministic drug-drug interaction detector (T10; R13, UC4;
 * ARCHITECTURE.md §6).
 *
 * Contraindicated pairs are a code guarantee, never model judgment. A pair
 * fires exactly one finding when both ingredients match CURRENT medications
 * (word-boundary, case-insensitive match inside the med name), with
 * provenance concatenated from both sides. A pair whose match is blocked
 * only by Unknown currency (AUDIT D10) is surfaced as unevaluable — never
 * silently passed. NotCurrent medications never participate. Pure: no I/O,
 * no clock, no globals.
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
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final class DrugDrugInteractionDetector
{
    public function __construct(private readonly InteractionPairs $pairs)
    {
    }

    public function detect(ChartSnapshot $snapshot): DetectorReport
    {
        $currentMeds = $snapshot->currentMedications();
        $unknownMeds = array_values(array_filter(
            $snapshot->medications,
            static fn (MedicationEntry $entry): bool => $entry->status === CurrencyStatus::Unknown,
        ));

        $findings = [];
        $unevaluable = [];

        foreach ($this->pairs->pairs as [$first, $second]) {
            $currentFirst = self::matching($currentMeds, $first);
            $currentSecond = self::matching($currentMeds, $second);

            if ($currentFirst !== [] && $currentSecond !== []) {
                $findings[] = new CriticalFinding(
                    CriticalFindingType::DrugDrugInteraction,
                    sprintf('Drug-drug interaction: current medications include both %s and %s', $first, $second),
                    self::mergeSources([...$currentFirst, ...$currentSecond]),
                );
                continue;
            }

            $unknownFirst = self::matching($unknownMeds, $first);
            $unknownSecond = self::matching($unknownMeds, $second);

            $firstPresent = $currentFirst !== [] || $unknownFirst !== [];
            $secondPresent = $currentSecond !== [] || $unknownSecond !== [];
            if ($firstPresent && $secondPresent) {
                $unevaluable[] = new UnevaluableItem(
                    sprintf(
                        'Possible %s + %s interaction cannot be confirmed: '
                            . 'at least one medication has unknown currency (D10)',
                        $first,
                        $second,
                    ),
                    self::mergeSources([...$currentFirst, ...$unknownFirst, ...$currentSecond, ...$unknownSecond]),
                );
            }
        }

        return new DetectorReport($findings, $unevaluable);
    }

    /**
     * @param list<MedicationEntry> $medications
     *
     * @return list<MedicationEntry>
     */
    private static function matching(array $medications, string $ingredient): array
    {
        return array_values(array_filter(
            $medications,
            static fn (MedicationEntry $entry): bool => IngredientMatcher::matches($entry->name, $ingredient),
        ));
    }

    /**
     * @param list<MedicationEntry> $entries
     *
     * @return list<SourceRef>
     */
    private static function mergeSources(array $entries): array
    {
        $merged = [];
        foreach ($entries as $entry) {
            foreach ($entry->sources as $source) {
                $merged[$source->sourceType . "\0" . $source->sourceId] = $source;
            }
        }

        return array_values($merged);
    }
}

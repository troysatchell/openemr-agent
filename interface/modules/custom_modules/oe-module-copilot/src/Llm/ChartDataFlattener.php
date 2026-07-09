<?php

/**
 * Flattens a synthesized ChartSnapshot into the flat, per-data-class arrays
 * the minimum-necessary boundary consumes (T12; UC2; AUDIT D1/D10;
 * ARCHITECTURE.md §2 ORCH, §3.5).
 *
 * Every one of the four data classes — medications, lab_results, allergies,
 * follow_ups — is ALWAYS present in the returned array, even when empty.
 * This chart was assessed for all four sources in one synthesis pass (D9);
 * a missing key would falsely tell the absence-marker channel downstream
 * (MinimumNecessaryPayloadBuilder) that a class was never assessed, when in
 * fact it was assessed and found empty (known-absent, e.g. NKDA-shaped).
 *
 * Discontinued (NotCurrent) medications and allergies never flatten — they
 * are history, not orientation content (D10). Only OPEN follow-ups flatten;
 * a closed loop is not orientation content either. Every lab flattens
 * regardless of value/unit completeness — those fields pass through null
 * when absent, because currency filtering does not apply to labs and the
 * detector layer, not this layer, judges evaluability.
 *
 * Citation tokens are minted exclusively via ReferenceIndex::tokenFor() —
 * the one canonical format — so every ref this layer emits resolves in the
 * same index the verifier grounds against.
 *
 * Pure: no I/O, no clock, no globals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;

final class ChartDataFlattener
{
    /**
     * @return array<string, list<array<string, mixed>>> all four data-class
     *   keys are always present, even when the entry list is empty
     */
    public function flatten(ChartSnapshot $chart): array
    {
        return [
            'medications' => $this->flattenMedications($chart),
            'lab_results' => $this->flattenLabs($chart),
            'allergies' => $this->flattenAllergies($chart),
            'follow_ups' => $this->flattenFollowUps($chart),
        ];
    }

    /**
     * @return list<array{name: string, status: string, ref: string}>
     */
    private function flattenMedications(ChartSnapshot $chart): array
    {
        $entries = [];
        foreach ($chart->medications as $medication) {
            if ($medication->status === CurrencyStatus::NotCurrent) {
                continue;
            }

            $entries[] = [
                'name' => $medication->name,
                'status' => CurrencyWire::status($medication->status),
                'ref' => ReferenceIndex::tokenFor($medication->sources[0]),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{analyte: string, value: float|null, unit: string|null, ref: string}>
     */
    private function flattenLabs(ChartSnapshot $chart): array
    {
        $entries = [];
        foreach ($chart->labs as $lab) {
            $entries[] = [
                'analyte' => $lab->analyte,
                'value' => $lab->value,
                'unit' => $lab->unit,
                'ref' => ReferenceIndex::tokenFor($lab->sources[0]),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{substance: string, status: string, ref: string}>
     */
    private function flattenAllergies(ChartSnapshot $chart): array
    {
        $entries = [];
        foreach ($chart->allergies as $allergy) {
            if ($allergy->status === CurrencyStatus::NotCurrent) {
                continue;
            }

            $entries[] = [
                'substance' => $allergy->substance,
                'status' => CurrencyWire::status($allergy->status),
                'ref' => ReferenceIndex::tokenFor($allergy->sources[0]),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{description: string, due: string|null, ref: string}>
     */
    private function flattenFollowUps(ChartSnapshot $chart): array
    {
        $entries = [];
        foreach ($chart->openFollowUps() as $followUp) {
            $entries[] = [
                'description' => $followUp->description,
                'due' => $followUp->dueDate?->format('Y-m-d'),
                'ref' => ReferenceIndex::tokenFor($followUp->sources[0]),
            ];
        }

        return $entries;
    }
}

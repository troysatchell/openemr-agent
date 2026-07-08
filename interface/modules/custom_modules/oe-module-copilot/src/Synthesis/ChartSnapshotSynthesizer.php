<?php

/**
 * One-pass chart synthesizer (T9; AUDIT D9; ARCHITECTURE.md §3.3).
 *
 * Builds a ChartSnapshot from all four sources in a single pass. Near-
 * duplicate meds/allergies (same trimmed, case-folded label AND same
 * currency status) collapse into one entry with ALL SourceRefs retained;
 * entries with different statuses never merge — a discontinued row and a
 * current row are different facts, and merging them would launder D10
 * state. Labs and follow-ups pass through in input order.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Synthesis;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;

final class ChartSnapshotSynthesizer
{
    /**
     * @param list<MedicationEntry> $medications
     * @param list<LabResultEntry>  $labs
     * @param list<AllergyEntry>    $allergies
     * @param list<FollowUpEntry>   $followUps
     */
    public function synthesize(array $medications, array $labs, array $allergies, array $followUps = []): ChartSnapshot
    {
        return new ChartSnapshot(
            $this->collapseMedications($medications),
            array_values($labs),
            $this->collapseAllergies($allergies),
            array_values($followUps),
        );
    }

    /**
     * @param list<MedicationEntry> $entries
     *
     * @return list<MedicationEntry>
     */
    private function collapseMedications(array $entries): array
    {
        $collapsed = [];
        foreach ($entries as $entry) {
            $key = $this->dedupeKey($entry->name, $entry->status);
            $existing = $collapsed[$key] ?? null;
            $collapsed[$key] = $existing === null
                ? $entry
                : new MedicationEntry(
                    $existing->name,
                    $existing->status,
                    [...$existing->sources, ...$entry->sources],
                );
        }

        return array_values($collapsed);
    }

    /**
     * @param list<AllergyEntry> $entries
     *
     * @return list<AllergyEntry>
     */
    private function collapseAllergies(array $entries): array
    {
        $collapsed = [];
        foreach ($entries as $entry) {
            $key = $this->dedupeKey($entry->substance, $entry->status);
            $existing = $collapsed[$key] ?? null;
            $collapsed[$key] = $existing === null
                ? $entry
                : new AllergyEntry(
                    $existing->substance,
                    $existing->status,
                    [...$existing->sources, ...$entry->sources],
                );
        }

        return array_values($collapsed);
    }

    private function dedupeKey(string $label, CurrencyStatus $status): string
    {
        return mb_strtolower(trim($label)) . "\0" . $status->name;
    }
}

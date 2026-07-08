<?php

/**
 * One-pass reconciled chart snapshot (T9; AUDIT D9; ARCHITECTURE.md §3.3).
 *
 * Meds, labs, allergies, and follow-up threads are held in ONE structure so
 * cross-source facts (drug–allergy pairs, med-context lab panics) are
 * reachable from a single synthesis pass — never per-source summaries,
 * because the dangerous interactions live *between* sources. Unknown-currency
 * entries are surfaced for honest-uncertainty UX (R11), never dropped.
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

final readonly class ChartSnapshot
{
    /**
     * @param list<MedicationEntry> $medications
     * @param list<LabResultEntry>  $labs
     * @param list<AllergyEntry>    $allergies
     * @param list<FollowUpEntry>   $followUps
     */
    public function __construct(
        public array $medications,
        public array $labs,
        public array $allergies,
        public array $followUps,
    ) {
    }

    /**
     * @return list<MedicationEntry>
     */
    public function currentMedications(): array
    {
        return array_values(array_filter(
            $this->medications,
            static fn (MedicationEntry $entry): bool => $entry->status === CurrencyStatus::Current,
        ));
    }

    /**
     * Meds and allergies whose currency could not be evaluated (AUDIT D10)
     * — surfaced to the caller for honest-uncertainty UX, never silently
     * treated as current and never dropped.
     *
     * @return list<MedicationEntry|AllergyEntry>
     */
    public function unknownCurrencyEntries(): array
    {
        $unknown = [];
        foreach ($this->medications as $medication) {
            if ($medication->status === CurrencyStatus::Unknown) {
                $unknown[] = $medication;
            }
        }
        foreach ($this->allergies as $allergy) {
            if ($allergy->status === CurrencyStatus::Unknown) {
                $unknown[] = $allergy;
            }
        }

        return $unknown;
    }

    /**
     * Cross product of CURRENT medications x CURRENT allergies — the
     * cross-source pairs the drug–allergy conflict detector evaluates (D9).
     * Discontinued or unknown-currency rows join no pair; Unknown rows are
     * surfaced separately via unknownCurrencyEntries().
     *
     * @return list<array{MedicationEntry, AllergyEntry}>
     */
    public function medicationAllergyPairs(): array
    {
        $currentAllergies = array_values(array_filter(
            $this->allergies,
            static fn (AllergyEntry $entry): bool => $entry->status === CurrencyStatus::Current,
        ));

        $pairs = [];
        foreach ($this->currentMedications() as $medication) {
            foreach ($currentAllergies as $allergy) {
                $pairs[] = [$medication, $allergy];
            }
        }

        return $pairs;
    }

    /**
     * @return list<FollowUpEntry>
     */
    public function openFollowUps(): array
    {
        return array_values(array_filter(
            $this->followUps,
            static fn (FollowUpEntry $entry): bool => $entry->open,
        ));
    }
}

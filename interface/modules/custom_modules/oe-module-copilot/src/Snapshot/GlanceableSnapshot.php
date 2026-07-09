<?php

/**
 * The glanceable pre-visit snapshot presented to the physician (T13; UC1;
 * ARCHITECTURE.md §1/§6; AUDIT D0/D1/D6/D10).
 *
 * A deterministic presentation shaping of already-guaranteed content — it
 * never invents, reorders, dedupes, or drops what the detectors and
 * synthesis layer produced. Honest uncertainty is structural, not
 * incidental: unknown-currency meds/allergies and unevaluable items each get
 * their own section rather than being silently folded into "current" or
 * dropped (D10); a change delta computed without a last-visit reference date
 * is UNKNOWN, never "no changes" (D1) — enforced here as an invariant, not
 * left to caller discipline. isQuiet() is the explicit, computed "nothing to
 * say" state (R5/R7): silence is earned, never assumed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Snapshot;

use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\UnevaluableItem;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;

final readonly class GlanceableSnapshot
{
    /**
     * @param list<CriticalFinding>              $mustNotMiss
     * @param list<UnevaluableItem>              $unevaluable
     * @param list<MedicationEntry|AllergyEntry> $unknownCurrency
     * @param list<LabResultEntry>               $newLabs
     * @param list<MedicationEntry>              $currentMedications
     * @param list<AllergyEntry>                 $currentAllergies
     */
    public function __construct(
        public PatientDemographics $patient,
        public array $mustNotMiss,
        public array $unevaluable,
        public array $unknownCurrency,
        public ChangesBasis $changesBasis,
        public array $newLabs,
        public array $currentMedications,
        public array $currentAllergies,
    ) {
        if ($changesBasis === ChangesBasis::UnknownNoLastVisit && $newLabs !== []) {
            throw new \DomainException(
                'A change delta may not be claimed without a reference basis '
                . '(no last-visit date to compare against — AUDIT D1)'
            );
        }
    }

    /**
     * True only when there is affirmatively nothing to say: no must-not-miss
     * findings, nothing unevaluable, nothing of unknown currency, no new
     * labs, AND the delta itself is known (SinceLastVisit) — an unknown
     * delta can never be reported as quiet (R5/R11). Standing current
     * medications/allergies are state, not change, and do not factor in.
     */
    public function isQuiet(): bool
    {
        return $this->mustNotMiss === []
            && $this->unevaluable === []
            && $this->unknownCurrency === []
            && $this->newLabs === []
            && $this->changesBasis === ChangesBasis::SinceLastVisit;
    }
}

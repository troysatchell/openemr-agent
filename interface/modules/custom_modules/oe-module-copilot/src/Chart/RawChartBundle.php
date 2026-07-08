<?php

/**
 * Raw five-source chart bundle for one patient (T5).
 *
 * Holds the unprocessed FHIR reads for the five v1 sources side by side so
 * downstream synthesis reconciles meds × labs × allergies in ONE pass
 * (AUDIT D9: interactions live between sources — no isolated per-source
 * summaries). Properties are raw decoded FHIR resources; data-trust
 * normalization (D0/D1/D4/D6/D10) happens downstream, never here.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

final readonly class RawChartBundle
{
    /**
     * @param list<array<string, mixed>> $patient Patient resources
     * @param list<array<string, mixed>> $medications MedicationRequest resources
     * @param list<array<string, mixed>> $observations Observation resources
     * @param list<array<string, mixed>> $allergies AllergyIntolerance resources
     * @param list<array<string, mixed>> $problems Condition resources
     */
    public function __construct(
        public array $patient,
        public array $medications,
        public array $observations,
        public array $allergies,
        public array $problems,
    ) {
    }
}

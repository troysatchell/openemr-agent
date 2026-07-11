<?php

/**
 * Production FhirServiceFactory: instantiates the real FHIR service per
 * resource type (T5).
 *
 * These are the same service classes the FHIR REST controllers use (see
 * src/RestControllers/FHIR/FhirPatientRestController.php), so every read
 * flows through the best-guarded surface: no raw SQL, no legacy-table reads,
 * no $ignoreAuth, no service account (S1/S4/S5/S6).
 *
 * The five construction arms are the module's only lines that require a live
 * stack (the service constructors read table metadata from the database) —
 * they are covered by the live smoke turn only. Everything downstream is
 * covered by the isolated contract suite via a stubbed factory
 * (tests/Tests/Isolated/Copilot/Chart/OpenEmrFhirGatewayTest.php); the
 * default arm's rejection of unsupported types is exercised there too.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

use OpenEMR\Services\FHIR\FhirAllergyIntoleranceService;
use OpenEMR\Services\FHIR\FhirConditionService;
use OpenEMR\Services\FHIR\FhirMedicationRequestService;
use OpenEMR\Services\FHIR\FhirObservationService;
use OpenEMR\Services\FHIR\FhirPatientService;
use OpenEMR\Services\FHIR\FhirServiceBase;

final class OpenEmrFhirServiceFactory implements FhirServiceFactory
{
    public function create(string $resourceType): FhirServiceBase
    {
        return match ($resourceType) {
            'Patient' => new FhirPatientService(),
            'MedicationRequest' => new FhirMedicationRequestService(),
            'Observation' => new FhirObservationService(),
            'AllergyIntolerance' => new FhirAllergyIntoleranceService(),
            'Condition' => new FhirConditionService(),
            default => throw new \InvalidArgumentException(
                sprintf('Unsupported FHIR resource type for chart reads: %s', $resourceType)
            ),
        };
    }
}

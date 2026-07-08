<?php

/**
 * Reads the five-source v1 chart bundle as the delegated physician (T5).
 *
 * Exactly five sanctioned FHIR sources — Patient, MedicationRequest,
 * Observation, AllergyIntolerance, Condition — each read with the SAME typed
 * PhysicianContext (delegation, never a service account — ARCHITECTURE.md §4,
 * Decision 3; S4/S6) and each search scoped to the one requested patient.
 * Gateway failures propagate: a read that cannot be completed is never
 * laundered into a silently-empty bundle.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

final class ChartReader
{
    public function __construct(private readonly FhirReadGateway $gateway)
    {
    }

    /**
     * @throws FhirReadFailedException when any of the five source reads fails
     */
    public function readChart(PhysicianContext $physician, string $patientUuid): RawChartBundle
    {
        if (trim($patientUuid) === '') {
            throw new \DomainException('Patient uuid must be non-empty: chart reads are always patient-scoped');
        }

        return new RawChartBundle(
            patient: $this->gateway->read($physician, 'Patient', ['_id' => $patientUuid]),
            medications: $this->gateway->read($physician, 'MedicationRequest', ['patient' => $patientUuid]),
            observations: $this->gateway->read($physician, 'Observation', ['patient' => $patientUuid]),
            allergies: $this->gateway->read($physician, 'AllergyIntolerance', ['patient' => $patientUuid]),
            problems: $this->gateway->read($physician, 'Condition', ['patient' => $patientUuid]),
        );
    }
}

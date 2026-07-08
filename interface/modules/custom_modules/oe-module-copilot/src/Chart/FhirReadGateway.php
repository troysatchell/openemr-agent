<?php

/**
 * Gateway contract for delegated FHIR resource reads (T5).
 *
 * Implementations MUST read through the FHIR service surface as the named
 * physician's own authority — never raw legacy tables, never a service
 * account, never the native background path (S4/S6; ARCHITECTURE.md §4,
 * Decision 3). Failures MUST surface as FhirReadFailedException: returning
 * an empty list for a failed read is forbidden, because a read outage must
 * never masquerade as an empty chart (omission is the enemy).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

interface FhirReadGateway
{
    /**
     * Executes a patient-scoped FHIR search as the delegated physician.
     *
     * @param PhysicianContext $physician the named principal every read runs as
     * @param string $resourceType FHIR resource type (e.g. 'Patient', 'Condition')
     * @param array<string, mixed> $searchParams FHIR search parameters, already patient-scoped
     * @return list<array<string, mixed>> decoded FHIR resources (JSON-shaped arrays)
     *
     * @throws FhirReadFailedException when the read cannot be completed
     */
    public function read(PhysicianContext $physician, string $resourceType, array $searchParams): array;
}

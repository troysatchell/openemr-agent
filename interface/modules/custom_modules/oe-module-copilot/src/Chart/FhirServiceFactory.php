<?php

/**
 * Factory contract for the FHIR services the chart gateway reads through (T5).
 *
 * Exists as the gateway's one injection seam: OpenEmrFhirServiceFactory builds
 * the real DB-backed services, while the isolated contract suite substitutes a
 * stub so every gateway pipeline branch is testable without a live stack.
 * Implementations MUST hand back services from the FHIR service surface only —
 * the same classes the FHIR REST controllers use — never anything that reads
 * legacy tables directly (S1 bright line).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

use OpenEMR\Services\FHIR\FhirServiceBase;

interface FhirServiceFactory
{
    /**
     * Builds the FHIR service handling one resource type.
     *
     * @param string $resourceType FHIR resource type (e.g. 'Patient', 'Condition')
     *
     * @throws \InvalidArgumentException when the type is not a supported v1 chart source
     */
    public function create(string $resourceType): FhirServiceBase;
}

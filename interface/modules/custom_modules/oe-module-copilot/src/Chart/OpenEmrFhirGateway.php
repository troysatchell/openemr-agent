<?php

/**
 * OpenEMR-backed FhirReadGateway: thin adapter over the FHIR service layer (T5).
 *
 * Obtains the matching FHIR service per resource type from the injected
 * FhirServiceFactory and calls getAll() — the same path the FHIR REST
 * controllers use (see src/RestControllers/FHIR/FhirPatientRestController.php
 * and src/Services/FHIR/FhirServiceBase.php getAll()) — so every read flows
 * through the best-guarded surface: no raw SQL, no legacy-table reads, no
 * $ignoreAuth, no service account (S1/S4/S5/S6).
 *
 * Delegation note: v1 is session-bound (ARCHITECTURE.md §4, Decision 3).
 * This adapter runs in-process inside the physician's authenticated session,
 * established upstream by the module's guarded routes; the typed
 * PhysicianContext parameter makes an anonymous call site unrepresentable at
 * the type level. The adapter performs no auth decisions and no writes.
 *
 * Pipeline logic is covered by the isolated contract suite via a stubbed
 * factory (tests/Tests/Isolated/Copilot/Chart/OpenEmrFhirGatewayTest.php);
 * only OpenEmrFhirServiceFactory's real service constructions need a live
 * stack (ticket T5).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

use OpenEMR\Validators\ProcessingResult;

final class OpenEmrFhirGateway implements FhirReadGateway
{
    public function __construct(private readonly FhirServiceFactory $serviceFactory)
    {
    }

    /**
     * @param array<string, mixed> $searchParams
     * @return list<array<string, mixed>>
     *
     * @throws FhirReadFailedException
     */
    public function read(PhysicianContext $physician, string $resourceType, array $searchParams): array
    {
        // $physician is deliberately required even though v1 delegation is
        // ambient (session-bound): no caller can reach patient data without
        // naming the physician principal. Disclosure audit logging happens at
        // the LLM boundary (C1/C5), not here.
        if ($searchParams === []) {
            throw new \InvalidArgumentException(
                'Refusing an unscoped FHIR read: search parameters must scope the query to a patient'
            );
        }

        // Outside the try: an unsupported type is a caller bug and must
        // surface as InvalidArgumentException, not a wrapped read failure.
        $service = $this->serviceFactory->create($resourceType);

        try {
            $result = $service->getAll($searchParams);
        } catch (\Throwable $failure) {
            throw new FhirReadFailedException(sprintf('FHIR %s read failed', $resourceType), 0, $failure);
        }

        if ($result->hasErrors()) {
            throw new FhirReadFailedException(sprintf(
                'FHIR %s read reported errors (validation fields: %s)',
                $resourceType,
                implode(', ', $this->validationFieldNames($result))
            ));
        }

        $data = $result->getData();
        if (!is_array($data)) {
            throw new FhirReadFailedException(
                sprintf('FHIR %s read returned a malformed result set', $resourceType)
            );
        }

        $resources = [];
        foreach ($data as $record) {
            $resources[] = $this->toResourceArray($resourceType, $record);
        }

        return $resources;
    }

    /**
     * Field names only — never raw validation message bodies — so the
     * exception message carries no query values (minimum necessary, C1).
     *
     * @return list<string>
     */
    private function validationFieldNames(ProcessingResult $result): array
    {
        $messages = $result->getValidationMessages();
        if (!is_array($messages)) {
            return [];
        }

        $fields = [];
        foreach (array_keys($messages) as $field) {
            $fields[] = (string) $field;
        }

        return $fields;
    }

    /**
     * Converts one FHIR service record (a JsonSerializable FHIR R4 resource,
     * see src/FHIR/R4/FHIRResource.php:68) into its JSON-shaped array form.
     *
     * @return array<string, mixed>
     *
     * @throws FhirReadFailedException
     */
    private function toResourceArray(string $resourceType, mixed $record): array
    {
        if (!$record instanceof \JsonSerializable) {
            throw new FhirReadFailedException(
                sprintf('FHIR %s read returned a record that is not a serializable FHIR resource', $resourceType)
            );
        }

        try {
            $decoded = json_decode(
                json_encode($record, JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $failure) {
            throw new FhirReadFailedException(
                sprintf('FHIR %s resource could not be converted to JSON form', $resourceType),
                0,
                $failure
            );
        }

        if (!is_array($decoded)) {
            throw new FhirReadFailedException(
                sprintf('FHIR %s resource did not decode to a JSON object', $resourceType)
            );
        }

        $resource = [];
        foreach ($decoded as $key => $value) {
            $resource[(string) $key] = $value;
        }

        return $resource;
    }
}

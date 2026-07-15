<?php

/**
 * NamedLaboratoryObservationService: preserves the extracted test name on
 * codeless derived lab results (TRO-56).
 *
 * Core's FhirObservationLaboratoryService::parseOpenEMRRecord() only emits
 * Observation.code when BOTH $dataRecord['code'] (result_code) and
 * $dataRecord['text'] (result_text) are non-empty — otherwise it falls back
 * to UtilsService::createNullFlavorUnknownCodeableConcept() and the recorded
 * name is dropped entirely
 * (src/Services/FHIR/Observation/FhirObservationLaboratoryService.php:251).
 * The module's DerivedObservationWriter persists an extracted analyte's test
 * name byte-verbatim in procedure_result.result_text while deliberately
 * leaving result_code = '' — the extraction wire carries no code, and
 * inventing one would be a fabrication core must never make and neither can
 * this module.
 *
 * This subclass fixes only the naming loss: when a derived row's coding is
 * the honest null-flavor UNK (we don't know the code system), it still
 * carries the recorded name across as CodeableConcept.text — FHIR's "as seen
 * by the user who entered the data" semantics for a text-only code. No LOINC
 * or other code is invented; the null-flavor coding is left exactly as core
 * produced it. This applies only to the module's in-process chart read path
 * (OpenEmrFhirServiceFactory → OpenEmrFhirGateway → FhirChartMapper) and
 * never touches the certified external FHIR API surface.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRObservation;
use OpenEMR\FHIR\R4\FHIRElement\FHIRString;
use OpenEMR\Services\FHIR\Observation\FhirObservationLaboratoryService;

final class NamedLaboratoryObservationService extends FhirObservationLaboratoryService
{
    /**
     * @param array<array-key, mixed> $dataRecord
     * @param bool                    $encode
     *
     * @return FHIRObservation
     */
    public function parseOpenEMRRecord($dataRecord = [], $encode = false): FHIRObservation
    {
        $observation = parent::parseOpenEMRRecord($dataRecord, $encode);

        $text = $dataRecord['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return $observation;
        }

        $recordCode = $dataRecord['code'] ?? null;
        if (is_string($recordCode) && trim($recordCode) !== '') {
            // A real code exists — the parent already emitted code + display;
            // leave it untouched.
            return $observation;
        }

        // Codeless row: the parent fell back to the null-flavor UNK concept,
        // which never carries a text — add the recorded name to it. The
        // parent sets Observation.code on every branch, so getCode() is
        // always present here.
        $observation->getCode()->setText(new FHIRString($text));

        return $observation;
    }
}

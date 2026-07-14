<?php

/**
 * Port for the document ingestion pipeline (W2_ARCHITECTURE.md §2 —
 * `attach_and_extract(patient_id, file_path, doc_type)`).
 *
 * This is the seam `DocumentUploadEndpoint` depends on: the endpoint only
 * shapes and validates wire input (§2 step 1, guarded by
 * `GuardedRouteRegistrar`/S5) and delegates exactly once to this port. The
 * real implementation — TRO-17/TRO-18 — will attach the uploaded file to the
 * patient via `DocumentService` with dedupe-by-content-hash (§2 step 2), then
 * run the VLM extraction and persist the derived facts per the §2 step
 * 3-5 pipeline (a logged PHI disclosure before the VLM call; parse-don't-
 * validate into the strict extraction DTOs; the two-write amendment's
 * persistence spine). None of that belongs to this interface — callers of
 * `attachAndExtract` see only the two-field result the endpoint forwards to
 * the wire.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Ingestion;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;

interface DocumentIngestion
{
    /**
     * Attach the source document to the patient and run extraction against
     * it, acting as the delegated physician throughout (never a service
     * account — S4/S6).
     *
     * @return array{document_id: string, extraction_status: string}
     */
    public function attachAndExtract(
        PhysicianContext $physician,
        string $patientUuid,
        string $filePath,
        string $docType,
    ): array;
}

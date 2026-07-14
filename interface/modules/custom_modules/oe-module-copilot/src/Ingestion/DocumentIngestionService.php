<?php

/**
 * Composes the full `attach_and_extract` flow (TRO-32; W2_ARCHITECTURE.md §2,
 * end to end).
 *
 * This is the real `DocumentIngestion` port implementation
 * `DocumentUploadEndpoint`/`Bootstrap` reach for once TRO-17 (attach) and
 * TRO-18 (VLM extract) are both available: resolve the caller-supplied
 * patient uuid to the trusted `pid` at the boundary (D7 — pid is the trusted
 * surrogate, uuid is never conflated with identity), read the uploaded file
 * from disk, attach it (dedupe-by-hash lives in `PatientDocumentAttacher`),
 * run VLM extraction (disclosure logged before the call, in
 * `VlmDocumentExtractor`), parse the model's raw text at the boundary
 * (`VlmExtractionParser` — parse, don't validate; a partial extraction fails
 * whole), and persist the parsed facts by shape: observation-shaped facts
 * (`lab_pdf`) through the native derived procedure chain
 * (`DerivedObservationWriter`); list-shaped facts (`intake_form`) as
 * module-owned reconciliation candidates (`IntakeCandidateWriter`).
 *
 * **Untrusted model documentId.** The VLM's own `documentId` claim inside its
 * JSON output is untrusted draft data like every other extracted field — it
 * is never trusted for provenance. After parsing, this class rebuilds the
 * extraction root DTO with the REAL attached document id (the value
 * `PatientDocumentAttacher::attach()` returned) before anything is persisted,
 * so what lands in the record always points back to the document OpenEMR
 * actually stored, never to whatever a model happened to echo.
 *
 * **Failure behavior (§2 "Failure behavior"), by stage:**
 *   - Refusals (bad doc_type, unreadable file, unknown patient uuid) throw
 *     `\DomainException` BEFORE any side effect — no attach, no disclosure,
 *     no extraction call.
 *   - A VLM transport fault (`LlmUnavailableException`) leaves the document
 *     ATTACHED and returns `extraction_status = 'extraction_failed'` —
 *     retryable, never a partial persist.
 *   - A schema violation (`ExtractionParseException`) or a persistence
 *     `\DomainException` (e.g. a lab panel with no persistable analyte —
 *     every value absent, D1) is treated the same way: the extraction
 *     failed to produce anything usefully groundable, so it is reported as
 *     `extraction_failed` with the document left attached, never as a
 *     partial write.
 *   - A `\RuntimeException` from a writer (a genuine transaction/storage
 *     failure, already rolled back by the writer itself) is NOT swallowed —
 *     it propagates, because that is a storage failure, not an extraction
 *     failure (§2's "storage failure -> generic error, nothing persisted").
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Ingestion;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Extraction\ExtractionParseException;
use OpenEMR\Modules\Copilot\Extraction\IntakeFormExtraction;
use OpenEMR\Modules\Copilot\Extraction\LabPdfExtraction;
use OpenEMR\Modules\Copilot\Extraction\VlmExtractionParser;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Persistence\DerivedObservationWriter;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidateWriter;

final class DocumentIngestionService implements DocumentIngestion
{
    /**
     * Closed set of accepted doc_type values (mirrors
     * `DocumentUploadEndpoint::ALLOWED_DOC_TYPES` — validated again here
     * because this port is the trust boundary a caller must not be able to
     * bypass by skipping the endpoint's own check).
     *
     * @var list<string>
     */
    private const ALLOWED_DOC_TYPES = ['lab_pdf', 'intake_form'];

    public function __construct(
        private readonly PatientDocumentAttacher $attacher,
        private readonly VlmDocumentExtractor $extractor,
    ) {
    }

    /**
     * @return array{document_id: string, extraction_status: string}
     */
    public function attachAndExtract(
        PhysicianContext $physician,
        string $patientUuid,
        string $filePath,
        string $docType,
    ): array {
        // Guards BEFORE any side effect (§2 "Failure behavior").
        if (!in_array($docType, self::ALLOWED_DOC_TYPES, true)) {
            throw new \DomainException(
                sprintf('"doc_type" must be one of: %s', implode(', ', self::ALLOWED_DOC_TYPES)),
            );
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            // Generic message — never echo the path back (R11).
            throw new \DomainException('Uploaded file could not be read');
        }

        $patientPid = $this->resolvePid($patientUuid);

        $bytes = file_get_contents($filePath);
        if ($bytes === false) {
            throw new \DomainException('Uploaded file could not be read');
        }

        $mediaType = self::mediaTypeFor($filePath);

        $attachment = $this->attacher->attach(
            $physician,
            $patientPid,
            basename($filePath),
            $mediaType,
            $bytes,
        );

        // Interim choice: this call has no live route-level turn span to
        // derive a child from (that arrives with the supervisor/dispatcher
        // wiring, W2_ARCHITECTURE §6), so a fresh root span is minted here
        // purely to carry a correlation id through the disclosure + trace.
        $trace = TraceContext::start('document-ingestion', new \DateTimeImmutable());

        try {
            $rawExtraction = $this->extractor->extract(
                $physician,
                $patientPid,
                (string) $attachment->documentId,
                $docType,
                $mediaType,
                base64_encode($bytes),
                $trace,
                new \DateTimeImmutable(),
            );
        } catch (LlmUnavailableException) {
            return [
                'document_id' => (string) $attachment->documentId,
                'extraction_status' => 'extraction_failed',
            ];
        }

        try {
            if ($docType === 'lab_pdf') {
                return $this->persistLabPdf($physician, $patientPid, $attachment->documentId, $rawExtraction);
            }

            return $this->persistIntakeForm($physician, $patientPid, $attachment->documentId, $rawExtraction);
        } catch (ExtractionParseException | \DomainException) {
            // Schema violation OR "nothing persistable" — both mean the
            // extraction failed to produce anything usefully groundable.
            // The document stays attached (already inserted above);
            // extraction is retryable (§2). A genuine \RuntimeException
            // (writer transaction failure) is deliberately NOT caught here
            // — it propagates as a storage failure, not an extraction one.
            return [
                'document_id' => (string) $attachment->documentId,
                'extraction_status' => 'extraction_failed',
            ];
        }
    }

    /**
     * @return array{document_id: string, extraction_status: string}
     */
    private function persistLabPdf(PhysicianContext $physician, int $patientPid, int $documentId, string $rawExtraction): array
    {
        $parsed = VlmExtractionParser::parseLabPdf($rawExtraction);

        // Rebuild with the REAL attached document id — the model's own
        // documentId claim inside $rawExtraction is untrusted and discarded.
        $stamped = new LabPdfExtraction((string) $documentId, $parsed->analytes);

        (new DerivedObservationWriter())->persist($physician, $patientPid, $stamped);

        return ['document_id' => (string) $documentId, 'extraction_status' => 'extracted'];
    }

    /**
     * @return array{document_id: string, extraction_status: string}
     */
    private function persistIntakeForm(PhysicianContext $physician, int $patientPid, int $documentId, string $rawExtraction): array
    {
        $parsed = VlmExtractionParser::parseIntakeForm($rawExtraction);

        // Rebuild with the REAL attached document id — see persistLabPdf().
        $stamped = new IntakeFormExtraction(
            (string) $documentId,
            $parsed->chiefConcern,
            $parsed->currentMedications,
            $parsed->allergies,
            $parsed->familyHistory,
            $parsed->demographics,
        );

        (new IntakeCandidateWriter())->persist($physician, $patientPid, $stamped);

        return ['document_id' => (string) $documentId, 'extraction_status' => 'extracted'];
    }

    /**
     * Resolves the caller-supplied patient uuid to the trusted `pid` (D7).
     *
     * Reuses the same `UuidRegistry::uuidToBytes()`-keyed lookup against
     * `patient_data.uuid` already established in
     * `Bootstrap::buildChartSnapshotProvider()` (the module's one existing
     * uuid->pid resolver) rather than duplicating the equivalent
     * `UNHEX(REPLACE(?, '-', ''))` SQL inline — same DB-level comparison,
     * one fewer place a uuid-format assumption could drift.
     */
    private function resolvePid(string $patientUuid): int
    {
        $records = QueryUtils::fetchRecords(
            'SELECT `pid` FROM `patient_data` WHERE `uuid` = ?',
            [UuidRegistry::uuidToBytes($patientUuid)],
        );
        $pid = $records[0]['pid'] ?? null;
        if (!is_int($pid) && !(is_string($pid) && ctype_digit($pid))) {
            throw new \DomainException('Unknown patient uuid — no pid mapping in the uuid registry.');
        }

        return (int) $pid;
    }

    /**
     * Media type from the uploaded file's extension (lowercased); anything
     * other than png/jpg/jpeg — including pdf and no-extension inputs —
     * defaults to application/pdf. `DocumentUploadEndpoint` already
     * allowlists extensions to {pdf, png, jpg, jpeg} before this port ever
     * runs, so the default only matters for direct-port callers (this
     * class's own tests).
     */
    private static function mediaTypeFor(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/pdf',
        };
    }
}

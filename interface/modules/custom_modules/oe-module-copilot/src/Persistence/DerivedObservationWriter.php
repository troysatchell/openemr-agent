<?php

/**
 * Persists observation-shaped extracted facts as a derived native procedure
 * chain (W2_ARCHITECTURE.md §2 step 5, §10; PS-4).
 *
 * This is write (b) of the two-write amendment (CLAUDE.md, founder-approved
 * 2026-07-13): persisting facts extracted from an already-attached source
 * document as observations provenance-linked back to that document. It is
 * NOT a FHIR write — FHIR Observation is GET-only. The mechanism is the
 * native chain procedure_order → procedure_order_code → procedure_report →
 * procedure_result, the same chain core uses to attach a document result to
 * a patient (see the core precedent at controllers/C_Document.class.php:1399,
 * a bare `procedure_result` insert stamping `document_id`; SP-1 locked this
 * chain as the mechanism).
 *
 * The stamp set is what prevents self-laundering (§2): every derived result
 * carries `result_status = 'preliminary'` (visibly provisional, never
 * confused with a lab-verified final result), `document_id` pointing back to
 * the source document (the native derivedFrom), and `procedure_report.source`
 * set to the delegated physician's user id — never a service account. A
 * module-owned link table (ExtractionLineageSchema) carries the
 * extraction-specific detail those core columns have no room for: extractor
 * version, the schema field path the value was read from, source page, and
 * per-field confidence — no core schema edits.
 *
 * Only analytes with a present value persist (D1: absent is absent, never
 * invented); a unitless value is impossible here because LabAnalyteExtraction
 * itself refuses a present value with an absent unit. A panel with nothing
 * persistable refuses before any insert — no partial chain is ever left
 * behind. All inserts run in one transaction: any failure rolls back the
 * whole chain.
 *
 * Supersession, deduplication against a prior extraction of the same
 * document, and re-extraction versioning are out of scope here (tracked
 * separately as TRO-22) — this writer only ever INSERTS new chains; it never
 * updates or hides an existing record.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Persistence;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Extraction\LabAnalyteExtraction;
use OpenEMR\Modules\Copilot\Extraction\LabPdfExtraction;

final class DerivedObservationWriter
{
    /**
     * Identifies which extraction pipeline version produced a lineage row.
     * Bump this when the VLM extraction prompt/schema changes materially —
     * it is what lets a future audit tell which extractor produced a given
     * derived observation.
     */
    public const EXTRACTOR_VERSION = 'vlm-extractor/1.0';

    /**
     * Fallback field path recorded in lineage when an analyte's value
     * citation carries no field id of its own. In practice every present
     * ExtractedField carries a non-null citation (D1), but the citation's
     * fieldOrChunkId is independently nullable (SourceRef), so this fallback
     * keeps lineage rows always resolvable to *some* schema location.
     */
    private const FALLBACK_FIELD_PATH = 'analytes[].value';

    /**
     * @throws \DomainException if the patient pid or source document id is invalid, or nothing in the extraction is persistable — always thrown before any insert
     * @throws \RuntimeException if any insert in the chain fails — the whole chain is rolled back before rethrowing
     */
    public function persist(PhysicianContext $physician, int $patientPid, LabPdfExtraction $extraction): PersistedDerivedObservations
    {
        if ($patientPid <= 0) {
            throw new \DomainException('DerivedObservationWriter requires a positive patient pid');
        }

        if (!ctype_digit($extraction->documentId)) {
            throw new \DomainException(
                'DerivedObservationWriter requires a numeric source document id'
                . ' — it is bound as the procedure_result.document_id foreign key (W2_ARCHITECTURE §10)'
            );
        }
        $documentId = (int) $extraction->documentId;

        /** @var list<LabAnalyteExtraction> $persistableAnalytes */
        $persistableAnalytes = array_values(array_filter(
            $extraction->analytes,
            static fn (LabAnalyteExtraction $analyte): bool => $analyte->value->isPresent,
        ));

        if ($persistableAnalytes === []) {
            throw new \DomainException(
                'DerivedObservationWriter has nothing persistable'
                . ' — every analyte has an absent value (D1: absent is absent, never invented)'
            );
        }

        ExtractionLineageSchema::ensureInstalled();

        try {
            return QueryUtils::inTransaction(
                fn (): PersistedDerivedObservations => $this->insertChain(
                    $physician,
                    $patientPid,
                    $documentId,
                    $persistableAnalytes,
                ),
            );
        } catch (\Throwable $e) {
            // inTransaction() has already rolled back; wrap generically (R11).
            throw new \RuntimeException('derived-observation persistence failed', 0, $e);
        }
    }

    /**
     * @param non-empty-list<LabAnalyteExtraction> $persistableAnalytes
     */
    private function insertChain(
        PhysicianContext $physician,
        int $patientPid,
        int $documentId,
        array $persistableAnalytes,
    ): PersistedDerivedObservations {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $collectedAt = $this->firstAnalyteCollectionTimestamp($persistableAnalytes) ?? $now;

        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO procedure_order'
                . ' (patient_id, provider_id, activity, date_ordered, date_collected, procedure_order_type, lab_id, order_status)'
                . ' VALUES (?, ?, 1, ?, ?, ?, 0, ?)',
            [$patientPid, $physician->userId, $now, $collectedAt, 'laboratory', 'complete'],
        );
        UuidRegistry::createMissingUuidForRow('procedure_order', 'procedure_order_id', $orderId);

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO procedure_order_code'
                . ' (procedure_order_id, procedure_order_seq, procedure_code, procedure_name, procedure_order_title, procedure_type, diagnoses)'
                . ' VALUES (?, 1, ?, ?, ?, ?, ?)',
            [$orderId, 'COPILOT-EXTRACT', 'Co-Pilot document extraction', 'laboratory', 'laboratory', ''],
        );

        $reportId = QueryUtils::sqlInsert(
            'INSERT INTO procedure_report'
                . ' (procedure_order_id, procedure_order_seq, date_report, date_collected, source, report_status, review_status)'
                . ' VALUES (?, 1, ?, ?, ?, ?, ?)',
            [$orderId, $now, $collectedAt, $physician->userId, 'final', 'received'],
        );
        UuidRegistry::createMissingUuidForRow('procedure_report', 'procedure_report_id', $reportId);

        $resultIds = [];
        foreach ($persistableAnalytes as $analyte) {
            $resultIds[] = $this->insertResultAndLineage($reportId, $documentId, $analyte, $now);
        }

        return new PersistedDerivedObservations($orderId, $reportId, $resultIds);
    }

    private function insertResultAndLineage(
        int $reportId,
        int $documentId,
        LabAnalyteExtraction $analyte,
        string $now,
    ): int {
        $resultDate = $analyte->collectionDate?->format('Y-m-d H:i:s') ?? $now;
        $range = $analyte->referenceRange->isPresent ? $analyte->referenceRange->value : '';
        $abnormal = $analyte->abnormalFlag->isPresent ? $analyte->abnormalFlag->value : '';

        $resultId = QueryUtils::sqlInsert(
            'INSERT INTO procedure_result'
                . ' (procedure_report_id, result_code, result_text, date, units, result, `range`, abnormal, comments, document_id, result_status)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $reportId,
                '',
                $analyte->testName->value,
                $resultDate,
                $analyte->unit->value,
                $analyte->value->value,
                $range,
                $abnormal,
                'co-pilot derived (TRO-20)',
                $documentId,
                'preliminary',
            ],
        );
        UuidRegistry::createMissingUuidForRow('procedure_result', 'procedure_result_id', $resultId);

        $citation = $analyte->value->citation;
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . ExtractionLineageSchema::LINEAGE_TABLE
                . ' (procedure_result_id, document_id, extractor_version, field_path, page, confidence, bbox)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $resultId,
                $documentId,
                self::EXTRACTOR_VERSION,
                $citation->fieldOrChunkId ?? self::FALLBACK_FIELD_PATH,
                $citation?->pageOrSection,
                $analyte->value->confidence?->value,
                $analyte->value->bbox?->toCsv(),
            ],
        );

        return $resultId;
    }

    /**
     * The panel-level collection timestamp: the first present analyte
     * collection date, used to stamp date_collected on both procedure_order
     * and procedure_report. Returns null (never "now") when no analyte in
     * the persistable set carries a date, so the caller's own "else NOW()"
     * fallback is the single source of the current-time default.
     *
     * @param non-empty-list<LabAnalyteExtraction> $persistableAnalytes
     */
    private function firstAnalyteCollectionTimestamp(array $persistableAnalytes): ?string
    {
        foreach ($persistableAnalytes as $analyte) {
            if ($analyte->collectionDate !== null) {
                return $analyte->collectionDate->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}

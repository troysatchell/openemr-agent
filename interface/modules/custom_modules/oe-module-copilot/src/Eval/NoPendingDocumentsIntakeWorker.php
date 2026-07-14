<?php

/**
 * A real (non-stub) {@see IntakeExtractorWorker} scoped honestly to what
 * TRO-35's golden set actually exercises (TRO-35; W2_ARCHITECTURE.md §6;
 * eval/goldenset/README.md "kind: turn" — "the pending-doc turn path is
 * TRO-32's frozen suite, not this set").
 *
 * Every case in the committed golden set declares
 * `has_pending_unextracted_document: false`, so
 * {@see \OpenEMR\Modules\Copilot\Orchestration\Supervisor::plan()} never plans
 * an `IntakeExtractor` step for any of them — this worker's `run()` is
 * therefore never actually invoked by the gate. It is still a genuine
 * implementation over the real database, not a test double (worker-level
 * stubs never appear in the gate, §6): it runs a real query for documents
 * attached to the patient under the module's document category that carry
 * neither an extraction-lineage row nor an intake-candidate row — the
 * honest definition of "pending, unextracted." Finding none, it reports a
 * genuine zero outcome. Finding one would mean processing it, which is a
 * turn-path feature outside this ticket's scope (a TRO-32 residual) — rather
 * than silently fabricate a "processed" result, this throws loud so the gap
 * cannot hide behind a faked success.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Orchestration\IntakeExtractionOutcome;
use OpenEMR\Modules\Copilot\Orchestration\IntakeExtractorWorker;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidatesSchema;

final class NoPendingDocumentsIntakeWorker implements IntakeExtractorWorker
{
    /** Mirrors {@see \OpenEMR\Modules\Copilot\Ingestion\PatientDocumentAttacher::CATEGORY_NAME}. */
    private const CATEGORY_NAME = 'Clinical Co-Pilot';

    private const CATEGORY_ROOT_ID = 1;

    public function run(PhysicianContext $physician, int $patientPid, TraceContext $workerSpan): IntakeExtractionOutcome
    {
        $pending = $this->countPendingDocuments($patientPid);

        if ($pending > 0) {
            throw new \RuntimeException(
                'NoPendingDocumentsIntakeWorker found a pending unextracted document — '
                    . 'processing pending documents on the turn path is out of scope for this eval gate',
            );
        }

        return new IntakeExtractionOutcome(0, 0, 0, 0);
    }

    private function countPendingDocuments(int $patientPid): int
    {
        $categoryId = QueryUtils::fetchSingleValue(
            'SELECT id FROM categories WHERE parent = ? AND name = ?',
            'id',
            [self::CATEGORY_ROOT_ID, self::CATEGORY_NAME],
        );
        if (!is_numeric($categoryId)) {
            // The co-pilot category has never been created -> no co-pilot documents exist at all.
            return 0;
        }

        $count = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM documents d'
                . ' JOIN categories_to_documents ctd ON ctd.document_id = d.id'
                . ' WHERE d.foreign_id = ? AND d.deleted = 0 AND ctd.category_id = ?'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' el WHERE el.document_id = d.id)'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE . ' ic WHERE ic.document_id = d.id)',
            'c',
            [$patientPid, (int) $categoryId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}

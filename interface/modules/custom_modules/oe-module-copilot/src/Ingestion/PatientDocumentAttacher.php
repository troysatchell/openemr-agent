<?php

/**
 * Attaches an uploaded source document to its patient via OpenEMR's native
 * document store (W2_ARCHITECTURE.md §2 step 2, §10 "Source document row").
 *
 * This is **write (a)** of the two-write amendment (founder-approved
 * 2026-07-13; CLAUDE.md "Bright lines"): the module may attach an uploaded
 * source document to its patient, acting as the delegated physician through
 * a guarded route — never a service account (S4/S6). The only permitted
 * caller is the §2 document-upload route (TRO-16); this class does no
 * authorization itself and trusts the `PhysicianContext` its caller already
 * established.
 *
 * Storage goes through the same native path core itself uses
 * (`\Document::createDocument()` — the mechanism `DocumentService` builds
 * on, see `src/Services/DocumentService.php::insertAtPath()`), so the file
 * lands in the same store the rest of the EMR reads and the row carries the
 * same `hash` convention (`sha3-512` of the raw bytes) core stamps on every
 * document. `createDocument()` also performs the category link
 * (`categories_to_documents`) for us.
 *
 * **Dedupe by content hash, per patient (D8 discipline applied to
 * documents).** Before any insert, the same `sha3-512` hash is computed
 * independently and looked up against `documents` scoped to
 * `foreign_id = $patientPid` (honoring the `deleted` filter, D10):
 * re-attaching identical bytes for the same patient returns the existing
 * document id and inserts nothing. Dedupe is deliberately per-patient, not
 * global — two patients may legitimately hold copies of the same form, and
 * collapsing across patients would cross-link unrelated records.
 *
 * Every source document lives under a dedicated 'Clinical Co-Pilot'
 * category, created on first use (nested-set insert via core's own
 * `\CategoryTree::add_node()`, the same mechanism the document-category
 * admin UI uses — `controllers/C_DocumentCategory.class.php`).
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
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;

final class PatientDocumentAttacher
{
    /** Dedicated co-pilot document category (created under the root category if absent). */
    private const CATEGORY_NAME = 'Clinical Co-Pilot';

    /**
     * Root category id (`categories.id = 1`, name 'Categories') — the same
     * fixed root the core document-category tree uses
     * (`controllers/C_DocumentCategory.class.php`: `new CategoryTree(1)`).
     */
    private const CATEGORY_ROOT_ID = 1;

    /** Core's own document content-hash algorithm (`Document::createDocument()`); reused so our dedupe key matches the stamped row. */
    private const HASH_ALGORITHM = 'sha3-512';

    /**
     * Attach `$bytes` as a native patient document for `$patientPid`, acting
     * as the delegated `$physician` throughout. Refuses blank input before
     * any side effect; dedupes by content hash scoped to this patient.
     */
    public function attach(
        PhysicianContext $physician,
        int $patientPid,
        string $fileName,
        string $mimeType,
        string $bytes,
    ): AttachmentResult {
        if ($patientPid <= 0) {
            throw new \DomainException('Patient pid must be a positive integer to attach a document');
        }
        if (trim($fileName) === '') {
            throw new \DomainException('Document file name must be non-blank');
        }
        if (trim($mimeType) === '') {
            throw new \DomainException('Document mime type must be non-blank');
        }
        if ($bytes === '') {
            throw new \DomainException('Document bytes must be non-empty');
        }

        $hash = hash(self::HASH_ALGORITHM, $bytes);

        $existingId = $this->findExistingDocumentId($patientPid, $hash);
        if ($existingId !== null) {
            return new AttachmentResult($existingId, true);
        }

        $categoryId = $this->resolveCategoryId();

        $document = new \Document();
        // createDocument() takes $data by reference; $bytes is a local
        // variable here so passing it directly is safe.
        $failureMessage = $document->createDocument(
            // Legacy boundary: core's createDocument() is stringly-typed for
            // patient_id; $patientPid is a validated positive int here, so
            // this widening conversion cannot launder bad data.
            (string) $patientPid,
            $categoryId,
            $fileName,
            $mimeType,
            $bytes,
            owner: $physician->userId,
        );

        if ($failureMessage !== '') {
            // Storage failure -> generic error, nothing persisted (§2
            // "Failure behavior"). The core failure string is internal
            // detail, not surfaced to callers.
            throw new \RuntimeException('Failed to attach the document to the patient record');
        }

        $documentId = $document->get_id();
        if (!is_numeric($documentId) || (int) $documentId <= 0) {
            throw new \RuntimeException('Document store did not return a valid document id after attach');
        }

        return new AttachmentResult((int) $documentId, false);
    }

    /**
     * Independent dedupe lookup: same hash algorithm core stamps on the row,
     * scoped to this patient and honoring the deleted filter (D10).
     */
    private function findExistingDocumentId(int $patientPid, string $hash): ?int
    {
        $existing = QueryUtils::fetchSingleValue(
            'SELECT id FROM documents WHERE foreign_id = ? AND hash = ? AND deleted = 0',
            'id',
            [$patientPid, $hash],
        );

        return is_numeric($existing) ? (int) $existing : null;
    }

    /**
     * Finds the 'Clinical Co-Pilot' category id under the root category,
     * creating it (nested-set insert) on first use.
     */
    private function resolveCategoryId(): int
    {
        $existing = QueryUtils::fetchSingleValue(
            'SELECT id FROM categories WHERE parent = ? AND name = ?',
            'id',
            [self::CATEGORY_ROOT_ID, self::CATEGORY_NAME],
        );
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $tree = new \CategoryTree(self::CATEGORY_ROOT_ID);
        $newId = $tree->add_node(self::CATEGORY_ROOT_ID, self::CATEGORY_NAME);
        if (!is_numeric($newId)) {
            throw new \RuntimeException('Failed to create the Clinical Co-Pilot document category');
        }

        return (int) $newId;
    }
}

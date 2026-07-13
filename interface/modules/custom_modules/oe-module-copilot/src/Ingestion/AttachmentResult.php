<?php

/**
 * Result of attaching a source document to a patient (W2_ARCHITECTURE.md §2
 * step 2, TRO-17).
 *
 * `documentId` is the native OpenEMR `documents.id` the caller can use to
 * reference the stored file (e.g. as the `document_id` stamp on a later
 * derived observation, §2 step 5). `deduplicated` distinguishes "this is the
 * document that already existed for this patient" from "a new row was
 * inserted" — both are success outcomes, but callers that trigger downstream
 * work (e.g. extraction) need to know whether a *new* document just arrived.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Ingestion;

final readonly class AttachmentResult
{
    public function __construct(
        public int $documentId,
        public bool $deduplicated,
    ) {
        if ($documentId <= 0) {
            throw new \DomainException(
                'AttachmentResult documentId must be a positive integer: a document that was not '
                . 'actually persisted (or found) has no valid id to point to'
            );
        }
    }
}

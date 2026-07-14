<?php

/**
 * The real, documents-table-backed {@see DerivedObservationGrounding} adapter
 * (TRO-35/TRO-23; W2_ARCHITECTURE.md §4; docs/W2_PRD_SEEDS.md PS-6).
 *
 * A derived observation's `sourceId` is the `procedure_result_id` the
 * scoped write amendment's write (b) stamped ({@see
 * \OpenEMR\Modules\Copilot\Persistence\DerivedObservationWriter}); every such
 * row carries `document_id` pointing back to the source document write (a)
 * attached. This adapter answers PS-6's no-grounding-by-proxy question for
 * real: does that source document row still exist (honoring the `deleted`
 * filter, D10)? A malformed id, a result row that no longer exists, or a
 * gone/deleted document all answer `false` — fail closed, never
 * grounded-by-proxy, and never a default-to-true on an unexpected shape.
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
use OpenEMR\Modules\Copilot\Verification\DerivedObservationGrounding;

final class DocumentBackedDerivedObservationGrounding implements DerivedObservationGrounding
{
    public function sourceDocumentExists(string $derivedObservationId): bool
    {
        if (!ctype_digit($derivedObservationId)) {
            return false;
        }

        $documentId = QueryUtils::fetchSingleValue(
            'SELECT document_id FROM procedure_result WHERE procedure_result_id = ?',
            'document_id',
            [(int) $derivedObservationId],
        );
        if (!is_numeric($documentId)) {
            return false;
        }

        $exists = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM documents WHERE id = ? AND deleted = 0',
            'c',
            [(int) $documentId],
        );

        return is_numeric($exists) && (int) $exists > 0;
    }
}

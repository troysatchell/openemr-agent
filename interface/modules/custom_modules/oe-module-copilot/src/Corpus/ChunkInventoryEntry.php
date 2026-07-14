<?php

/**
 * One entry in the corpus manifest's "Chunk inventory" table (corpus README §3).
 *
 * The inventory is the machine-readable source of truth for what chunk IDs
 * exist: if a chunk ID appears in a golden retrieval case, it must appear
 * here. `chunkId` is the stable, append-only citation target
 * (`field_or_chunk_id` in the `SourceRef` contract); `sourceId` names the
 * parent document; `section` is a human-readable label for the chunk's
 * position, checked against the chunk marker's own heading by the
 * integrity suite. All three fields are mandatory.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Corpus;

final readonly class ChunkInventoryEntry
{
    public function __construct(
        public string $chunkId,
        public string $sourceId,
        public string $section,
    ) {
        if (trim($chunkId) === '') {
            throw new \DomainException('ChunkInventoryEntry chunkId must be non-blank.');
        }
        if (trim($sourceId) === '') {
            throw new \DomainException('ChunkInventoryEntry sourceId must be non-blank.');
        }
        if (trim($section) === '') {
            throw new \DomainException('ChunkInventoryEntry section must be non-blank.');
        }
    }
}

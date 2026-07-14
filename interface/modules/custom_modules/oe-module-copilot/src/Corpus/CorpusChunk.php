<?php

/**
 * One parsed chunk from a corpus document (corpus README §2; W2_ARCHITECTURE §5).
 *
 * Produced by `CorpusChunker` from a `<!-- chunk: ... -->` marker and the
 * text that follows it up to the next marker or EOF. `chunkId` is the
 * stable, append-only citation target; `sourceId` is the parent document
 * and MUST equal the filename stem it was parsed from (checked by
 * `CorpusIntegrityTest`, not enforced here); `derivedFrom` is the national
 * guideline reference this chunk operationalizes; `heading` is the first
 * markdown heading following the marker (fills a retrieval citation's
 * `page_or_section`); `body` is the chunk's prose (fills `quote_or_value`).
 *
 * Only `chunkId` and `sourceId` are validated non-blank here — a chunk with
 * neither cannot be provenance-linked to anything and is a parser bug, not
 * data to carry forward. `derivedFrom`, `heading`, and `body` may be blank
 * as parsed (e.g. a malformed marker in a non-corpus fixture); the real
 * committed corpus is additionally held to non-blank `derivedFrom` and
 * `body` by `CorpusIntegrityTest`, a checkable data contract rather than a
 * DTO invariant.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Corpus;

final readonly class CorpusChunk
{
    public function __construct(
        public string $chunkId,
        public string $sourceId,
        public string $derivedFrom,
        public string $heading,
        public string $body,
    ) {
        if (trim($chunkId) === '') {
            throw new \DomainException('CorpusChunk chunkId must be non-blank.');
        }
        if (trim($sourceId) === '') {
            throw new \DomainException('CorpusChunk sourceId must be non-blank.');
        }
    }
}

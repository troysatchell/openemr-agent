<?php

/**
 * Provenance pointer for a synthesized chart entry (T9).
 *
 * Every entry in a ChartSnapshot must carry at least one SourceRef back to
 * the concrete record it came from — groundwork for citation-grounded output
 * (R6/R10). Empty or whitespace-only components are rejected: provenance
 * that cannot be resolved is no provenance at all.
 *
 * Week 2 five-field shape (W2_ARCHITECTURE.md §4): SourceRef grows from
 * `{source_type, source_id}` to the canonical citation-contract shape
 * `{source_type, source_id, page_or_section, field_or_chunk_id,
 * quote_or_value}`, used identically by chart facts, document extractions,
 * guideline evidence, and detector findings:
 *   - Structured chart facts: `pageOrSection` null, the rest as in Week 1.
 *   - Document extractions: `sourceId` = OpenEMR document id, `pageOrSection`
 *     = page, `fieldOrChunkId` = schema field path, `quoteOrValue` = the
 *     extracted value as read.
 *   - Guideline evidence: `sourceId` = corpus document, `fieldOrChunkId` =
 *     chunk id, `quoteOrValue` = the snippet.
 *   - Detector findings: `sourceType` = "detector", `sourceId` = the finding
 *     type, `quoteOrValue` = the flagged value — the Week 1 shape, unchanged.
 *
 * Migration note (§13): the three new fields are optional and default to
 * null, so every Week 1 two-argument call site keeps constructing unchanged
 * — that IS the migration, no call site edits required. A non-null new
 * field must still be non-blank; blank provenance components are no
 * provenance at all.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Synthesis;

final readonly class SourceRef
{
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public ?string $pageOrSection = null,
        public ?string $fieldOrChunkId = null,
        public ?string $quoteOrValue = null,
    ) {
        if (trim($sourceType) === '') {
            throw new \DomainException('SourceRef sourceType must be non-empty (provenance is mandatory)');
        }
        if (trim($sourceId) === '') {
            throw new \DomainException('SourceRef sourceId must be non-empty (provenance is mandatory)');
        }
        if ($pageOrSection !== null && trim($pageOrSection) === '') {
            throw new \DomainException('SourceRef pageOrSection, when present, must be non-blank (blank provenance is no provenance)');
        }
        if ($fieldOrChunkId !== null && trim($fieldOrChunkId) === '') {
            throw new \DomainException('SourceRef fieldOrChunkId, when present, must be non-blank (blank provenance is no provenance)');
        }
        if ($quoteOrValue !== null && trim($quoteOrValue) === '') {
            throw new \DomainException('SourceRef quoteOrValue, when present, must be non-blank (blank provenance is no provenance)');
        }
    }
}

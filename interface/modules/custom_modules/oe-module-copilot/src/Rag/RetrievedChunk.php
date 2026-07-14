<?php

/**
 * A single reranked, cited guideline snippet returned by HybridRetriever
 * (W2_ARCHITECTURE.md §5 "Hybrid RAG + rerank").
 *
 * Carries exactly the citation metadata the corpus README §2 chunking
 * convention promises: chunk id, parent document (`source_id`), and section
 * heading — plus the reranked relevance `score` that decided the chunk's
 * place in the top-k. `toSourceRef()` mints the Week 2 five-field `SourceRef`
 * the same way every other source class does (chart facts, extractions,
 * detector findings): `source_type = guideline`, `source_id` = the chunk's
 * parent document, `page_or_section` = the chunk's heading, `field_or_chunk_id`
 * = the chunk id, `quote_or_value` = a bounded snippet of the chunk body —
 * never the full body, consistent with minimum-necessary evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final readonly class RetrievedChunk
{
    /**
     * The `quote_or_value` on the minted SourceRef is a snippet of the chunk
     * body, not the whole thing — bounded here at a fixed length so a long
     * chunk never turns "cited evidence" into "the entire source document."
     */
    private const SNIPPET_LENGTH = 300;

    public function __construct(
        public string $chunkId,
        public string $sourceId,
        public string $heading,
        public string $body,
        public float $score,
    ) {
        if (trim($this->chunkId) === '') {
            throw new \DomainException('RetrievedChunk chunkId must be non-blank');
        }

        if (trim($this->sourceId) === '') {
            throw new \DomainException('RetrievedChunk sourceId must be non-blank');
        }
    }

    public function toSourceRef(): SourceRef
    {
        return new SourceRef(
            'guideline',
            $this->sourceId,
            $this->heading,
            $this->chunkId,
            mb_substr($this->body, 0, self::SNIPPET_LENGTH),
        );
    }
}

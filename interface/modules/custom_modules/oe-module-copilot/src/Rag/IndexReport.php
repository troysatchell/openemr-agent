<?php

/**
 * Outcome of one `CorpusIndexer::rebuild()` run (W2_ARCHITECTURE.md §5, §11;
 * PS-12).
 *
 * This report IS the PS-12 stale-index alarm: when the embed vendor boundary
 * is unreachable at build time, the keyword leg still indexes in full
 * (`chunksIndexed` stays at the manifest's true chunk count) while the dense
 * leg is skipped entirely (`embeddingsStored === 0`, `embeddingsSkipped ===
 * true`) — an operator-facing signal carried in the return value, never a
 * thrown, user-facing failure. The invariant below is what keeps that
 * signal trustworthy: a report can never claim embeddings were skipped while
 * also claiming some were stored.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final readonly class IndexReport
{
    public function __construct(
        public int $chunksIndexed,
        public int $embeddingsStored,
        public bool $embeddingsSkipped,
    ) {
        if ($this->chunksIndexed < 0) {
            throw new \DomainException('IndexReport chunksIndexed must be >= 0');
        }
        if ($this->embeddingsStored < 0) {
            throw new \DomainException('IndexReport embeddingsStored must be >= 0');
        }
        if ($this->embeddingsSkipped && $this->embeddingsStored !== 0) {
            throw new \DomainException('IndexReport embeddingsSkipped implies embeddingsStored === 0');
        }
    }
}

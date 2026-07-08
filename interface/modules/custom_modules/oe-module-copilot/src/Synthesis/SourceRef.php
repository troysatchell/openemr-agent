<?php

/**
 * Provenance pointer for a synthesized chart entry (T9).
 *
 * Every entry in a ChartSnapshot must carry at least one SourceRef back to
 * the concrete record it came from — groundwork for citation-grounded output
 * (R6/R10). Empty or whitespace-only components are rejected: provenance
 * that cannot be resolved is no provenance at all.
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
    ) {
        if (trim($sourceType) === '') {
            throw new \DomainException('SourceRef sourceType must be non-empty (provenance is mandatory)');
        }
        if (trim($sourceId) === '') {
            throw new \DomainException('SourceRef sourceId must be non-empty (provenance is mandatory)');
        }
    }
}

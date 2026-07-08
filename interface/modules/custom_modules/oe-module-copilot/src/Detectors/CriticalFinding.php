<?php

/**
 * A single must-not-miss finding from a deterministic detector (T10; R13).
 *
 * Every finding carries a human-readable summary and mandatory SourceRef
 * provenance back to the concrete chart records it was derived from —
 * groundwork for citation-grounded output (R6/R10). A finding without a
 * summary or without provenance cannot exist.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Detectors;

use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final readonly class CriticalFinding
{
    /**
     * @param list<SourceRef> $sources
     */
    public function __construct(
        public CriticalFindingType $type,
        public string $summary,
        public array $sources,
    ) {
        if (trim($summary) === '') {
            throw new \DomainException('CriticalFinding summary must be non-blank');
        }
        if ($sources === []) {
            throw new \DomainException(
                'CriticalFinding requires at least one SourceRef (provenance is mandatory — R6/R10)'
            );
        }
    }
}

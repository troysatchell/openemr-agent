<?php

/**
 * An input a deterministic detector could NOT evaluate (T10; R13; AUDIT D10).
 *
 * Silence is the failure mode: a tracked analyte with a missing unit or a
 * dangerous med pair blocked only by unknown currency must be surfaced to
 * the physician, never silently skipped. Carries the reason and mandatory
 * SourceRef provenance (R6/R10).
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

final readonly class UnevaluableItem
{
    /**
     * @param list<SourceRef> $sources
     */
    public function __construct(
        public string $reason,
        public array $sources,
    ) {
        if (trim($reason) === '') {
            throw new \DomainException('UnevaluableItem reason must be non-blank');
        }
        if ($sources === []) {
            throw new \DomainException(
                'UnevaluableItem requires at least one SourceRef (provenance is mandatory — R6/R10)'
            );
        }
    }
}

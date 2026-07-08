<?php

/**
 * Lab result entry in a one-pass chart synthesis (T9; AUDIT D9).
 *
 * Value, unit, and resulted-at are nullable by design (AUDIT D0/D1/D6:
 * empty strings, zero dates, and free-text values are endemic) — a lab row
 * that cannot be fully parsed is still carried with its provenance so the
 * panic-lab detector can surface it as unevaluable rather than skip it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Synthesis;

final readonly class LabResultEntry
{
    /**
     * @param list<SourceRef> $sources
     */
    public function __construct(
        public string $analyte,
        public ?float $value,
        public ?string $unit,
        public ?\DateTimeImmutable $resultedAt,
        public array $sources,
    ) {
        if ($sources === []) {
            throw new \DomainException('LabResultEntry requires at least one SourceRef (provenance is mandatory)');
        }
    }
}

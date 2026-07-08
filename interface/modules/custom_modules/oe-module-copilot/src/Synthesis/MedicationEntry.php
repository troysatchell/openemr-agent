<?php

/**
 * Medication entry in a one-pass chart synthesis (T9; AUDIT D9/D10).
 *
 * Carries a three-state CurrencyStatus (D10: soft-deleted rows read as
 * current unless activity/enddate are applied; Unknown is surfaced, never
 * dropped) and mandatory SourceRef provenance — an entry without a source
 * record cannot exist.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Synthesis;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;

final readonly class MedicationEntry
{
    /**
     * @param list<SourceRef> $sources
     */
    public function __construct(
        public string $name,
        public CurrencyStatus $status,
        public array $sources,
    ) {
        if ($sources === []) {
            throw new \DomainException('MedicationEntry requires at least one SourceRef (provenance is mandatory)');
        }
    }
}

<?php

/**
 * Allergy entry in a one-pass chart synthesis (T9; AUDIT D9/D10).
 *
 * Carries a three-state CurrencyStatus (D10) and mandatory SourceRef
 * provenance. Allergies are synthesized alongside medications and labs in
 * one pass because drug–allergy conflicts live *between* sources (D9) —
 * an isolated allergy summary cannot see them.
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

final readonly class AllergyEntry
{
    /**
     * @param list<SourceRef> $sources
     */
    public function __construct(
        public string $substance,
        public CurrencyStatus $status,
        public array $sources,
    ) {
        if ($sources === []) {
            throw new \DomainException('AllergyEntry requires at least one SourceRef (provenance is mandatory)');
        }
    }
}

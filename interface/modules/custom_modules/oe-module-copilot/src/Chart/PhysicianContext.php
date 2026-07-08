<?php

/**
 * Typed physician principal for delegated chart reads (T5).
 *
 * Every co-pilot read of patient data is delegation: it executes as a named,
 * authenticated physician (ARCHITECTURE.md §4, Decision 3). This object is
 * impossible to construct anonymously, so no service-account or
 * ambient-authority read path can exist (AUDIT S4: the native CLI/batch path
 * sets $ignoreAuth = true; S6: background_services is executable config —
 * both are bright lines this type exists to enforce).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

final readonly class PhysicianContext
{
    public function __construct(
        public string $username,
        public int $userId,
    ) {
        if (trim($username) === '') {
            throw new \DomainException(
                'Physician username must be non-empty: delegated reads require a named principal (S4/S6)'
            );
        }

        if ($userId <= 0) {
            throw new \DomainException(
                'Physician user id must be a positive integer: delegated reads require a named principal (S4/S6)'
            );
        }
    }
}

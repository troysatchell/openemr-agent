<?php

/**
 * Patient demographics value object for identity resolution (T7).
 *
 * pid is the trusted surrogate key (AUDIT D7: uuid is nullable/backfilled,
 * so uuid is carried as best-effort only and never validated here). A pid
 * is NOT a unique person (AUDIT D8) — dedupe happens by demographics in
 * PatientIdentityResolver, never by equating pid with identity.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\DataTrust;

final readonly class PatientDemographics
{
    public function __construct(
        public int $pid,
        public ?string $uuid,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $dob,
        public ?string $sex,
    ) {
        if ($pid <= 0) {
            throw new \DomainException('Patient pid must be a positive integer (pid is the trusted surrogate key — D7)');
        }
    }
}

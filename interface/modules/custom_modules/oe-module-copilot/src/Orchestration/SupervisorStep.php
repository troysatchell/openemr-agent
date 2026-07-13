<?php

/**
 * One node in a supervisor plan: a worker kind paired with the stated reason
 * it was routed to (W2_ARCHITECTURE.md §6; PS-10).
 *
 * Routing decisions are data, not vibes — a blank reason is refused at
 * construction so every step in a plan is self-explaining without the
 * supervisor's source in hand. The `Supervisor` never re-reads these fields
 * beyond `kind`; `reason` exists for the trace and the dashboard (§6 "routing
 * must read as legibly as a framework trace").
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

final readonly class SupervisorStep
{
    public function __construct(
        public SupervisorStepKind $kind,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new \DomainException(
                'A SupervisorStep requires a non-blank reason — routing decisions are data, not vibes'
            );
        }
    }
}

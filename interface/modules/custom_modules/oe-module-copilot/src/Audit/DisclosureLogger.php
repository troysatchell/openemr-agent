<?php

/**
 * Contract for recording external-AI disclosures (T2).
 *
 * Implementations persist a Disclosure into an audit trail. Failure to
 * record must propagate to the caller — an unlogged disclosure must never
 * look logged (AUDIT C1/C5; ARCHITECTURE §3.4).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Audit;

interface DisclosureLogger
{
    /**
     * Record one disclosure as exactly one audit event. Sink failures
     * propagate; they are never swallowed.
     */
    public function record(Disclosure $disclosure): void;
}

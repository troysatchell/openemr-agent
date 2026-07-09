<?php

/**
 * The result of verifying a set of draft claims against a chart's
 * reference index (T14; R6/R10/R11; ARCHITECTURE.md §3.4).
 *
 * grounded and rejected partition the input claims — every draft claim ends
 * up in exactly one bucket, never dropped (R11). hasUnverifiedContent()
 * lets callers surface an honest-uncertainty banner whenever any claim
 * could not be attributed to the chart.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Verification;

final readonly class VerifiedAnswer
{
    /**
     * @param list<GroundedClaim> $grounded
     * @param list<DraftClaim>    $rejected
     */
    public function __construct(
        public array $grounded,
        public array $rejected,
    ) {
    }

    public function hasUnverifiedContent(): bool
    {
        return $this->rejected !== [];
    }
}

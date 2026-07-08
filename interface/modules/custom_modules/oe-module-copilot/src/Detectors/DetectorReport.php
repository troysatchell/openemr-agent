<?php

/**
 * Result of one deterministic detector pass (T10; R13).
 *
 * Findings are the must-not-miss items the detector could positively
 * evaluate; unevaluable items are inputs it could NOT evaluate, surfaced
 * for honest-uncertainty UX (R11) rather than dropped. Both lists together
 * are the detector's complete account of what it saw.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Detectors;

final readonly class DetectorReport
{
    /**
     * @param list<CriticalFinding> $findings
     * @param list<UnevaluableItem> $unevaluable
     */
    public function __construct(
        public array $findings,
        public array $unevaluable,
    ) {
    }
}

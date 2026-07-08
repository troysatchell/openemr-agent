<?php

/**
 * The clinical-accuracy gate's verdict for one evaluation run (T11;
 * ARCHITECTURE.md §6).
 *
 * `armed` is true only when at least one adjudicated case was evaluated; an
 * unarmed gate cannot fail (labels are a Phase 0 human deliverable). criticalMisses
 * are "caseId:labelId" strings across adjudicated cases (R13). precision (R7) and
 * factualAccuracy (R6) are null when unmeasurable — no flags or no facts — which is
 * not a failure.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\GoldenChart;

final readonly class GateReport
{
    /**
     * @param list<string> $criticalMisses
     */
    public function __construct(
        public bool $armed,
        public bool $passed,
        public array $criticalMisses,
        public ?float $precision,
        public ?float $factualAccuracy,
        public string $summary,
    ) {
    }
}

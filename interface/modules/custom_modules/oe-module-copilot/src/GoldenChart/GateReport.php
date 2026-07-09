<?php

/**
 * The clinical-accuracy gate's verdict for one evaluation run (T11;
 * ARCHITECTURE.md §6; two-track rework T15, founder decisions locked
 * 2026-07-09).
 *
 * `armed` is true only when at least one adjudicated case was evaluated; an
 * unarmed gate cannot fail (labels are a Phase 0 human deliverable).
 *
 * The verdict is now a two-track model:
 *
 * - TRACK 1 (hard zeros, invariants — never percentages): criticalMisses are
 *   "caseId:labelId" strings across adjudicated cases; falseFlagCount is any
 *   spurious critical-subset flag (a data-trust bug, not a precision drag);
 *   incorrectFactCount is any wrong stated fact on the golden set. Any one of
 *   these above zero fails its track. `criticalTrackPassed` covers misses +
 *   false flags; `factualTrackPassed` covers incorrect facts. precision and
 *   factualAccuracy are still computed and reported here — they are
 *   production monitors only and never gate `passed`.
 * - TRACK 2 (provisional regression thresholds, judgment items only):
 *   judgmentPrecision / judgmentRecall are the only tunable rates in the
 *   system, null when unmeasurable (no judgment flags / no judgment labels).
 *   `judgmentTrackPassed` is true whenever a metric is null (nothing measured
 *   yet, or — for recall — no governance-set threshold exists: UNSOURCED
 *   pending governance, ARCHITECTURE.md §6).
 *
 * Overall `passed` is the conjunction of all three track flags once armed;
 * an unarmed gate always reports `passed = true` with every track flag at its
 * default (true).
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
        public int $falseFlagCount = 0,
        public int $incorrectFactCount = 0,
        public ?float $judgmentPrecision = null,
        public ?float $judgmentRecall = null,
        public bool $criticalTrackPassed = true,
        public bool $factualTrackPassed = true,
        public bool $judgmentTrackPassed = true,
    ) {
    }
}

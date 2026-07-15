<?php

/**
 * Aggregated view of the JSONL trace for one reporting window (T19;
 * ARCHITECTURE.md §6 observability).
 *
 * Every number here derives from trace lines alone. Rates and percentiles
 * are null when unmeasurable (zero turns) — a 0-out-of-0 is a lie, not a
 * metric. notApplicable names the metrics this system CANNOT measure and
 * why (no retry logic exists; no queue exists) — an honest absence beats a
 * fabricated number. malformedLineCount surfaces trace corruption instead
 * of silently dropping it.
 *
 * Week 2 additions (TRO-45/TRO-46; W2_ARCHITECTURE.md §8): ingestionCount /
 * ingestionLatencyP95Ms / ingestionFailureRate summarize the
 * `document-ingestion` steps the extraction path records; routeCounts
 * summarizes the supervisor's `handoff.<route>` decisions, keyed by route
 * suffix; vendorCostUsd and costUsdByCorrelation roll up cost from BOTH
 * trace-carried cost sources — token-usage cost (existing top-level
 * `cost_usd`, grouped under the `anthropic` vendor) and non-token vendor
 * cost ({@see VendorUnits}, grouped under its own vendor) — so total spend
 * is derivable from the trace alone, per vendor and per encounter
 * (correlation id). Same n/a-honesty rule as the rest of this report: no
 * ingestion steps means null latency/failure-rate, never a fabricated zero.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final readonly class DashboardReport
{
    /**
     * @param array<string, int> $stepCounts
     * @param array<string, int> $stepFailureCounts
     * @param array<string, string> $notApplicable metric => stated reason it cannot be measured
     * @param array<string, int> $routeCounts supervisor route suffix (from `handoff.<route>`) => count
     * @param array<string, float> $vendorCostUsd vendor name => total cost USD across both cost sources
     * @param array<string, float> $costUsdByCorrelation correlation id (encounter) => total cost USD across both cost sources
     */
    public function __construct(
        public int $turnCount,
        public int $errorTurnCount,
        public int $degradedTurnCount,
        public ?float $errorRate,
        public ?float $turnLatencyP50Ms,
        public ?float $turnLatencyP95Ms,
        public array $stepCounts,
        public array $stepFailureCounts,
        public int $groundedClaimCount,
        public int $rejectedClaimCount,
        public int $inputTokensTotal,
        public int $outputTokensTotal,
        public float $costUsdTotal,
        public int $malformedLineCount,
        public array $notApplicable,
        public int $ingestionCount = 0,
        public ?float $ingestionLatencyP95Ms = null,
        public ?float $ingestionFailureRate = null,
        public array $routeCounts = [],
        public array $vendorCostUsd = [],
        public array $costUsdByCorrelation = [],
    ) {
    }
}

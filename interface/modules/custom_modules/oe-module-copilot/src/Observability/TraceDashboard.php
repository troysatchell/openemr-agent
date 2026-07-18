<?php

/**
 * Pure aggregator over the JSONL trace (T19; ARCHITECTURE.md §6
 * observability).
 *
 * Reads the PHI-free trace emitted by JsonlTraceRecorder and rolls it up
 * into a DashboardReport: request count, error and degraded rates, latency
 * percentiles, per-step call/failure counts, verification verdict counts,
 * token and cost totals. Deterministic and side-effect-free — it reads
 * text, never the network or the database, so the dashboard is exactly as
 * trustworthy as the trace it summarizes. Malformed lines are counted, not
 * silently dropped; unmeasurable metrics are named N/A with a reason.
 *
 * Week 2 (TRO-45/TRO-46): also summarizes the `document-ingestion` step
 * family (count, p95 latency, failure rate) and the supervisor's
 * `handoff.<route>` decisions (per-route counts), and rolls cost up per
 * vendor and per correlation id from BOTH trace-carried cost sources —
 * token-usage cost (`anthropic`) and non-token vendor cost
 * ({@see VendorUnits}, its own vendor name) — so cost is derivable from the
 * trace alone.
 *
 * TRO-45 remainder: also builds routesByCorrelation — per correlation id,
 * the ORDERED list of `handoff.<route>` suffixes in the order they appear
 * in the trace — so the per-turn route is a rendered artifact.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final readonly class TraceDashboard
{
    /**
     * Metrics this system cannot measure, and why. Stated, never invented:
     * a zero would imply a measurement that does not exist.
     */
    private const NOT_APPLICABLE = [
        'retry_count' => 'No retry logic exists on the turn path — LlmUnavailableException degrades the turn immediately (R11).',
        'queue_depth' => 'No queue exists — the pre-chart is session-bound; unattended batch is deferred (ARCHITECTURE §4).',
    ];

    public function summarize(string $jsonl): DashboardReport
    {
        $malformed = 0;
        $stepCounts = [];
        $stepFailureCounts = [];
        $grounded = 0;
        $rejected = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $costUsd = 0.0;

        /** @var array<string, float> $turnDurations */
        $turnDurations = [];
        /** @var array<string, bool> $turnHasFailure */
        $turnHasFailure = [];
        /** @var array<string, bool> $turnDegraded */
        $turnDegraded = [];

        $ingestionCount = 0;
        $ingestionFailureCount = 0;
        /** @var list<float> $ingestionDurations */
        $ingestionDurations = [];
        // RAG retrieval latency = per-turn sum of the two evidence-retriever
        // vendor legs (embed + rerank), keyed by correlation so a turn's
        // retrieval is one measurement, not two.
        /** @var array<string, float> $retrievalDurationsByCorrelation */
        $retrievalDurationsByCorrelation = [];
        /** @var array<string, int> $routeCounts */
        $routeCounts = [];
        /** @var array<string, float> $vendorCostUsd */
        $vendorCostUsd = [];
        /** @var array<string, float> $costUsdByCorrelation */
        $costUsdByCorrelation = [];
        /** @var array<string, list<string>> $routesByCorrelation */
        $routesByCorrelation = [];

        foreach (explode("\n", $jsonl) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (
                !is_array($decoded)
                || !is_string($decoded['correlation_id'] ?? null)
                || !is_string($decoded['step'] ?? null)
                || !is_string($decoded['outcome'] ?? null)
            ) {
                $malformed++;
                continue;
            }

            $correlationId = $decoded['correlation_id'];
            $step = $decoded['step'];
            $failed = $decoded['outcome'] === StepOutcome::Failed->value;

            $stepCounts[$step] = ($stepCounts[$step] ?? 0) + 1;
            if ($failed) {
                $stepFailureCounts[$step] = ($stepFailureCounts[$step] ?? 0) + 1;
                $turnHasFailure[$correlationId] = true;
                if ($step === 'llm') {
                    $turnDegraded[$correlationId] = true;
                }
            }

            $duration = $decoded['duration_ms'] ?? null;
            if (is_int($duration) || is_float($duration)) {
                $turnDurations[$correlationId] = ($turnDurations[$correlationId] ?? 0.0) + (float) $duration;
            } else {
                $turnDurations[$correlationId] ??= 0.0;
            }

            if (is_int($decoded['grounded_count'] ?? null)) {
                $grounded += $decoded['grounded_count'];
            }
            if (is_int($decoded['rejected_count'] ?? null)) {
                $rejected += $decoded['rejected_count'];
            }
            if (is_int($decoded['input_tokens'] ?? null)) {
                $inputTokens += $decoded['input_tokens'];
            }
            if (is_int($decoded['output_tokens'] ?? null)) {
                $outputTokens += $decoded['output_tokens'];
            }
            $cost = $decoded['cost_usd'] ?? null;
            if (is_int($cost) || is_float($cost)) {
                $costUsd += (float) $cost;

                $vendorCostUsd['anthropic'] = ($vendorCostUsd['anthropic'] ?? 0.0) + (float) $cost;
                $costUsdByCorrelation[$correlationId] = ($costUsdByCorrelation[$correlationId] ?? 0.0) + (float) $cost;
            }

            if ($step === 'document-ingestion') {
                $ingestionCount++;
                if ($failed) {
                    $ingestionFailureCount++;
                }
                if (is_int($duration) || is_float($duration)) {
                    $ingestionDurations[] = (float) $duration;
                }
            }

            // The RAG retrieval leg is the evidence-retriever's two vendor
            // calls; sum their durations per turn so retrieval latency is one
            // number per retrieval, mirroring the per-turn latency roll-up.
            if ($step === 'embed' || $step === 'rerank') {
                if (is_int($duration) || is_float($duration)) {
                    $retrievalDurationsByCorrelation[$correlationId] =
                        ($retrievalDurationsByCorrelation[$correlationId] ?? 0.0) + (float) $duration;
                } else {
                    $retrievalDurationsByCorrelation[$correlationId] ??= 0.0;
                }
            }

            if (str_starts_with($step, 'handoff.')) {
                $route = substr($step, strlen('handoff.'));
                $routeCounts[$route] = ($routeCounts[$route] ?? 0) + 1;
                $routesByCorrelation[$correlationId] ??= [];
                $routesByCorrelation[$correlationId][] = $route;
            }

            $vendorUnits = $decoded['vendor_units'] ?? null;
            if (is_array($vendorUnits)) {
                $vendor = $vendorUnits['vendor'] ?? null;
                $vendorUnitsCost = $vendorUnits['cost_usd'] ?? null;
                if (is_string($vendor) && (is_int($vendorUnitsCost) || is_float($vendorUnitsCost))) {
                    $vendorCostUsd[$vendor] = ($vendorCostUsd[$vendor] ?? 0.0) + (float) $vendorUnitsCost;
                    $costUsdByCorrelation[$correlationId] = ($costUsdByCorrelation[$correlationId] ?? 0.0) + (float) $vendorUnitsCost;
                }
            }
        }

        $turnCount = count($turnDurations);
        $errorTurnCount = count($turnHasFailure);
        $sums = array_values($turnDurations);
        sort($sums);

        sort($ingestionDurations);

        $retrievalDurations = array_values($retrievalDurationsByCorrelation);
        sort($retrievalDurations);

        return new DashboardReport(
            turnCount: $turnCount,
            errorTurnCount: $errorTurnCount,
            degradedTurnCount: count($turnDegraded),
            errorRate: $turnCount > 0 ? $errorTurnCount / $turnCount : null,
            turnLatencyP50Ms: self::nearestRank($sums, 50),
            turnLatencyP95Ms: self::nearestRank($sums, 95),
            stepCounts: $stepCounts,
            stepFailureCounts: $stepFailureCounts,
            groundedClaimCount: $grounded,
            rejectedClaimCount: $rejected,
            inputTokensTotal: $inputTokens,
            outputTokensTotal: $outputTokens,
            costUsdTotal: $costUsd,
            malformedLineCount: $malformed,
            notApplicable: self::NOT_APPLICABLE,
            ingestionCount: $ingestionCount,
            ingestionLatencyP95Ms: self::nearestRank($ingestionDurations, 95),
            ingestionFailureRate: $ingestionCount > 0 ? $ingestionFailureCount / $ingestionCount : null,
            routeCounts: $routeCounts,
            vendorCostUsd: $vendorCostUsd,
            costUsdByCorrelation: $costUsdByCorrelation,
            routesByCorrelation: $routesByCorrelation,
            retrievalLatencyP95Ms: self::nearestRank($retrievalDurations, 95),
        );
    }

    /**
     * Nearest-rank percentile over an ascending-sorted list; null when the
     * list is empty (unmeasurable is not zero).
     *
     * @param list<float> $sortedAscending
     */
    private static function nearestRank(array $sortedAscending, int $percentile): ?float
    {
        $count = count($sortedAscending);
        if ($count === 0) {
            return null;
        }

        $rank = (int) ceil($percentile / 100 * $count);

        return $sortedAscending[max(0, $rank - 1)];
    }
}

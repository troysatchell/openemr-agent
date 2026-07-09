<?php

/**
 * FROZEN acceptance tests — T19: trace dashboard aggregator (Early
 * Submission observability requirement; ARCHITECTURE.md §6).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: every dashboard number derives from the JSONL trace
 * alone — no second data source, no invention. A turn is one correlation
 * id; an error turn contains any failed step; a degraded turn is an llm
 * failure specifically (honest degradation, findings still delivered).
 * Latency percentiles use nearest-rank over per-turn duration sums.
 * Malformed lines are COUNTED, never silently dropped and never fatal.
 * Metrics the system cannot measure are reported N/A with a stated reason
 * (no retry logic exists; no queue exists) — an honest absence beats a
 * fabricated number. Empty input yields zero turns and null rates, not a
 * 0-out-of-0 lie.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Observability;

use OpenEMR\Modules\Copilot\Observability\TraceDashboard;
use PHPUnit\Framework\TestCase;

class TraceDashboardTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private static function line(string $correlationId, string $step, float $durationMs, array $overrides = []): string
    {
        return json_encode(array_merge([
            'correlation_id' => $correlationId,
            'turn_kind' => 'followup_qa',
            'step' => $step,
            'started_at' => '2026-07-09T09:00:00+00:00',
            'duration_ms' => $durationMs,
            'outcome' => 'ok',
            'error_class' => null,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'cost_usd' => null,
            'grounded_count' => null,
            'rejected_count' => null,
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private static function sampleTrace(): string
    {
        $lines = [
            // Turn A — clean; total 130ms.
            self::line('corr-a', 'retrieve', 10.0),
            self::line('corr-a', 'detect', 5.0),
            self::line('corr-a', 'build_payload', 5.0),
            self::line('corr-a', 'disclose', 5.0),
            self::line('corr-a', 'llm', 100.0, ['model' => 'claude-x', 'input_tokens' => 1000, 'output_tokens' => 200, 'cost_usd' => 0.01]),
            self::line('corr-a', 'ground', 5.0, ['grounded_count' => 2, 'rejected_count' => 1]),
            // Turn B — degraded (llm failed, no ground step); total 85ms.
            self::line('corr-b', 'retrieve', 20.0),
            self::line('corr-b', 'detect', 5.0),
            self::line('corr-b', 'build_payload', 5.0),
            self::line('corr-b', 'disclose', 5.0),
            self::line('corr-b', 'llm', 50.0, ['outcome' => 'failed', 'error_class' => 'OpenEMR\\Modules\\Copilot\\Llm\\LlmUnavailableException']),
            // Turn C — clean; total 240ms.
            self::line('corr-c', 'retrieve', 15.0),
            self::line('corr-c', 'detect', 5.0),
            self::line('corr-c', 'build_payload', 5.0),
            self::line('corr-c', 'disclose', 5.0),
            self::line('corr-c', 'llm', 200.0, ['model' => 'claude-x', 'input_tokens' => 2000, 'output_tokens' => 300, 'cost_usd' => 0.0175]),
            self::line('corr-c', 'ground', 10.0, ['grounded_count' => 1, 'rejected_count' => 0]),
            // One malformed line — counted, never silently dropped, never fatal.
            'this is not json {{{',
        ];

        return implode("\n", $lines) . "\n";
    }

    public function testAggregatesTurnsErrorsAndDegradationFromTheTraceAlone(): void
    {
        $report = (new TraceDashboard())->summarize(self::sampleTrace());

        $this->assertSame(3, $report->turnCount);
        $this->assertSame(1, $report->errorTurnCount);
        $this->assertSame(1, $report->degradedTurnCount, 'A degraded turn is an llm failure — findings still delivered, prose absent.');
        $this->assertNotNull($report->errorRate);
        $this->assertEqualsWithDelta(1 / 3, $report->errorRate, 1e-9);
        $this->assertSame(1, $report->malformedLineCount);
    }

    public function testTurnLatencyPercentilesUseNearestRankOverPerTurnSums(): void
    {
        $report = (new TraceDashboard())->summarize(self::sampleTrace());

        // Per-turn sums sorted: [85, 130, 240]. Nearest rank: p50 = 2nd, p95 = 3rd.
        $this->assertNotNull($report->turnLatencyP50Ms);
        $this->assertEqualsWithDelta(130.0, $report->turnLatencyP50Ms, 1e-9);
        $this->assertNotNull($report->turnLatencyP95Ms);
        $this->assertEqualsWithDelta(240.0, $report->turnLatencyP95Ms, 1e-9);
    }

    public function testStepAndVerificationAndCostRollupsDeriveFromTheLines(): void
    {
        $report = (new TraceDashboard())->summarize(self::sampleTrace());

        $this->assertSame(3, $report->stepCounts['retrieve']);
        $this->assertSame(3, $report->stepCounts['llm']);
        $this->assertSame(2, $report->stepCounts['ground'], 'No ground step on the degraded turn — the trace must not claim grounding happened.');
        $this->assertSame(['llm' => 1], $report->stepFailureCounts);

        $this->assertSame(3, $report->groundedClaimCount);
        $this->assertSame(1, $report->rejectedClaimCount, 'Rejections are the system working (R6/R10) — watched, not paged.');

        $this->assertSame(3000, $report->inputTokensTotal);
        $this->assertSame(500, $report->outputTokensTotal);
        $this->assertEqualsWithDelta(0.0275, $report->costUsdTotal, 1e-12);
    }

    public function testAbsentMetricsAreReportedNotApplicableWithAReason(): void
    {
        $report = (new TraceDashboard())->summarize(self::sampleTrace());

        $this->assertArrayHasKey('retry_count', $report->notApplicable, 'No retry logic exists — say so instead of inventing a zero that implies measurement.');
        $this->assertArrayHasKey('queue_depth', $report->notApplicable, 'No queue exists — the pre-chart is session-bound.');
        foreach ($report->notApplicable as $metric => $reason) {
            $this->assertNotSame('', trim($reason), sprintf('N/A metric "%s" must state WHY it cannot be measured.', $metric));
        }
    }

    public function testEmptyInputYieldsZeroTurnsAndNullRatesNotLies(): void
    {
        $report = (new TraceDashboard())->summarize('');

        $this->assertSame(0, $report->turnCount);
        $this->assertNull($report->errorRate, 'A rate over zero turns is not 0.0 — it is unmeasurable.');
        $this->assertNull($report->turnLatencyP50Ms);
        $this->assertNull($report->turnLatencyP95Ms);
        $this->assertSame(0, $report->malformedLineCount);
    }
}

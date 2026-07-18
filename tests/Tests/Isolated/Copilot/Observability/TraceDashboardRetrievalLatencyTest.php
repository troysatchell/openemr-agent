<?php

/**
 * RAG retrieval-latency metric — backs the rubric's "RAG retrieval latency"
 * alert (docs/OBSERVABILITY.md; bin/alert-check.php).
 *
 * Failure mode guarded against: the alert would silently never fire if the
 * aggregator did not roll the evidence-retriever's embed+rerank legs into a
 * per-turn retrieval latency. These tests pin the per-turn summing, the
 * "no retrieval traffic" null, and the degraded single-leg case (the alert
 * must not go blind when only one vendor leg ran). The frozen
 * TraceDashboardTest (T19) is not touched.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Observability;

use OpenEMR\Modules\Copilot\Observability\TraceDashboard;
use PHPUnit\Framework\TestCase;

class TraceDashboardRetrievalLatencyTest extends TestCase
{
    private static function line(string $correlationId, string $step, float $durationMs, string $outcome = 'ok'): string
    {
        return json_encode([
            'correlation_id' => $correlationId,
            'step' => $step,
            'started_at' => '2026-07-18T09:00:00+00:00',
            'duration_ms' => $durationMs,
            'outcome' => $outcome,
        ], JSON_THROW_ON_ERROR);
    }

    public function testRetrievalLatencyP95SumsEmbedAndRerankPerTurn(): void
    {
        $jsonl = implode("\n", [
            self::line('turn-a', 'embed', 100.0),
            self::line('turn-a', 'rerank', 50.0),   // turn-a retrieval leg = 150 ms
            self::line('turn-b', 'embed', 200.0),
            self::line('turn-b', 'rerank', 100.0),  // turn-b retrieval leg = 300 ms
        ]);

        $report = (new TraceDashboard())->summarize($jsonl);

        // nearest-rank p95 over [150, 300] -> the 300 ms turn
        $this->assertNotNull($report->retrievalLatencyP95Ms);
        $this->assertEqualsWithDelta(300.0, $report->retrievalLatencyP95Ms, 1e-9);
    }

    public function testRetrievalLatencyNullWhenNoRetrievalSteps(): void
    {
        // 'retrieve' is the chart-provider read, not the RAG leg — it must not
        // be counted as retrieval latency.
        $jsonl = implode("\n", [
            self::line('turn-a', 'retrieve', 10.0),
            self::line('turn-a', 'llm', 500.0),
        ]);

        $report = (new TraceDashboard())->summarize($jsonl);

        $this->assertNull($report->retrievalLatencyP95Ms);
    }

    public function testRetrievalLatencyCountsADegradedSingleLegTurn(): void
    {
        // Only the embed leg ran (rerank degraded/absent, PS-12) — the turn
        // still yields a retrieval measurement so the alert never goes blind.
        $report = (new TraceDashboard())->summarize(self::line('turn-a', 'embed', 120.0));

        $this->assertNotNull($report->retrievalLatencyP95Ms);
        $this->assertEqualsWithDelta(120.0, $report->retrievalLatencyP95Ms, 1e-9);
    }
}

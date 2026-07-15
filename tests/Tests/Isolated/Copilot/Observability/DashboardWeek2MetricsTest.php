<?php

/**
 * FROZEN acceptance tests — TRO-45 + TRO-46: the dashboard reads the Week 2
 * trace surface and the cost report is derivable from traces alone.
 *
 * Authored by the orchestrator from the tickets' acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract:
 *  - TRO-46: a VendorUnits value object (vendor, unit kind, units consumed,
 *    versioned unit price, derived cost) generalizes the TokenUsage pattern
 *    to the non-token vendor models (embed / rerank / vision units);
 *    StepRecord optionally carries one; JsonlTraceRecorder serializes it so
 *    cost is derivable FROM TRACES ALONE. The dashboard rolls cost up per
 *    vendor and per correlation id (the encounter key).
 *  - TRO-45: the dashboard summarizes the Week 2 step families the module
 *    already records — 'document-ingestion' (count + p95 latency + failure
 *    rate) and the supervisor's 'handoff.<route>' decisions (per-route
 *    counts) — with the Week 1 n/a honesty rule: an empty or silent trace
 *    yields nulls and empty maps, never invented zeros-as-measurements.
 *
 * The fixture JSONL is produced by the REAL JsonlTraceRecorder, so this
 * suite pins the recorder and the dashboard as one wire-compatible pair.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Observability;

use OpenEMR\Modules\Copilot\Observability\JsonlTraceRecorder;
use OpenEMR\Modules\Copilot\Observability\StepOutcome;
use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TokenUsage;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceDashboard;
use OpenEMR\Modules\Copilot\Observability\VendorUnits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DashboardWeek2MetricsTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testVendorUnitsIsAValidatedValueObject(): void
    {
        $units = new VendorUnits('cohere', 'rerank_search_unit', 3, 'cohere-2026-07', 0.006);

        $this->assertSame('cohere', $units->vendor);
        $this->assertSame('rerank_search_unit', $units->unitKind);
        $this->assertSame(3, $units->units);
        $this->assertSame('cohere-2026-07', $units->priceVersion);
        $this->assertSame(0.006, $units->costUsd);
    }

    /**
     * @return array<string, array{string, string, int, string, float}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidVendorUnitsProvider(): array
    {
        return [
            'blank vendor' => ['', 'rerank_search_unit', 1, 'v1', 0.01],
            'blank unit kind' => ['cohere', ' ', 1, 'v1', 0.01],
            'negative units' => ['cohere', 'rerank_search_unit', -1, 'v1', 0.01],
            'blank price version' => ['cohere', 'rerank_search_unit', 1, '', 0.01],
            'negative cost' => ['cohere', 'rerank_search_unit', 1, 'v1', -0.01],
        ];
    }

    #[DataProvider('invalidVendorUnitsProvider')]
    public function testVendorUnitsRefusesNonsense(string $vendor, string $kind, int $units, string $version, float $cost): void
    {
        $this->expectException(\DomainException::class);
        new VendorUnits($vendor, $kind, $units, $version, $cost);
    }

    public function testStepRecordOptionallyCarriesVendorUnits(): void
    {
        $units = new VendorUnits('cohere', 'embed_token', 1200, 'cohere-2026-07', 0.00012);
        $with = new StepRecord('retrieval', $this->now(), 10.0, StepOutcome::Ok, null, null, null, null, $units);
        $without = new StepRecord('retrieval', $this->now(), 10.0, StepOutcome::Ok);

        $this->assertSame($units, $with->vendorUnits);
        $this->assertNull($without->vendorUnits);
    }

    public function testRecorderSerializesVendorUnitsSoCostIsDerivableFromTracesAlone(): void
    {
        $path = $this->tempTracePath();
        $recorder = new JsonlTraceRecorder($path);
        $context = new TraceContext('corr-vu', 'question', $this->now());

        $recorder->record($context, new StepRecord(
            'retrieval',
            $this->now(),
            12.5,
            StepOutcome::Ok,
            null,
            null,
            null,
            null,
            new VendorUnits('cohere', 'rerank_search_unit', 2, 'cohere-2026-07', 0.004),
        ));

        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $line = json_decode(trim($raw), true);
        $this->assertIsArray($line);

        $vendorUnits = $line['vendor_units'] ?? null;
        $this->assertIsArray($vendorUnits, 'vendor units cross the trace wire — cost must be derivable from traces alone (TRO-46)');
        $this->assertSame('cohere', $vendorUnits['vendor'] ?? null);
        $this->assertSame('rerank_search_unit', $vendorUnits['unit_kind'] ?? null);
        $this->assertSame(2, $vendorUnits['units'] ?? null);
        $this->assertSame('cohere-2026-07', $vendorUnits['price_version'] ?? null);
        $costUsd = $vendorUnits['cost_usd'] ?? null;
        $this->assertIsFloat($costUsd);
        $this->assertEqualsWithDelta(0.004, $costUsd, 0.0000001);
    }

    public function testDashboardRollsUpWeek2StepsAndCost(): void
    {
        $path = $this->tempTracePath();
        $recorder = new JsonlTraceRecorder($path);

        $turnA = new TraceContext('corr-a', 'question', $this->now());
        $recorder->record($turnA, new StepRecord('document-ingestion', $this->now(), 400.0, StepOutcome::Ok));
        $recorder->record($turnA, new StepRecord('document-ingestion', $this->now(), 900.0, StepOutcome::Failed, \RuntimeException::class));
        $recorder->record($turnA, new StepRecord('handoff.intake-extractor', $this->now(), 5.0, StepOutcome::Ok));
        $recorder->record($turnA, new StepRecord(
            'llm',
            $this->now(),
            800.0,
            StepOutcome::Ok,
            null,
            new TokenUsage('claude-opus-4-8', 1000, 200, 0.02),
        ));

        $turnB = new TraceContext('corr-b', 'question', $this->now());
        $recorder->record($turnB, new StepRecord('handoff.evidence-retriever', $this->now(), 5.0, StepOutcome::Ok));
        $recorder->record($turnB, new StepRecord('handoff.evidence-retriever', $this->now(), 6.0, StepOutcome::Ok));
        $recorder->record($turnB, new StepRecord(
            'retrieval',
            $this->now(),
            80.0,
            StepOutcome::Ok,
            null,
            null,
            null,
            null,
            new VendorUnits('cohere', 'rerank_search_unit', 2, 'cohere-2026-07', 0.004),
        ));

        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $report = (new TraceDashboard())->summarize($raw);

        $this->assertSame(2, $report->ingestionCount, 'every ingestion counts, including the failed one');
        $this->assertNotNull($report->ingestionLatencyP95Ms);
        $this->assertEqualsWithDelta(0.5, $report->ingestionFailureRate ?? -1.0, 0.0001);

        $this->assertSame(
            ['intake-extractor' => 1, 'evidence-retriever' => 2],
            $report->routeCounts,
            'supervisor routing decisions are counted per route from the handoff.* steps',
        );

        $vendorCost = $report->vendorCostUsd;
        $this->assertEqualsWithDelta(0.02, $vendorCost['anthropic'] ?? -1.0, 0.0000001, 'token-usage cost rolls up under the anthropic vendor');
        $this->assertEqualsWithDelta(0.004, $vendorCost['cohere'] ?? -1.0, 0.0000001, 'vendor-units cost rolls up under its own vendor');

        $byCorrelation = $report->costUsdByCorrelation;
        $this->assertEqualsWithDelta(0.02, $byCorrelation['corr-a'] ?? -1.0, 0.0000001, 'per-encounter rollup keys on the correlation id');
        $this->assertEqualsWithDelta(0.004, $byCorrelation['corr-b'] ?? -1.0, 0.0000001);
    }

    public function testSilentTraceStaysHonestlyUnmeasured(): void
    {
        $report = (new TraceDashboard())->summarize('');

        $this->assertSame(0, $report->ingestionCount);
        $this->assertNull($report->ingestionLatencyP95Ms, 'no data is n/a, never a fabricated zero measurement');
        $this->assertNull($report->ingestionFailureRate);
        $this->assertSame([], $report->routeCounts);
        $this->assertSame([], $report->vendorCostUsd);
        $this->assertSame([], $report->costUsdByCorrelation);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-15T10:00:00+00:00');
    }

    private function tempTracePath(): string
    {
        $path = sys_get_temp_dir() . '/copilot-w2-metrics-' . uniqid('', true) . '.jsonl';
        $this->tempFiles[] = $path;

        return $path;
    }
}

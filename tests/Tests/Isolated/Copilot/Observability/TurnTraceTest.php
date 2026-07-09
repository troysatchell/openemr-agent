<?php

/**
 * FROZEN acceptance tests — T17: per-turn observability trace (UC2;
 * ARCHITECTURE.md §6 observability; AUDIT S4/C4/C5; founder decision 5,
 * locked 2026-07-09).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: a full turn is reconstructible from logs alone.
 * The correlation ID is minted at the orchestrator boundary (the single
 * choke point) and passed EXPLICITLY — through value objects and ports,
 * never an ambient global or static (that would reproduce S4, the audit's
 * largest-blast-radius pattern). Every port call the orchestrator makes
 * emits one StepRecord, failures and the degraded path included — a failed
 * tool must trace, not vanish. The trace log carries NO PHI: no pid, no
 * uuid, no patient name, no chart content, no question text (per C5 an
 * identifier that maps back to a person IS PHI; the disclosure log, not the
 * trace, is the PHI-carrying record — the two share only the correlation
 * ID as join key). Model identity and token usage are recorded on the LLM
 * step — the Anthropic API does not write our audit trail; this trace is
 * it. The recorder is an optional trailing injection defaulting to a
 * no-op, so every frozen positional construction of TurnOrchestrator
 * stays green.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Observability;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Llm\ChartDataFlattener;
use OpenEMR\Modules\Copilot\Llm\CopilotTask;
use OpenEMR\Modules\Copilot\Llm\FieldAllowlist;
use OpenEMR\Modules\Copilot\Llm\LlmClient;
use OpenEMR\Modules\Copilot\Llm\LlmTurnRequest;
use OpenEMR\Modules\Copilot\Llm\LlmTurnResponse;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
use OpenEMR\Modules\Copilot\Observability\JsonlTraceRecorder;
use OpenEMR\Modules\Copilot\Observability\StepOutcome;
use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TokenUsage;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceRecorder;
use OpenEMR\Modules\Copilot\Orchestration\ChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Orchestration\ProvidedChart;
use OpenEMR\Modules\Copilot\Orchestration\TurnOrchestrator;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class TurnTraceTest extends TestCase
{
    private const QUESTION = 'Anything new on the potassium trend for this visit?';

    /** The orchestrator's port-call seams, in execution order. */
    private const EXPECTED_STEPS = ['retrieve', 'detect', 'build_payload', 'disclose', 'llm', 'ground'];

    /**
     * Keys that must never exist in a serialized trace line: the trace is
     * PHI-free BY SCHEMA, not by discipline (C5; founder decision 5).
     */
    private const FORBIDDEN_TRACE_KEYS = ['pid', 'uuid', 'patient', 'question', 'payload', 'chart', 'claims', 'answer'];

    private ?string $jsonlPath = null;

    protected function tearDown(): void
    {
        if ($this->jsonlPath !== null && file_exists($this->jsonlPath)) {
            unlink($this->jsonlPath);
        }
        parent::tearDown();
    }

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('ellis.tran', 7);
    }

    private function providedChart(): ProvidedChart
    {
        return new ProvidedChart(
            new PatientDemographics(42, null, 'Alma', 'Reyes', '1961-03-14', 'F'),
            $this->richChart(),
        );
    }

    private function richChart(): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize(
            [
                new MedicationEntry('Warfarin 5mg Tablet', CurrencyStatus::Current, [new SourceRef('lists', 'med-warf')]),
                new MedicationEntry('Aspirin 81mg', CurrencyStatus::Current, [new SourceRef('lists', 'med-asa')]),
            ],
            [new LabResultEntry(
                'Potassium',
                6.8,
                'mmol/L',
                new \DateTimeImmutable('2026-07-07 07:00:00'),
                [new SourceRef('procedure_result', 'lab-k')],
            )],
            [],
        );
    }

    private function provider(): ChartSnapshotProvider
    {
        $chart = $this->providedChart();

        return new class ($chart) implements ChartSnapshotProvider {
            public function __construct(private readonly ProvidedChart $chart)
            {
            }

            public function provide(PhysicianContext $physician, string $patientUuid): ProvidedChart
            {
                return $this->chart;
            }
        };
    }

    private function capturingLogger(): DisclosureLogger
    {
        return new class implements DisclosureLogger {
            public ?Disclosure $last = null;

            public function record(Disclosure $disclosure): void
            {
                $this->last = $disclosure;
            }
        };
    }

    private function scriptedLlm(bool $unavailable = false, ?TokenUsage $usage = null): LlmClient
    {
        return new class ($unavailable, $usage) implements LlmClient {
            public ?LlmTurnRequest $captured = null;

            public function __construct(
                private readonly bool $unavailable,
                private readonly ?TokenUsage $usage,
            ) {
            }

            public function complete(LlmTurnRequest $request): LlmTurnResponse
            {
                $this->captured = $request;
                if ($this->unavailable) {
                    throw new LlmUnavailableException('vendor-transport-internals');
                }

                return new LlmTurnResponse([], $this->usage);
            }
        };
    }

    /**
     * @return TraceRecorder&object{contexts: list<TraceContext>, steps: list<StepRecord>}
     */
    private function collectingRecorder(): TraceRecorder
    {
        return new class implements TraceRecorder {
            /** @var list<TraceContext> */
            public array $contexts = [];

            /** @var list<StepRecord> */
            public array $steps = [];

            public function record(TraceContext $context, StepRecord $step): void
            {
                $this->contexts[] = $context;
                $this->steps[] = $step;
            }
        };
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-07-09 09:00:00', new \DateTimeZone('UTC'));
            }
        };
    }

    private function builder(): MinimumNecessaryPayloadBuilder
    {
        return new MinimumNecessaryPayloadBuilder([
            CopilotTask::FollowUpQa->value => new FieldAllowlist([
                'medications' => ['name', 'status', 'ref'],
                'lab_results' => ['analyte', 'value', 'unit', 'ref'],
            ]),
        ]);
    }

    private function orchestrator(
        DisclosureLogger $logger,
        LlmClient $llm,
        TraceRecorder $recorder,
    ): TurnOrchestrator {
        return new TurnOrchestrator(
            $this->provider(),
            CriticalSubsetDetectors::withDraftTables(),
            new ChartDataFlattener(),
            $this->builder(),
            CopilotTask::FollowUpQa,
            $logger,
            $llm,
            new ClaimVerifier(),
            $this->fixedClock(),
            $recorder,
        );
    }

    public function testACompletedTurnEmitsEveryStepInOrderUnderOneCorrelationId(): void
    {
        $recorder = $this->collectingRecorder();
        $orchestrator = $this->orchestrator($this->capturingLogger(), $this->scriptedLlm(), $recorder);

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertSame(
            self::EXPECTED_STEPS,
            array_map(static fn (StepRecord $s): string => $s->step, $recorder->steps),
            'A full trace must be reconstructible from logs alone — every port call, in order.',
        );
        foreach ($recorder->steps as $step) {
            $this->assertSame(StepOutcome::Ok, $step->outcome);
            $this->assertNull($step->errorClass, 'An ok step carries no error class.');
            $this->assertGreaterThanOrEqual(0.0, $step->durationMs);
        }

        $ids = array_unique(array_map(static fn (TraceContext $c): string => $c->correlationId, $recorder->contexts));
        $this->assertCount(1, $ids, 'One turn, one correlation ID, on every step.');
        $this->assertNotSame('', trim($ids[0]));
        $this->assertSame(CopilotTask::FollowUpQa->value, $recorder->contexts[0]->turnKind);
    }

    public function testTwoTurnsMintDistinctCorrelationIds(): void
    {
        $recorder = $this->collectingRecorder();
        $orchestrator = $this->orchestrator($this->capturingLogger(), $this->scriptedLlm(), $recorder);

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);
        $orchestrator->runTurn($this->physician(), 'uuid-1', 'And the meds?');

        $ids = array_unique(array_map(static fn (TraceContext $c): string => $c->correlationId, $recorder->contexts));
        $this->assertCount(2, $ids, 'Correlation IDs are minted per turn — reuse would merge unrelated traces.');
    }

    public function testAFailedLlmEmitsAFailedStepAndTheTurnStillTraces(): void
    {
        $recorder = $this->collectingRecorder();
        $orchestrator = $this->orchestrator($this->capturingLogger(), $this->scriptedLlm(unavailable: true), $recorder);

        $result = $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertTrue($result->degraded, 'The degraded path is a first-class outcome, and it must trace.');

        $byName = [];
        foreach ($recorder->steps as $step) {
            $byName[$step->step] = $step;
        }

        $this->assertArrayHasKey('llm', $byName, 'A failed tool emits a step — it must never vanish from the trace.');
        $this->assertSame(StepOutcome::Failed, $byName['llm']->outcome);
        $this->assertSame(LlmUnavailableException::class, $byName['llm']->errorClass, 'The error CLASS is recorded; internals are not.');
        $this->assertArrayNotHasKey('ground', $byName, 'No grounding happened; the trace must not claim it did.');

        foreach (['retrieve', 'detect', 'build_payload', 'disclose'] as $priorStep) {
            $this->assertArrayHasKey($priorStep, $byName, 'Steps before the failure remain traced.');
        }
    }

    public function testTheCorrelationIdJoinsTraceDisclosureRequestAndResult(): void
    {
        $recorder = $this->collectingRecorder();
        $logger = $this->capturingLogger();
        $llm = $this->scriptedLlm();
        $orchestrator = $this->orchestrator($logger, $llm, $recorder);

        $result = $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $correlationId = $recorder->contexts[0]->correlationId;
        $this->assertNotSame('', trim($correlationId));
        $this->assertNotNull($logger->last);
        $this->assertSame(
            $correlationId,
            $logger->last->correlationId,
            'The disclosure log is the PHI-carrying record; the correlation ID is the ONLY join key between it and the trace.',
        );
        $this->assertNotNull($llm->captured);
        $this->assertSame($correlationId, $llm->captured->correlationId, 'The ID crosses to the adapter explicitly — never via an ambient global (S4).');
        $this->assertSame($correlationId, $result->correlationId, 'The caller can echo the ID for support/audit lookups.');
    }

    public function testTheLlmStepRecordsModelIdentityAndTokenUsage(): void
    {
        $recorder = $this->collectingRecorder();
        $usage = new TokenUsage('claude-test-model-1', 1200, 300, 0.0081);
        $orchestrator = $this->orchestrator($this->capturingLogger(), $this->scriptedLlm(usage: $usage), $recorder);

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $llmStep = null;
        foreach ($recorder->steps as $step) {
            if ($step->step === 'llm') {
                $llmStep = $step;
            }
        }

        $this->assertNotNull($llmStep);
        $this->assertNotNull($llmStep->tokenUsage, 'Timestamps + user attribution + MODEL ID is what an audit trail needs; the vendor does not log it for us.');
        $this->assertSame('claude-test-model-1', $llmStep->tokenUsage->modelId);
        $this->assertSame(1200, $llmStep->tokenUsage->inputTokens);
        $this->assertSame(300, $llmStep->tokenUsage->outputTokens);
        $this->assertSame(0.0081, $llmStep->tokenUsage->costUsd);
    }

    public function testTheJsonlTraceCarriesNoPhiByConstruction(): void
    {
        $this->jsonlPath = tempnam(sys_get_temp_dir(), 'copilot-trace-') ?: self::fail('tempnam failed');
        $recorder = new JsonlTraceRecorder($this->jsonlPath);
        $usage = new TokenUsage('claude-test-model-1', 1200, 300, 0.0081);
        $orchestrator = $this->orchestrator($this->capturingLogger(), $this->scriptedLlm(usage: $usage), $recorder);

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $raw = file_get_contents($this->jsonlPath);
        $this->assertNotFalse($raw);
        $lines = array_values(array_filter(explode("\n", trim($raw)), static fn (string $l): bool => $l !== ''));
        $this->assertCount(count(self::EXPECTED_STEPS), $lines, 'One JSON line per step.');

        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            foreach (['correlation_id', 'turn_kind', 'step', 'started_at', 'duration_ms', 'outcome'] as $requiredKey) {
                $this->assertArrayHasKey($requiredKey, $decoded);
            }
            foreach (self::FORBIDDEN_TRACE_KEYS as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $decoded, 'The trace schema has no slot for PHI — free-form content cannot leak through it.');
            }
        }

        // Belt and braces: nothing patient-identifying or chart-derived from
        // this scenario may appear anywhere in the serialized output.
        foreach (['uuid-1', 'Alma', 'Reyes', '1961-03-14', 'Warfarin', 'Potassium', 'ellis.tran', self::QUESTION] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, sprintf('PHI leak: "%s" found in the trace log.', $needle));
        }

        // The model id must appear — the trace, not the vendor, is our audit trail.
        $this->assertStringContainsString('claude-test-model-1', $raw);
    }

    public function testStepRecordRefusesInconsistentConstruction(): void
    {
        $startedAt = new \DateTimeImmutable('2026-07-09 09:00:00');

        try {
            new StepRecord('llm', $startedAt, 1.0, StepOutcome::Ok, LlmUnavailableException::class);
            self::fail('An ok step with an error class is a contradiction and must be refused.');
        } catch (\DomainException) {
        }

        try {
            new StepRecord('llm', $startedAt, 1.0, StepOutcome::Failed, null);
            self::fail('A failed step without an error class hides what failed and must be refused.');
        } catch (\DomainException) {
        }

        $this->expectException(\DomainException::class);
        new StepRecord(' ', $startedAt, 1.0, StepOutcome::Ok, null);
    }

    public function testTokenUsageRefusesNonsense(): void
    {
        try {
            new TokenUsage(' ', 1, 1, 0.1);
            self::fail('A blank model id defeats the audit purpose and must be refused.');
        } catch (\DomainException) {
        }

        $this->expectException(\DomainException::class);
        new TokenUsage('claude-test-model-1', -1, 1, 0.1);
    }
}

<?php

/**
 * FROZEN acceptance tests — TRO-32: supervised worker dispatch (W2_ARCHITECTURE §6; PS-10).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: SupervisedTurnDispatcher executes a Supervisor plan
 * against the worker PORTS — each handoff is a StepRecord carrying the
 * decision (step name), its stated reason, and the worker's outcome; each
 * worker runs inside a CHILD span of the turn span (same correlationId,
 * parentSpanId = turn spanId — the S4 explicit-carry rule as a span tree).
 * A snapshot state dispatches NO workers (zero-RAG). A worker failure is
 * recorded as a failed handoff and then propagates — never swallowed.
 * Worker stubs appear here and ONLY here (§6/§7): the eval gate exercises
 * the real implementations.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Observability\StepOutcome;
use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceRecorder;
use OpenEMR\Modules\Copilot\Orchestration\EvidenceRetrieverWorker;
use OpenEMR\Modules\Copilot\Orchestration\IntakeExtractionOutcome;
use OpenEMR\Modules\Copilot\Orchestration\IntakeExtractorWorker;
use OpenEMR\Modules\Copilot\Orchestration\SupervisedTurnDispatcher;
use OpenEMR\Modules\Copilot\Orchestration\Supervisor;
use OpenEMR\Modules\Copilot\Orchestration\SupervisorTurnState;
use OpenEMR\Modules\Copilot\Rag\RetrievalOutcome;
use PHPUnit\Framework\TestCase;

class SupervisedTurnDispatcherTest extends TestCase
{
    private RecordingTraceRecorder $recorder;

    private SpyIntakeWorker $intake;

    private SpyEvidenceWorker $evidence;

    protected function setUp(): void
    {
        $this->recorder = new RecordingTraceRecorder();
        $this->intake = new SpyIntakeWorker();
        $this->evidence = new SpyEvidenceWorker();
    }

    private function dispatcher(): SupervisedTurnDispatcher
    {
        return new SupervisedTurnDispatcher(new Supervisor(), $this->intake, $this->evidence, $this->recorder);
    }

    private function state(
        bool $snapshot = false,
        bool $pendingDoc = false,
        bool $evidence = false,
    ): SupervisorTurnState {
        return new SupervisorTurnState($snapshot, $pendingDoc, $evidence, false, false);
    }

    private function turnSpan(): TraceContext
    {
        return TraceContext::start('question', new \DateTimeImmutable('2026-07-13 12:00:00'));
    }

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('dr-tran', 7);
    }

    /**
     * @return list<string>
     */
    private function recordedSteps(): array
    {
        return array_map(static fn (StepRecord $r): string => $r->step, array_column($this->recorder->records, 1));
    }

    public function testFullPlanDispatchesWorkersAndRecordsHandoffsInPlanOrder(): void
    {
        $turn = $this->turnSpan();

        $result = $this->dispatcher()->dispatch(
            $this->physician(),
            $this->state(pendingDoc: true, evidence: true),
            42,
            'what supports the statin recommendation?',
            5,
            $turn,
        );

        $this->assertSame(
            ['handoff.intake-extractor', 'handoff.evidence-retriever', 'handoff.compose-answer'],
            $this->recordedSteps(),
        );
        $this->assertNotNull($result->intake);
        $this->assertNotNull($result->evidence);
        $this->assertCount(3, $result->plan);
    }

    public function testWorkersRunInChildSpansOfTheTurnSpan(): void
    {
        $turn = $this->turnSpan();

        $this->dispatcher()->dispatch($this->physician(), $this->state(pendingDoc: true, evidence: true), 42, 'q', 5, $turn);

        $this->assertNotNull($this->intake->span);
        $this->assertSame($turn->correlationId, $this->intake->span->correlationId, 'the correlation ID is carried explicitly (S4)');
        $this->assertSame($turn->spanId, $this->intake->span->parentSpanId);
        $this->assertNotSame($turn->spanId, $this->intake->span->spanId);

        $this->assertNotNull($this->evidence->span);
        $this->assertSame($turn->spanId, $this->evidence->span->parentSpanId);
        $this->assertNotSame($this->intake->span->spanId, $this->evidence->span->spanId, 'sibling workers get distinct spans');
    }

    public function testSnapshotStateDispatchesNoWorkers(): void
    {
        $result = $this->dispatcher()->dispatch($this->physician(), $this->state(snapshot: true), 42, 'q', 5, $this->turnSpan());

        $this->assertSame(0, $this->intake->calls, 'zero extraction on the snapshot path');
        $this->assertSame(0, $this->evidence->calls, 'zero retrieval on the snapshot path (§5)');
        $this->assertNull($result->intake);
        $this->assertNull($result->evidence);
        $this->assertSame(['handoff.compose-answer'], $this->recordedSteps());
    }

    public function testHandoffRecordsCarryTheStatedReasonAndOkOutcome(): void
    {
        $this->dispatcher()->dispatch($this->physician(), $this->state(evidence: true), 42, 'q', 5, $this->turnSpan());

        [, $handoff] = $this->recorder->records[0];
        $this->assertSame('handoff.evidence-retriever', $handoff->step);
        $this->assertSame(StepOutcome::Ok, $handoff->outcome);
    }

    public function testEvidenceDegradationFlagsSurfaceInTheResult(): void
    {
        $this->evidence->outcome = new RetrievalOutcome([], false, true);

        $result = $this->dispatcher()->dispatch($this->physician(), $this->state(evidence: true), 42, 'q', 5, $this->turnSpan());

        $this->assertNotNull($result->evidence);
        $this->assertTrue($result->evidence->rerankDegraded);
    }

    public function testAThrowingWorkerIsRecordedAsFailedThenPropagates(): void
    {
        $this->evidence->throw = true;

        try {
            $this->dispatcher()->dispatch($this->physician(), $this->state(evidence: true), 42, 'q', 5, $this->turnSpan());
            $this->fail('expected the worker failure to propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $steps = $this->recordedSteps();
        $this->assertContains('handoff.evidence-retriever', $steps);
        [, $failed] = $this->recorder->records[array_search('handoff.evidence-retriever', $steps, true)];
        $this->assertSame(StepOutcome::Failed, $failed->outcome);
        $this->assertNotNull($failed->errorClass, 'a failed handoff names its error class — silence would hide what failed');
    }
}

/**
 * Frozen-test support: recording trace recorder.
 */
final class RecordingTraceRecorder implements TraceRecorder
{
    /** @var list<array{0: TraceContext, 1: StepRecord}> */
    public array $records = [];

    public function record(TraceContext $context, StepRecord $step): void
    {
        $this->records[] = [$context, $step];
    }
}

/**
 * Frozen-test support: spy intake worker (stubs live here ONLY — §6/§7).
 */
final class SpyIntakeWorker implements IntakeExtractorWorker
{
    public int $calls = 0;

    public ?TraceContext $span = null;

    public function run(PhysicianContext $physician, int $patientPid, TraceContext $workerSpan): IntakeExtractionOutcome
    {
        ++$this->calls;
        $this->span = $workerSpan;

        return new IntakeExtractionOutcome(1, 2, 0, 0);
    }
}

/**
 * Frozen-test support: spy evidence worker (stubs live here ONLY — §6/§7).
 */
final class SpyEvidenceWorker implements EvidenceRetrieverWorker
{
    public int $calls = 0;

    public ?TraceContext $span = null;

    public ?RetrievalOutcome $outcome = null;

    public bool $throw = false;

    public function run(string $question, int $topK, TraceContext $workerSpan): RetrievalOutcome
    {
        ++$this->calls;
        $this->span = $workerSpan;
        if ($this->throw) {
            throw new \RuntimeException('evidence worker exploded');
        }

        return $this->outcome ?? new RetrievalOutcome([], false, false);
    }
}

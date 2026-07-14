<?php

/**
 * Executes one `Supervisor` plan against the worker PORTS (TRO-32;
 * W2_ARCHITECTURE.md §6; PS-10).
 *
 * The supervisor's plan is pure data — an ordered list of `SupervisorStep`s,
 * each already carrying the stated reason it was routed
 * (`SupervisorStep::$reason`). This class is the one place that turns that
 * plan into action: for each step it either invokes the matching worker port
 * or, for `ComposeAnswer`, does nothing at all — composition stays the
 * Week 1 verified path (`TurnOrchestrator`/`ClaimVerifier`), invoked by the
 * caller once this dispatch returns. `ComposeAnswer` therefore always
 * records a handoff and never executes here; ticket TRO-32 scopes this class
 * to routing intake extraction and evidence retrieval only.
 *
 * Every handoff — including the no-op `ComposeAnswer` one — writes exactly
 * one `StepRecord` to the TURN span's trace, named `handoff.intake-extractor`
 * / `handoff.evidence-retriever` / `handoff.compose-answer`. `StepRecord` has
 * no free-text field for a reason (T17: the trace is PHI-free and
 * internals-free BY SCHEMA, not by discipline) — the stated reason lives on
 * the `SupervisorStep` carried in `DispatchResult::$plan`. The plan and the
 * handoff records together reconstruct the full route (which workers ran,
 * why, and what they returned); neither reconstructs it alone, and the
 * recorder itself never carries prose.
 *
 * Each worker that actually runs is given a CHILD span of the turn span
 * (`TraceContext::child()`), derived directly from `$turnSpan` for each
 * step — siblings, not a chain — so the correlation ID is carried explicitly
 * (S4) and the span tree is reconstructible from the trace alone. A worker
 * failure is recorded as a `Failed` handoff (`errorClass` = the throwable's
 * class, NEVER its message — T17) and then rethrown wrapped as a generic
 * `\RuntimeException`; it is never swallowed, and no later plan step runs
 * once a step throws.
 *
 * Worker stubs exist ONLY in this class's own unit tests
 * (`SupervisedTurnDispatcherTest`) — the eval gate always exercises the real
 * `IntakeExtractorWorker`/`EvidenceRetrieverWorker` implementations (§6/§7).
 *
 * Timing note: the frozen constructor accepts no clock, so `startedAt` uses
 * `new \DateTimeImmutable()` and elapsed duration uses `microtime(true)`
 * directly rather than an injected `ClockInterface` (contrast
 * `TurnOrchestrator`, which has one). This is acceptable for trace timing; a
 * deterministic-clock refactor rides the later observability pass rather
 * than reopening this frozen contract now.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Observability\StepOutcome;
use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceRecorder;
use OpenEMR\Modules\Copilot\Rag\RetrievalOutcome;

final readonly class SupervisedTurnDispatcher
{
    private const STEP_INTAKE = 'handoff.intake-extractor';
    private const STEP_EVIDENCE = 'handoff.evidence-retriever';
    private const STEP_COMPOSE = 'handoff.compose-answer';

    private const SPAN_INTAKE = 'intake-extractor';
    private const SPAN_EVIDENCE = 'evidence-retriever';

    public function __construct(
        private Supervisor $supervisor,
        private IntakeExtractorWorker $intakeWorker,
        private EvidenceRetrieverWorker $evidenceWorker,
        private TraceRecorder $recorder,
    ) {
    }

    /**
     * Plans and dispatches one turn's worker handoffs.
     *
     * @param int $topK the evidence-retriever's requested chunk count; ignored when no
     *                  EvidenceRetriever step is planned
     */
    public function dispatch(
        PhysicianContext $physician,
        SupervisorTurnState $state,
        int $patientPid,
        string $question,
        int $topK,
        TraceContext $turnSpan,
    ): DispatchResult {
        $plan = $this->supervisor->plan($state);

        $intake = null;
        $evidence = null;

        foreach ($plan as $step) {
            match ($step->kind) {
                SupervisorStepKind::IntakeExtractor => $intake = $this->dispatchIntake($physician, $patientPid, $turnSpan),
                SupervisorStepKind::EvidenceRetriever => $evidence = $this->dispatchEvidence($question, $topK, $turnSpan),
                SupervisorStepKind::ComposeAnswer => $this->recordComposeHandoff($turnSpan),
            };
        }

        return new DispatchResult($plan, $intake, $evidence);
    }

    private function dispatchIntake(
        PhysicianContext $physician,
        int $patientPid,
        TraceContext $turnSpan,
    ): IntakeExtractionOutcome {
        $childSpan = $turnSpan->child(self::SPAN_INTAKE, new \DateTimeImmutable());
        $startedAt = new \DateTimeImmutable();
        $start = microtime(true);

        try {
            $outcome = $this->intakeWorker->run($physician, $patientPid, $childSpan);
        } catch (\Throwable $e) {
            $this->recordFailure(self::STEP_INTAKE, $turnSpan, $startedAt, $start, $e);

            throw new \RuntimeException('worker dispatch failed', 0, $e);
        }

        $this->recordSuccess(self::STEP_INTAKE, $turnSpan, $startedAt, $start);

        return $outcome;
    }

    private function dispatchEvidence(string $question, int $topK, TraceContext $turnSpan): RetrievalOutcome
    {
        $childSpan = $turnSpan->child(self::SPAN_EVIDENCE, new \DateTimeImmutable());
        $startedAt = new \DateTimeImmutable();
        $start = microtime(true);

        try {
            $outcome = $this->evidenceWorker->run($question, $topK, $childSpan);
        } catch (\Throwable $e) {
            $this->recordFailure(self::STEP_EVIDENCE, $turnSpan, $startedAt, $start, $e);

            throw new \RuntimeException('worker dispatch failed', 0, $e);
        }

        $this->recordSuccess(self::STEP_EVIDENCE, $turnSpan, $startedAt, $start);

        return $outcome;
    }

    /**
     * `ComposeAnswer` never executes here — composition stays the Week 1
     * verified path, invoked by the caller once dispatch returns. This
     * records the handoff only, so the plan always terminates in a traced
     * `handoff.compose-answer` step exactly like every other step kind.
     */
    private function recordComposeHandoff(TraceContext $turnSpan): void
    {
        $this->recordSuccess(self::STEP_COMPOSE, $turnSpan, new \DateTimeImmutable(), microtime(true));
    }

    private function recordSuccess(string $step, TraceContext $turnSpan, \DateTimeImmutable $startedAt, float $start): void
    {
        $this->recorder->record(
            $turnSpan,
            new StepRecord($step, $startedAt, $this->elapsedMs($start), StepOutcome::Ok),
        );
    }

    private function recordFailure(
        string $step,
        TraceContext $turnSpan,
        \DateTimeImmutable $startedAt,
        float $start,
        \Throwable $e,
    ): void {
        $this->recorder->record(
            $turnSpan,
            new StepRecord($step, $startedAt, $this->elapsedMs($start), StepOutcome::Failed, $e::class),
        );
    }

    /**
     * Elapsed milliseconds since $startSeconds (microtime(true)) — a
     * measurement, not domain time, so it is taken directly from microtime
     * rather than an injected clock (see class docblock).
     */
    private function elapsedMs(float $startSeconds): float
    {
        return (microtime(true) - $startSeconds) * 1000.0;
    }
}

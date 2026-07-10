<?php

/**
 * Per-turn orchestrator: one grounded conversational turn (T12; UC2;
 * ARCHITECTURE.md §2 ORCH, §3.5; R6/R10/R11/R13; AUDIT C1/C5/D10).
 *
 * Every turn re-grounds against the live chart: the provider is called on
 * every runTurn(), never cached (§3.5) — stale context is a wrong answer
 * waiting to happen. The deterministic critical subset
 * (CriticalSubsetDetectors) bypasses the model entirely; its findings reach
 * the TurnResult whatever the model says, including when the model fails
 * (R13). Every LLM crossing is a minimum-necessary DisclosedPayload whose
 * disclosure is recorded BEFORE the model is called: a crash mid-send must
 * leave a logged crossing, never an unlogged one (C1). Model failure
 * degrades honestly — findings intact, answer absent, a generic reason that
 * never includes the exception's internals (R11); only
 * LlmUnavailableException is caught here, so configuration errors
 * (\DomainException) propagate rather than being silently absorbed as a
 * degraded turn. Model output is untrusted draft prose until ClaimVerifier
 * grounds it against the same ReferenceIndex the payload's citation tokens
 * were minted from — one mint, one index.
 *
 * Observability (T17; ARCHITECTURE.md §6; AUDIT S4/C4/C5; founder decision
 * 5, 2026-07-09): this orchestrator is the single choke point where a
 * correlation ID is minted, once per turn, and from there carried EXPLICITLY
 * through value objects and ports — never an ambient global or static,
 * which would reproduce S4 (auth hinging on a mutable global). Every port
 * call is wrapped in a StepRecord, failures and the degraded path included —
 * a failed tool traces, it never vanishes. The trace this emits is PHI-free
 * by schema; the disclosure log recorded below is the separate,
 * PHI-carrying record. The correlation ID is the ONLY join key between them,
 * so a full turn is reconstructible from logs alone.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Llm\ChartDataFlattener;
use OpenEMR\Modules\Copilot\Llm\CopilotTask;
use OpenEMR\Modules\Copilot\Llm\DisclosedPayload;
use OpenEMR\Modules\Copilot\Llm\LlmClient;
use OpenEMR\Modules\Copilot\Llm\LlmTurnRequest;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
use OpenEMR\Modules\Copilot\Observability\NullTraceRecorder;
use OpenEMR\Modules\Copilot\Observability\StepOutcome;
use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceRecorder;
use OpenEMR\Modules\Copilot\Verification\CitationIndex;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;
use Psr\Clock\ClockInterface;

final class TurnOrchestrator
{
    /**
     * Generic, user-facing degradation reason. Deliberately carries no
     * exception internals (no SQL, no stack traces, no vendor error text) —
     * see the class docblock and LlmUnavailableException.
     */
    private const DEGRADED_REASON = 'The assistant is temporarily unavailable. The findings below are unaffected; please try again shortly.';

    /**
     * Not promoted: PHP forbids `new` in a promoted property's default
     * value, so the optional TraceRecorder is accepted as a nullable
     * constructor parameter and resolved into this property in the body.
     */
    private readonly TraceRecorder $traceRecorder;

    public function __construct(
        private readonly ChartSnapshotProvider $provider,
        private readonly CriticalSubsetDetectors $detectors,
        private readonly ChartDataFlattener $flattener,
        private readonly MinimumNecessaryPayloadBuilder $payloadBuilder,
        private readonly CopilotTask $task,
        private readonly DisclosureLogger $disclosureLogger,
        private readonly LlmClient $llm,
        private readonly ClaimVerifier $verifier,
        private readonly ClockInterface $clock,
        ?TraceRecorder $traceRecorder = null,
    ) {
        $this->traceRecorder = $traceRecorder ?? new NullTraceRecorder();
    }

    /**
     * @param list<string> $priorTurns prior Q/A turns — phrasing context only, never a fact source (§3.5)
     */
    public function runTurn(
        PhysicianContext $physician,
        string $patientUuid,
        string $question,
        array $priorTurns = [],
    ): TurnResult {
        // Mint once, at the choke point, and carry explicitly from here on
        // (T17; S4).
        $context = TraceContext::start($this->task->value, $this->clock->now());

        // (a) Every turn reads fresh — never cached (§3.5).
        $provided = $this->runStep(
            $context,
            'retrieve',
            fn (): ProvidedChart => $this->provider->provide($physician, $patientUuid),
        );

        // (b) The critical subset bypasses the model entirely (R13).
        $today = $this->clock->now();
        $reports = $this->runStep(
            $context,
            'detect',
            fn (): array => $this->detectors->detectAll($provided->chart, $today),
        );

        $mustNotMiss = [];
        $unevaluable = [];
        foreach ($reports as $report) {
            $mustNotMiss = [...$mustNotMiss, ...$report->findings];
            $unevaluable = [...$unevaluable, ...$report->unevaluable];
        }

        // Citation labels for the wire, from the same freshly read chart the
        // tokens are minted against — carried on the result so every chip
        // (findings and grounded claims alike) can name its record. Built now
        // so it survives the degraded path below (R6/R10).
        $citations = CitationIndex::fromChart($provided->chart);

        // (c) Minimum-necessary payload, built from the freshly read chart only.
        $payload = $this->runStep(
            $context,
            'build_payload',
            fn (): DisclosedPayload => $this->payloadBuilder->build(
                $this->task,
                $this->flattener->flatten($provided->chart),
                $physician->username,
                $provided->patient->pid,
                $today,
                $context->correlationId,
            ),
        );

        // (d) Construct BEFORE logging: a blank question throws here, before
        // anything is logged, sent, or traced — construction is not a
        // traced step.
        $request = new LlmTurnRequest($payload, $question, $priorTurns, $context->correlationId);

        // (e) Log THEN send: a crash mid-send must leave a logged crossing,
        // never an unlogged one (C1).
        $this->runStep(
            $context,
            'disclose',
            function () use ($payload): void {
                $this->disclosureLogger->record($payload->disclosure);
            },
        );

        // (f) The llm step is special: only LlmUnavailableException is
        // caught and degraded honestly; anything else propagates. No
        // 'ground' step is recorded on this path — grounding never happened.
        $llmStartedAt = $this->clock->now();
        $llmStart = hrtime(true);
        try {
            $response = $this->llm->complete($request);
        } catch (LlmUnavailableException) {
            $this->traceRecorder->record(
                $context,
                new StepRecord(
                    'llm',
                    $llmStartedAt,
                    $this->elapsedMs($llmStart),
                    StepOutcome::Failed,
                    LlmUnavailableException::class,
                ),
            );

            // Degrade honestly: findings intact, answer absent, a generic
            // reason — never the exception's internals (R11).
            return new TurnResult(
                mustNotMiss: $mustNotMiss,
                unevaluable: $unevaluable,
                answer: null,
                degraded: true,
                degradedReason: self::DEGRADED_REASON,
                disclosure: $payload->disclosure,
                citations: $citations,
                correlationId: $context->correlationId,
            );
        }

        $this->traceRecorder->record(
            $context,
            new StepRecord(
                'llm',
                $llmStartedAt,
                $this->elapsedMs($llmStart),
                StepOutcome::Ok,
                tokenUsage: $response->tokenUsage,
            ),
        );

        // (g) The model's output is untrusted draft prose until grounded
        // against the same reference index the payload's citation tokens
        // were minted from. The ground step is hand-rolled (like llm) so the
        // verifier's verdict COUNTS ride on the trace (T19) — counts only,
        // never claim content.
        $groundStartedAt = $this->clock->now();
        $groundStart = hrtime(true);
        try {
            $answer = $this->verifier->verify($response->claims, ReferenceIndex::fromChart($provided->chart));
        } catch (\Throwable $e) {
            $this->traceRecorder->record(
                $context,
                new StepRecord('ground', $groundStartedAt, $this->elapsedMs($groundStart), StepOutcome::Failed, $e::class),
            );

            throw $e;
        }

        $this->traceRecorder->record(
            $context,
            new StepRecord(
                'ground',
                $groundStartedAt,
                $this->elapsedMs($groundStart),
                StepOutcome::Ok,
                groundedCount: count($answer->grounded),
                rejectedCount: count($answer->rejected),
            ),
        );

        // (h)
        return new TurnResult(
            mustNotMiss: $mustNotMiss,
            unevaluable: $unevaluable,
            answer: $answer,
            degraded: false,
            degradedReason: null,
            disclosure: $payload->disclosure,
            citations: $citations,
            correlationId: $context->correlationId,
        );
    }

    /**
     * Runs one port call under a traced StepRecord: an Ok record on success,
     * a Failed record (errorClass = the throwable's class, never its
     * message) on failure — then rethrows. A crash mid-turn still leaves
     * every prior step traced (TraceRecorder is per-step, not per-turn).
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function runStep(TraceContext $context, string $step, \Closure $work): mixed
    {
        $startedAt = $this->clock->now();
        $start = hrtime(true);

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->traceRecorder->record(
                $context,
                new StepRecord($step, $startedAt, $this->elapsedMs($start), StepOutcome::Failed, $e::class),
            );

            throw $e;
        }

        $this->traceRecorder->record(
            $context,
            new StepRecord($step, $startedAt, $this->elapsedMs($start), StepOutcome::Ok),
        );

        return $result;
    }

    /**
     * Elapsed milliseconds since $startNanoseconds (hrtime(true)) — a
     * measurement, not domain time, so it is taken from hrtime rather than
     * the injected clock.
     */
    private function elapsedMs(int $startNanoseconds): float
    {
        return (hrtime(true) - $startNanoseconds) / 1_000_000.0;
    }
}

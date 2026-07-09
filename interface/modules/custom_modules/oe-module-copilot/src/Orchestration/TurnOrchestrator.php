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
use OpenEMR\Modules\Copilot\Llm\LlmClient;
use OpenEMR\Modules\Copilot\Llm\LlmTurnRequest;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
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
    ) {
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
        // (a) Every turn reads fresh — never cached (§3.5).
        $provided = $this->provider->provide($physician, $patientUuid);

        // (b) The critical subset bypasses the model entirely (R13).
        $today = $this->clock->now();
        $reports = $this->detectors->detectAll($provided->chart, $today);

        $mustNotMiss = [];
        $unevaluable = [];
        foreach ($reports as $report) {
            $mustNotMiss = [...$mustNotMiss, ...$report->findings];
            $unevaluable = [...$unevaluable, ...$report->unevaluable];
        }

        // (c) Minimum-necessary payload, built from the freshly read chart only.
        $payload = $this->payloadBuilder->build(
            $this->task,
            $this->flattener->flatten($provided->chart),
            $physician->username,
            $provided->patient->pid,
            $today,
        );

        // (d) Construct BEFORE logging: a blank question throws here, before
        // anything is logged or sent.
        $request = new LlmTurnRequest($payload, $question, $priorTurns);

        // (e) Log THEN send: a crash mid-send must leave a logged crossing,
        // never an unlogged one (C1).
        $this->disclosureLogger->record($payload->disclosure);

        try {
            $response = $this->llm->complete($request);
        } catch (LlmUnavailableException) {
            // (f) Degrade honestly: findings intact, answer absent, a
            // generic reason — never the exception's internals (R11).
            return new TurnResult(
                mustNotMiss: $mustNotMiss,
                unevaluable: $unevaluable,
                answer: null,
                degraded: true,
                degradedReason: self::DEGRADED_REASON,
                disclosure: $payload->disclosure,
            );
        }

        // (g) The model's output is untrusted draft prose until grounded
        // against the same reference index the payload's citation tokens
        // were minted from.
        $answer = $this->verifier->verify($response->claims, ReferenceIndex::fromChart($provided->chart));

        // (h)
        return new TurnResult(
            mustNotMiss: $mustNotMiss,
            unevaluable: $unevaluable,
            answer: $answer,
            degraded: false,
            degradedReason: null,
            disclosure: $payload->disclosure,
        );
    }
}

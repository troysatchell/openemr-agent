<?php

/**
 * The turn endpoint's wire contract (T20; UC2; R5/R6/R10/R11; AUDIT S5;
 * ARCHITECTURE.md §3.4).
 *
 * Thin shaping layer over TurnOrchestrator::runTurn(): parses raw route
 * input into typed arguments (blank patient_uuid/question refused BEFORE the
 * orchestrator runs — cheap validation never touches the chart or the model),
 * then translates the resulting TurnResult into the exact array this class
 * documents below. This array IS the panel's wire contract — the
 * preserve-distrust UX is only as honest as this shape, so every field here
 * is deliberate:
 *
 *   [
 *     'correlation_id'  => ?string,          // trace join key (T17)
 *     'degraded'        => bool,
 *     'degraded_reason' => ?string,           // set iff degraded
 *     'must_not_miss'   => list<array{type: string, summary: string, refs: list<string>}>,
 *     'unevaluable'     => list<array{reason: string, refs: list<string>}>,
 *     'answer'          => null | array{
 *                            grounded: list<array{text: string, refs: list<string>}>,
 *                            rejected: list<array{text: string}>,
 *                          },
 *   ]
 *
 * must_not_miss and unevaluable are the deterministic critical subset
 * (R13): they carry resolvable citation tokens and survive whatever the
 * model does, including model failure — a degraded turn still ships them.
 * answer is null exactly when the turn is degraded (TurnResult enforces this
 * invariant at construction). Grounded claims carry text plus the tokens
 * that survived verification; REJECTED claims carry text ONLY — an invented
 * citation is never forwarded to the UI as if it were provenance (R6/R10).
 *
 * Every 'refs' entry is a citation object {token, kind, label}: the token is
 * minted via ReferenceIndex::tokenFor() — the ONE canonical mint, so it
 * resolves in the same index the verifier grounded against — and the kind +
 * label are added by CitationIndex from the same chart, giving the physician
 * a readable, clickable citation without inventing anything (R6/R10).
 *
 * This route registers no ACL/authorization on its own: that is the module's
 * GuardedRouteRegistrar's job at the RestApiCreateEvent wiring layer (S5).
 * This class is a pure shaping layer, deliberately without route or ACL
 * knowledge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Routes;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\UnevaluableItem;
use OpenEMR\Modules\Copilot\Orchestration\TurnOrchestrator;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\CitationIndex;
use OpenEMR\Modules\Copilot\Verification\VerifiedAnswer;

final readonly class TurnEndpoint
{
    public function __construct(private TurnOrchestrator $orchestrator)
    {
    }

    /**
     * @param array<string, mixed> $input Raw route input:
     *        'patient_uuid' (string), 'question' (string), optional
     *        'prior_turns' (list<string>).
     *
     * @return array{
     *     correlation_id: ?string,
     *     degraded: bool,
     *     degraded_reason: ?string,
     *     must_not_miss: list<array{type: string, summary: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     unevaluable: list<array{reason: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     answer: null|array{
     *         grounded: list<array{text: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *         rejected: list<array{text: string}>,
     *     },
     * }
     *
     * @throws \DomainException when patient_uuid/question is blank/missing,
     *         or prior_turns is present but not a list<string> — refused
     *         before the orchestrator (and therefore the chart read and the
     *         LLM) ever runs.
     */
    public function handle(PhysicianContext $physician, array $input): array
    {
        $patientUuid = $this->requireNonBlankString($input, 'patient_uuid');
        $question = $this->requireNonBlankString($input, 'question');
        $priorTurns = $this->extractPriorTurns($input);

        $result = $this->orchestrator->runTurn($physician, $patientUuid, $question, $priorTurns);

        return [
            'correlation_id' => $result->correlationId,
            'degraded' => $result->degraded,
            'degraded_reason' => $result->degradedReason,
            'must_not_miss' => $this->shapeFindings($result->mustNotMiss, $result->citations),
            'unevaluable' => $this->shapeUnevaluable($result->unevaluable, $result->citations),
            'answer' => $result->answer === null ? null : $this->shapeAnswer($result->answer, $result->citations),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireNonBlankString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException(sprintf('"%s" must be a non-blank string', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    private function extractPriorTurns(array $input): array
    {
        if (!array_key_exists('prior_turns', $input)) {
            return [];
        }

        $priorTurns = $input['prior_turns'];
        if (!is_array($priorTurns) || !array_is_list($priorTurns)) {
            throw new \DomainException('"prior_turns" must be a list<string>');
        }

        $result = [];
        foreach ($priorTurns as $turn) {
            if (!is_string($turn)) {
                throw new \DomainException('"prior_turns" must be a list<string>: found a non-string entry');
            }
            $result[] = $turn;
        }

        return $result;
    }

    /**
     * @param list<CriticalFinding> $findings
     *
     * @return list<array{type: string, summary: string, refs: list<array{token: string, kind: string, label: string|null}>}>
     */
    private function shapeFindings(array $findings, CitationIndex $citations): array
    {
        $shaped = [];
        foreach ($findings as $finding) {
            $shaped[] = [
                'type' => $finding->type->name,
                'summary' => $finding->summary,
                'refs' => $this->citationsFor($finding->sources, $citations),
            ];
        }

        return $shaped;
    }

    /**
     * @param list<UnevaluableItem> $items
     *
     * @return list<array{reason: string, refs: list<array{token: string, kind: string, label: string|null}>}>
     */
    private function shapeUnevaluable(array $items, CitationIndex $citations): array
    {
        $shaped = [];
        foreach ($items as $item) {
            $shaped[] = [
                'reason' => $item->reason,
                'refs' => $this->citationsFor($item->sources, $citations),
            ];
        }

        return $shaped;
    }

    /**
     * @return array{
     *     grounded: list<array{text: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     rejected: list<array{text: string}>,
     * }
     */
    private function shapeAnswer(VerifiedAnswer $answer, CitationIndex $citations): array
    {
        $grounded = [];
        foreach ($answer->grounded as $claim) {
            $grounded[] = [
                'text' => $claim->text,
                'refs' => $this->citationsFor($claim->sources, $citations),
            ];
        }

        // Rejected claims carry text ONLY — never the claimed sourceIds: an
        // invented citation must not reach the UI as if it were provenance
        // (R6/R10).
        $rejected = [];
        foreach ($answer->rejected as $claim) {
            $rejected[] = ['text' => $claim->text];
        }

        return ['grounded' => $grounded, 'rejected' => $rejected];
    }

    /**
     * Each citation carries the exact grounding token (provenance fidelity)
     * plus the humanized kind and record label the panel renders (R6/R10).
     *
     * @param list<SourceRef> $sources
     *
     * @return list<array{token: string, kind: string, label: string|null}>
     */
    private function citationsFor(array $sources, CitationIndex $citations): array
    {
        return array_map(
            static fn (SourceRef $source): array => $citations->describe($source),
            $sources,
        );
    }
}

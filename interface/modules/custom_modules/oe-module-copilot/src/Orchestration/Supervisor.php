<?php

/**
 * Supervisor: a small explicit state machine over typed turn state — no
 * framework, no LangGraph-in-PHP (W2_ARCHITECTURE.md §6; PS-10).
 *
 * `plan()` is pure logic: given a `SupervisorTurnState`, it returns the
 * ordered list of `SupervisorStep`s a turn will run, each carrying the
 * stated reason it was routed. There is no live dependency here and no
 * worker invocation — workers are stubbed only in this class's own routing
 * unit tests, never in the eval gate. The graph this method encodes is
 * deliberately narrower and stronger than a general-purpose graph, per the
 * spec's own warning against graph-shaped optics:
 *
 * - **Snapshot turns compose only, unconditionally.** A snapshot/pre-chart
 *   turn (`isSnapshotTurn`) plans exactly `[ComposeAnswer]` regardless of any
 *   other flag — zero-RAG-on-snapshot (§5) has no exceptions, including the
 *   one conditional edge below. The snapshot renders critical findings from
 *   detector output alone; the 90-second thesis is never re-taxed.
 * - **IntakeExtractor runs first when a document is pending.** Extraction
 *   works on attached, unextracted documents only — it cannot re-enter once
 *   run, so this is a straight-line prefix, not a loop.
 * - **Exactly one conditional forward edge.** EvidenceRetriever joins the
 *   plan when, and only when, the physician's question asks for guideline
 *   evidence (UC7) OR a present critical finding has been engaged by the
 *   physician this turn (UC6→UC4). A critical finding alone — unengaged —
 *   never fires it; that is the difference between a flag rendering in a
 *   snapshot and a physician asking "why is this flagged, and what do we
 *   do?" on a turn whose budget tolerates retrieval.
 * - **ComposeAnswer always terminates the plan, exactly once.** Every plan
 *   this method returns ends in exactly one ComposeAnswer step — the graph
 *   is acyclic and terminates by construction; no step kind repeats and no
 *   other re-entry edge exists.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

final class Supervisor
{
    private const SNAPSHOT_REASON = 'Snapshot/pre-chart turn — zero-RAG-on-snapshot applies unconditionally, '
        . 'overriding every other flag (§5); the answer composes from detector output alone.';

    private const PENDING_DOCUMENT_REASON = 'A document is attached to this patient and not yet extracted — '
        . 'intake-extractor must run before any answer can ground in it.';

    private const EVIDENCE_QUESTION_REASON = "The physician's question asks for guideline recommendation support "
        . '(UC7) — evidence-retriever fetches grounded, cited guideline snippets.';

    private const ENGAGED_FINDING_REASON = 'The physician engaged a present critical finding this turn (asked '
        . 'about it, or opened the flag) — the one conditional edge (PS-10; UC6→UC4) fetches its mapped response '
        . 'protocol.';

    private const COMPOSE_REASON = 'Enough grounded material is available for this turn — compose the final '
        . 'answer through the verified path.';

    /**
     * @return list<SupervisorStep>
     */
    public function plan(SupervisorTurnState $state): array
    {
        if ($state->isSnapshotTurn) {
            return [new SupervisorStep(SupervisorStepKind::ComposeAnswer, self::SNAPSHOT_REASON)];
        }

        $steps = [];

        if ($state->hasPendingUnextractedDocument) {
            $steps[] = new SupervisorStep(SupervisorStepKind::IntakeExtractor, self::PENDING_DOCUMENT_REASON);
        }

        if ($state->questionAsksForEvidence) {
            $steps[] = new SupervisorStep(SupervisorStepKind::EvidenceRetriever, self::EVIDENCE_QUESTION_REASON);
        } elseif ($state->criticalFindingPresent && $state->physicianEngagedCriticalFinding) {
            $steps[] = new SupervisorStep(SupervisorStepKind::EvidenceRetriever, self::ENGAGED_FINDING_REASON);
        }

        $steps[] = new SupervisorStep(SupervisorStepKind::ComposeAnswer, self::COMPOSE_REASON);

        return $steps;
    }
}

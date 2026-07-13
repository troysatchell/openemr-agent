<?php

/**
 * FROZEN acceptance tests — TRO-30: supervisor routing over typed states (W2_ARCHITECTURE §6; PS-10).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the Supervisor is pure logic over a typed turn state —
 * no live dependency, no worker invocation. plan() returns a finite ordered
 * list of SupervisorStep, each carrying a stated non-blank reason (routing
 * decisions are data, not vibes), always terminating in exactly one
 * ComposeAnswer as the last step (acyclic, terminates by construction).
 * Zero-RAG-on-snapshot (§5): a snapshot turn plans [ComposeAnswer] only —
 * never extraction, never retrieval — regardless of flags. The ONE
 * conditional edge (PS-10): EvidenceRetriever joins the plan on an
 * engagement turn (critical finding present AND physician engaged it), or on
 * an explicit evidence question (UC7); a critical finding alone never fires
 * it. A state claiming engagement without a present critical finding is a
 * contradiction and refuses to exist.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Orchestration\Supervisor;
use OpenEMR\Modules\Copilot\Orchestration\SupervisorStep;
use OpenEMR\Modules\Copilot\Orchestration\SupervisorStepKind;
use OpenEMR\Modules\Copilot\Orchestration\SupervisorTurnState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupervisorPlanTest extends TestCase
{
    private function state(
        bool $isSnapshotTurn = false,
        bool $hasPendingUnextractedDocument = false,
        bool $questionAsksForEvidence = false,
        bool $criticalFindingPresent = false,
        bool $physicianEngagedCriticalFinding = false,
    ): SupervisorTurnState {
        return new SupervisorTurnState(
            $isSnapshotTurn,
            $hasPendingUnextractedDocument,
            $questionAsksForEvidence,
            $criticalFindingPresent,
            $physicianEngagedCriticalFinding,
        );
    }

    /**
     * @param list<SupervisorStep> $plan
     *
     * @return list<SupervisorStepKind>
     */
    private function kinds(array $plan): array
    {
        return array_map(static fn (SupervisorStep $step): SupervisorStepKind => $step->kind, $plan);
    }

    public function testSnapshotTurnPlansComposeOnlyEvenUnderEveryFlag(): void
    {
        $plan = (new Supervisor())->plan($this->state(
            isSnapshotTurn: true,
            hasPendingUnextractedDocument: true,
            questionAsksForEvidence: true,
            criticalFindingPresent: true,
            physicianEngagedCriticalFinding: true,
        ));

        $this->assertSame([SupervisorStepKind::ComposeAnswer], $this->kinds($plan));
    }

    public function testPlainFollowUpPlansComposeOnly(): void
    {
        $plan = (new Supervisor())->plan($this->state());

        $this->assertSame([SupervisorStepKind::ComposeAnswer], $this->kinds($plan));
    }

    public function testPendingDocumentRoutesToIntakeExtractorFirst(): void
    {
        $plan = (new Supervisor())->plan($this->state(hasPendingUnextractedDocument: true));

        $this->assertSame(
            [SupervisorStepKind::IntakeExtractor, SupervisorStepKind::ComposeAnswer],
            $this->kinds($plan),
        );
    }

    public function testEvidenceQuestionRoutesToEvidenceRetriever(): void
    {
        $plan = (new Supervisor())->plan($this->state(questionAsksForEvidence: true));

        $this->assertSame(
            [SupervisorStepKind::EvidenceRetriever, SupervisorStepKind::ComposeAnswer],
            $this->kinds($plan),
        );
    }

    public function testEngagedCriticalFindingFiresTheConditionalEdge(): void
    {
        $plan = (new Supervisor())->plan($this->state(
            criticalFindingPresent: true,
            physicianEngagedCriticalFinding: true,
        ));

        $this->assertContains(SupervisorStepKind::EvidenceRetriever, $this->kinds($plan));
    }

    public function testUnengagedCriticalFindingNeverFiresTheEdge(): void
    {
        $plan = (new Supervisor())->plan($this->state(criticalFindingPresent: true));

        $this->assertSame([SupervisorStepKind::ComposeAnswer], $this->kinds($plan));
    }

    public function testExtractThenRetrieveThenComposeOrdering(): void
    {
        $plan = (new Supervisor())->plan($this->state(
            hasPendingUnextractedDocument: true,
            questionAsksForEvidence: true,
        ));

        $this->assertSame(
            [
                SupervisorStepKind::IntakeExtractor,
                SupervisorStepKind::EvidenceRetriever,
                SupervisorStepKind::ComposeAnswer,
            ],
            $this->kinds($plan),
        );
    }

    /**
     * @return array<string, array{bool, bool, bool, bool, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function stateMatrixProvider(): array
    {
        $cases = [];
        foreach ([false, true] as $snapshot) {
            foreach ([false, true] as $pendingDoc) {
                foreach ([false, true] as $evidence) {
                    foreach ([[false, false], [true, false], [true, true]] as [$critical, $engaged]) {
                        $key = sprintf(
                            'snapshot=%d doc=%d evidence=%d critical=%d engaged=%d',
                            $snapshot,
                            $pendingDoc,
                            $evidence,
                            $critical,
                            $engaged,
                        );
                        $cases[$key] = [$snapshot, $pendingDoc, $evidence, $critical, $engaged];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('stateMatrixProvider')]
    public function testEveryPlanTerminatesInExactlyOneComposeAnswerWithStatedReasons(
        bool $snapshot,
        bool $pendingDoc,
        bool $evidence,
        bool $critical,
        bool $engaged,
    ): void {
        $plan = (new Supervisor())->plan($this->state($snapshot, $pendingDoc, $evidence, $critical, $engaged));
        $kinds = $this->kinds($plan);

        $this->assertNotEmpty($plan);
        $this->assertSame(SupervisorStepKind::ComposeAnswer, $kinds[count($kinds) - 1], 'plans always end by composing');
        $this->assertCount(1, array_keys($kinds, SupervisorStepKind::ComposeAnswer, true), 'exactly one compose step');
        $this->assertSame($kinds, array_values(array_unique($kinds, SORT_REGULAR)), 'no step kind repeats — the graph is acyclic');

        foreach ($plan as $step) {
            $this->assertNotSame('', trim($step->reason), 'every routing decision states its reason (data, not vibes)');
        }
    }

    public function testEngagementWithoutAPresentCriticalFindingIsAContradiction(): void
    {
        $this->expectException(\DomainException::class);
        $this->state(criticalFindingPresent: false, physicianEngagedCriticalFinding: true);
    }

    public function testBlankStepReasonIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        new SupervisorStep(SupervisorStepKind::ComposeAnswer, '   ');
    }
}

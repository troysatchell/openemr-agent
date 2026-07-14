<?php

/**
 * FROZEN acceptance tests — Wave K.2 (TRO-44 live wiring, UC7): the
 * TurnOrchestrator evidence seam (W2_ARCHITECTURE.md §4/§5/§6; PS-14).
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Contract under test — a deliberate,
 * ADDITIVE reopening of the Week 1 turn contract, sanctioned 2026-07-14:
 * `runTurn()` gains an optional `?RetrievalOutcome $evidence = null` final
 * parameter. Everything Week 1 froze stays byte-identical when the
 * parameter is absent (every existing TurnOrchestratorTest passes
 * untouched); when a retrieval outcome is supplied by the caller (the
 * supervised dispatch composed in Bootstrap):
 *
 *  - the retrieved chunks enter the LLM payload as a `guideline_evidence`
 *    data class, subject to the task's FieldAllowlist like every other
 *    class, and the disclosure records that class — evidence crossing the
 *    LLM boundary is a disclosed crossing like any other (C1/C5);
 *  - the verification index becomes the union mint: chart refs PLUS the
 *    evidence chunks' SourceRefs (one mint, one index — §4 holds across
 *    source classes on the LIVE path, not just in the gate);
 *  - grounding still passes ONLY through THIS turn's evidence: a claim
 *    citing an unretrieved chunk stays rejected — no grounding through the
 *    corpus at large.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Orchestration;

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
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
use OpenEMR\Modules\Copilot\Orchestration\ChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Orchestration\ProvidedChart;
use OpenEMR\Modules\Copilot\Orchestration\TurnOrchestrator;
use OpenEMR\Modules\Copilot\Rag\RetrievalOutcome;
use OpenEMR\Modules\Copilot\Rag\RetrievedChunk;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\Modules\Copilot\Verification\DraftClaim;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Frozen-test support: collecting spy for the DisclosureLogger port (named
 * class with typed state, per this repo's spy convention).
 */
final class EvidenceTurnDisclosureSpy implements DisclosureLogger
{
    public ?Disclosure $last = null;

    public function record(Disclosure $disclosure): void
    {
        $this->last = $disclosure;
    }
}

class EvidenceAugmentedTurnTest extends TestCase
{
    private const GUIDELINE_TOKEN = 'guideline:protocol-htn-v1#htn.bp-target';
    private const CHART_TOKEN = 'lists:med-warf';
    private const UNRETRIEVED_TOKEN = 'guideline:protocol-t2dm-v1#t2dm.glycemic-target';

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('ellis.tran', 7);
    }

    private function providedChart(): ProvidedChart
    {
        $chart = (new ChartSnapshotSynthesizer())->synthesize(
            [new MedicationEntry('Warfarin 5mg Tablet', CurrencyStatus::Current, [new SourceRef('lists', 'med-warf')])],
            [],
            [],
        );

        return new ProvidedChart(new PatientDemographics(42, null, 'Alma', 'Reyes', '1961-03-14', 'F'), $chart);
    }

    private function provider(): ChartSnapshotProvider
    {
        $provided = $this->providedChart();

        return new class ($provided) implements ChartSnapshotProvider {
            public function __construct(private readonly ProvidedChart $chart)
            {
            }

            public function provide(PhysicianContext $physician, string $patientUuid): ProvidedChart
            {
                return $this->chart;
            }
        };
    }

    private function evidence(): RetrievalOutcome
    {
        return new RetrievalOutcome(
            [new RetrievedChunk(
                'htn.bp-target',
                'protocol-htn-v1',
                'Blood-pressure target',
                'The treatment target is <130/80 mm Hg for most adults on hypertension follow-up.',
                0.97,
            )],
            false,
            false,
        );
    }

    /**
     * @return LlmClient&object{captured: ?LlmTurnRequest}
     */
    private function capturingLlm(DraftClaim ...$claims): LlmClient
    {
        return new class (array_values($claims)) implements LlmClient {
            public ?LlmTurnRequest $captured = null;

            /** @param list<DraftClaim> $claims */
            public function __construct(private readonly array $claims)
            {
            }

            public function complete(LlmTurnRequest $request): LlmTurnResponse
            {
                $this->captured = $request;

                return new LlmTurnResponse($this->claims);
            }
        };
    }

    private function spyLogger(): EvidenceTurnDisclosureSpy
    {
        return new EvidenceTurnDisclosureSpy();
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-07-14 09:00:00', new \DateTimeZone('UTC'));
            }
        };
    }

    private function orchestrator(DisclosureLogger $logger, LlmClient $llm): TurnOrchestrator
    {
        return new TurnOrchestrator(
            $this->provider(),
            CriticalSubsetDetectors::withDraftTables(),
            new ChartDataFlattener(),
            new MinimumNecessaryPayloadBuilder([
                CopilotTask::FollowUpQa->value => new FieldAllowlist([
                    'medications' => ['name', 'status', 'ref'],
                    'lab_results' => ['analyte', 'value', 'unit', 'ref'],
                    'allergies' => ['substance', 'status', 'ref'],
                    'follow_ups' => ['description', 'due', 'ref'],
                    'guideline_evidence' => ['chunk', 'source', 'heading', 'snippet', 'ref'],
                ]),
            ]),
            CopilotTask::FollowUpQa,
            $logger,
            $llm,
            new ClaimVerifier(),
            $this->fixedClock(),
        );
    }

    public function testEvidenceGroundsThroughTheUnionMintAndIsDisclosed(): void
    {
        $logger = $this->spyLogger();
        $llm = $this->capturingLlm(
            new DraftClaim('The practice protocol sets a blood-pressure target for follow-up.', [self::GUIDELINE_TOKEN]),
            new DraftClaim('Warfarin 5mg is on the active medication list.', [self::CHART_TOKEN]),
        );

        $result = $this->orchestrator($logger, $llm)->runTurn(
            $this->physician(),
            'uuid-alma',
            'What BP target does our protocol recommend?',
            [],
            $this->evidence(),
        );

        $answer = $result->answer;
        $this->assertNotNull($answer);
        $this->assertCount(2, $answer->grounded, 'guideline and chart claims both ground through the one union mint');
        $this->assertSame([], $answer->rejected);

        $guidelineSources = $answer->grounded[0]->sources;
        $this->assertNotSame([], $guidelineSources);
        $this->assertSame('guideline', $guidelineSources[0]->sourceType);
        $this->assertSame('protocol-htn-v1', $guidelineSources[0]->sourceId);
        $this->assertSame('htn.bp-target', $guidelineSources[0]->fieldOrChunkId);

        $disclosure = $logger->last;
        $this->assertNotNull($disclosure, 'the crossing is disclosed before the model is called');
        $this->assertContains(
            'guideline_evidence',
            $disclosure->dataClasses,
            'evidence entering the LLM payload is a disclosed data class like any other (C1/C5)',
        );
    }

    public function testWithoutEvidenceGuidelineCitationsStayRejected(): void
    {
        $llm = $this->capturingLlm(
            new DraftClaim('The practice protocol sets a blood-pressure target for follow-up.', [self::GUIDELINE_TOKEN]),
        );

        $result = $this->orchestrator($this->spyLogger(), $llm)->runTurn(
            $this->physician(),
            'uuid-alma',
            'What BP target does our protocol recommend?',
        );

        $answer = $result->answer;
        $this->assertNotNull($answer);
        $this->assertSame([], $answer->grounded, 'Week 1 behavior unchanged: no evidence supplied, no guideline grounding');
        $this->assertCount(1, $answer->rejected);
    }

    public function testGroundingPassesOnlyThroughThisTurnsEvidence(): void
    {
        $llm = $this->capturingLlm(
            new DraftClaim('Our diabetes protocol sets an A1c target.', [self::UNRETRIEVED_TOKEN]),
        );

        $result = $this->orchestrator($this->spyLogger(), $llm)->runTurn(
            $this->physician(),
            'uuid-alma',
            'What A1c target do we use?',
            [],
            $this->evidence(),
        );

        $answer = $result->answer;
        $this->assertNotNull($answer);
        $this->assertSame([], $answer->grounded, 'an unretrieved chunk never grounds — no grounding through the corpus at large');
        $this->assertCount(1, $answer->rejected);
    }

    public function testEvidenceAbsentMeansNoGuidelineDataClassDisclosed(): void
    {
        $logger = $this->spyLogger();
        $llm = $this->capturingLlm();

        $this->orchestrator($logger, $llm)->runTurn($this->physician(), 'uuid-alma', 'Anything new?');

        $disclosure = $logger->last;
        $this->assertNotNull($disclosure);
        $this->assertNotContains(
            'guideline_evidence',
            $disclosure->dataClasses,
            'no evidence, no phantom disclosure class — the disclosure names exactly what crossed',
        );
    }
}

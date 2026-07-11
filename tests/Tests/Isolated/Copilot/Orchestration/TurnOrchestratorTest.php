<?php

/**
 * FROZEN acceptance tests — T12: per-turn orchestrator (UC2;
 * ARCHITECTURE.md §2 ORCH, §3.5; R6/R10/R11/R13; AUDIT C1/C5/D10).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: one grounded conversational turn. Every turn
 * re-grounds against the live chart (the provider is called per turn; prior
 * turns inform phrasing, never facts, and the model's own earlier output is
 * never a source). The deterministic critical subset bypasses the model
 * entirely — findings reach the TurnResult whatever the model says. Every
 * LLM crossing is a minimum-necessary DisclosedPayload whose disclosure is
 * recorded BEFORE the model is called (a crash mid-send must leave a logged
 * crossing, never an unlogged one — C1). Model failure degrades honestly:
 * findings intact, answer absent, a generic reason (never the exception
 * message), never a silent wrong answer (R11). Currency crosses via the one
 * canonical CurrencyWire mapper; NotCurrent rows never cross (D10); an
 * empty chart crosses as known-absent markers, not a refusal; citation
 * tokens in the payload are minted by the same ReferenceIndex the verifier
 * resolves against.
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
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Llm\ChartDataFlattener;
use OpenEMR\Modules\Copilot\Llm\CopilotTask;
use OpenEMR\Modules\Copilot\Llm\CurrencyWire;
use OpenEMR\Modules\Copilot\Llm\FieldAllowlist;
use OpenEMR\Modules\Copilot\Llm\LlmClient;
use OpenEMR\Modules\Copilot\Llm\LlmTurnRequest;
use OpenEMR\Modules\Copilot\Llm\LlmTurnResponse;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
use OpenEMR\Modules\Copilot\Orchestration\ChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Orchestration\ProvidedChart;
use OpenEMR\Modules\Copilot\Orchestration\TurnOrchestrator;
use OpenEMR\Modules\Copilot\Orchestration\TurnResult;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\CitationIndex;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\Modules\Copilot\Verification\DraftClaim;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;
use OpenEMR\Modules\Copilot\Verification\VerifiedAnswer;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class TurnOrchestratorTest extends TestCase
{
    private const QUESTION = 'What changed since the last visit?';

    /** @var list<string> */
    private array $sequence = [];

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('ellis.tran', 7);
    }

    private function providedChart(?ChartSnapshot $chart = null): ProvidedChart
    {
        return new ProvidedChart(
            new PatientDemographics(42, null, 'Alma', 'Reyes', '1961-03-14', 'F'),
            $chart ?? $this->richChart(),
        );
    }

    private function richChart(): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize(
            [
                new MedicationEntry('Warfarin 5mg Tablet', CurrencyStatus::Current, [new SourceRef('lists', 'med-warf')]),
                new MedicationEntry('Aspirin 81mg', CurrencyStatus::Current, [new SourceRef('lists', 'med-asa')]),
                new MedicationEntry('Old Statin 20mg', CurrencyStatus::NotCurrent, [new SourceRef('lists', 'med-old')]),
                new MedicationEntry('Metformin 500mg', CurrencyStatus::Unknown, [new SourceRef('lists', 'med-met')]),
            ],
            [new LabResultEntry(
                'Potassium',
                6.8,
                'mmol/L',
                new \DateTimeImmutable('2026-07-07 07:00:00'),
                [new SourceRef('procedure_result', 'lab-k')],
            )],
            [],
            [
                new FollowUpEntry('Repeat CBC', new \DateTimeImmutable('2026-06-30'), true, [new SourceRef('lists', 'fu-open')]),
                new FollowUpEntry('Colonoscopy done', new \DateTimeImmutable('2026-01-15'), false, [new SourceRef('lists', 'fu-closed')]),
            ],
        );
    }

    private function emptyChart(): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize([], [], []);
    }

    /**
     * @return object{provider: ChartSnapshotProvider, calls: callable(): int}
     */
    private function countingProvider(ProvidedChart $chart): object
    {
        return new class ($chart) implements ChartSnapshotProvider {
            public int $calls = 0;

            public function __construct(private readonly ProvidedChart $chart)
            {
            }

            public function provide(PhysicianContext $physician, string $patientUuid): ProvidedChart
            {
                $this->calls++;

                return $this->chart;
            }
        };
    }

    private function recordingLogger(): DisclosureLogger
    {
        $sequence = &$this->sequence;

        return new class ($sequence) implements DisclosureLogger {
            public ?Disclosure $last = null;

            /** @param list<string> $sequence */
            public function __construct(private array &$sequence)
            {
            }

            public function record(Disclosure $disclosure): void
            {
                $this->sequence[] = 'disclosure-logged';
                $this->last = $disclosure;
            }
        };
    }

    /**
     * @param list<DraftClaim> $claims
     */
    private function scriptedLlm(array $claims = [], bool $unavailable = false): LlmClient
    {
        $sequence = &$this->sequence;

        return new class ($claims, $unavailable, $sequence) implements LlmClient {
            public ?LlmTurnRequest $captured = null;

            /**
             * @param list<DraftClaim> $claims
             * @param list<string> $sequence
             */
            public function __construct(
                private readonly array $claims,
                private readonly bool $unavailable,
                private array &$sequence,
            ) {
            }

            public function complete(LlmTurnRequest $request): LlmTurnResponse
            {
                $this->sequence[] = 'llm-called';
                $this->captured = $request;

                if ($this->unavailable) {
                    throw new LlmUnavailableException('boom-internal-details');
                }

                return new LlmTurnResponse($this->claims);
            }
        };
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-07-08 21:30:00', new \DateTimeZone('UTC'));
            }
        };
    }

    private function builder(): MinimumNecessaryPayloadBuilder
    {
        return new MinimumNecessaryPayloadBuilder([
            CopilotTask::FollowUpQa->value => new FieldAllowlist([
                'medications' => ['name', 'status', 'ref'],
                'lab_results' => ['analyte', 'value', 'unit', 'ref'],
                'allergies' => ['substance', 'status', 'ref'],
                'follow_ups' => ['description', 'due', 'ref'],
            ]),
        ]);
    }

    private function orchestrator(
        ChartSnapshotProvider $provider,
        DisclosureLogger $logger,
        LlmClient $llm,
        ?MinimumNecessaryPayloadBuilder $builder = null,
    ): TurnOrchestrator {
        return new TurnOrchestrator(
            $provider,
            CriticalSubsetDetectors::withDraftTables(),
            new ChartDataFlattener(),
            $builder ?? $this->builder(),
            CopilotTask::FollowUpQa,
            $logger,
            $llm,
            new ClaimVerifier(),
            $this->fixedClock(),
        );
    }

    public function testEveryTurnRegroundsAgainstTheLiveChart(): void
    {
        $provider = $this->countingProvider($this->providedChart());
        $orchestrator = $this->orchestrator($provider, $this->recordingLogger(), $this->scriptedLlm());

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);
        $orchestrator->runTurn($this->physician(), 'uuid-1', 'And the potassium?');

        $this->assertSame(2, $provider->calls, 'Stale context is a wrong answer waiting to happen — every turn reads fresh (§3.5).');
    }

    public function testTheDisclosureIsLoggedBeforeTheModelIsCalled(): void
    {
        $logger = $this->recordingLogger();
        $orchestrator = $this->orchestrator($this->countingProvider($this->providedChart()), $logger, $this->scriptedLlm());

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertSame(
            ['disclosure-logged', 'llm-called'],
            $this->sequence,
            'Log THEN send: a crash mid-send must leave a logged crossing, never an unlogged one (C1).',
        );
        $this->assertNotNull($logger->last);
        $this->assertSame('ellis.tran', $logger->last->userId);
        $this->assertSame(42, $logger->last->patientPid, 'The disclosure names the patient by trusted pid (D7).');
        $this->assertSame(CopilotTask::FollowUpQa->value, $logger->last->purpose);
    }

    public function testDetectorFindingsBypassTheModelEntirely(): void
    {
        $orchestrator = $this->orchestrator(
            $this->countingProvider($this->providedChart()),
            $this->recordingLogger(),
            $this->scriptedLlm([]),
        );

        $result = $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $types = array_map(static fn ($f) => $f->type, $result->mustNotMiss);
        $this->assertContains(CriticalFindingType::PanicLab, $types, 'K 6.8 must surface whatever the model says (R13).');
        $this->assertContains(CriticalFindingType::DrugDrugInteraction, $types, 'warfarin+aspirin must surface whatever the model says (R13).');
        $this->assertContains(CriticalFindingType::OpenFollowUp, $types, 'The open loop must surface whatever the model says (R13).');
        $this->assertFalse($result->degraded, 'A quiet model is not a failure; the findings are code-guaranteed.');
    }

    public function testModelFailureDegradesHonestlyNeverSilently(): void
    {
        $orchestrator = $this->orchestrator(
            $this->countingProvider($this->providedChart()),
            $this->recordingLogger(),
            $this->scriptedLlm(unavailable: true),
        );

        $result = $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertTrue($result->degraded, 'Model down => degraded turn, never an exception to the UI and never silence (R11).');
        $this->assertNull($result->answer);
        $this->assertNotNull($result->degradedReason);
        $this->assertNotSame('', trim($result->degradedReason));
        $this->assertStringNotContainsString(
            'boom-internal-details',
            $result->degradedReason,
            'Exception internals never reach user-facing output.',
        );
        $this->assertNotSame([], $result->mustNotMiss, 'The critical subset survives model failure — that is the point of the bypass.');
        $this->assertNotNull($result->disclosure, 'The crossing was logged before the failed send; the record stands.');
    }

    public function testPriorTurnsInformPhrasingNeverFacts(): void
    {
        $llm = $this->scriptedLlm();
        $orchestrator = $this->orchestrator($this->countingProvider($this->providedChart()), $this->recordingLogger(), $llm);

        $orchestrator->runTurn(
            $this->physician(),
            'uuid-1',
            self::QUESTION,
            ['Q: Is she on anticoagulation? A: Yes, warfarin.'],
        );

        $this->assertNotNull($llm->captured);
        $this->assertSame(
            ['Q: Is she on anticoagulation? A: Yes, warfarin.'],
            $llm->captured->priorTurns,
            'Prior turns ride as phrasing context…',
        );
        $this->assertSame(self::QUESTION, $llm->captured->question);

        $payloadClasses = array_keys($llm->captured->payload->payload);
        sort($payloadClasses);
        $this->assertSame(
            ['follow_ups', 'lab_results', 'medications'],
            array_values(array_intersect($payloadClasses, ['follow_ups', 'lab_results', 'medications'])),
            '…while the payload is built from the freshly read chart only — the model\'s past output is never a source.',
        );
    }

    public function testFieldsBeyondTheAllowlistNeverReachTheModel(): void
    {
        $narrowBuilder = new MinimumNecessaryPayloadBuilder([
            CopilotTask::FollowUpQa->value => new FieldAllowlist([
                'medications' => ['name', 'ref'],
            ]),
        ]);
        $llm = $this->scriptedLlm();
        $orchestrator = $this->orchestrator(
            $this->countingProvider($this->providedChart()),
            $this->recordingLogger(),
            $llm,
            $narrowBuilder,
        );

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertNotNull($llm->captured);
        foreach ($llm->captured->payload->payload['medications'] as $entry) {
            $keys = array_keys($entry);
            sort($keys);
            $this->assertSame(['name', 'ref'], $keys, 'The allowlist, not the flattener, decides what crosses (C5).');
        }
    }

    public function testCurrencyCrossesAsCanonicalWireTokensAndNotCurrentNeverCrosses(): void
    {
        $llm = $this->scriptedLlm();
        $orchestrator = $this->orchestrator($this->countingProvider($this->providedChart()), $this->recordingLogger(), $llm);

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertNotNull($llm->captured);
        $meds = $llm->captured->payload->payload['medications'];
        $this->assertCount(3, $meds, 'Current + Unknown cross; NotCurrent is history, not orientation content (D10).');

        $statusByName = [];
        foreach ($meds as $entry) {
            $statusByName[$entry['name']] = $entry['status'];
        }
        $this->assertSame(CurrencyWire::CURRENT, $statusByName['Warfarin 5mg Tablet']);
        $this->assertSame(CurrencyWire::UNKNOWN, $statusByName['Metformin 500mg'], 'Unknown crosses as the one canonical token — never blank, never per-class.');
        $this->assertArrayNotHasKey('Old Statin 20mg', $statusByName);
    }

    public function testOnlyOpenFollowUpsCross(): void
    {
        $llm = $this->scriptedLlm();
        $orchestrator = $this->orchestrator($this->countingProvider($this->providedChart()), $this->recordingLogger(), $llm);

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertNotNull($llm->captured);
        $followUps = $llm->captured->payload->payload['follow_ups'];
        $this->assertCount(1, $followUps, 'A closed loop is not orientation content — minimum necessary (C5).');
        $this->assertSame('Repeat CBC', $followUps[0]['description']);
        $this->assertSame('2026-06-30', $followUps[0]['due']);
    }

    public function testPayloadCitationTokensResolveAgainstTheVerifierIndex(): void
    {
        $llm = $this->scriptedLlm();
        $chart = $this->richChart();
        $orchestrator = $this->orchestrator($this->countingProvider($this->providedChart($chart)), $this->recordingLogger(), $llm);

        $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertNotNull($llm->captured);
        $index = ReferenceIndex::fromChart($chart);
        foreach (['medications', 'lab_results', 'follow_ups'] as $class) {
            foreach ($llm->captured->payload->payload[$class] as $entry) {
                $this->assertNotNull(
                    $index->resolve($entry['ref']),
                    'One mint: every ref the model sees must resolve in the same index the verifier grounds against.',
                );
            }
        }
    }

    public function testUnattributableModelClaimsAreNotStatedAsFact(): void
    {
        $orchestrator = $this->orchestrator(
            $this->countingProvider($this->providedChart()),
            $this->recordingLogger(),
            $this->scriptedLlm([
                new DraftClaim('On warfarin.', ['lists:med-warf']),
                new DraftClaim('Cholesterol is well controlled.', ['lists:invented-source']),
            ]),
        );

        $result = $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertInstanceOf(VerifiedAnswer::class, $result->answer);
        $this->assertCount(1, $result->answer->grounded);
        $this->assertCount(1, $result->answer->rejected, 'The invented citation is surfaced as unverified, never shown as fact (R6/R10).');
    }

    public function testAnEmptyChartCrossesAsKnownAbsentMarkersNotARefusal(): void
    {
        $llm = $this->scriptedLlm();
        $logger = $this->recordingLogger();
        $orchestrator = $this->orchestrator(
            $this->countingProvider($this->providedChart($this->emptyChart())),
            $logger,
            $llm,
        );

        $result = $orchestrator->runTurn($this->physician(), 'uuid-1', self::QUESTION);

        $this->assertFalse($result->degraded, 'An assessed-empty chart is knowledge (NKDA-shaped), not an error.');
        $this->assertNotNull($llm->captured);
        $assessment = $llm->captured->payload->payload['chart_assessment'];
        $this->assertSame(CurrencyWire::KNOWN_ABSENT, $assessment['medications']);
        $this->assertSame(CurrencyWire::KNOWN_ABSENT, $assessment['allergies']);
        $this->assertSame(CurrencyWire::KNOWN_ABSENT, $assessment['follow_ups']);
        $this->assertNotNull($logger->last, 'Markers cross the boundary, so the crossing is disclosed (C1).');
    }

    public function testABlankQuestionIsRefusedBeforeAnythingIsLoggedOrSent(): void
    {
        $orchestrator = $this->orchestrator(
            $this->countingProvider($this->providedChart()),
            $this->recordingLogger(),
            $this->scriptedLlm(),
        );

        try {
            $orchestrator->runTurn($this->physician(), 'uuid-1', '   ');
            $this->fail('A blank question must be refused.');
        } catch (\DomainException) {
            $this->assertSame([], $this->sequence, 'Refusal happens before any disclosure is logged or any send attempted.');
        }
    }

    public function testTurnResultRefusesInconsistentConstruction(): void
    {
        $this->expectException(\DomainException::class);
        new TurnResult(
            mustNotMiss: [],
            unevaluable: [],
            answer: null,
            degraded: false,
            degradedReason: null,
            disclosure: null,
            citations: CitationIndex::fromChart(new ChartSnapshot([], [], [], [])),
        );
    }
}

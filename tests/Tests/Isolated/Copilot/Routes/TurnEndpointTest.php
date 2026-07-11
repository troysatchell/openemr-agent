<?php

/**
 * FROZEN acceptance tests — T20: the turn endpoint's JSON contract (UC2;
 * R5/R6/R10/R11; AUDIT S5; ARCHITECTURE.md §3.4).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the endpoint is the panel's wire contract — the
 * preserve-distrust UX is only as honest as this shape. Must-not-miss
 * findings carry their type, summary, and resolvable citation tokens;
 * unevaluable items carry their reason and tokens (honest uncertainty is
 * content, not an error); grounded claims carry text + the tokens that
 * survived verification; REJECTED claims carry text ONLY — an invented
 * citation is never forwarded to the UI as if it were provenance. A
 * degraded turn ships findings with answer null and a reason (never a
 * silent failure, never internals). Blank inputs are refused before the
 * orchestrator runs. The whole shape is json_encodable as-is.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Routes;

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
use OpenEMR\Modules\Copilot\Orchestration\ChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Orchestration\ProvidedChart;
use OpenEMR\Modules\Copilot\Orchestration\TurnOrchestrator;
use OpenEMR\Modules\Copilot\Routes\TurnEndpoint;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\Modules\Copilot\Verification\DraftClaim;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class TurnEndpointTest extends TestCase
{
    private const QUESTION = 'What changed since the last visit?';

    private function provider(): ChartSnapshotProvider
    {
        $chart = (new ChartSnapshotSynthesizer())->synthesize(
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
        $provided = new ProvidedChart(new PatientDemographics(42, null, 'Alma', 'Reyes', '1961-03-14', 'F'), $chart);

        return new class ($provided) implements ChartSnapshotProvider {
            public function __construct(private readonly ProvidedChart $provided)
            {
            }

            public function provide(PhysicianContext $physician, string $patientUuid): ProvidedChart
            {
                return $this->provided;
            }
        };
    }

    /**
     * @param list<DraftClaim> $claims
     */
    private function endpoint(array $claims = [], bool $unavailable = false): TurnEndpoint
    {
        $llm = new class ($claims, $unavailable) implements LlmClient {
            /**
             * @param list<DraftClaim> $claims
             */
            public function __construct(
                private readonly array $claims,
                private readonly bool $unavailable,
            ) {
            }

            public function complete(LlmTurnRequest $request): LlmTurnResponse
            {
                if ($this->unavailable) {
                    throw new LlmUnavailableException('vendor-internals');
                }

                return new LlmTurnResponse($this->claims);
            }
        };

        $logger = new class implements DisclosureLogger {
            public function record(Disclosure $disclosure): void
            {
            }
        };

        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-07-09 09:00:00', new \DateTimeZone('UTC'));
            }
        };

        $orchestrator = new TurnOrchestrator(
            $this->provider(),
            CriticalSubsetDetectors::withDraftTables(),
            new ChartDataFlattener(),
            new MinimumNecessaryPayloadBuilder([
                CopilotTask::FollowUpQa->value => new FieldAllowlist([
                    'medications' => ['name', 'status', 'ref'],
                    'lab_results' => ['analyte', 'value', 'unit', 'ref'],
                ]),
            ]),
            CopilotTask::FollowUpQa,
            $logger,
            $llm,
            new ClaimVerifier(),
            $clock,
        );

        return new TurnEndpoint($orchestrator);
    }

    private static function physician(): PhysicianContext
    {
        return new PhysicianContext('ellis.tran', 7);
    }

    public function testAGroundedTurnShapesTheFullPreserveDistrustContract(): void
    {
        $endpoint = $this->endpoint([
            new DraftClaim('On warfarin.', ['lists:med-warf']),
            new DraftClaim('Cholesterol is well controlled.', ['lists:invented-source']),
        ]);

        $result = $endpoint->handle(self::physician(), [
            'patient_uuid' => 'uuid-1',
            'question' => self::QUESTION,
        ]);

        $this->assertIsString($result['correlation_id']);
        $this->assertNotSame('', trim($result['correlation_id']));
        $this->assertFalse($result['degraded']);
        $this->assertNull($result['degraded_reason']);

        $this->assertNotSame([], $result['must_not_miss'], 'K 6.8 and warfarin+aspirin must surface (R13).');
        foreach ($result['must_not_miss'] as $finding) {
            $this->assertIsString($finding['type']);
            $this->assertNotSame('', trim($finding['summary']));
            $this->assertIsArray($finding['refs']);
            $this->assertNotSame([], $finding['refs'], 'A finding without citations cannot be trusted at a glance.');
        }

        $this->assertIsArray($result['unevaluable']);

        $this->assertIsArray($result['answer']);
        $this->assertCount(1, $result['answer']['grounded']);
        $this->assertSame('On warfarin.', $result['answer']['grounded'][0]['text']);
        // A grounded claim's citation is the exact token PLUS the readable
        // kind + record label, labelled from the same chart (R6/R10).
        $this->assertSame(
            [['token' => 'lists:med-warf', 'kind' => 'Medication', 'label' => 'Warfarin 5mg Tablet']],
            $result['answer']['grounded'][0]['refs'],
        );
        $this->assertCount(1, $result['answer']['rejected']);
        $this->assertSame(
            ['text' => 'Cholesterol is well controlled.'],
            $result['answer']['rejected'][0],
            'A rejected claim carries text ONLY — an invented citation is never forwarded as if it were provenance (R6/R10).',
        );

        $this->assertNotFalse(json_encode($result), 'The endpoint shape is the wire contract — it must encode as-is.');
    }

    public function testADegradedTurnShipsFindingsWithAnHonestReason(): void
    {
        $result = $this->endpoint(unavailable: true)->handle(self::physician(), [
            'patient_uuid' => 'uuid-1',
            'question' => self::QUESTION,
        ]);

        $this->assertTrue($result['degraded']);
        $this->assertNull($result['answer']);
        $this->assertIsString($result['degraded_reason']);
        $this->assertNotSame('', trim($result['degraded_reason']));
        $this->assertStringNotContainsString('vendor-internals', $result['degraded_reason']);
        $this->assertNotSame([], $result['must_not_miss'], 'The critical subset survives model failure — that is the point of the bypass (R13/R11).');
    }

    public function testBlankInputsAreRefusedBeforeTheOrchestratorRuns(): void
    {
        $endpoint = $this->endpoint();

        try {
            $endpoint->handle(self::physician(), ['patient_uuid' => '  ', 'question' => self::QUESTION]);
            self::fail('A blank patient uuid must be refused.');
        } catch (\DomainException) {
        }

        $this->expectException(\DomainException::class);
        $endpoint->handle(self::physician(), ['patient_uuid' => 'uuid-1', 'question' => '   ']);
    }
}

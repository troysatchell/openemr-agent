<?php

/**
 * FROZEN acceptance tests — T21: the session panel's snapshot endpoint (UC1
 * "90-second re-orientation"; R5/R6/R10/R11/R13; AUDIT D1/D7/D9/D10;
 * ARCHITECTURE.md §4 session-bound delegation).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: patient uuid in, GlanceableSnapshot wire shape out,
 * mirroring TurnEndpoint's conventions — snake_case keys, finding types by
 * enum name, every citation token minted via ReferenceIndex::tokenFor(). The
 * endpoint composes the REAL detectors (CriticalSubsetDetectors — the
 * critical subset is deterministic code, never model salience, R13) and the
 * REAL SnapshotComposer over an injected ChartSnapshotProvider and
 * last-visit resolver. Honesty is structural: a blank patient_uuid is
 * refused before any read; with no last-visit date the basis is UNKNOWN and
 * no delta is claimed (D1) — never conflated with "no changes"; quiet is an
 * earned, computed state; unknown-currency rows get their own section, never
 * folded into current (D10). A failed chart read degrades into an explicit
 * error shape whose reason never echoes exception internals and whose
 * snapshot is null — a degraded response can never be mistaken for a quiet
 * chart. No LLM crossing happens on this path, so no disclosure is logged
 * here (the turn path keeps its own).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use OpenEMR\Modules\Copilot\Chart\FhirReadFailedException;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Orchestration\ChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Orchestration\ProvidedChart;
use OpenEMR\Modules\Copilot\Panel\SnapshotEndpoint;
use OpenEMR\Modules\Copilot\Snapshot\SnapshotComposer;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class SnapshotEndpointTest extends TestCase
{
    private const UUID = '9b7c3e1a-0000-4000-8000-000000000042';

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-07-09 12:00:00', new \DateTimeZone('UTC'));
            }
        };
    }

    private function patient(?string $uuid = 'uuid-alma'): PatientDemographics
    {
        return new PatientDemographics(42, $uuid, 'Alma', 'Reyes', '1961-03-14', 'F');
    }

    /**
     * A chart that fires nothing: one current med with no interaction
     * partner, no labs, no allergies, no follow-ups.
     */
    private function quietChart(): ChartSnapshot
    {
        return new ChartSnapshot(
            [new MedicationEntry('Lisinopril 10mg Tablet', CurrencyStatus::Current, [new SourceRef('lists', 'med-lis')])],
            [],
            [],
            [],
        );
    }

    /**
     * @param array{count?: int} $providerCalls records provide() invocations
     */
    private function provider(ProvidedChart $provided, array &$providerCalls = []): ChartSnapshotProvider
    {
        $providerCalls['count'] = 0;
        $fn = function (PhysicianContext $physician, string $patientUuid) use (&$providerCalls, $provided): ProvidedChart {
            ++$providerCalls['count'];

            return $provided;
        };

        return new class ($fn) implements ChartSnapshotProvider {
            public function __construct(private readonly \Closure $fn)
            {
            }

            public function provide(PhysicianContext $physician, string $patientUuid): ProvidedChart
            {
                return ($this->fn)($physician, $patientUuid);
            }
        };
    }

    /**
     * @param array{count?: int}   $providerCalls
     * @param array{0?: int}       $resolverArgs  records the pid the resolver saw
     */
    private function endpoint(
        ProvidedChart $provided,
        ?\DateTimeImmutable $lastVisit,
        array &$providerCalls = [],
        array &$resolverArgs = [],
        ?\Throwable $resolverThrows = null,
    ): SnapshotEndpoint {
        return new SnapshotEndpoint(
            $this->provider($provided, $providerCalls),
            CriticalSubsetDetectors::withDraftTables(),
            new SnapshotComposer(),
            function (int $pid) use (&$resolverArgs, $lastVisit, $resolverThrows): ?\DateTimeImmutable {
                $resolverArgs = [$pid];
                if ($resolverThrows !== null) {
                    throw $resolverThrows;
                }

                return $lastVisit;
            },
            $this->clock(),
        );
    }

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('dr.tran', 7);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function blankInputProvider(): array
    {
        return [
            'missing patient_uuid' => [[]],
            'empty patient_uuid' => [['patient_uuid' => '']],
            'whitespace patient_uuid' => [['patient_uuid' => '   ']],
            'non-string patient_uuid' => [['patient_uuid' => 42]],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('blankInputProvider')]
    public function testBlankPatientUuidIsRefusedBeforeAnyRead(array $input): void
    {
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(), $this->quietChart()),
            null,
            $providerCalls,
            $resolverArgs,
        );

        try {
            $endpoint->handle($this->physician(), $input);
            $this->fail('A blank patient_uuid must be refused');
        } catch (\DomainException) {
        }

        $this->assertSame(0, $providerCalls['count'], 'Refusal happens before the chart is read');
        $this->assertSame([], $resolverArgs, 'Refusal happens before the last-visit lookup');
    }

    public function testHappyPathWireShape(): void
    {
        $chart = new ChartSnapshot(
            [new MedicationEntry('Lisinopril 10mg Tablet', CurrencyStatus::Current, [new SourceRef('lists', 'med-lis')])],
            [new LabResultEntry(
                'Potassium',
                6.8,
                'mmol/L',
                new \DateTimeImmutable('2026-07-07 07:00:00', new \DateTimeZone('UTC')),
                [new SourceRef('procedure_result', 'lab-k')],
            )],
            [],
            [],
        );
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(), $chart),
            new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
            $providerCalls,
            $resolverArgs,
        );

        $result = $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);

        $this->assertSame(['degraded', 'degraded_reason', 'snapshot'], array_keys($result));
        $this->assertFalse($result['degraded']);
        $this->assertNull($result['degraded_reason']);

        $snapshot = $result['snapshot'];
        $this->assertIsArray($snapshot);
        $this->assertSame(
            [
                'patient',
                'quiet',
                'changes_basis',
                'must_not_miss',
                'unevaluable',
                'unknown_currency',
                'new_labs',
                'current_medications',
                'current_allergies',
            ],
            array_keys($snapshot),
        );

        $this->assertSame(
            [
                'pid' => 42,
                'uuid' => 'uuid-alma',
                'first_name' => 'Alma',
                'last_name' => 'Reyes',
                'dob' => '1961-03-14',
                'sex' => 'F',
            ],
            $snapshot['patient'],
        );

        $this->assertFalse($snapshot['quiet']);
        $this->assertSame('since_last_visit', $snapshot['changes_basis']);

        $this->assertCount(1, $snapshot['must_not_miss'], 'K 6.8 breaches the draft high bound — deterministic, not model salience (R13)');
        $finding = $snapshot['must_not_miss'][0];
        $this->assertSame(['type', 'summary', 'refs'], array_keys($finding));
        $this->assertSame('PanicLab', $finding['type']);
        $this->assertStringContainsString('Potassium', $finding['summary']);
        // A citation is the exact grounding token PLUS the humanized kind and
        // the record's own label — readable provenance, never invented (R6/R10).
        $this->assertSame(
            [['token' => 'procedure_result:lab-k', 'kind' => 'Lab', 'label' => 'Potassium']],
            $finding['refs'],
        );

        $this->assertSame([], $snapshot['unevaluable']);
        $this->assertSame([], $snapshot['unknown_currency']);

        $this->assertSame(
            [
                [
                    'analyte' => 'Potassium',
                    'value' => 6.8,
                    'unit' => 'mmol/L',
                    'resulted_at' => '2026-07-07T07:00:00+00:00',
                    'refs' => [['token' => 'procedure_result:lab-k', 'kind' => 'Lab', 'label' => 'Potassium']],
                ],
            ],
            $snapshot['new_labs'],
        );

        $this->assertSame(
            [[
                'name' => 'Lisinopril 10mg Tablet',
                'refs' => [['token' => 'lists:med-lis', 'kind' => 'Medication', 'label' => 'Lisinopril 10mg Tablet']],
            ]],
            $snapshot['current_medications'],
        );
        $this->assertSame([], $snapshot['current_allergies']);

        $this->assertSame(1, $providerCalls['count'], 'Exactly one fresh read per request');
        $this->assertSame([42], $resolverArgs, 'The last-visit resolver keys off the trusted pid (D7)');
    }

    public function testQuietIsAnEarnedComputedState(): void
    {
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(), $this->quietChart()),
            new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
            $providerCalls,
            $resolverArgs,
        );

        $result = $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);

        $snapshot = $result['snapshot'];
        $this->assertTrue($snapshot['quiet']);
        $this->assertSame('since_last_visit', $snapshot['changes_basis']);
        $this->assertSame([], $snapshot['must_not_miss']);
        $this->assertSame([], $snapshot['new_labs']);
    }

    public function testNoLastVisitMeansUnknownBasisAndNeverQuiet(): void
    {
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(uuid: null), $this->quietChart()),
            null,
            $providerCalls,
            $resolverArgs,
        );

        $result = $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);

        $snapshot = $result['snapshot'];
        $this->assertSame('unknown_no_last_visit', $snapshot['changes_basis']);
        $this->assertSame([], $snapshot['new_labs'], 'No delta may be claimed without a basis (D1)');
        $this->assertFalse($snapshot['quiet'], 'An unknown delta can never be reported as quiet (D1)');
        $this->assertNull($snapshot['patient']['uuid'], 'A backfilled-null patient uuid stays honest on the wire (D7)');
    }

    public function testLabsBeforeTheLastVisitAreNotNew(): void
    {
        $chart = new ChartSnapshot(
            [new MedicationEntry('Lisinopril 10mg Tablet', CurrencyStatus::Current, [new SourceRef('lists', 'med-lis')])],
            [new LabResultEntry(
                'Vitamin D',
                31.0,
                'ng/mL',
                new \DateTimeImmutable('2026-06-01 09:00:00', new \DateTimeZone('UTC')),
                [new SourceRef('procedure_result', 'lab-d')],
            )],
            [],
            [],
        );
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(), $chart),
            new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
            $providerCalls,
            $resolverArgs,
        );

        $result = $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);

        $this->assertSame([], $result['snapshot']['new_labs']);
        $this->assertTrue($result['snapshot']['quiet']);
    }

    public function testUndatedLabIsUnevaluableNotSilentlySkipped(): void
    {
        $chart = new ChartSnapshot(
            [],
            [new LabResultEntry('Vitamin D', 31.0, 'ng/mL', null, [new SourceRef('procedure_result', 'lab-d')])],
            [],
            [],
        );
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(), $chart),
            new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
            $providerCalls,
            $resolverArgs,
        );

        $result = $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);

        $snapshot = $result['snapshot'];
        $this->assertSame([], $snapshot['new_labs']);
        $this->assertCount(1, $snapshot['unevaluable']);
        $item = $snapshot['unevaluable'][0];
        $this->assertSame(['reason', 'refs'], array_keys($item));
        $this->assertStringContainsString('Vitamin D', $item['reason']);
        $this->assertSame(
            [['token' => 'procedure_result:lab-d', 'kind' => 'Lab', 'label' => 'Vitamin D']],
            $item['refs'],
        );
        $this->assertFalse($snapshot['quiet'], 'An unevaluable item forfeits quiet (R5)');
    }

    public function testUnknownCurrencyRowsGetTheirOwnHonestSection(): void
    {
        $chart = new ChartSnapshot(
            [new MedicationEntry('Atorvastatin 20mg', CurrencyStatus::Unknown, [new SourceRef('lists', 'med-ator')])],
            [],
            [new AllergyEntry('Latex', CurrencyStatus::Unknown, [new SourceRef('lists', 'alg-latex')])],
            [],
        );
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(), $chart),
            new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
            $providerCalls,
            $resolverArgs,
        );

        $result = $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);

        $snapshot = $result['snapshot'];
        $this->assertSame(
            [
                [
                    'kind' => 'medication',
                    'name' => 'Atorvastatin 20mg',
                    'refs' => [['token' => 'lists:med-ator', 'kind' => 'Medication', 'label' => 'Atorvastatin 20mg']],
                ],
                [
                    'kind' => 'allergy',
                    'name' => 'Latex',
                    'refs' => [['token' => 'lists:alg-latex', 'kind' => 'Allergy', 'label' => 'Latex']],
                ],
            ],
            $snapshot['unknown_currency'],
        );
        $this->assertSame([], $snapshot['current_medications'], 'Unknown currency is never folded into current (D10)');
        $this->assertSame([], $snapshot['current_allergies']);
        $this->assertSame([], $snapshot['unevaluable']);
        $this->assertFalse($snapshot['quiet'], 'Unknown currency forfeits quiet (D10)');
    }

    public function testChartReadFailureDegradesHonestlyAndNeverFabricatesQuiet(): void
    {
        $failingProvider = new class implements ChartSnapshotProvider {
            public function provide(PhysicianContext $physician, string $patientUuid): ProvidedChart
            {
                throw new FhirReadFailedException('vendor-internals: SELECT secret FROM somewhere');
            }
        };
        $endpoint = new SnapshotEndpoint(
            $failingProvider,
            CriticalSubsetDetectors::withDraftTables(),
            new SnapshotComposer(),
            static fn (int $pid): ?\DateTimeImmutable => null,
            $this->clock(),
        );

        $result = $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);

        $this->assertSame(['degraded', 'degraded_reason', 'snapshot'], array_keys($result));
        $this->assertTrue($result['degraded']);
        $this->assertNull($result['snapshot'], 'A failed read yields NO snapshot — never a fabricated quiet one');
        $this->assertIsString($result['degraded_reason']);
        $this->assertNotSame('', trim($result['degraded_reason']));
        $this->assertStringNotContainsString(
            'vendor-internals',
            $result['degraded_reason'],
            'Exception internals never reach user-facing output (R11)',
        );
    }

    public function testResolverFailurePropagatesRatherThanSilentlyDegrading(): void
    {
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(), $this->quietChart()),
            null,
            $providerCalls,
            $resolverArgs,
            resolverThrows: new \RuntimeException('last-visit lookup failed'),
        );

        $this->expectException(\RuntimeException::class);
        $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);
    }

    public function testOutputIsJsonEncodableAsIs(): void
    {
        $chart = new ChartSnapshot(
            [new MedicationEntry('Lisinopril 10mg Tablet', CurrencyStatus::Current, [new SourceRef('lists', 'med-lis')])],
            [new LabResultEntry(
                'Potassium',
                6.8,
                'mmol/L',
                new \DateTimeImmutable('2026-07-07 07:00:00', new \DateTimeZone('UTC')),
                [new SourceRef('procedure_result', 'lab-k')],
            )],
            [],
            [],
        );
        $providerCalls = [];
        $resolverArgs = [];
        $endpoint = $this->endpoint(
            new ProvidedChart($this->patient(), $chart),
            new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
            $providerCalls,
            $resolverArgs,
        );

        $result = $endpoint->handle($this->physician(), ['patient_uuid' => self::UUID]);

        $this->assertNotFalse(json_encode($result));
    }
}

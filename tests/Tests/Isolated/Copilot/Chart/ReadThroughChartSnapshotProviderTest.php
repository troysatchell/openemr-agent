<?php

/**
 * FROZEN acceptance tests — T20: FHIR read-through ChartSnapshotProvider
 * (UC1/UC2 live path; AUDIT D0/D1/D4/D6/D7/D8/D9/D10; ARCHITECTURE.md §3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the live path is read (five FHIR sources, delegated
 * physician) → data-trust mapping → ONE synthesis pass → ProvidedChart.
 * The mapper consumes raw FHIR resources defensively: blank/missing is
 * unknown (D1), an unrecognizable status is Unknown — never guessed (D4/
 * D10), an unparseable date or non-numeric value makes the ROW unmappable
 * (D0/D6), and unmappable rows are COUNTED, never silently dropped and
 * never silently included. The trusted pid comes from the injected
 * uuid→pid resolver (the DB registry's job), never from FHIR content (D7);
 * zero or multiple Patient resources for one uuid fail loud (D8 — never
 * conflate). Every mapped entry mints a SourceRef whose token resolves in
 * the SAME ReferenceIndex the verifier grounds against — the live path
 * must be citable end-to-end or nothing the model says about it can ever
 * be shown as fact. Gateway failures propagate: a failed read is never
 * laundered into an empty chart.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Chart;

use OpenEMR\Modules\Copilot\Chart\ChartReader;
use OpenEMR\Modules\Copilot\Chart\FhirChartMapper;
use OpenEMR\Modules\Copilot\Chart\FhirReadFailedException;
use OpenEMR\Modules\Copilot\Chart\FhirReadGateway;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Chart\RawChartBundle;
use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Orchestration\ReadThroughChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;
use PHPUnit\Framework\TestCase;

class ReadThroughChartSnapshotProviderTest extends TestCase
{
    private const UUID = 'uuid-1';

    /**
     * @return array<string, mixed>
     */
    private static function patientResource(): array
    {
        return [
            'resourceType' => 'Patient',
            'id' => self::UUID,
            'name' => [['given' => ['Alma'], 'family' => 'Reyes']],
            'birthDate' => '1961-03-14',
            'gender' => 'female',
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byResourceType
     */
    private static function gateway(array $byResourceType): FhirReadGateway
    {
        return new class ($byResourceType) implements FhirReadGateway {
            /**
             * @param array<string, list<array<string, mixed>>> $byResourceType
             */
            public function __construct(private readonly array $byResourceType)
            {
            }

            public function read(PhysicianContext $physician, string $resourceType, array $searchParams): array
            {
                return $this->byResourceType[$resourceType] ?? [];
            }
        };
    }

    private static function provider(FhirReadGateway $gateway, int $pid = 42): ReadThroughChartSnapshotProvider
    {
        return new ReadThroughChartSnapshotProvider(
            new ChartReader($gateway),
            new FhirChartMapper(),
            new ChartSnapshotSynthesizer(),
            static fn (string $uuid): int => $pid,
        );
    }

    private static function physician(): PhysicianContext
    {
        return new PhysicianContext('ellis.tran', 7);
    }

    public function testProvidesAOnePassSnapshotWithTrustedPidAndDefensiveDemographics(): void
    {
        $provider = self::provider(self::gateway([
            'Patient' => [self::patientResource()],
            'MedicationRequest' => [
                ['resourceType' => 'MedicationRequest', 'id' => 'mr-1', 'status' => 'active', 'medicationCodeableConcept' => ['text' => 'Warfarin 5mg Tablet']],
                ['resourceType' => 'MedicationRequest', 'id' => 'mr-2', 'status' => 'stopped', 'medicationCodeableConcept' => ['coding' => [['display' => 'Old Statin 20mg']]]],
                ['resourceType' => 'MedicationRequest', 'id' => 'mr-3', 'status' => 'not-a-fhir-status', 'medicationCodeableConcept' => ['text' => 'Metformin 500mg']],
            ],
            'Observation' => [
                [
                    'resourceType' => 'Observation',
                    'id' => 'ob-1',
                    'category' => [['coding' => [['code' => 'laboratory']]]],
                    'code' => ['text' => 'Potassium'],
                    'valueQuantity' => ['value' => 6.8, 'unit' => 'mmol/L'],
                    'effectiveDateTime' => '2026-07-07T07:00:00+00:00',
                ],
            ],
            'AllergyIntolerance' => [
                ['resourceType' => 'AllergyIntolerance', 'id' => 'al-1', 'clinicalStatus' => ['coding' => [['code' => 'active']]], 'code' => ['text' => 'Penicillin']],
            ],
        ]), pid: 77);

        $provided = $provider->provide(self::physician(), self::UUID);

        $this->assertSame(77, $provided->patient->pid, 'pid comes from the injected uuid→pid resolver — the DB registry, never FHIR content (D7).');
        $this->assertSame(self::UUID, $provided->patient->uuid);
        $this->assertSame('Alma', $provided->patient->firstName);
        $this->assertSame('Reyes', $provided->patient->lastName);
        $this->assertSame('1961-03-14', $provided->patient->dob);
        $this->assertSame('female', $provided->patient->sex);

        $medsByName = [];
        foreach ($provided->chart->medications as $med) {
            $medsByName[$med->name] = $med->status;
        }
        $this->assertSame(CurrencyStatus::Current, $medsByName['Warfarin 5mg Tablet']);
        $this->assertSame(CurrencyStatus::NotCurrent, $medsByName['Old Statin 20mg'], 'History stays in the snapshot; the LLM boundary trims it (D10).');
        $this->assertSame(CurrencyStatus::Unknown, $medsByName['Metformin 500mg'], 'An unrecognizable status is Unknown — surfaced, never guessed (D4/D10).');

        $this->assertCount(1, $provided->chart->labs);
        $lab = $provided->chart->labs[0];
        $this->assertSame('Potassium', $lab->analyte);
        $this->assertSame(6.8, $lab->value);
        $this->assertSame('mmol/L', $lab->unit);

        $this->assertCount(1, $provided->chart->allergies);
        $this->assertSame('Penicillin', $provided->chart->allergies[0]->substance);
        $this->assertSame(CurrencyStatus::Current, $provided->chart->allergies[0]->status);
    }

    public function testEveryMappedEntryMintsATokenTheVerifierCanResolve(): void
    {
        $provider = self::provider(self::gateway([
            'Patient' => [self::patientResource()],
            'MedicationRequest' => [
                ['resourceType' => 'MedicationRequest', 'id' => 'mr-1', 'status' => 'active', 'medicationCodeableConcept' => ['text' => 'Warfarin 5mg Tablet']],
            ],
            'Observation' => [
                [
                    'resourceType' => 'Observation',
                    'id' => 'ob-1',
                    'category' => [['coding' => [['code' => 'laboratory']]]],
                    'code' => ['text' => 'Potassium'],
                    'valueQuantity' => ['value' => 6.8, 'unit' => 'mmol/L'],
                    'effectiveDateTime' => '2026-07-07T07:00:00+00:00',
                ],
            ],
        ]));

        $provided = $provider->provide(self::physician(), self::UUID);
        $index = ReferenceIndex::fromChart($provided->chart);

        $this->assertNotNull($index->resolve('MedicationRequest:mr-1'), 'One mint: a live-path entry the verifier cannot resolve can never be shown as fact.');
        $this->assertNotNull($index->resolve('Observation:ob-1'));
    }

    public function testZeroOrMultiplePatientResourcesFailLoud(): void
    {
        $none = self::provider(self::gateway(['Patient' => []]));
        try {
            $none->provide(self::physician(), self::UUID);
            self::fail('Zero Patient resources for the requested uuid must fail loud, not proceed chartless.');
        } catch (\DomainException) {
        }

        $two = self::provider(self::gateway([
            'Patient' => [self::patientResource(), self::patientResource()],
        ]));
        $this->expectException(\DomainException::class);
        $two->provide(self::physician(), self::UUID);
    }

    public function testGatewayFailurePropagatesNeverAnEmptyChart(): void
    {
        $failing = new class implements FhirReadGateway {
            public function read(PhysicianContext $physician, string $resourceType, array $searchParams): array
            {
                throw new FhirReadFailedException('read failed');
            }
        };

        $this->expectException(FhirReadFailedException::class);
        self::provider($failing)->provide(self::physician(), self::UUID);
    }

    // ── Mapper-direct: defensive data-trust rules ──────────────────────────

    public function testUnmappableRowsAreCountedNeverSilentlyDroppedOrIncluded(): void
    {
        $mapped = (new FhirChartMapper())->map(new RawChartBundle(
            patient: [self::patientResource()],
            medications: [
                ['resourceType' => 'MedicationRequest', 'id' => 'mr-1', 'status' => 'active', 'medicationCodeableConcept' => ['text' => 'Warfarin 5mg Tablet']],
                // No extractable name — unusable, and silence would hide it (D1).
                ['resourceType' => 'MedicationRequest', 'id' => 'mr-noname', 'status' => 'active'],
                // Blank name is missing, not a name (D1).
                ['resourceType' => 'MedicationRequest', 'id' => 'mr-blank', 'status' => 'active', 'medicationCodeableConcept' => ['text' => '   ']],
                // No id — an uncitable row can never be grounded (R6/R10).
                ['resourceType' => 'MedicationRequest', 'status' => 'active', 'medicationCodeableConcept' => ['text' => 'Ghost Med']],
            ],
            observations: [
                // Unparseable date: never guess a clinical date (D0/D6).
                [
                    'resourceType' => 'Observation',
                    'id' => 'ob-baddate',
                    'category' => [['coding' => [['code' => 'laboratory']]]],
                    'code' => ['text' => 'Sodium'],
                    'valueQuantity' => ['value' => 130.0, 'unit' => 'mmol/L'],
                    'effectiveDateTime' => 'not-a-date',
                ],
                // Non-numeric value: never coerce (D4's lesson generalized).
                [
                    'resourceType' => 'Observation',
                    'id' => 'ob-nonnum',
                    'category' => [['coding' => [['code' => 'laboratory']]]],
                    'code' => ['text' => 'Glucose'],
                    'valueQuantity' => ['value' => 'high', 'unit' => 'mg/dL'],
                    'effectiveDateTime' => '2026-07-07T07:00:00+00:00',
                ],
            ],
            allergies: [],
            problems: [],
        ));

        $this->assertCount(1, $mapped->medications, 'Only the fully-mappable med survives.');
        $this->assertSame('Warfarin 5mg Tablet', $mapped->medications[0]->name);
        $this->assertCount(0, $mapped->labs);
        $this->assertSame(5, $mapped->unmappableRowCount, 'Every unusable row is COUNTED — an honest number instead of a silent hole in the chart.');
    }

    public function testAbsentLabDateIsCarriedUndatedNeverDroppedAsUnmappable(): void
    {
        // An ABSENT date is a known unknown (D1): the row is otherwise fully
        // trustworthy, and LabResultEntry carries resultedAt=null exactly so
        // the composer can surface it as unevaluable against a last-visit
        // date (D0/D6) instead of leaving a silent hole. GARBAGE in the date
        // field is different — it poisons trust in the row, which stays
        // unmappable (the frozen contract above). OpenEMR's live FHIR layer
        // emits a data-absent-reason extension object for missing report
        // dates, so both absence shapes are pinned here.
        $mapped = (new FhirChartMapper())->map(new RawChartBundle(
            patient: [self::patientResource()],
            medications: [],
            observations: [
                [
                    'resourceType' => 'Observation',
                    'id' => 'ob-nodate',
                    'category' => [['coding' => [['code' => 'laboratory']]]],
                    'code' => ['text' => 'Vitamin D, 25-Hydroxy'],
                    'valueQuantity' => ['value' => 31.0, 'unit' => 'ng/mL'],
                ],
                [
                    'resourceType' => 'Observation',
                    'id' => 'ob-absentreason',
                    'category' => [['coding' => [['code' => 'laboratory']]]],
                    'code' => ['text' => 'TSH'],
                    'valueQuantity' => ['value' => 2.1, 'unit' => 'mIU/L'],
                    'effectiveDateTime' => ['extension' => [[
                        'url' => 'http://hl7.org/fhir/StructureDefinition/data-absent-reason',
                        'valueCode' => 'unknown',
                    ]]],
                ],
                [
                    'resourceType' => 'Observation',
                    'id' => 'ob-baddate',
                    'category' => [['coding' => [['code' => 'laboratory']]]],
                    'code' => ['text' => 'Sodium'],
                    'valueQuantity' => ['value' => 130.0, 'unit' => 'mmol/L'],
                    'effectiveDateTime' => 'not-a-date',
                ],
            ],
            allergies: [],
            problems: [],
        ));

        $this->assertCount(2, $mapped->labs, 'Undated-but-trustworthy rows are carried; only the garbage-date row is unusable.');
        $this->assertSame('Vitamin D, 25-Hydroxy', $mapped->labs[0]->analyte);
        $this->assertNull($mapped->labs[0]->resultedAt, 'Absence is carried as null — never guessed, never dropped (D0/D6).');
        $this->assertSame('TSH', $mapped->labs[1]->analyte);
        $this->assertNull($mapped->labs[1]->resultedAt);
        $this->assertSame(1, $mapped->unmappableRowCount, 'Only the unparseable-garbage date poisons its row.');
    }

    public function testNonLaboratoryObservationsAreOutOfScopeByDesignNotUnmappable(): void
    {
        $mapped = (new FhirChartMapper())->map(new RawChartBundle(
            patient: [self::patientResource()],
            medications: [],
            observations: [
                [
                    'resourceType' => 'Observation',
                    'id' => 'ob-vital',
                    'category' => [['coding' => [['code' => 'vital-signs']]]],
                    'code' => ['text' => 'Heart rate'],
                    'valueQuantity' => ['value' => 72, 'unit' => '/min'],
                    'effectiveDateTime' => '2026-07-07T07:00:00+00:00',
                ],
            ],
            allergies: [],
            problems: [],
        ));

        $this->assertCount(0, $mapped->labs);
        $this->assertSame(0, $mapped->unmappableRowCount, 'A vital sign is out of v1 scope (selection, not omission) — not a mapping failure.');
    }

    public function testAllergyStatusesMapDefensively(): void
    {
        $mapped = (new FhirChartMapper())->map(new RawChartBundle(
            patient: [self::patientResource()],
            medications: [],
            observations: [],
            allergies: [
                ['resourceType' => 'AllergyIntolerance', 'id' => 'al-1', 'clinicalStatus' => ['coding' => [['code' => 'active']]], 'code' => ['text' => 'Penicillin']],
                ['resourceType' => 'AllergyIntolerance', 'id' => 'al-2', 'clinicalStatus' => ['coding' => [['code' => 'resolved']]], 'code' => ['text' => 'Latex']],
                ['resourceType' => 'AllergyIntolerance', 'id' => 'al-3', 'code' => ['text' => 'Sulfa']],
            ],
            problems: [],
        ));

        $byName = [];
        foreach ($mapped->allergies as $allergy) {
            $byName[$allergy->substance] = $allergy->status;
        }
        $this->assertSame(CurrencyStatus::Current, $byName['Penicillin']);
        $this->assertSame(CurrencyStatus::NotCurrent, $byName['Latex']);
        $this->assertSame(CurrencyStatus::Unknown, $byName['Sulfa'], 'A missing clinical status is Unknown — never assumed active OR resolved.');
    }
}

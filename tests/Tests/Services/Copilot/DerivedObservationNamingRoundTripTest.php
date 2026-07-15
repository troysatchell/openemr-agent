<?php

/**
 * FROZEN acceptance tests — TRO-56: derived-lab naming round-trip (upload → snapshot naming).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract under test: analytes persisted by DerivedObservationWriter
 * (test name byte-verbatim in procedure_result.result_text, result_code
 * deliberately '' — no code exists on the extraction wire) must come back
 * through the module's FHIR read path (OpenEmrFhirServiceFactory →
 * OpenEmrFhirGateway → FhirChartMapper) carrying their extracted test names,
 * never the "unknown" null-flavor placeholder.
 *
 * The naming seam this pins: core FhirObservationLaboratoryService only emits
 * Observation.code when BOTH result_code and result_text are non-empty
 * (src/Services/FHIR/Observation/FhirObservationLaboratoryService.php:251),
 * so a codeless derived row loses its name entirely and the mapper's
 * codeableConceptText() falls back to the null-flavor coding display
 * "unknown". The fix must carry result_text across as CodeableConcept.text
 * on the module read path WITHOUT inventing a code the extraction never had:
 * the honest null-flavor UNK coding must remain (we know the name, not the
 * code system), and core's certified surface must not be edited to do it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Chart\FhirChartMapper;
use OpenEMR\Modules\Copilot\Chart\OpenEmrFhirGateway;
use OpenEMR\Modules\Copilot\Chart\OpenEmrFhirServiceFactory;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Chart\RawChartBundle;
use OpenEMR\Modules\Copilot\Extraction\ExtractedField;
use OpenEMR\Modules\Copilot\Extraction\ExtractionConfidence;
use OpenEMR\Modules\Copilot\Extraction\LabAnalyteExtraction;
use OpenEMR\Modules\Copilot\Extraction\LabPdfExtraction;
use OpenEMR\Modules\Copilot\Persistence\DerivedObservationWriter;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Services\FHIR\FhirCodeSystemConstants;
use OpenEMR\Services\FHIR\UtilsService;
use PHPUnit\Framework\TestCase;

class DerivedObservationNamingRoundTripTest extends TestCase
{
    /**
     * Byte-verbatim wire test names, deliberately including mixed case and
     * punctuation a lossy naming path would mangle.
     *
     * @var list<array{name: string, value: string, unit: string}>
     */
    private const ANALYTES = [
        ['name' => 'Potassium', 'value' => '6.8', 'unit' => 'mmol/L'],
        ['name' => 'Sodium', 'value' => '118', 'unit' => 'mEq/L'],
        ['name' => 'eGFR (CKD-EPI)', 'value' => '52', 'unit' => 'mL/min/1.73m2'],
    ];

    private int $pid = 0;

    private string $uuid = '';

    protected function setUp(): void
    {
        ExtractionLineageSchema::ensureInstalled();

        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $this->pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 3000;
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO patient_data (pid, pubpid, fname, lname, date, uuid) VALUES (?, ?, ?, ?, NOW(), UNHEX(REPLACE(UUID(),'-','')))",
            [$this->pid, 'copilot-tro56-' . $this->pid, 'Naming', 'RoundTrip'],
        );

        $uuidHex = QueryUtils::fetchSingleValue('SELECT LOWER(HEX(uuid)) AS u FROM patient_data WHERE pid = ?', 'u', [$this->pid]);
        if (!is_string($uuidHex) || strlen($uuidHex) !== 32) {
            $this->fail('could not read back the fixture patient uuid');
        }
        $this->uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($uuidHex, 0, 8),
            substr($uuidHex, 8, 4),
            substr($uuidHex, 12, 4),
            substr($uuidHex, 16, 4),
            substr($uuidHex, 20, 12),
        );
    }

    protected function tearDown(): void
    {
        $resultIds = QueryUtils::fetchTableColumn(
            'SELECT prr.procedure_result_id AS rid FROM procedure_result prr'
            . ' JOIN procedure_report pr ON prr.procedure_report_id = pr.procedure_report_id'
            . ' JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id'
            . ' WHERE po.patient_id = ?',
            'rid',
            [$this->pid],
        );
        foreach ($resultIds as $rid) {
            if (is_numeric($rid)) {
                QueryUtils::sqlStatementThrowException(
                    'DELETE FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' WHERE procedure_result_id = ?',
                    [(int) $rid],
                );
            }
        }
        QueryUtils::sqlStatementThrowException('DELETE prr FROM procedure_result prr JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$this->pid]);
        QueryUtils::sqlStatementThrowException('DELETE pr FROM procedure_report pr JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$this->pid]);
        QueryUtils::sqlStatementThrowException('DELETE poc FROM procedure_order_code poc JOIN procedure_order po ON poc.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$this->pid]);
        QueryUtils::sqlStatementThrowException('DELETE FROM procedure_order WHERE patient_id = ?', [$this->pid]);
        QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$this->pid]);
    }

    public function testExtractedAnalytesRoundTripUnderTheirWireTestNames(): void
    {
        $this->persistFixtureExtraction();

        $labs = $this->mappedLabs();

        $this->assertCount(count(self::ANALYTES), $labs, 'every persisted analyte must survive the FHIR read + mapping');

        $expectedNames = array_map(static fn (array $a): string => $a['name'], self::ANALYTES);
        $actualNames = array_map(static fn (LabResultEntry $lab): string => $lab->analyte, $labs);
        sort($expectedNames);
        sort($actualNames);

        $this->assertSame($expectedNames, $actualNames, 'analyte names must be byte-verbatim the wire test names (TRO-56 acceptance)');
    }

    public function testNoNamedAnalyteRendersAsTheUnknownPlaceholder(): void
    {
        $this->persistFixtureExtraction();

        foreach ($this->mappedLabs() as $lab) {
            $this->assertNotSame(
                'unknown',
                mb_strtolower(trim($lab->analyte), 'UTF-8'),
                'a named analyte must never surface as the null-flavor "unknown" placeholder (TRO-56)',
            );
        }
    }

    public function testTheReadPathNeverInventsACodeTheExtractionDidNotCarry(): void
    {
        $this->persistFixtureExtraction();

        $resources = $this->laboratoryResources();
        $this->assertCount(count(self::ANALYTES), $resources);

        foreach ($resources as $resource) {
            $code = $resource['code'] ?? null;
            $this->assertIsArray($code, 'Observation.code must be present');

            $coding = $code['coding'][0] ?? null;
            $this->assertIsArray($coding, 'the honest null-flavor coding must remain');
            $this->assertSame(
                UtilsService::UNKNOWNABLE_CODE_NULL_FLAVOR,
                $coding['code'] ?? null,
                'no code may be invented for a codeless derived row — the null-flavor UNK coding stays',
            );
            $this->assertSame(
                FhirCodeSystemConstants::HL7_NULL_FLAVOR,
                $coding['system'] ?? null,
                'the coding system must stay the HL7 null-flavor system, never a claimed LOINC',
            );

            $text = $code['text'] ?? null;
            $this->assertIsString($text, 'the recorded test name must cross as CodeableConcept.text');
            $this->assertContains(
                $text,
                array_map(static fn (array $a): string => $a['name'], self::ANALYTES),
                'CodeableConcept.text must be byte-verbatim one of the persisted wire test names',
            );
        }
    }

    private function persistFixtureExtraction(): void
    {
        $analytes = array_map(
            fn (array $a): LabAnalyteExtraction => $this->analyte($a['name'], $a['value'], $a['unit']),
            self::ANALYTES,
        );

        (new DerivedObservationWriter())->persist(
            new PhysicianContext('dr-tran', 1),
            $this->pid,
            new LabPdfExtraction('72', $analytes),
        );
    }

    /**
     * Reads the fixture patient's Observations through the module's real
     * production read path (the same factory + gateway ChartReader uses) and
     * returns only laboratory-category resources.
     *
     * @return list<array<string, mixed>>
     */
    private function laboratoryResources(): array
    {
        $gateway = new OpenEmrFhirGateway(new OpenEmrFhirServiceFactory());
        $resources = $gateway->read(new PhysicianContext('dr-tran', 1), 'Observation', ['patient' => $this->uuid]);

        return array_values(array_filter($resources, function (array $resource): bool {
            $categories = $resource['category'] ?? [];
            foreach (is_array($categories) ? $categories : [] as $category) {
                $codings = is_array($category) ? ($category['coding'] ?? []) : [];
                foreach (is_array($codings) ? $codings : [] as $coding) {
                    if (is_array($coding) && ($coding['code'] ?? null) === 'laboratory') {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * @return list<LabResultEntry>
     */
    private function mappedLabs(): array
    {
        $bundle = new RawChartBundle([], [], $this->laboratoryResources(), [], []);

        return (new FhirChartMapper())->map($bundle)->labs;
    }

    private function present(string $value, string $fieldPath): ExtractedField
    {
        return ExtractedField::present(
            $value,
            new ExtractionConfidence(0.9),
            new SourceRef('lab_pdf', '72', '2', $fieldPath, $value),
        );
    }

    private function analyte(string $name, string $value, string $unit): LabAnalyteExtraction
    {
        return new LabAnalyteExtraction(
            $this->present($name, 'analytes[].testName'),
            $this->present($value, 'analytes[].value'),
            $this->present($unit, 'analytes[].unit'),
            ExtractedField::absent(),
            ExtractedField::absent(),
            '2026-07-01',
        );
    }
}

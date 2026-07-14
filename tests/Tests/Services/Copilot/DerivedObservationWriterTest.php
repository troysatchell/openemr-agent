<?php

/**
 * FROZEN acceptance tests — TRO-20: the persistence spine (W2_ARCHITECTURE §2 step 5, §10; PS-4).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract under test: observation-shaped extracted facts persist
 * as native, stamped derived records via the chain
 * procedure_order → procedure_order_code → procedure_report →
 * procedure_result (the mechanism SP-1 locked and TRO-12's live smoke
 * round-tripped). Stamps: result_status='preliminary', document_id → the
 * source document (native derivedFrom), report.source = the delegated
 * physician, order.activity=1, seq-matched report/order_code. Extraction
 * lineage (extractor version, field path, page, confidence) lives in a
 * module-owned link table keyed by procedure_result_id — no core schema
 * edits. Only analytes with a present value persist; a panel with nothing
 * persistable refuses before any insert.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Extraction\ExtractedField;
use OpenEMR\Modules\Copilot\Extraction\ExtractionConfidence;
use OpenEMR\Modules\Copilot\Extraction\LabAnalyteExtraction;
use OpenEMR\Modules\Copilot\Extraction\LabPdfExtraction;
use OpenEMR\Modules\Copilot\Persistence\DerivedObservationWriter;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class DerivedObservationWriterTest extends TestCase
{
    private int $pid = 0;

    protected function setUp(): void
    {
        ExtractionLineageSchema::ensureInstalled();

        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $this->pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 2000;
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO patient_data (pid, pubpid, fname, lname, date) VALUES (?, ?, ?, ?, NOW())',
            [$this->pid, 'copilot-dow-' . $this->pid, 'Spine', 'Fixture'],
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

    private function present(string $value, string $fieldPath, float $confidence = 0.9): ExtractedField
    {
        return ExtractedField::present(
            $value,
            new ExtractionConfidence($confidence),
            new SourceRef('lab_pdf', '72', '2', $fieldPath, $value),
        );
    }

    private function analyte(string $name, string $value, string $unit, ?string $date = '2026-07-01'): LabAnalyteExtraction
    {
        return new LabAnalyteExtraction(
            $this->present($name, 'analytes[].testName'),
            $this->present($value, 'analytes[].value'),
            $this->present($unit, 'analytes[].unit'),
            ExtractedField::absent(),
            ExtractedField::absent(),
            $date,
        );
    }

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('dr-tran', 1);
    }

    public function testPersistCreatesTheStampedNativeChain(): void
    {
        $extraction = new LabPdfExtraction('72', [
            $this->analyte('Potassium', '6.8', 'mmol/L'),
            $this->analyte('Sodium', '118', 'mEq/L'),
        ]);

        $persisted = (new DerivedObservationWriter())->persist($this->physician(), $this->pid, $extraction);

        $this->assertGreaterThan(0, $persisted->procedureOrderId);
        $this->assertCount(2, $persisted->procedureResultIds);

        $order = QueryUtils::querySingleRow('SELECT patient_id, activity FROM procedure_order WHERE procedure_order_id = ?', [$persisted->procedureOrderId]);
        $this->assertIsArray($order);
        $this->assertEquals($this->pid, $order['patient_id']);
        $this->assertEquals(1, $order['activity']);

        $report = QueryUtils::querySingleRow('SELECT source, procedure_order_seq FROM procedure_report WHERE procedure_report_id = ?', [$persisted->procedureReportId]);
        $this->assertIsArray($report);
        $this->assertEquals(1, $report['source'], 'report.source stamps the delegated physician (SP-1)');

        $seqMatch = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM procedure_order_code WHERE procedure_order_id = ? AND procedure_order_seq = ?',
            'c',
            [$persisted->procedureOrderId, $report['procedure_order_seq']],
        );
        $this->assertEquals(1, $seqMatch, 'report seq must match an order_code row (the read-path join contract)');

        foreach ($persisted->procedureResultIds as $resultId) {
            $result = QueryUtils::querySingleRow('SELECT result_status, document_id FROM procedure_result WHERE procedure_result_id = ?', [$resultId]);
            $this->assertIsArray($result);
            $this->assertSame('preliminary', $result['result_status'], 'derived records are visibly provisional');
            $this->assertEquals(72, $result['document_id'], 'document_id is the native derivedFrom stamp');
        }
    }

    public function testResultRowsCarryTheAnalyteValues(): void
    {
        $extraction = new LabPdfExtraction('72', [$this->analyte('Potassium', '6.8', 'mmol/L')]);
        $persisted = (new DerivedObservationWriter())->persist($this->physician(), $this->pid, $extraction);

        $row = QueryUtils::querySingleRow(
            'SELECT result_text, result, units, date FROM procedure_result WHERE procedure_result_id = ?',
            [$persisted->procedureResultIds[0]],
        );
        $this->assertIsArray($row);
        $this->assertSame('Potassium', $row['result_text']);
        $this->assertSame('6.8', $row['result']);
        $this->assertSame('mmol/L', $row['units']);
        $this->assertIsString($row['date']);
        $this->assertStringStartsWith('2026-07-01', $row['date']);
    }

    public function testEveryResultGetsALineageRow(): void
    {
        $extraction = new LabPdfExtraction('72', [$this->analyte('Potassium', '6.8', 'mmol/L')]);
        $persisted = (new DerivedObservationWriter())->persist($this->physician(), $this->pid, $extraction);

        $lineage = QueryUtils::querySingleRow(
            'SELECT document_id, extractor_version, field_path, page, confidence FROM '
            . ExtractionLineageSchema::LINEAGE_TABLE . ' WHERE procedure_result_id = ?',
            [$persisted->procedureResultIds[0]],
        );
        $this->assertIsArray($lineage);
        $this->assertEquals(72, $lineage['document_id']);
        $this->assertSame(DerivedObservationWriter::EXTRACTOR_VERSION, $lineage['extractor_version']);
        $this->assertSame('analytes[].value', $lineage['field_path']);
        $this->assertSame('2', $lineage['page']);
        $this->assertEqualsWithDelta(0.9, (float) $lineage['confidence'], 0.0001);
    }

    public function testAnalytesWithoutAPresentValueAreSkippedNotInvented(): void
    {
        $noValue = new LabAnalyteExtraction(
            $this->present('Chloride', 'analytes[].testName'),
            ExtractedField::absent(),
            ExtractedField::absent(),
            ExtractedField::absent(),
            ExtractedField::absent(),
            null,
        );
        $extraction = new LabPdfExtraction('72', [$this->analyte('Potassium', '6.8', 'mmol/L'), $noValue]);

        $persisted = (new DerivedObservationWriter())->persist($this->physician(), $this->pid, $extraction);

        $this->assertCount(1, $persisted->procedureResultIds, 'an absent value persists nothing (D1: absent is absent)');
    }

    public function testNothingPersistableRefusesBeforeAnyInsert(): void
    {
        $noValue = new LabAnalyteExtraction(
            $this->present('Chloride', 'analytes[].testName'),
            ExtractedField::absent(),
            ExtractedField::absent(),
            ExtractedField::absent(),
            ExtractedField::absent(),
            null,
        );
        $extraction = new LabPdfExtraction('72', [$noValue]);

        try {
            (new DerivedObservationWriter())->persist($this->physician(), $this->pid, $extraction);
            $this->fail('expected a \DomainException refusal');
        } catch (\DomainException) {
            // expected
        }

        $orderCount = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM procedure_order WHERE patient_id = ?', 'c', [$this->pid]);
        $this->assertEquals(0, $orderCount, 'refusal must leave no partial chain');
    }

    public function testNonNumericDocumentIdIsRefused(): void
    {
        $extraction = new LabPdfExtraction('doc-abc', [$this->analyte('Potassium', '6.8', 'mmol/L')]);

        $this->expectException(\DomainException::class);
        (new DerivedObservationWriter())->persist($this->physician(), $this->pid, $extraction);
    }
}

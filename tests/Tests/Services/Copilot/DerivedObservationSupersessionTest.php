<?php

/**
 * FROZEN acceptance tests — TRO-22: one-directional supersession (PS-5; W2_ARCHITECTURE §2 step 5).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract under test: the derived record is the ONLY thing
 * supersession may ever suppress — and suppression is a MODULE-LAYER
 * annotation on the lineage table, never a mutation of any core row (ours or
 * real). A derived observation is superseded by a real one (a result with no
 * lineage row) matching patient + normalized analyte (case-insensitive) +
 * normalized unit + collection date within the tolerance window. An
 * ambiguous match (two real candidates) keeps BOTH and flags — a duplicate
 * is a provenance-distinguished annoyance, a wrong merge is data loss.
 * Impossible-by-construction: reconcile() writes only to the module lineage
 * table, so real observations cannot be suppressed by any code path.
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
use OpenEMR\Modules\Copilot\Persistence\DerivedObservationSupersession;
use OpenEMR\Modules\Copilot\Persistence\DerivedObservationWriter;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Persistence\SupersessionSchema;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class DerivedObservationSupersessionTest extends TestCase
{
    private int $pid = 0;

    protected function setUp(): void
    {
        SupersessionSchema::ensureInstalled();

        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $this->pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 4000;
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO patient_data (pid, pubpid, fname, lname, date) VALUES (?, ?, ?, ?, NOW())',
            [$this->pid, 'copilot-sup-' . $this->pid, 'Supersede', 'Fixture'],
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

    /**
     * A DERIVED observation: persisted through the module writer, so it
     * carries a lineage row.
     */
    private function derivedResultId(string $analyte = 'Potassium', string $unit = 'mmol/L', string $date = '2026-07-01'): int
    {
        $present = fn (string $v, string $p): ExtractedField => ExtractedField::present(
            $v,
            new ExtractionConfidence(0.9),
            new SourceRef('lab_pdf', '72', '2', $p, $v),
        );
        $extraction = new LabPdfExtraction('72', [new LabAnalyteExtraction(
            $present($analyte, 'analytes[].testName'),
            $present('6.8', 'analytes[].value'),
            $present($unit, 'analytes[].unit'),
            ExtractedField::absent(),
            ExtractedField::absent(),
            $date,
        )]);

        $persisted = (new DerivedObservationWriter())->persist(new PhysicianContext('dr-tran', 1), $this->pid, $extraction);

        return $persisted->procedureResultIds[0];
    }

    /**
     * A REAL observation: a native chain with NO lineage row (interface
     * feed / manual entry).
     */
    private function realResultId(string $analyte = 'Potassium', string $unit = 'mmol/L', string $date = '2026-07-01 08:00:00'): int
    {
        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO procedure_order (patient_id, provider_id, activity, date_ordered, date_collected, procedure_order_type, lab_id, order_status) VALUES (?, 1, 1, NOW(), ?, ?, 0, ?)',
            [$this->pid, $date, 'laboratory', 'complete'],
        );
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO procedure_order_code (procedure_order_id, procedure_order_seq, procedure_code, procedure_name, procedure_order_title, procedure_type, diagnoses) VALUES (?, 1, ?, ?, ?, ?, ?)',
            [$orderId, 'LAB-FEED', 'Interface lab feed', 'laboratory', 'laboratory', ''],
        );
        $reportId = QueryUtils::sqlInsert(
            'INSERT INTO procedure_report (procedure_order_id, procedure_order_seq, date_report, date_collected, source, report_status, review_status) VALUES (?, 1, NOW(), ?, 1, ?, ?)',
            [$orderId, $date, 'final', 'received'],
        );

        $resultId = QueryUtils::sqlInsert(
            'INSERT INTO procedure_result (procedure_report_id, result_code, result_text, result, units, `range`, abnormal, comments, result_status, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$reportId, '2823-3', $analyte, '6.9', $unit, '3.5-5.1', '', 'interface feed', 'final', $date],
        );

        return is_int($resultId) ? $resultId : (int) $resultId;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotResult(int $resultId): array
    {
        $row = QueryUtils::querySingleRow('SELECT * FROM procedure_result WHERE procedure_result_id = ?', [$resultId]);
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function lineageFor(int $resultId): array
    {
        $row = QueryUtils::querySingleRow(
            'SELECT superseded_by_result_id, superseded_at, ambiguous_flag FROM '
            . ExtractionLineageSchema::LINEAGE_TABLE . ' WHERE procedure_result_id = ?',
            [$resultId],
        );
        $this->assertIsArray($row);

        return $row;
    }

    public function testCleanSupersessionAnnotatesLineageAndMutatesNoCoreRow(): void
    {
        $derivedId = $this->derivedResultId();
        $realId = $this->realResultId();

        $derivedBefore = $this->snapshotResult($derivedId);
        $realBefore = $this->snapshotResult($realId);

        $report = (new DerivedObservationSupersession())->reconcile($this->pid);

        $this->assertSame([$derivedId], $report->supersededResultIds);
        $this->assertSame([], $report->ambiguousResultIds);

        $lineage = $this->lineageFor($derivedId);
        $this->assertEquals($realId, $lineage['superseded_by_result_id']);
        $this->assertNotNull($lineage['superseded_at']);
        $this->assertEquals(0, $lineage['ambiguous_flag']);

        $this->assertSame($derivedBefore, $this->snapshotResult($derivedId), 'suppression is a lineage annotation — the derived core row is untouched');
        $this->assertSame($realBefore, $this->snapshotResult($realId), 'a real observation is NEVER mutated by dedup (PS-5)');
    }

    public function testAmbiguousMatchKeepsBothAndFlags(): void
    {
        $derivedId = $this->derivedResultId();
        $realA = $this->realResultId(date: '2026-07-01 08:00:00');
        $realB = $this->realResultId(date: '2026-07-01 17:30:00');

        $report = (new DerivedObservationSupersession())->reconcile($this->pid);

        $this->assertSame([], $report->supersededResultIds, 'an ambiguous match never suppresses');
        $this->assertSame([$derivedId], $report->ambiguousResultIds);

        $lineage = $this->lineageFor($derivedId);
        $this->assertNull($lineage['superseded_at']);
        $this->assertEquals(1, $lineage['ambiguous_flag']);

        $this->assertNotEmpty($this->snapshotResult($realA));
        $this->assertNotEmpty($this->snapshotResult($realB));
    }

    public function testRealDrawOutsideTheCollectionWindowDoesNotSupersede(): void
    {
        $derivedId = $this->derivedResultId(date: '2026-07-01');
        $this->realResultId(date: '2026-07-06 08:00:00');

        $report = (new DerivedObservationSupersession())->reconcile($this->pid);

        $this->assertSame([], $report->supersededResultIds);
        $this->assertSame([], $report->ambiguousResultIds);
        $this->assertNull($this->lineageFor($derivedId)['superseded_at']);
    }

    public function testUnitMismatchDoesNotSupersede(): void
    {
        $derivedId = $this->derivedResultId(unit: 'mmol/L');
        $this->realResultId(unit: 'mg/dL');

        $report = (new DerivedObservationSupersession())->reconcile($this->pid);

        $this->assertSame([], $report->supersededResultIds);
        $this->assertNull($this->lineageFor($derivedId)['superseded_at']);
    }

    public function testAnalyteAndUnitMatchingIsCaseInsensitive(): void
    {
        $derivedId = $this->derivedResultId(analyte: 'Potassium', unit: 'mmol/L');
        $realId = $this->realResultId(analyte: 'POTASSIUM', unit: 'MMOL/L');

        $report = (new DerivedObservationSupersession())->reconcile($this->pid);

        $this->assertSame([$derivedId], $report->supersededResultIds);
        $this->assertEquals($realId, $this->lineageFor($derivedId)['superseded_by_result_id']);
    }

    public function testReconcileIsIdempotent(): void
    {
        $derivedId = $this->derivedResultId();
        $this->realResultId();

        $service = new DerivedObservationSupersession();
        $first = $service->reconcile($this->pid);
        $second = $service->reconcile($this->pid);

        $this->assertSame([$derivedId], $first->supersededResultIds);
        $this->assertSame([], $second->supersededResultIds, 'an already-superseded record is not re-reported');
    }
}

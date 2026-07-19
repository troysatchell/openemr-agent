<?php

/**
 * DB-BACKED: the document-ingestion flow emits a 'document-ingestion' trace
 * step so the observability dashboard's ingestion metrics (count, p95 latency,
 * failure rate) have data.
 *
 * Failure mode guarded against: the ingestion path used to record NO trace step
 * at all — it set 'document-ingestion' as the TraceContext turn_kind but never
 * emitted a StepRecord — so `bin/alert-check.php` / the dashboard reported "no
 * document extractions" even after a real upload. The frozen TRO-32 test
 * (DocumentIngestionServiceTest) covers the attach/extract/persist result but
 * never asserts the trace, so this sits alongside it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestionService;
use OpenEMR\Modules\Copilot\Ingestion\PatientDocumentAttacher;
use OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor;
use OpenEMR\Modules\Copilot\Observability\StepOutcome;
use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceRecorder;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidatesSchema;
use PHPUnit\Framework\TestCase;

class DocumentIngestionTraceTest extends TestCase
{
    private int $pid = 0;

    private string $patientUuid = '';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        ExtractionLineageSchema::ensureInstalled();
        IntakeCandidatesSchema::ensureInstalled();

        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $this->pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 6000;
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO patient_data (pid, pubpid, fname, lname, date, uuid) VALUES (?, ?, ?, ?, NOW(), UNHEX(REPLACE(UUID(),'-','')))",
            [$this->pid, 'copilot-dit-' . $this->pid, 'Ingest', 'TraceFixture'],
        );
        $uuidHex = QueryUtils::fetchSingleValue('SELECT LOWER(HEX(uuid)) AS u FROM patient_data WHERE pid = ?', 'u', [$this->pid]);
        $this->assertIsString($uuidHex);
        $this->patientUuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($uuidHex, 0, 8),
            substr($uuidHex, 8, 4),
            substr($uuidHex, 12, 4),
            substr($uuidHex, 16, 4),
            substr($uuidHex, 20, 12),
        );
        $this->tempFiles = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $resultIds = QueryUtils::fetchTableColumn(
            'SELECT prr.procedure_result_id AS rid FROM procedure_result prr JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?',
            'rid',
            [$this->pid],
        );
        foreach ($resultIds as $rid) {
            if (is_numeric($rid)) {
                QueryUtils::sqlStatementThrowException('DELETE FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' WHERE procedure_result_id = ?', [(int) $rid]);
            }
        }
        QueryUtils::sqlStatementThrowException('DELETE prr FROM procedure_result prr JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$this->pid]);
        QueryUtils::sqlStatementThrowException('DELETE pr FROM procedure_report pr JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$this->pid]);
        QueryUtils::sqlStatementThrowException('DELETE poc FROM procedure_order_code poc JOIN procedure_order po ON poc.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$this->pid]);
        QueryUtils::sqlStatementThrowException('DELETE FROM procedure_order WHERE patient_id = ?', [$this->pid]);
        $docIds = QueryUtils::fetchTableColumn('SELECT id FROM documents WHERE foreign_id = ?', 'id', [$this->pid]);
        foreach ($docIds as $docId) {
            if (is_numeric($docId)) {
                QueryUtils::sqlStatementThrowException('DELETE FROM categories_to_documents WHERE document_id = ?', [(int) $docId]);
                QueryUtils::sqlStatementThrowException('DELETE FROM documents WHERE id = ?', [(int) $docId]);
            }
        }
        QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$this->pid]);
    }

    private function uploadFile(string $bytes, string $name): string
    {
        $path = sys_get_temp_dir() . '/copilot-ingest-trace-' . uniqid('', true) . '-' . $name;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function vlmTransport(string $modelJson): \Closure
    {
        return static fn (array $requestBody): array => [200, ['content' => [['type' => 'text', 'text' => $modelJson]]]];
    }

    private function service(string $modelJson, TraceRecorder $recorder): DocumentIngestionService
    {
        return new DocumentIngestionService(
            new PatientDocumentAttacher(),
            new VlmDocumentExtractor($this->vlmTransport($modelJson), new NoopDisclosureLogger(), 'claude-opus-4-8'),
            $recorder,
        );
    }

    private function labWire(): string
    {
        $citation = static fn (string $path, string $quote): array => [
            'source_type' => 'lab_pdf', 'source_id' => '999999', 'page_or_section' => '1',
            'field_or_chunk_id' => $path, 'quote_or_value' => $quote,
        ];

        return (string) json_encode([
            'documentId' => '999999',
            'analytes' => [[
                'testName' => ['isPresent' => true, 'value' => 'Potassium', 'confidence' => 0.95, 'citation' => $citation('analytes[0].testName', 'Potassium')],
                'value' => ['isPresent' => true, 'value' => '6.8', 'confidence' => 0.95, 'citation' => $citation('analytes[0].value', '6.8')],
                'unit' => ['isPresent' => true, 'value' => 'mmol/L', 'confidence' => 0.95, 'citation' => $citation('analytes[0].unit', 'mmol/L')],
                'referenceRange' => ['isPresent' => false, 'value' => null, 'confidence' => null, 'citation' => null],
                'abnormalFlag' => ['isPresent' => false, 'value' => null, 'confidence' => null, 'citation' => null],
                'collectionDate' => '2026-07-01',
            ]],
        ]);
    }

    /**
     * @return list<StepRecord>
     */
    private function ingestionSteps(CollectingTraceRecorder $recorder): array
    {
        return array_values(array_filter(
            $recorder->steps,
            static fn (StepRecord $s): bool => $s->step === 'document-ingestion',
        ));
    }

    public function testSuccessfulIngestionEmitsAnOkDocumentIngestionStep(): void
    {
        $recorder = new CollectingTraceRecorder();
        $service = $this->service($this->labWire(), $recorder);
        $file = $this->uploadFile('%PDF-1.7 lab-bytes', 'panel.pdf');

        $result = $service->attachAndExtract(new PhysicianContext('dr-tran', 1), $this->patientUuid, $file, 'lab_pdf');
        $this->assertSame('extracted', $result['extraction_status']);

        $steps = $this->ingestionSteps($recorder);
        $this->assertCount(1, $steps, 'exactly one document-ingestion step is recorded per upload');
        $this->assertSame(StepOutcome::Ok, $steps[0]->outcome);
        $this->assertNull($steps[0]->errorClass);
        $this->assertSame('document-ingestion', $recorder->lastTurnKind, 'the ingestion span carries the document-ingestion turn_kind');
    }

    public function testFailedExtractionEmitsAFailedDocumentIngestionStep(): void
    {
        $recorder = new CollectingTraceRecorder();
        $service = $this->service('{"not": "the schema"}', $recorder);
        $file = $this->uploadFile('%PDF-1.7 bad-extract', 'panel.pdf');

        $result = $service->attachAndExtract(new PhysicianContext('dr-tran', 1), $this->patientUuid, $file, 'lab_pdf');
        $this->assertSame('extraction_failed', $result['extraction_status']);

        $steps = $this->ingestionSteps($recorder);
        $this->assertCount(1, $steps);
        $this->assertSame(StepOutcome::Failed, $steps[0]->outcome);
        $this->assertNotNull($steps[0]->errorClass, 'a failed step names the error class');
    }
}

final class CollectingTraceRecorder implements TraceRecorder
{
    /** @var list<StepRecord> */
    public array $steps = [];

    public string $lastTurnKind = '';

    public function record(TraceContext $context, StepRecord $step): void
    {
        $this->steps[] = $step;
        $this->lastTurnKind = $context->turnKind;
    }
}

final class NoopDisclosureLogger implements DisclosureLogger
{
    public function record(Disclosure $disclosure): void
    {
    }
}

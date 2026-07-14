<?php

/**
 * FROZEN acceptance tests — TRO-32: the composed attach_and_extract flow (W2_ARCHITECTURE §2, end to end).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract under test: DocumentIngestionService implements the
 * DocumentIngestion port (TRO-16's seam — the route's 501 stub swaps out for
 * this) by composing the shipped pieces: resolve patient uuid → pid once at
 * the boundary (D7: pid is the trusted surrogate) → read the uploaded file →
 * attach (dedupe-by-hash) → VLM extract (disclosure before call) → parse
 * (whole-fail) → persist by fact shape (lab_pdf → native derived chains;
 * intake_form → reconciliation candidates). §2's failure behavior holds: an
 * extraction failure leaves the document ATTACHED with status
 * 'extraction_failed' — retryable, never a partial persist.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
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
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidatesSchema;
use PHPUnit\Framework\TestCase;

class DocumentIngestionServiceTest extends TestCase
{
    private int $pid = 0;

    private string $patientUuid = '';

    private CollectingDisclosureLogger $logger;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        ExtractionLineageSchema::ensureInstalled();
        IntakeCandidatesSchema::ensureInstalled();

        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $this->pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 5000;
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO patient_data (pid, pubpid, fname, lname, date, uuid) VALUES (?, ?, ?, ?, NOW(), UNHEX(REPLACE(UUID(),'-','')))",
            [$this->pid, 'copilot-dis-' . $this->pid, 'Ingest', 'Fixture'],
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
        $this->logger = new CollectingDisclosureLogger();
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
        QueryUtils::sqlStatementThrowException('DELETE FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE . ' WHERE patient_pid = ?', [$this->pid]);

        $docIds = QueryUtils::fetchTableColumn('SELECT id FROM documents WHERE foreign_id = ?', 'id', [$this->pid]);
        foreach ($docIds as $docId) {
            if (is_numeric($docId)) {
                $url = QueryUtils::fetchSingleValue('SELECT url FROM documents WHERE id = ?', 'url', [(int) $docId]);
                if (is_string($url) && str_starts_with($url, 'file://')) {
                    $path = substr($url, 7);
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                QueryUtils::sqlStatementThrowException('DELETE FROM categories_to_documents WHERE document_id = ?', [(int) $docId]);
                QueryUtils::sqlStatementThrowException('DELETE FROM documents WHERE id = ?', [(int) $docId]);
            }
        }
        QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$this->pid]);
    }

    /**
     * Writes real upload bytes to a temp file and returns its path — the
     * service reads the uploaded file from disk, as the route hands it over.
     */
    private function uploadFile(string $bytes, string $name): string
    {
        $path = sys_get_temp_dir() . '/copilot-ingest-' . uniqid('', true) . '-' . $name;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * A VLM transport returning a fixed model output for every request.
     */
    private function vlmTransport(string $modelJson): \Closure
    {
        return static function (array $requestBody) use ($modelJson): array {
            return [200, ['content' => [['type' => 'text', 'text' => $modelJson]]]];
        };
    }

    private function service(string $modelJson): DocumentIngestionService
    {
        return new DocumentIngestionService(
            new PatientDocumentAttacher(),
            new VlmDocumentExtractor($this->vlmTransport($modelJson), $this->logger, 'claude-opus-4-8'),
        );
    }

    /**
     * Valid lab_pdf wire JSON. The model's documentId claim is untrusted —
     * the service must stamp the REAL attached document id into what it
     * persists, regardless of what the model echoes here.
     */
    private function labWire(): string
    {
        $citation = static fn (string $path, string $quote): array => [
            'source_type' => 'lab_pdf',
            'source_id' => '999999',
            'page_or_section' => '1',
            'field_or_chunk_id' => $path,
            'quote_or_value' => $quote,
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

    public function testLabPdfIngestionRoundTripsAttachExtractPersist(): void
    {
        $service = $this->service($this->labWire());
        $file = $this->uploadFile('%PDF-1.7 lab-bytes', 'panel.pdf');

        $result = $service->attachAndExtract(new PhysicianContext('dr-tran', 1), $this->patientUuid, $file, 'lab_pdf');

        $this->assertIsNumeric($result['document_id']);
        $this->assertSame('extracted', $result['extraction_status']);

        $this->assertCount(1, $this->logger->records, 'the VLM call is a logged disclosure');
        $this->assertContains('document-media', $this->logger->records[0]->dataClasses);
        $this->assertSame($this->pid, $this->logger->records[0]->patientPid, 'uuid resolved to the trusted pid at the boundary (D7)');

        $chainCount = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM procedure_result prr JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ? AND prr.result_status = ?',
            'c',
            [$this->pid, 'preliminary'],
        );
        $this->assertEquals(1, $chainCount, 'the lab analyte persisted as a stamped derived chain');

        $stampedDocId = QueryUtils::fetchSingleValue(
            'SELECT prr.document_id AS d FROM procedure_result prr JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?',
            'd',
            [$this->pid],
        );
        $this->assertEquals($result['document_id'], $stampedDocId, 'the REAL attached document id is stamped — never the model\'s claim');
    }

    public function testExtractionFailureLeavesTheDocumentAttachedAndRetryable(): void
    {
        $service = $this->service('{"not": "the schema"}');
        $file = $this->uploadFile('%PDF-1.7 bad-extract', 'panel.pdf');

        $result = $service->attachAndExtract(new PhysicianContext('dr-tran', 1), $this->patientUuid, $file, 'lab_pdf');

        $this->assertIsNumeric($result['document_id']);
        $this->assertSame('extraction_failed', $result['extraction_status']);

        $docCount = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM documents WHERE foreign_id = ?', 'c', [$this->pid]);
        $this->assertEquals(1, $docCount, 'the document stays attached — extraction is retryable (§2)');

        $chainCount = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM procedure_order WHERE patient_id = ?', 'c', [$this->pid]);
        $this->assertEquals(0, $chainCount, 'no partial persistence on a failed extraction');
    }

    public function testIntakeFormIngestionPersistsReconciliationCandidates(): void
    {
        $citation = static fn (string $path, string $quote): array => [
            'source_type' => 'intake_form',
            'source_id' => '999999',
            'page_or_section' => '1',
            'field_or_chunk_id' => $path,
            'quote_or_value' => $quote,
        ];
        $intakeWire = (string) json_encode([
            'documentId' => '999999',
            'chiefConcern' => ['isPresent' => true, 'value' => 'chest pain', 'confidence' => 0.8, 'citation' => $citation('chiefConcern', 'chest pain')],
            'currentMedications' => [['isPresent' => true, 'value' => 'metoprolol 50mg', 'confidence' => 0.8, 'citation' => $citation('currentMedications[0]', 'metoprolol 50mg')]],
            'allergies' => [],
            'familyHistory' => [],
            'demographics' => [],
        ]);
        $service = $this->service($intakeWire);
        $file = $this->uploadFile('%PDF-1.7 intake-bytes', 'intake.pdf');

        $result = $service->attachAndExtract(new PhysicianContext('dr-tran', 1), $this->patientUuid, $file, 'intake_form');

        $this->assertSame('extracted', $result['extraction_status']);

        $candidateCount = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE . ' WHERE patient_pid = ? AND superseded_at IS NULL',
            'c',
            [$this->pid],
        );
        $this->assertEquals(2, $candidateCount, 'chief concern + one med as reconciliation candidates');

        $chainCount = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM procedure_order WHERE patient_id = ?', 'c', [$this->pid]);
        $this->assertEquals(0, $chainCount, 'intake facts never persist as observations');
    }

    public function testUnknownDocTypeIsRefusedBeforeAnySideEffect(): void
    {
        $service = $this->service('{}');
        $file = $this->uploadFile('bytes', 'x.pdf');

        try {
            $service->attachAndExtract(new PhysicianContext('dr-tran', 1), $this->patientUuid, $file, 'radiology_report');
            $this->fail('expected a \DomainException refusal');
        } catch (\DomainException) {
            // expected
        }

        $docCount = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM documents WHERE foreign_id = ?', 'c', [$this->pid]);
        $this->assertEquals(0, $docCount);
        $this->assertSame([], $this->logger->records);
    }

    public function testUnknownPatientUuidIsRefusedBeforeAnySideEffect(): void
    {
        $service = $this->service('{}');
        $file = $this->uploadFile('bytes', 'x.pdf');

        $this->expectException(\DomainException::class);
        $service->attachAndExtract(new PhysicianContext('dr-tran', 1), '00000000-0000-4000-8000-000000000000', $file, 'lab_pdf');
    }
}

/**
 * Frozen-test support: collecting spy for the DisclosureLogger port.
 */
final class CollectingDisclosureLogger implements DisclosureLogger
{
    /** @var list<Disclosure> */
    public array $records = [];

    public function record(Disclosure $disclosure): void
    {
        $this->records[] = $disclosure;
    }
}

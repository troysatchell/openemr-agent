<?php

/**
 * Acceptance tests — overlay citation path, DB-backed end to end
 * (W2_ARCHITECTURE.md §4): from a real ingested document, through the live
 * lineage lookup, to the click-to-source document preview the overlay
 * renders.
 *
 * Contract: DerivedLabSourceRewriter::forLiveLookup() maps the FHIR
 * Observation uuids of REAL extraction-derived `procedure_result` rows to
 * `derived_observation:<procedure_result_id>` refs (uuids without a lineage
 * row are untouched), and the minted token resolves through
 * SourceResolverEndpoint to the `{type: 'document', ...}` preview carrying
 * the source PDF bytes — the exact wire the panel's bbox overlay draws
 * from. This is the end-to-end path the user-visible bug broke: an
 * extracted lab's chip must open its source document, not dead-end.
 *
 * DB-BACKED: ingestion uses the replay-transport pattern of
 * BboxLineageRoundTripTest (attach + stubbed VLM + persist).
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
use OpenEMR\Modules\Copilot\Chart\DerivedLabSourceRewriter;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestionService;
use OpenEMR\Modules\Copilot\Ingestion\PatientDocumentAttacher;
use OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Routes\SourceResolverEndpoint;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class DerivedLabRewriteOverlayRoundTripTest extends TestCase
{
    /** @var list<int> */
    private array $pids = [];

    /** @var list<string> */
    private array $tempFiles = [];

    private PhysicianContext $physician;

    protected function setUp(): void
    {
        ExtractionLineageSchema::ensureInstalled();
        $this->physician = new PhysicianContext('dr-tran', 1);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        foreach ($this->pids as $pid) {
            $docIds = QueryUtils::fetchTableColumn('SELECT id FROM documents WHERE foreign_id = ?', 'id', [$pid]);
            foreach ($docIds as $docId) {
                if (is_numeric($docId)) {
                    $url = QueryUtils::fetchSingleValue('SELECT url FROM documents WHERE id = ?', 'url', [(int) $docId]);
                    if (is_string($url) && str_starts_with($url, 'file://')) {
                        @unlink(substr($url, 7));
                    }
                    QueryUtils::sqlStatementThrowException('DELETE FROM categories_to_documents WHERE document_id = ?', [(int) $docId]);
                    QueryUtils::sqlStatementThrowException('DELETE FROM documents WHERE id = ?', [(int) $docId]);
                }
            }
            $resultIds = QueryUtils::fetchTableColumn(
                'SELECT prr.procedure_result_id AS rid FROM procedure_result prr JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?',
                'rid',
                [$pid],
            );
            foreach ($resultIds as $rid) {
                if (is_numeric($rid)) {
                    QueryUtils::sqlStatementThrowException('DELETE FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' WHERE procedure_result_id = ?', [(int) $rid]);
                }
            }
            QueryUtils::sqlStatementThrowException('DELETE prr FROM procedure_result prr JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$pid]);
            QueryUtils::sqlStatementThrowException('DELETE pr FROM procedure_report pr JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$pid]);
            QueryUtils::sqlStatementThrowException('DELETE poc FROM procedure_order_code poc JOIN procedure_order po ON poc.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$pid]);
            QueryUtils::sqlStatementThrowException('DELETE FROM procedure_order WHERE patient_id = ?', [$pid]);
            QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$pid]);
        }
    }

    public function testLiveLookupRewritesOnlyLineageBackedUuidsAndTheTokenOpensItsSourceDocument(): void
    {
        [, $uuid] = $this->fixturePatient('dlr');
        $documentId = $this->ingestOneAnalyteDocument($uuid);

        $derived = QueryUtils::fetchRecords(
            'SELECT prr.procedure_result_id AS rid, LOWER(HEX(prr.uuid)) AS uuid_hex'
            . ' FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' lin'
            . ' JOIN procedure_result prr ON prr.procedure_result_id = lin.procedure_result_id'
            . ' WHERE lin.document_id = ?',
            [$documentId],
        );
        $this->assertCount(1, $derived, 'precondition: the ingest persisted one derived observation with lineage');
        $rid = $derived[0]['rid'];
        $uuidHex = $derived[0]['uuid_hex'];
        $this->assertIsNumeric($rid);
        $this->assertIsString($uuidHex);
        $observationUuid = self::dashUuid($uuidHex);

        $rewriter = DerivedLabSourceRewriter::forLiveLookup();
        $labs = $rewriter->rewrite([
            new LabResultEntry('Potassium', 6.8, 'mmol/L', null, [new SourceRef('Observation', $observationUuid)]),
            new LabResultEntry('Sodium', 141.0, 'mmol/L', null, [new SourceRef('Observation', '99999999-9999-9999-9999-999999999999')]),
            new LabResultEntry('Potassium', 6.8, 'mmol/L', null, [new SourceRef('Observation', strtoupper($observationUuid))]),
        ]);

        $rewritten = $labs[0]->sources[0];
        $this->assertSame('derived_observation', $rewritten->sourceType);
        $this->assertSame((string) (int) $rid, $rewritten->sourceId);

        $untouched = $labs[1]->sources[0];
        $this->assertSame('Observation', $untouched->sourceType, 'a lab with no lineage row keeps its chart ref — seeded/interface-feed labs ground to the chart');

        $upper = $labs[2]->sources[0];
        $this->assertSame('derived_observation', $upper->sourceType, 'a case-variant spelling of the same uuid still grounds — every original spelling maps back from its normalized hex');
        $this->assertSame((string) (int) $rid, $upper->sourceId);

        $preview = SourceResolverEndpoint::forLiveResolution()->handle($this->physician, [
            'token' => $rewritten->sourceType . ':' . $rewritten->sourceId,
            'patient_uuid' => $uuid,
        ]);

        $this->assertSame('document', $preview['type'], 'the rewritten chip token must open the source document — this is the click the overlay renders');
        $this->assertSame($documentId, $preview['document_id']);
        $bytes = base64_decode($preview['document_base64'], true);
        $this->assertIsString($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function testLiveLookupWithNoMatchesLeavesTheChartUntouched(): void
    {
        $rewriter = DerivedLabSourceRewriter::forLiveLookup();

        $plain = new LabResultEntry('Hemoglobin', 13.8, 'g/dL', null, [new SourceRef('Observation', '88888888-8888-8888-8888-888888888888')]);
        $labs = $rewriter->rewrite([$plain]);

        $this->assertSame($plain, $labs[0]);
    }

    private static function dashUuid(string $hex): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @return array{int, string} [pid, uuid]
     */
    private function fixturePatient(string $tag): array
    {
        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 7000;
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO patient_data (pid, pubpid, fname, lname, date, uuid) VALUES (?, ?, ?, ?, NOW(), UNHEX(REPLACE(UUID(),'-','')))",
            [$pid, 'copilot-dlr-' . $tag . '-' . $pid, 'Rewrite', 'Fixture'],
        );
        $this->pids[] = $pid;
        $uuidHex = QueryUtils::fetchSingleValue('SELECT LOWER(HEX(uuid)) AS u FROM patient_data WHERE pid = ?', 'u', [$pid]);
        $this->assertIsString($uuidHex);

        return [$pid, self::dashUuid($uuidHex)];
    }

    /**
     * Ingests a one-analyte lab document through the real production path
     * (attach + stubbed VLM transport + persist) — same replay-transport
     * pattern as BboxLineageRoundTripTest.
     */
    private function ingestOneAnalyteDocument(string $patientUuid): int
    {
        $citation = static fn (string $field, string $quote): array => [
            'source_type' => 'lab_pdf',
            'source_id' => '999999',
            'page_or_section' => '1',
            'field_or_chunk_id' => 'analytes[0].' . $field,
            'quote_or_value' => $quote,
        ];
        $absent = ['isPresent' => false, 'value' => null, 'confidence' => null, 'citation' => null];

        $wire = json_encode([
            'documentId' => '999999',
            'analytes' => [
                [
                    'testName' => ['isPresent' => true, 'value' => 'Potassium', 'confidence' => 0.95, 'citation' => $citation('testName', 'Potassium')],
                    'value' => ['isPresent' => true, 'value' => '6.8', 'confidence' => 0.95, 'citation' => $citation('value', '6.8'), 'bbox' => [0.12, 0.34, 0.5, 0.04]],
                    'unit' => ['isPresent' => true, 'value' => 'mmol/L', 'confidence' => 0.95, 'citation' => $citation('unit', 'mmol/L')],
                    'referenceRange' => $absent,
                    'abnormalFlag' => $absent,
                    'collectionDate' => '2026-07-12',
                ],
            ],
        ]);
        $this->assertIsString($wire);

        $transport = static fn (array $requestBody): array => [200, ['content' => [['type' => 'text', 'text' => $wire]]]];

        $path = sys_get_temp_dir() . '/copilot-dlr-' . uniqid('', true) . '.pdf';
        file_put_contents($path, "%PDF-1.7 derived-lab rewrite fixture\n");
        $this->tempFiles[] = $path;

        $service = new DocumentIngestionService(
            new PatientDocumentAttacher(),
            new VlmDocumentExtractor($transport, new RewriteDisclosureSpy(), 'claude-opus-4-8'),
        );
        $result = $service->attachAndExtract($this->physician, $patientUuid, $path, 'lab_pdf');
        $this->assertSame('extracted', $result['extraction_status']);
        $this->assertIsNumeric($result['document_id']);

        return (int) $result['document_id'];
    }
}

final class RewriteDisclosureSpy implements DisclosureLogger
{
    /** @var list<Disclosure> */
    public array $records = [];

    public function record(Disclosure $disclosure): void
    {
        $this->records[] = $disclosure;
    }
}

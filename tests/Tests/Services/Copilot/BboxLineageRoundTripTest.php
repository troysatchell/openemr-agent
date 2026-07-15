<?php

/**
 * FROZEN acceptance tests — TRO-44 (persistence + resolution side): stored
 * coordinates round-trip from the VLM wire to the click-to-source preview.
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract: the module-owned extraction-lineage table gains a
 * nullable `bbox` column (ensureInstalled() upgrades an existing installation
 * in place — module-owned schema evolution, no core schema edits); the ingest
 * path persists each analyte's value-field box as the canonical 4-decimal CSV
 * (null when the wire carried none); and resolving a `lab_pdf:` citation
 * token returns the document preview enriched for the overlay viewer —
 * `bbox` as a 4-float list (null when unstored), plus the source PDF bytes
 * (`document_base64`, `document_mime`) so the panel can render the cited
 * page and draw the box, all behind the same patient-scope refusal the
 * resolver already enforces.
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
use OpenEMR\Modules\Copilot\Routes\SourceResolverEndpoint;
use PHPUnit\Framework\TestCase;

class BboxLineageRoundTripTest extends TestCase
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

    public function testEnsureInstalledAddsTheBboxColumnToAPreexistingTable(): void
    {
        if ($this->lineageHasBboxColumn()) {
            QueryUtils::sqlStatementThrowException(
                'ALTER TABLE ' . ExtractionLineageSchema::LINEAGE_TABLE . ' DROP COLUMN bbox',
                [],
            );
        }
        $this->assertFalse($this->lineageHasBboxColumn(), 'precondition: simulating an existing pre-bbox installation');

        ExtractionLineageSchema::ensureInstalled();

        $this->assertTrue(
            $this->lineageHasBboxColumn(),
            'ensureInstalled() must upgrade an existing installation in place — deployed sites never reinstall the module',
        );
    }

    public function testIngestPersistsTheValueBoxAndHonestlyStoresNullWhenAbsent(): void
    {
        [$pid, $uuid] = $this->fixturePatient('bbx');
        $documentId = $this->ingestTwoAnalyteDocument($uuid, withBoxOnFirst: true);

        $rows = QueryUtils::fetchRecords(
            'SELECT lin.bbox AS bbox, prr.result_text AS name FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' lin'
            . ' JOIN procedure_result prr ON prr.procedure_result_id = lin.procedure_result_id'
            . ' WHERE lin.document_id = ? ORDER BY prr.procedure_result_id',
            [$documentId],
        );
        $this->assertCount(2, $rows);

        $this->assertSame('Potassium', $rows[0]['name']);
        $this->assertSame('0.1200,0.3400,0.5000,0.0400', $rows[0]['bbox'], 'the value-field box persists as canonical CSV');

        $this->assertSame('Sodium', $rows[1]['name']);
        $this->assertNull($rows[1]['bbox'], 'no wire box stores NULL — never a guessed or zeroed box (D1)');

        $this->assertContains($pid, $this->pids);
    }

    public function testResolvedDocumentPreviewCarriesTheBoxAndTheRenderableSource(): void
    {
        [, $uuid] = $this->fixturePatient('bbr');
        $documentId = $this->ingestTwoAnalyteDocument($uuid, withBoxOnFirst: true);

        $endpoint = SourceResolverEndpoint::forLiveResolution();
        $preview = $endpoint->handle($this->physician, [
            'token' => 'lab_pdf:' . $documentId . '#analytes[0].value',
            'patient_uuid' => $uuid,
        ]);

        $this->assertSame('document', $preview['type']);

        $bbox = $preview['bbox'] ?? null;
        $this->assertIsArray($bbox, 'the stored box rides the preview for the overlay');
        $this->assertCount(4, $bbox);
        $expected = [0.12, 0.34, 0.5, 0.04];
        foreach ($bbox as $i => $component) {
            $this->assertEqualsWithDelta($expected[$i], $component, 0.0001);
        }

        $this->assertSame('application/pdf', $preview['document_mime']);

        $bytes = base64_decode($preview['document_base64'], true);
        $this->assertIsString($bytes);
        $this->assertStringStartsWith('%PDF', $bytes, 'the preview carries the real source bytes the viewer renders');
    }

    public function testBoxlessCitationStillResolvesWithANullBoxNeverAnInventedOne(): void
    {
        [, $uuid] = $this->fixturePatient('bbn');
        $documentId = $this->ingestTwoAnalyteDocument($uuid, withBoxOnFirst: true);

        $endpoint = SourceResolverEndpoint::forLiveResolution();
        $preview = $endpoint->handle($this->physician, [
            'token' => 'lab_pdf:' . $documentId . '#analytes[1].value',
            'patient_uuid' => $uuid,
        ]);

        $this->assertSame('document', $preview['type']);
        $this->assertNull($preview['bbox'] ?? null, 'no stored box resolves as null — the viewer opens the page without an overlay (R-W3)');
        $this->assertNotSame('', $preview['document_base64'], 'the source stays renderable even without a box');
    }

    private function lineageHasBboxColumn(): bool
    {
        $rows = QueryUtils::fetchRecords(
            "SHOW COLUMNS FROM " . ExtractionLineageSchema::LINEAGE_TABLE . " LIKE 'bbox'",
            [],
        );

        return $rows !== [];
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
            [$pid, 'copilot-bbox-' . $tag . '-' . $pid, 'Bbox', 'Fixture'],
        );
        $this->pids[] = $pid;
        $uuidHex = QueryUtils::fetchSingleValue('SELECT LOWER(HEX(uuid)) AS u FROM patient_data WHERE pid = ?', 'u', [$pid]);
        $this->assertIsString($uuidHex);
        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($uuidHex, 0, 8),
            substr($uuidHex, 8, 4),
            substr($uuidHex, 12, 4),
            substr($uuidHex, 16, 4),
            substr($uuidHex, 20, 12),
        );

        return [$pid, $uuid];
    }

    /**
     * Ingests a two-analyte lab document through the real production path
     * (attach + stubbed VLM transport + persist); the first analyte's value
     * field carries a box, the second carries none.
     */
    private function ingestTwoAnalyteDocument(string $patientUuid, bool $withBoxOnFirst): int
    {
        $citation = static fn (int $i, string $field, string $quote): array => [
            'source_type' => 'lab_pdf',
            'source_id' => '999999',
            'page_or_section' => '2',
            'field_or_chunk_id' => 'analytes[' . $i . '].' . $field,
            'quote_or_value' => $quote,
        ];
        $absent = ['isPresent' => false, 'value' => null, 'confidence' => null, 'citation' => null];

        $valueField = ['isPresent' => true, 'value' => '6.8', 'confidence' => 0.95, 'citation' => $citation(0, 'value', '6.8')];
        if ($withBoxOnFirst) {
            $valueField['bbox'] = [0.12, 0.34, 0.5, 0.04];
        }

        $wire = json_encode([
            'documentId' => '999999',
            'analytes' => [
                [
                    'testName' => ['isPresent' => true, 'value' => 'Potassium', 'confidence' => 0.95, 'citation' => $citation(0, 'testName', 'Potassium')],
                    'value' => $valueField,
                    'unit' => ['isPresent' => true, 'value' => 'mmol/L', 'confidence' => 0.95, 'citation' => $citation(0, 'unit', 'mmol/L')],
                    'referenceRange' => $absent,
                    'abnormalFlag' => $absent,
                    'collectionDate' => '2026-07-01',
                ],
                [
                    'testName' => ['isPresent' => true, 'value' => 'Sodium', 'confidence' => 0.95, 'citation' => $citation(1, 'testName', 'Sodium')],
                    'value' => ['isPresent' => true, 'value' => '138', 'confidence' => 0.95, 'citation' => $citation(1, 'value', '138')],
                    'unit' => ['isPresent' => true, 'value' => 'mEq/L', 'confidence' => 0.95, 'citation' => $citation(1, 'unit', 'mEq/L')],
                    'referenceRange' => $absent,
                    'abnormalFlag' => $absent,
                    'collectionDate' => '2026-07-01',
                ],
            ],
        ]);
        $this->assertIsString($wire);

        $transport = static fn (array $requestBody): array => [200, ['content' => [['type' => 'text', 'text' => $wire]]]];

        $path = sys_get_temp_dir() . '/copilot-bbox-' . uniqid('', true) . '.pdf';
        file_put_contents($path, "%PDF-1.7 bbox round-trip fixture\n");
        $this->tempFiles[] = $path;

        $service = new DocumentIngestionService(
            new PatientDocumentAttacher(),
            new VlmDocumentExtractor($transport, new BboxDisclosureSpy(), 'claude-opus-4-8'),
        );
        $result = $service->attachAndExtract($this->physician, $patientUuid, $path, 'lab_pdf');
        $this->assertSame('extracted', $result['extraction_status']);
        $this->assertIsNumeric($result['document_id']);

        return (int) $result['document_id'];
    }
}

final class BboxDisclosureSpy implements DisclosureLogger
{
    /** @var list<Disclosure> */
    public array $records = [];

    public function record(Disclosure $disclosure): void
    {
        $this->records[] = $disclosure;
    }
}

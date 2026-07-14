<?php

/**
 * FROZEN acceptance tests — TRO-44 (MVP click-to-source slice): the source
 * resolver behind `POST /api/copilot/source` (W2_ARCHITECTURE.md §4; UC6;
 * MVP row 5 "source-grounded UI").
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Contract under test:
 * Routes\SourceResolverEndpoint::handle(PhysicianContext, array) resolves a
 * citation token — the same self-describing `sourceType:sourceId[#field]`
 * shape ReferenceIndex::tokenFor() mints and TurnEndpoint ships to the
 * panel — into the minimum-necessary preview a clinician needs to verify
 * the source, re-grounded against LIVE data on every call (never a cached
 * turn):
 *
 *  - guideline tokens resolve through the REAL corpus index (DB) to the
 *    chunk's heading + snippet + source document;
 *  - document-extraction tokens resolve through the REAL persisted lineage
 *    to the attached document (id, filename, page, cited value) — and
 *    REFUSE a document that does not belong to the named patient
 *    (cross-patient leak is the S-class failure here);
 *  - detector and chart tokens resolve to typed, PHI-minimal labels;
 *  - malformed or unknown tokens fail loud (\DomainException), never a
 *    guessed preview.
 *
 * DB-BACKED: corpus index and ingestion lineage are real; the VLM crossing
 * in setup uses the replay-transport pattern of DocumentIngestionServiceTest.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot\Routes;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestionService;
use OpenEMR\Modules\Copilot\Ingestion\PatientDocumentAttacher;
use OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidatesSchema;
use OpenEMR\Modules\Copilot\Rag\CohereEmbedClient;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexer;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use OpenEMR\Modules\Copilot\Routes\SourceResolverEndpoint;
use PHPUnit\Framework\TestCase;

class SourceResolverEndpointTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot';

    private static bool $corpusIndexed = false;

    /** @var list<int> */
    private array $pids = [];

    /** @var list<string> */
    private array $tempFiles = [];

    private PhysicianContext $physician;

    protected function setUp(): void
    {
        ExtractionLineageSchema::ensureInstalled();
        IntakeCandidatesSchema::ensureInstalled();
        $this->physician = new PhysicianContext('dr-tran', 1);
        $this->ensureCorpusIndexed();
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
            QueryUtils::sqlStatementThrowException('DELETE FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE . ' WHERE patient_pid = ?', [$pid]);
            QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$pid]);
        }
    }

    private function ensureCorpusIndexed(): void
    {
        if (self::$corpusIndexed) {
            return;
        }
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::EMBEDDING_TABLE, []);
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::CHUNK_TABLE, []);
        CorpusIndexSchema::ensureInstalled();

        $dimensions = CorpusIndexSchema::EMBEDDING_DIMENSIONS;
        $transport = static function (array $requestBody) use ($dimensions): array {
            $texts = $requestBody['texts'] ?? null;
            if (!is_array($texts)) {
                throw new \RuntimeException('corpus embed request is missing "texts"');
            }
            $vectors = [];
            foreach ($texts as $text) {
                if (!is_string($text)) {
                    throw new \RuntimeException('corpus embed request text is not a string');
                }
                $seed = hash('sha256', $text);
                $vector = [];
                for ($i = 0; $i < $dimensions; $i++) {
                    $vector[] = (float) (hexdec(substr(hash('sha256', $seed . $i), 0, 8)) % 2000 - 1000) / 1000.0;
                }
                $vectors[] = $vector;
            }

            return [200, ['embeddings' => ['float' => $vectors]]];
        };

        (new CorpusIndexer(new CohereEmbedClient($transport, 'embed-english-v3.0')))
            ->rebuild(self::MODULE_DIR . '/corpus');
        self::$corpusIndexed = true;
    }

    /**
     * @return array{int, string} [pid, uuid]
     */
    private function fixturePatient(string $tag): array
    {
        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 6000;
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO patient_data (pid, pubpid, fname, lname, date, uuid) VALUES (?, ?, ?, ?, NOW(), UNHEX(REPLACE(UUID(),'-','')))",
            [$pid, 'copilot-src-' . $tag . '-' . $pid, 'Source', 'Fixture'],
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
     * Ingests one single-analyte lab document for the patient and returns the
     * real attached document id.
     */
    private function ingestLabDocument(string $patientUuid, string $bytesTag): int
    {
        $wire = (string) json_encode([
            'documentId' => '999999',
            'analytes' => [[
                'testName' => ['isPresent' => true, 'value' => 'Potassium', 'confidence' => 0.95, 'citation' => ['source_type' => 'lab_pdf', 'source_id' => '999999', 'page_or_section' => '2', 'field_or_chunk_id' => 'analytes[0].testName', 'quote_or_value' => 'Potassium']],
                'value' => ['isPresent' => true, 'value' => '4.4', 'confidence' => 0.95, 'citation' => ['source_type' => 'lab_pdf', 'source_id' => '999999', 'page_or_section' => '2', 'field_or_chunk_id' => 'analytes[0].value', 'quote_or_value' => '4.4']],
                'unit' => ['isPresent' => true, 'value' => 'mmol/L', 'confidence' => 0.95, 'citation' => ['source_type' => 'lab_pdf', 'source_id' => '999999', 'page_or_section' => '2', 'field_or_chunk_id' => 'analytes[0].unit', 'quote_or_value' => 'mmol/L']],
                'referenceRange' => ['isPresent' => false, 'value' => null, 'confidence' => null, 'citation' => null],
                'abnormalFlag' => ['isPresent' => false, 'value' => null, 'confidence' => null, 'citation' => null],
                'collectionDate' => '2026-07-01',
            ]],
        ]);
        $transport = static fn (array $requestBody): array => [200, ['content' => [['type' => 'text', 'text' => $wire]]]];

        $path = sys_get_temp_dir() . '/copilot-src-' . uniqid('', true) . '-' . $bytesTag . '.pdf';
        file_put_contents($path, '%PDF-1.7 source-resolver fixture ' . $bytesTag . "\n");
        $this->tempFiles[] = $path;

        $service = new DocumentIngestionService(
            new PatientDocumentAttacher(),
            new VlmDocumentExtractor($transport, new SourceResolverDisclosureSpy(), 'claude-opus-4-8'),
        );
        $result = $service->attachAndExtract($this->physician, $patientUuid, $path, 'lab_pdf');
        $this->assertSame('extracted', $result['extraction_status']);
        $this->assertIsNumeric($result['document_id']);

        return (int) $result['document_id'];
    }

    public function testGuidelineTokenResolvesThroughTheRealCorpusIndex(): void
    {
        [, $uuid] = $this->fixturePatient('guide');
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $preview = $endpoint->handle($this->physician, [
            'token' => 'guideline:protocol-htn-v1#htn.bp-target',
            'patient_uuid' => $uuid,
        ]);

        $this->assertSame('guideline', $preview['type']);
        $this->assertSame('protocol-htn-v1', $preview['source_id']);
        $this->assertSame('htn.bp-target', $preview['chunk_id']);
        $heading = $preview['heading'] ?? null;
        $this->assertIsString($heading);
        $this->assertNotSame('', trim($heading));
        $snippet = $preview['snippet'] ?? null;
        $this->assertIsString($snippet);
        $this->assertStringContainsString('130/80', $snippet, 'the snippet is the real chunk body, not a placeholder');
    }

    public function testDocumentExtractionTokenResolvesToTheAttachedDocument(): void
    {
        [, $uuid] = $this->fixturePatient('doc');
        $documentId = $this->ingestLabDocument($uuid, 'own');
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $preview = $endpoint->handle($this->physician, [
            'token' => 'lab_pdf:' . $documentId . '#analytes[0].value',
            'patient_uuid' => $uuid,
        ]);

        $this->assertSame('document', $preview['type']);
        $this->assertSame($documentId, $preview['document_id']);
        $filename = $preview['filename'] ?? null;
        $this->assertIsString($filename);
        $this->assertNotSame('', trim($filename));
        $this->assertSame('2', $preview['page'], 'the cited page rides the preview so the viewer can open to it');
        $this->assertSame('4.4', $preview['quote'], 'the cited value, byte-exact — the thing the clinician verifies');
        $this->assertSame('analytes[0].value', $preview['field'], 'the schema field path is the citation anchor');
    }

    public function testDocumentTokenForAnotherPatientsDocumentIsRefused(): void
    {
        [, $ownerUuid] = $this->fixturePatient('owner');
        [, $otherUuid] = $this->fixturePatient('other');
        $documentId = $this->ingestLabDocument($ownerUuid, 'leak');
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $this->expectException(\DomainException::class);
        $endpoint->handle($this->physician, [
            'token' => 'lab_pdf:' . $documentId . '#analytes[0].value',
            'patient_uuid' => $otherUuid,
        ]);
    }

    public function testDetectorTokenResolvesToTypedLabelWithoutDbState(): void
    {
        [, $uuid] = $this->fixturePatient('det');
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $preview = $endpoint->handle($this->physician, [
            'token' => 'detector:panic-potassium-high',
            'patient_uuid' => $uuid,
        ]);

        $this->assertSame('detector', $preview['type']);
        $this->assertSame('panic-potassium-high', $preview['finding_id']);
        $label = $preview['label'] ?? null;
        $this->assertIsString($label);
        $this->assertNotSame('', trim($label));
    }

    public function testChartTokenResolvesToKindAndLabelOnly(): void
    {
        [, $uuid] = $this->fixturePatient('chart');
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $preview = $endpoint->handle($this->physician, [
            'token' => 'procedure_result:lab-potassium',
            'patient_uuid' => $uuid,
        ]);

        $this->assertSame('chart', $preview['type']);
        $this->assertSame('procedure_result', $preview['source_type']);
        $this->assertSame('lab-potassium', $preview['source_id']);
    }

    public function testUnknownChunkFailsLoudNeverAGuessedPreview(): void
    {
        [, $uuid] = $this->fixturePatient('unk');
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $this->expectException(\DomainException::class);
        $endpoint->handle($this->physician, [
            'token' => 'guideline:protocol-htn-v1#htn.not-a-chunk',
            'patient_uuid' => $uuid,
        ]);
    }

    public function testMalformedTokenFailsLoud(): void
    {
        [, $uuid] = $this->fixturePatient('mal');
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $this->expectException(\DomainException::class);
        $endpoint->handle($this->physician, ['token' => 'no-colon-here', 'patient_uuid' => $uuid]);
    }
}

/**
 * Frozen-test support: collecting spy for the DisclosureLogger port (named
 * class with a public array, per this repo's spy convention).
 */
final class SourceResolverDisclosureSpy implements DisclosureLogger
{
    /** @var list<Disclosure> */
    public array $records = [];

    public function record(Disclosure $disclosure): void
    {
        $this->records[] = $disclosure;
    }
}

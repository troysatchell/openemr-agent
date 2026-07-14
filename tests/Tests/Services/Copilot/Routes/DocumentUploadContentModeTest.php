<?php

/**
 * FROZEN acceptance tests — Wave K.2 (TRO-43 completion): the document
 * upload route gains a browser-reachable content mode
 * (W2_ARCHITECTURE.md §1/§2; UC6; MVP row 1).
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Contract under test — an ADDITIVE
 * extension of DocumentUploadEndpoint's input contract, sanctioned
 * 2026-07-14 after Wave K found the gap: the existing 'file_path' mode
 * (server-side path; frozen Wave H tests) stays intact, and a second mode
 * accepts what a browser can actually send:
 *
 *   'file_content_b64' (strict base64 of the raw file bytes) + 'filename'
 *   (extension-allowlisted exactly like file_path).
 *
 * The endpoint materializes the decoded bytes server-side and hands the
 * SAME composed ingestion path the same arguments — attach, dedupe-by-hash,
 * VLM extract behind the disclosure, schema parse, persist. Exactly one of
 * the two modes must be supplied: both present is ambiguity and refuses;
 * neither refuses; malformed base64 refuses; a disallowed extension on
 * 'filename' refuses — all \DomainException with no internals, before
 * anything is attached or logged.
 *
 * DB-BACKED: real attach + persist; VLM replayed at the transport, per
 * DocumentIngestionServiceTest's pattern.
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
use OpenEMR\Modules\Copilot\Routes\DocumentUploadEndpoint;
use PHPUnit\Framework\TestCase;

class DocumentUploadContentModeTest extends TestCase
{
    /** @var list<int> */
    private array $pids = [];

    private PhysicianContext $physician;

    protected function setUp(): void
    {
        ExtractionLineageSchema::ensureInstalled();
        IntakeCandidatesSchema::ensureInstalled();
        $this->physician = new PhysicianContext('dr-tran', 1);
    }

    protected function tearDown(): void
    {
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
            QueryUtils::sqlStatementThrowException('DELETE FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE . ' WHERE patient_pid = ?', [$pid]);
            QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$pid]);
        }
    }

    /**
     * @return array{int, string} [pid, uuid]
     */
    private function fixturePatient(): array
    {
        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 7000;
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO patient_data (pid, pubpid, fname, lname, date, uuid) VALUES (?, ?, ?, ?, NOW(), UNHEX(REPLACE(UUID(),'-','')))",
            [$pid, 'copilot-b64-' . $pid, 'Upload', 'Fixture'],
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

    private function endpoint(): DocumentUploadEndpoint
    {
        $wire = (string) json_encode([
            'documentId' => '999999',
            'chiefConcern' => ['isPresent' => true, 'value' => 'Annual physical', 'confidence' => 0.95, 'citation' => ['source_type' => 'intake_form', 'source_id' => '999999', 'page_or_section' => '1', 'field_or_chunk_id' => 'chiefConcern', 'quote_or_value' => 'Annual physical']],
            'currentMedications' => [],
            'allergies' => [],
            'familyHistory' => [],
            'demographics' => [],
        ]);
        $transport = static fn (array $requestBody): array => [200, ['content' => [['type' => 'text', 'text' => $wire]]]];

        return new DocumentUploadEndpoint(new DocumentIngestionService(
            new PatientDocumentAttacher(),
            new VlmDocumentExtractor($transport, new ContentModeDisclosureSpy(), 'claude-opus-4-8'),
        ));
    }

    public function testBase64ContentModeRoundTripsAttachAndExtract(): void
    {
        [$pid, $uuid] = $this->fixturePatient();

        $result = $this->endpoint()->handle($this->physician, [
            'patient_uuid' => $uuid,
            'doc_type' => 'intake_form',
            'filename' => 'front-desk-intake.pdf',
            'file_content_b64' => base64_encode("%PDF-1.7 browser-upload fixture bytes\n"),
        ]);

        $this->assertSame('extracted', $result['extraction_status']);
        $this->assertIsNumeric($result['document_id']);

        $owner = QueryUtils::fetchSingleValue('SELECT foreign_id AS f FROM documents WHERE id = ?', 'f', [(int) $result['document_id']]);
        $this->assertIsNumeric($owner);
        $this->assertSame($pid, (int) $owner, 'the document attached to the named patient');
    }

    public function testFilePathModeStillWorksUnchanged(): void
    {
        [, $uuid] = $this->fixturePatient();

        $path = sys_get_temp_dir() . '/copilot-b64-legacy-' . uniqid('', true) . '.pdf';
        file_put_contents($path, "%PDF-1.7 legacy path-mode fixture bytes\n");

        try {
            $result = $this->endpoint()->handle($this->physician, [
                'patient_uuid' => $uuid,
                'doc_type' => 'intake_form',
                'file_path' => $path,
                'file_size_bytes' => filesize($path),
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertSame('extracted', $result['extraction_status']);
    }

    public function testBothModesTogetherRefuseAsAmbiguous(): void
    {
        [, $uuid] = $this->fixturePatient();

        $this->expectException(\DomainException::class);
        $this->endpoint()->handle($this->physician, [
            'patient_uuid' => $uuid,
            'doc_type' => 'intake_form',
            'filename' => 'a.pdf',
            'file_content_b64' => base64_encode('x'),
            'file_path' => '/tmp/somewhere.pdf',
            'file_size_bytes' => 1,
        ]);
    }

    public function testNeitherModeRefuses(): void
    {
        [, $uuid] = $this->fixturePatient();

        $this->expectException(\DomainException::class);
        $this->endpoint()->handle($this->physician, [
            'patient_uuid' => $uuid,
            'doc_type' => 'intake_form',
        ]);
    }

    public function testMalformedBase64Refuses(): void
    {
        [, $uuid] = $this->fixturePatient();

        $this->expectException(\DomainException::class);
        $this->endpoint()->handle($this->physician, [
            'patient_uuid' => $uuid,
            'doc_type' => 'intake_form',
            'filename' => 'a.pdf',
            'file_content_b64' => 'not valid base64 !!!',
        ]);
    }

    public function testDisallowedExtensionOnFilenameRefuses(): void
    {
        [, $uuid] = $this->fixturePatient();

        $this->expectException(\DomainException::class);
        $this->endpoint()->handle($this->physician, [
            'patient_uuid' => $uuid,
            'doc_type' => 'intake_form',
            'filename' => 'payload.exe',
            'file_content_b64' => base64_encode('MZ...'),
        ]);
    }
}

/**
 * Frozen-test support: collecting spy for the DisclosureLogger port.
 */
final class ContentModeDisclosureSpy implements DisclosureLogger
{
    /** @var list<Disclosure> */
    public array $records = [];

    public function record(Disclosure $disclosure): void
    {
        $this->records[] = $disclosure;
    }
}

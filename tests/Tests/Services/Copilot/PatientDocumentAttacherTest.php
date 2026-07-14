<?php

/**
 * FROZEN acceptance tests — TRO-17: native document attach + dedupe-by-content-hash (W2_ARCHITECTURE §2 step 2).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED: exercises the real documents/categories tables — the seam
 * between the module and OpenEMR's native document store is exactly the code
 * that lies in-memory. Contract under test: attach() stores the file as a
 * native patient document (the same storage the rest of the EMR reads) under
 * the dedicated 'Clinical Co-Pilot' category (created if absent), stamps a
 * content hash, and DEDUPES by it: re-attaching identical bytes for the same
 * patient returns the existing document id with no second row (D8 discipline
 * applied to documents). Blank inputs are refused before anything persists.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Ingestion\PatientDocumentAttacher;
use PHPUnit\Framework\TestCase;

class PatientDocumentAttacherTest extends TestCase
{
    private const CATEGORY_NAME = 'Clinical Co-Pilot';

    private int $pid = 0;

    protected function setUp(): void
    {
        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $this->pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 1000;

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO patient_data (pid, pubpid, fname, lname, date) VALUES (?, ?, ?, ?, NOW())',
            [$this->pid, 'copilot-test-' . $this->pid, 'Attach', 'Fixture'],
        );
    }

    protected function tearDown(): void
    {
        $docIds = QueryUtils::fetchTableColumn(
            'SELECT id FROM documents WHERE foreign_id = ?',
            'id',
            [$this->pid],
        );
        foreach ($docIds as $docId) {
            if (!is_numeric($docId)) {
                continue;
            }
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

        QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$this->pid]);
    }

    private function attacher(): PatientDocumentAttacher
    {
        return new PatientDocumentAttacher();
    }

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('dr-tran', 1);
    }

    private function documentCountForPatient(): int
    {
        $count = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM documents WHERE foreign_id = ?', 'c', [$this->pid]);

        return is_numeric($count) ? (int) $count : -1;
    }

    public function testAttachStoresANativeDocumentUnderTheCopilotCategory(): void
    {
        $result = $this->attacher()->attach($this->physician(), $this->pid, 'panel-2026-07-01.pdf', 'application/pdf', '%PDF-1.7 fixture-bytes-A');

        $this->assertGreaterThan(0, $result->documentId);
        $this->assertFalse($result->deduplicated);

        $row = QueryUtils::querySingleRow('SELECT foreign_id, hash, mimetype FROM documents WHERE id = ?', [$result->documentId]);
        $this->assertIsArray($row);
        $this->assertEquals($this->pid, $row['foreign_id']);
        $this->assertIsString($row['hash']);
        $this->assertNotSame('', trim($row['hash']), 'the content hash is the dedupe key and must be stamped');
        $this->assertSame('application/pdf', $row['mimetype']);

        $categoryName = QueryUtils::fetchSingleValue(
            'SELECT c.name FROM categories c JOIN categories_to_documents cd ON cd.category_id = c.id WHERE cd.document_id = ?',
            'name',
            [$result->documentId],
        );
        $this->assertSame(self::CATEGORY_NAME, $categoryName);
    }

    public function testReattachingIdenticalBytesReturnsTheExistingDocument(): void
    {
        $first = $this->attacher()->attach($this->physician(), $this->pid, 'panel.pdf', 'application/pdf', '%PDF-1.7 same-bytes');
        $second = $this->attacher()->attach($this->physician(), $this->pid, 'panel-rescan.pdf', 'application/pdf', '%PDF-1.7 same-bytes');

        $this->assertSame($first->documentId, $second->documentId);
        $this->assertTrue($second->deduplicated);
        $this->assertSame(1, $this->documentCountForPatient(), 're-uploading the same file must not create a second row');
    }

    public function testDifferentBytesCreateANewDocument(): void
    {
        $first = $this->attacher()->attach($this->physician(), $this->pid, 'panel-a.pdf', 'application/pdf', '%PDF-1.7 bytes-A');
        $second = $this->attacher()->attach($this->physician(), $this->pid, 'panel-b.pdf', 'application/pdf', '%PDF-1.7 bytes-B');

        $this->assertNotSame($first->documentId, $second->documentId);
        $this->assertFalse($second->deduplicated);
        $this->assertSame(2, $this->documentCountForPatient());
    }

    public function testSameBytesForADifferentPatientAreNotDeduplicated(): void
    {
        // Dedupe is per patient: two patients may legitimately hold copies
        // of an identical form; collapsing across patients would cross-link
        // records.
        $otherPid = $this->pid + 1;
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO patient_data (pid, pubpid, fname, lname, date) VALUES (?, ?, ?, ?, NOW())',
            [$otherPid, 'copilot-test-' . $otherPid, 'Attach', 'FixtureTwo'],
        );

        try {
            $first = $this->attacher()->attach($this->physician(), $this->pid, 'form.pdf', 'application/pdf', '%PDF-1.7 shared-bytes');
            $second = $this->attacher()->attach($this->physician(), $otherPid, 'form.pdf', 'application/pdf', '%PDF-1.7 shared-bytes');

            $this->assertNotSame($first->documentId, $second->documentId);
            $this->assertFalse($second->deduplicated);
        } finally {
            $docIds = QueryUtils::fetchTableColumn('SELECT id FROM documents WHERE foreign_id = ?', 'id', [$otherPid]);
            foreach ($docIds as $docId) {
                if (is_numeric($docId)) {
                    QueryUtils::sqlStatementThrowException('DELETE FROM categories_to_documents WHERE document_id = ?', [(int) $docId]);
                    QueryUtils::sqlStatementThrowException('DELETE FROM documents WHERE id = ?', [(int) $docId]);
                }
            }
            QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$otherPid]);
        }
    }

    public function testBlankInputsAreRefusedBeforeAnythingPersists(): void
    {
        foreach (
            [
                ['', 'application/pdf', 'bytes'],
                ['file.pdf', '  ', 'bytes'],
                ['file.pdf', 'application/pdf', ''],
            ] as [$fileName, $mimeType, $bytes]
        ) {
            try {
                $this->attacher()->attach($this->physician(), $this->pid, $fileName, $mimeType, $bytes);
                $this->fail('expected a \DomainException refusal');
            } catch (\DomainException) {
                // expected
            }
        }

        $this->assertSame(0, $this->documentCountForPatient(), 'refused input must never persist a document');
    }
}

<?php

/**
 * FROZEN acceptance tests — TRO-21: list-shaped intake facts as module-owned reconciliation candidates (W2_ARCHITECTURE §2 step 5, §10; PS-4).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract under test: list-shaped clinical facts (intake meds,
 * allergies, family history, demographics, chief concern) persist as
 * module-owned extraction records surfaced as cited reconciliation
 * candidates — NEVER written into the native med/allergy lists (that would
 * be write-back through the side door; med reconciliation is a clinical act
 * beyond the two-write amendment). Re-persisting the same document versions
 * the candidate set: prior rows are superseded (retained, stamped), never
 * silently overwritten (§10).
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
use OpenEMR\Modules\Copilot\Extraction\IntakeFormExtraction;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidatesSchema;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidateWriter;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class IntakeCandidateWriterTest extends TestCase
{
    private int $pid = 0;

    protected function setUp(): void
    {
        IntakeCandidatesSchema::ensureInstalled();

        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $this->pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + 3000;
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO patient_data (pid, pubpid, fname, lname, date) VALUES (?, ?, ?, ?, NOW())',
            [$this->pid, 'copilot-icw-' . $this->pid, 'Intake', 'Fixture'],
        );
    }

    protected function tearDown(): void
    {
        QueryUtils::sqlStatementThrowException(
            'DELETE FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE . ' WHERE patient_pid = ?',
            [$this->pid],
        );
        QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$this->pid]);
    }

    private function present(string $value, string $fieldPath): ExtractedField
    {
        return ExtractedField::present(
            $value,
            new ExtractionConfidence(0.85),
            new SourceRef('intake_form', '72', '1', $fieldPath, $value),
        );
    }

    private function extraction(string $documentId = '72'): IntakeFormExtraction
    {
        return new IntakeFormExtraction(
            $documentId,
            $this->present('chest pain on exertion', 'chiefConcern'),
            [$this->present('metoprolol 50mg BID', 'currentMedications[0]'), $this->present('lisinopril 10mg', 'currentMedications[1]')],
            [$this->present('penicillin', 'allergies[0]')],
            [],
            [],
        );
    }

    private function nativeCounts(): array
    {
        return [
            'lists' => QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM lists', 'c', []),
            'prescriptions' => QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM prescriptions', 'c', []),
        ];
    }

    private function activeCandidates(): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT field_group, value_text, field_path, page, confidence FROM '
            . IntakeCandidatesSchema::CANDIDATES_TABLE
            . ' WHERE patient_pid = ? AND superseded_at IS NULL ORDER BY id',
            [$this->pid],
        );

        return is_array($rows) ? $rows : [];
    }

    public function testPersistStoresCitedCandidatesPerPresentEntry(): void
    {
        $set = (new IntakeCandidateWriter())->persist(new PhysicianContext('dr-tran', 1), $this->pid, $this->extraction());

        $this->assertCount(4, $set->candidateIds, 'chief concern + 2 meds + 1 allergy');

        $rows = $this->activeCandidates();
        $this->assertCount(4, $rows);

        $groups = array_column($rows, 'field_group');
        $this->assertContains('chiefConcern', $groups);
        $this->assertContains('currentMedications', $groups);
        $this->assertContains('allergies', $groups);

        $medRow = null;
        foreach ($rows as $row) {
            if (($row['value_text'] ?? null) === 'metoprolol 50mg BID') {
                $medRow = $row;
            }
        }
        $this->assertIsArray($medRow);
        $this->assertSame('currentMedications[0]', $medRow['field_path']);
        $this->assertSame('1', $medRow['page']);
        $this->assertEqualsWithDelta(0.85, (float) $medRow['confidence'], 0.0001);
    }

    public function testNativeMedAndAllergyListsAreNeverTouched(): void
    {
        $before = $this->nativeCounts();

        (new IntakeCandidateWriter())->persist(new PhysicianContext('dr-tran', 1), $this->pid, $this->extraction());

        $this->assertSame($before, $this->nativeCounts(), 'writing native lists would be write-back through the side door');
    }

    public function testEmptyExtractionPersistsNothingWithoutError(): void
    {
        $empty = new IntakeFormExtraction('72', ExtractedField::absent(), [], [], [], []);

        $set = (new IntakeCandidateWriter())->persist(new PhysicianContext('dr-tran', 1), $this->pid, $empty);

        $this->assertSame([], $set->candidateIds);
        $this->assertSame([], $this->activeCandidates());
    }

    public function testRepersistingADocumentSupersedesRetainsAndReplaces(): void
    {
        $writer = new IntakeCandidateWriter();
        $physician = new PhysicianContext('dr-tran', 1);

        $writer->persist($physician, $this->pid, $this->extraction());
        $second = new IntakeFormExtraction(
            '72',
            $this->present('chest pain, worsening', 'chiefConcern'),
            [$this->present('metoprolol 50mg BID', 'currentMedications[0]')],
            [],
            [],
            [],
        );
        $writer->persist($physician, $this->pid, $second);

        $active = $this->activeCandidates();
        $this->assertCount(2, $active, 're-extraction defines the new active set');

        $total = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE . ' WHERE patient_pid = ?',
            'c',
            [$this->pid],
        );
        $this->assertEquals(6, $total, 'prior candidates are retained superseded, never silently overwritten (§10)');
    }

    public function testNonNumericDocumentIdIsRefused(): void
    {
        $bad = new IntakeFormExtraction('doc-x', ExtractedField::absent(), [], [], [], []);

        $this->expectException(\DomainException::class);
        (new IntakeCandidateWriter())->persist(new PhysicianContext('dr-tran', 1), $this->pid, $bad);
    }
}

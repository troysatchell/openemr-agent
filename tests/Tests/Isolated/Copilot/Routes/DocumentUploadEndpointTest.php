<?php

/**
 * FROZEN acceptance tests — TRO-16: the guarded document-upload endpoint's wire contract (W2_ARCHITECTURE §2 step 1).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: DocumentUploadEndpoint is a thin shaping layer (the
 * TurnEndpoint idiom — no route or ACL knowledge; GuardedRouteRegistrar owns
 * authz at the wiring layer, S5). It parses raw route input into typed
 * arguments and refuses bad input BEFORE the ingestion port runs: blank
 * patient_uuid; doc_type outside the closed set {lab_pdf, intake_form};
 * file_path with a disallowed extension (allowlist: pdf, png, jpg, jpeg —
 * case-insensitive); file_size_bytes missing, non-int, non-positive, or over
 * the 10 MiB cap (§10 type/size allowlist at upload). Valid input delegates
 * to the DocumentIngestion port exactly once and returns its result under
 * the documented wire keys.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Routes;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestion;
use OpenEMR\Modules\Copilot\Routes\DocumentUploadEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentUploadEndpointTest extends TestCase
{
    private RecordingDocumentIngestion $port;

    protected function setUp(): void
    {
        $this->port = new RecordingDocumentIngestion();
    }

    /**
     * @return list<array{patientUuid: string, filePath: string, docType: string}>
     */
    private function portCalls(): array
    {
        return $this->port->calls;
    }

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('dr-tran', 7);
    }

    private function endpoint(): DocumentUploadEndpoint
    {
        return new DocumentUploadEndpoint($this->port);
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(): array
    {
        return [
            'patient_uuid' => 'a23de234-f850-41e7-bfd9-935107c4e0b9',
            'file_path' => '/uploads/labs/panel-2026-07-01.pdf',
            'doc_type' => 'lab_pdf',
            'file_size_bytes' => 245760,
        ];
    }

    public function testValidUploadDelegatesToThePortOnceAndShapesTheResult(): void
    {
        $result = $this->endpoint()->handle($this->physician(), $this->validInput());

        $this->assertSame('doc-99', $result['document_id']);
        $this->assertSame('pending', $result['extraction_status']);
        $this->assertCount(1, $this->portCalls());
        $this->assertSame('lab_pdf', $this->portCalls()[0]['docType']);
        $this->assertSame('/uploads/labs/panel-2026-07-01.pdf', $this->portCalls()[0]['filePath']);
    }

    public function testIntakeFormIsAnAcceptedDocType(): void
    {
        $input = $this->validInput();
        $input['doc_type'] = 'intake_form';
        $input['file_path'] = '/uploads/intake/front-desk-scan.PNG';

        $result = $this->endpoint()->handle($this->physician(), $input);

        $this->assertSame('doc-99', $result['document_id']);
    }

    /**
     * @return array<string, array{string, mixed}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function rejectedInputProvider(): array
    {
        return [
            'blank patient_uuid' => ['patient_uuid', '   '],
            'missing patient_uuid' => ['patient_uuid', null],
            'unknown doc_type' => ['doc_type', 'radiology_report'],
            'blank doc_type' => ['doc_type', ''],
            'disallowed extension' => ['file_path', '/uploads/evil.exe'],
            'no extension' => ['file_path', '/uploads/labs/panel'],
            'blank file_path' => ['file_path', '  '],
            'zero size' => ['file_size_bytes', 0],
            'negative size' => ['file_size_bytes', -5],
            'over the 10 MiB cap' => ['file_size_bytes', 10 * 1024 * 1024 + 1],
            'non-int size' => ['file_size_bytes', '245760'],
            'missing size' => ['file_size_bytes', null],
        ];
    }

    #[DataProvider('rejectedInputProvider')]
    public function testInvalidInputIsRefusedBeforeThePortRuns(string $key, mixed $badValue): void
    {
        $input = $this->validInput();
        if ($badValue === null) {
            unset($input[$key]);
        } else {
            $input[$key] = $badValue;
        }

        try {
            $this->endpoint()->handle($this->physician(), $input);
            $this->fail('expected a \DomainException refusal');
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame([], $this->portCalls(), 'the ingestion port must never run on refused input');
    }

    public function testExactlyTenMiBIsAccepted(): void
    {
        $input = $this->validInput();
        $input['file_size_bytes'] = 10 * 1024 * 1024;

        $result = $this->endpoint()->handle($this->physician(), $input);

        $this->assertSame('doc-99', $result['document_id']);
    }
}

/**
 * Recording spy for the DocumentIngestion port — frozen-test support only.
 */
final class RecordingDocumentIngestion implements DocumentIngestion
{
    /** @var list<array{patientUuid: string, filePath: string, docType: string}> */
    public array $calls = [];

    public function attachAndExtract(
        PhysicianContext $physician,
        string $patientUuid,
        string $filePath,
        string $docType,
    ): array {
        $this->calls[] = ['patientUuid' => $patientUuid, 'filePath' => $filePath, 'docType' => $docType];

        return ['document_id' => 'doc-99', 'extraction_status' => 'pending'];
    }
}

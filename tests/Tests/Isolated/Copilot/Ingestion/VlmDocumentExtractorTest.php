<?php

/**
 * FROZEN acceptance tests — TRO-18: VLM extraction call with disclosure-before-call (W2_ARCHITECTURE §2 step 3; C1/C5).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: sending a document to the VLM is a PHI disclosure —
 * recorded through the Week 1 DisclosureLogger BEFORE the transport runs,
 * under the document-media payload category, carrying the turn's correlation
 * ID. The wire call rides an injected transport (the AnthropicLlmClient
 * idiom) whose request carries a document content block with the base64
 * payload. Transport faults and malformed responses raise
 * LlmUnavailableException — and the disclosure is on record either way,
 * because the disclosure describes the attempt, not the outcome.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Ingestion;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use PHPUnit\Framework\TestCase;

class VlmDocumentExtractorTest extends TestCase
{
    private CallSequenceRecorder $sequence;

    private RecordingDisclosureLogger $logger;

    /** @var list<array<string, mixed>> */
    private array $transportBodies = [];

    protected function setUp(): void
    {
        $this->sequence = new CallSequenceRecorder();
        $this->logger = new RecordingDisclosureLogger($this->sequence);
        $this->transportBodies = [];
    }

    /**
     * @param array{int, array<string, mixed>}|null $response null = the transport throws
     */
    private function transport(?array $response): \Closure
    {
        $sequence = $this->sequence;
        $bodies = &$this->transportBodies;

        return static function (array $requestBody) use ($sequence, &$bodies, $response): array {
            $sequence->mark('transport');
            $bodies[] = $requestBody;
            if ($response === null) {
                throw new \RuntimeException('socket closed');
            }

            return $response;
        };
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function okResponse(string $text): array
    {
        return [200, ['content' => [['type' => 'text', 'text' => $text]]]];
    }

    /**
     * @param array{int, array<string, mixed>}|null $response
     */
    private function extractor(?array $response): VlmDocumentExtractor
    {
        return new VlmDocumentExtractor($this->transport($response), $this->logger, 'claude-opus-4-8');
    }

    private function extract(VlmDocumentExtractor $extractor): string
    {
        return $extractor->extract(
            new PhysicianContext('dr-tran', 7),
            42,
            'doc-42',
            'lab_pdf',
            'application/pdf',
            base64_encode('%PDF-1.7 fake'),
            TraceContext::start('document-extraction', new \DateTimeImmutable('2026-07-13 12:00:00')),
            new \DateTimeImmutable('2026-07-13 12:00:00'),
        );
    }

    public function testDisclosureIsRecordedBeforeTheTransportRuns(): void
    {
        $this->extract($this->extractor($this->okResponse('{"documentId":"doc-42","analytes":[]}')));

        $this->assertSame(['disclosure', 'transport'], $this->sequence->marks);
    }

    public function testDisclosureCarriesTheDocumentMediaCategoryPatientAndCorrelationId(): void
    {
        $this->extract($this->extractor($this->okResponse('{}')));

        $this->assertCount(1, $this->logger->records);
        $disclosure = $this->logger->records[0];
        $this->assertSame('dr-tran', $disclosure->userId);
        $this->assertSame(42, $disclosure->patientPid);
        $this->assertContains('document-media', $disclosure->dataClasses);
        $this->assertContains('lab_pdf', $disclosure->dataClasses);
        $this->assertNotNull($disclosure->correlationId);
    }

    public function testTransportReceivesADocumentContentBlockWithTheBase64Payload(): void
    {
        $payload = base64_encode('%PDF-1.7 fake');
        $this->extract($this->extractor($this->okResponse('{}')));

        $this->assertCount(1, $this->transportBodies);
        $encoded = (string) json_encode($this->transportBodies[0]);
        $this->assertStringContainsString('"document"', $encoded);
        $this->assertStringContainsString('application\/pdf', $encoded);
        $this->assertStringContainsString($payload, $encoded);
    }

    public function testReturnsTheModelTextVerbatim(): void
    {
        $json = '{"documentId":"doc-42","analytes":[]}';

        $this->assertSame($json, $this->extract($this->extractor($this->okResponse($json))));
    }

    public function testTransportFaultRaisesUnavailableAndTheDisclosureIsStillOnRecord(): void
    {
        try {
            $this->extract($this->extractor(null));
            $this->fail('expected LlmUnavailableException');
        } catch (LlmUnavailableException) {
            // expected
        }

        $this->assertSame(['disclosure', 'transport'], $this->sequence->marks, 'the disclosure describes the attempt, not the outcome');
    }

    public function testNon200StatusRaisesUnavailable(): void
    {
        $this->expectException(LlmUnavailableException::class);
        $this->extract($this->extractor([529, ['type' => 'error']]));
    }

    public function testMalformedResponseBodyRaisesUnavailable(): void
    {
        $this->expectException(LlmUnavailableException::class);
        $this->extract($this->extractor([200, ['content' => []]]));
    }
}

/**
 * Frozen-test support: ordered call-sequence marks shared by the spies.
 */
final class CallSequenceRecorder
{
    /** @var list<string> */
    public array $marks = [];

    public function mark(string $mark): void
    {
        $this->marks[] = $mark;
    }
}

/**
 * Frozen-test support: recording spy for the DisclosureLogger port.
 */
final class RecordingDisclosureLogger implements DisclosureLogger
{
    /** @var list<Disclosure> */
    public array $records = [];

    public function __construct(private readonly CallSequenceRecorder $sequence)
    {
    }

    public function record(Disclosure $disclosure): void
    {
        $this->sequence->mark('disclosure');
        $this->records[] = $disclosure;
    }
}

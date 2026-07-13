<?php

/**
 * FROZEN acceptance tests — TRO-14: the Week 2 five-field SourceRef (W2_ARCHITECTURE §4).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: SourceRef grows from {sourceType, sourceId} to
 * {source_type, source_id, page_or_section, field_or_chunk_id, quote_or_value},
 * used identically by chart facts, document extractions, guideline evidence,
 * and detector findings. The extension is BACKWARD-COMPATIBLE: every Week 1
 * two-argument call site keeps constructing (migration note, §13) with the
 * three new fields null. A non-null new field must be non-blank — blank
 * provenance components are no provenance at all.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Synthesis;

use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceRefWeek2ShapeTest extends TestCase
{
    public function testTwoArgumentConstructionRemainsValidWithNullWeek2Fields(): void
    {
        $ref = new SourceRef('problem', 'p-1');

        $this->assertSame('problem', $ref->sourceType);
        $this->assertSame('p-1', $ref->sourceId);
        $this->assertNull($ref->pageOrSection);
        $this->assertNull($ref->fieldOrChunkId);
        $this->assertNull($ref->quoteOrValue);
    }

    public function testFiveFieldConstructionExposesAllFields(): void
    {
        $ref = new SourceRef('guideline', 'protocol-htn-v1', 'Blood-pressure target', 'htn.bp-target', 'target <130/80 for most adults');

        $this->assertSame('guideline', $ref->sourceType);
        $this->assertSame('protocol-htn-v1', $ref->sourceId);
        $this->assertSame('Blood-pressure target', $ref->pageOrSection);
        $this->assertSame('htn.bp-target', $ref->fieldOrChunkId);
        $this->assertSame('target <130/80 for most adults', $ref->quoteOrValue);
    }

    public function testDocumentExtractionShapeCarriesPageAndFieldPath(): void
    {
        $ref = new SourceRef('lab_pdf', 'doc-42', '2', 'analytes[0].value', '6.8');

        $this->assertSame('2', $ref->pageOrSection);
        $this->assertSame('analytes[0].value', $ref->fieldOrChunkId);
        $this->assertSame('6.8', $ref->quoteOrValue);
    }

    /**
     * @return array<string, array{?string, ?string, ?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function blankOptionalFieldProvider(): array
    {
        return [
            'blank pageOrSection' => ['   ', null, null],
            'blank fieldOrChunkId' => [null, '', null],
            'blank quoteOrValue' => [null, null, "\t"],
        ];
    }

    #[DataProvider('blankOptionalFieldProvider')]
    public function testNonNullButBlankWeek2FieldIsRejected(?string $page, ?string $fieldOrChunk, ?string $quote): void
    {
        $this->expectException(\DomainException::class);
        new SourceRef('lab_pdf', 'doc-42', $page, $fieldOrChunk, $quote);
    }

    public function testRequiredFieldsStillRejectBlank(): void
    {
        $this->expectException(\DomainException::class);
        new SourceRef('', 'doc-42');
    }
}

<?php

/**
 * FROZEN acceptance tests — TRO-13: the extracted-field present/absent marker (W2_ARCHITECTURE §3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: every extracted field is EITHER present — carrying a
 * non-blank value, a per-field confidence, and a source citation it is grounded
 * in — OR explicitly absent (D1: a field the VLM could not ground in a source
 * region is absent, never defaulted). A present field cannot exist without its
 * citation; an absent field carries no value, confidence, or citation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Extraction;

use OpenEMR\Modules\Copilot\Extraction\ExtractedField;
use OpenEMR\Modules\Copilot\Extraction\ExtractionConfidence;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExtractedFieldTest extends TestCase
{
    private function citation(): SourceRef
    {
        return new SourceRef('lab_pdf', 'doc-42');
    }

    public function testPresentFieldCarriesValueConfidenceAndCitation(): void
    {
        $confidence = new ExtractionConfidence(0.9);
        $citation = $this->citation();
        $field = ExtractedField::present('6.8', $confidence, $citation);

        $this->assertTrue($field->isPresent);
        $this->assertSame('6.8', $field->value);
        $this->assertSame($confidence, $field->confidence);
        $this->assertSame($citation, $field->citation);
    }

    public function testAbsentFieldHasNoValueConfidenceOrCitation(): void
    {
        $field = ExtractedField::absent();

        $this->assertFalse($field->isPresent);
        $this->assertNull($field->value);
        $this->assertNull($field->confidence);
        $this->assertNull($field->citation);
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function blankValueProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }

    #[DataProvider('blankValueProvider')]
    public function testPresentFieldRejectsBlankValue(string $value): void
    {
        $this->expectException(\DomainException::class);
        ExtractedField::present($value, new ExtractionConfidence(0.9), $this->citation());
    }
}

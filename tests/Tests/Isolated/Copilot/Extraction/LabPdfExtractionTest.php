<?php

/**
 * FROZEN acceptance tests — TRO-13: lab_pdf extraction DTOs (W2_ARCHITECTURE §3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test (per analyte): test name, value, unit, reference range,
 * abnormal flag (each an ExtractedField), plus a defensively-parsed collection
 * date. Invariants: units are REQUIRED, never inferred — a present value with an
 * absent unit is a \DomainException (a unitless lab value is dangerous); an
 * analyte must carry a present test name; the collection date goes through
 * ClinicalDate (D0/D6) and a non-blank unparseable date is rejected. A
 * LabPdfExtraction ties its analytes to a non-blank source document id.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Extraction;

use OpenEMR\Modules\Copilot\Extraction\ExtractedField;
use OpenEMR\Modules\Copilot\Extraction\ExtractionConfidence;
use OpenEMR\Modules\Copilot\Extraction\LabAnalyteExtraction;
use OpenEMR\Modules\Copilot\Extraction\LabPdfExtraction;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class LabPdfExtractionTest extends TestCase
{
    private function field(string $value, float $confidence = 0.9): ExtractedField
    {
        return ExtractedField::present($value, new ExtractionConfidence($confidence), new SourceRef('lab_pdf', 'doc-42'));
    }

    private function analyte(
        ?ExtractedField $testName = null,
        ?ExtractedField $value = null,
        ?ExtractedField $unit = null,
        ?string $collectionDateRaw = '2024-03-15',
    ): LabAnalyteExtraction {
        return new LabAnalyteExtraction(
            $testName ?? $this->field('Potassium'),
            $value ?? $this->field('6.8'),
            $unit ?? $this->field('mmol/L'),
            ExtractedField::absent(),
            ExtractedField::absent(),
            $collectionDateRaw,
        );
    }

    public function testValidAnalyteConstructsAndExposesFields(): void
    {
        $analyte = $this->analyte();

        $this->assertSame('Potassium', $analyte->testName->value);
        $this->assertSame('6.8', $analyte->value->value);
        $this->assertSame('mmol/L', $analyte->unit->value);
        $this->assertInstanceOf(\DateTimeImmutable::class, $analyte->collectionDate);
        $this->assertSame('2024-03-15', $analyte->collectionDate->format('Y-m-d'));
    }

    public function testPresentValueWithAbsentUnitIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->analyte(value: $this->field('6.8'), unit: ExtractedField::absent());
    }

    public function testAnalyteWithoutTestNameIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->analyte(testName: ExtractedField::absent());
    }

    public function testNonBlankUnparseableCollectionDateIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->analyte(collectionDateRaw: 'next tuesday');
    }

    public function testAbsentCollectionDateIsUnknownNotAnError(): void
    {
        $analyte = $this->analyte(collectionDateRaw: null);
        $this->assertNull($analyte->collectionDate);
    }

    public function testMysqlZeroDateIsTreatedAsUnknown(): void
    {
        $analyte = $this->analyte(collectionDateRaw: '0000-00-00');
        $this->assertNull($analyte->collectionDate);
    }

    public function testExtractionCarriesAnalytesAndSourceDocument(): void
    {
        $extraction = new LabPdfExtraction('doc-42', [$this->analyte(), $this->analyte()]);

        $this->assertSame('doc-42', $extraction->documentId);
        $this->assertCount(2, $extraction->analytes);
    }

    public function testEmptyAnalyteListIsAllowed(): void
    {
        $extraction = new LabPdfExtraction('doc-42', []);
        $this->assertSame([], $extraction->analytes);
    }

    public function testBlankDocumentIdIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        new LabPdfExtraction('   ', [$this->analyte()]);
    }
}

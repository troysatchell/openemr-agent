<?php

/**
 * FROZEN acceptance tests — TRO-44 (extraction side): bounding boxes ride the
 * extraction wire as a UI affordance, never as verification ground (R-W3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract: a new BoundingBox domain primitive (normalized page coordinates,
 * each component in [0,1], zero-area boxes refused); ExtractedField optionally
 * carries one; VlmExtractionParser accepts an OPTIONAL nullable `bbox` key on
 * every field wire object ([x, y, w, h] normalized) and — per R-W3 ("a sloppy
 * box degrades UX, never correctness") — a malformed bbox degrades to null
 * WITHOUT rejecting the field, while the wire without any bbox key stays
 * valid (backward compatibility with every existing fixture). The committed
 * lab_pdf JSON schema mirrors the same optionality.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Extraction;

use OpenEMR\Modules\Copilot\Extraction\BoundingBox;
use OpenEMR\Modules\Copilot\Extraction\ExtractedField;
use OpenEMR\Modules\Copilot\Extraction\ExtractionConfidence;
use OpenEMR\Modules\Copilot\Extraction\VlmExtractionParser;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BboxExtractionContractTest extends TestCase
{
    private const SCHEMA_PATH = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/schemas/extraction/lab_pdf.schema.json';

    public function testBoundingBoxCarriesNormalizedCoordinates(): void
    {
        $box = new BoundingBox(0.12, 0.34, 0.5, 0.04);

        $this->assertSame(0.12, $box->x);
        $this->assertSame(0.34, $box->y);
        $this->assertSame(0.5, $box->width);
        $this->assertSame(0.04, $box->height);
        $this->assertSame('0.1200,0.3400,0.5000,0.0400', $box->toCsv(), 'canonical 4-decimal CSV is the storage form');
    }

    /**
     * @return array<string, array{float, float, float, float}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidBoxProvider(): array
    {
        return [
            'x above 1' => [1.2, 0.0, 0.1, 0.1],
            'negative y' => [0.1, -0.01, 0.1, 0.1],
            'zero width' => [0.1, 0.1, 0.0, 0.1],
            'zero height' => [0.1, 0.1, 0.1, 0.0],
            'width above 1' => [0.1, 0.1, 1.5, 0.1],
        ];
    }

    #[DataProvider('invalidBoxProvider')]
    public function testBoundingBoxRefusesNonNormalizedCoordinates(float $x, float $y, float $w, float $h): void
    {
        $this->expectException(\DomainException::class);
        new BoundingBox($x, $y, $w, $h);
    }

    public function testFromWireParsesAFourNumberArray(): void
    {
        $box = BoundingBox::fromWire([0.12, 0.34, 0.5, 0.04]);

        $this->assertInstanceOf(BoundingBox::class, $box);
        $this->assertSame('0.1200,0.3400,0.5000,0.0400', $box->toCsv());
    }

    /**
     * @return array<string, array{mixed}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function degradedWireProvider(): array
    {
        return [
            'null' => [null],
            'not an array' => ['0.1,0.2,0.3,0.4'],
            'wrong arity' => [[0.1, 0.2, 0.3]],
            'non-numeric member' => [[0.1, 0.2, 'x', 0.4]],
            'out of range' => [[1.5, 0.2, 0.3, 0.4]],
            'zero area' => [[0.1, 0.2, 0.0, 0.4]],
        ];
    }

    /**
     * R-W3: a sloppy box degrades UX, never correctness — malformed wire
     * boxes become null, they never throw and never poison the field.
     *
     */
    #[DataProvider('degradedWireProvider')]
    public function testFromWireDegradesMalformedBoxesToNull(mixed $wire): void
    {
        $this->assertNull(BoundingBox::fromWire($wire));
    }

    public function testExtractedFieldOptionallyCarriesABox(): void
    {
        $citation = new SourceRef('lab_pdf', '72', '2', 'analytes[0].value', '6.8');
        $box = new BoundingBox(0.12, 0.34, 0.5, 0.04);

        $with = ExtractedField::present('6.8', new ExtractionConfidence(0.9), $citation, $box);
        $without = ExtractedField::present('6.8', new ExtractionConfidence(0.9), $citation);

        $this->assertSame($box, $with->bbox);
        $this->assertNull($without->bbox, 'bbox stays optional — no box is a valid extraction');
        $this->assertNull(ExtractedField::absent()->bbox, 'an absent field has no box');
    }

    public function testParserCarriesAWireBoxThroughToTheField(): void
    {
        $extraction = VlmExtractionParser::parseLabPdf($this->labWire(['bbox' => [0.12, 0.34, 0.5, 0.04]]));

        $bbox = $extraction->analytes[0]->value->bbox;
        $this->assertInstanceOf(BoundingBox::class, $bbox);
        $this->assertSame('0.1200,0.3400,0.5000,0.0400', $bbox->toCsv());
    }

    public function testParserAcceptsTheWireWithoutAnyBoxKey(): void
    {
        $extraction = VlmExtractionParser::parseLabPdf($this->labWire([]));

        $this->assertNull($extraction->analytes[0]->value->bbox);
        $this->assertTrue($extraction->analytes[0]->value->isPresent, 'backward compatibility: the pre-bbox wire stays fully valid');
    }

    public function testParserDegradesAMalformedWireBoxToNullWithoutRejectingTheField(): void
    {
        $extraction = VlmExtractionParser::parseLabPdf($this->labWire(['bbox' => [9.9, 0.0, 0.1]]));

        $this->assertNull($extraction->analytes[0]->value->bbox, 'R-W3: malformed box degrades to null');
        $this->assertTrue($extraction->analytes[0]->value->isPresent, 'the field itself is untouched by a sloppy box');
        $this->assertSame('6.8', $extraction->analytes[0]->value->value);
    }

    public function testCommittedSchemaDeclaresBboxAsOptionalAndNullable(): void
    {
        $raw = file_get_contents(self::SCHEMA_PATH);
        $this->assertIsString($raw);
        $schema = json_decode($raw, true);
        $this->assertIsArray($schema);

        $defs = $schema['$defs'] ?? null;
        $this->assertIsArray($defs);
        $field = $defs['ExtractedField'] ?? null;
        $this->assertIsArray($field);

        $properties = $field['properties'] ?? null;
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('bbox', $properties, 'the wire contract names bbox explicitly (additionalProperties stays false)');

        $required = $field['required'] ?? null;
        $this->assertIsArray($required);
        $this->assertNotContains('bbox', $required, 'bbox is optional — old wires and boxless extractions stay valid');
    }

    /**
     * One-analyte lab wire mirroring the live VLM response shape; $valueExtras
     * is merged into the value field's wire object.
     *
     * @param array<string, mixed> $valueExtras
     */
    private function labWire(array $valueExtras): string
    {
        $citation = static fn (string $field, string $quote): array => [
            'source_type' => 'lab_pdf',
            'source_id' => '72',
            'page_or_section' => '2',
            'field_or_chunk_id' => $field,
            'quote_or_value' => $quote,
        ];

        $wire = json_encode([
            'documentId' => '72',
            'analytes' => [[
                'testName' => ['isPresent' => true, 'value' => 'Potassium', 'confidence' => 0.95, 'citation' => $citation('analytes[0].testName', 'Potassium')],
                'value' => array_merge(
                    ['isPresent' => true, 'value' => '6.8', 'confidence' => 0.95, 'citation' => $citation('analytes[0].value', '6.8')],
                    $valueExtras,
                ),
                'unit' => ['isPresent' => true, 'value' => 'mmol/L', 'confidence' => 0.95, 'citation' => $citation('analytes[0].unit', 'mmol/L')],
                'referenceRange' => ['isPresent' => false, 'value' => null, 'confidence' => null, 'citation' => null],
                'abnormalFlag' => ['isPresent' => false, 'value' => null, 'confidence' => null, 'citation' => null],
                'collectionDate' => '2026-07-01',
            ]],
        ]);
        $this->assertIsString($wire);

        return $wire;
    }
}

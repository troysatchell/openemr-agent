<?php

/**
 * FROZEN acceptance tests — TRO-15: DTO ⇄ JSON Schema agreement (W2_ARCHITECTURE §3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the committed JSON Schemas are the canonical extraction
 * contracts — so the PHP DTOs and the schemas must agree exactly, in both
 * directions, and neither can drift silently. Property-name convention, stated
 * once: extraction DTO fields appear in the schemas under their camelCase PHP
 * names; the SourceCitation def alone uses the §4 citation contract's
 * snake_case wire names (source_type, source_id, page_or_section,
 * field_or_chunk_id, quote_or_value) mapped from SourceRef's camelCase
 * properties. A field added to a DTO without its schema — or to a schema
 * without its DTO — fails this suite.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Extraction;

use OpenEMR\Modules\Copilot\Extraction\ExtractedField;
use OpenEMR\Modules\Copilot\Extraction\IntakeFormExtraction;
use OpenEMR\Modules\Copilot\Extraction\LabAnalyteExtraction;
use OpenEMR\Modules\Copilot\Extraction\LabPdfExtraction;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class ExtractionSchemaContractTest extends TestCase
{
    private const SCHEMA_DIR = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/schemas/extraction';

    /**
     * @return array<string, mixed>
     */
    private function schema(string $fileName): array
    {
        $raw = file_get_contents(self::SCHEMA_DIR . '/' . $fileName);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private function propertyNames(array $node): array
    {
        $this->assertArrayHasKey('properties', $node);
        $this->assertIsArray($node['properties']);

        $names = array_keys($node['properties']);
        sort($names);

        return $names;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function publicPropertyNames(string $class): array
    {
        $names = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $names[] = $property->getName();
        }
        sort($names);

        return $names;
    }

    private function toSnakeCase(string $camel): string
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $camel);
        $this->assertIsString($snake);

        return strtolower($snake);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function def(array $schema, string $name): array
    {
        $this->assertArrayHasKey('$defs', $schema);
        $this->assertIsArray($schema['$defs']);
        $this->assertArrayHasKey($name, $schema['$defs'], "schema must define \$defs.{$name}");
        $this->assertIsArray($schema['$defs'][$name]);

        /** @var array<string, mixed> $def */
        $def = $schema['$defs'][$name];

        return $def;
    }

    public function testLabPdfRootMatchesLabPdfExtractionDto(): void
    {
        $this->assertSame(
            $this->publicPropertyNames(LabPdfExtraction::class),
            $this->propertyNames($this->schema('lab_pdf.schema.json')),
        );
    }

    public function testLabAnalyteDefMatchesLabAnalyteExtractionDto(): void
    {
        $this->assertSame(
            $this->publicPropertyNames(LabAnalyteExtraction::class),
            $this->propertyNames($this->def($this->schema('lab_pdf.schema.json'), 'LabAnalyteExtraction')),
        );
    }

    public function testExtractedFieldDefMatchesDtoInBothSchemas(): void
    {
        $dtoProps = $this->publicPropertyNames(ExtractedField::class);

        $this->assertSame($dtoProps, $this->propertyNames($this->def($this->schema('lab_pdf.schema.json'), 'ExtractedField')));
        $this->assertSame($dtoProps, $this->propertyNames($this->def($this->schema('intake_form.schema.json'), 'ExtractedField')));
    }

    public function testIntakeFormRootMatchesIntakeFormExtractionDto(): void
    {
        $this->assertSame(
            $this->publicPropertyNames(IntakeFormExtraction::class),
            $this->propertyNames($this->schema('intake_form.schema.json')),
        );
    }

    public function testSourceCitationDefMatchesSourceRefInSnakeCaseInBothSchemas(): void
    {
        $expected = array_map($this->toSnakeCase(...), $this->publicPropertyNames(SourceRef::class));
        sort($expected);

        $this->assertSame($expected, $this->propertyNames($this->def($this->schema('lab_pdf.schema.json'), 'SourceCitation')));
        $this->assertSame($expected, $this->propertyNames($this->def($this->schema('intake_form.schema.json'), 'SourceCitation')));
    }

    public function testConfidenceBoundsMatchTheDtoInvariant(): void
    {
        foreach (['lab_pdf.schema.json', 'intake_form.schema.json'] as $file) {
            $def = $this->def($this->schema($file), 'ExtractionConfidence');
            $this->assertSame(0, $def['minimum'] ?? null, "{$file}: confidence minimum must be 0");
            $this->assertSame(1, $def['maximum'] ?? null, "{$file}: confidence maximum must be 1");
        }
    }

    public function testEverySchemaPropertyIsRequired(): void
    {
        // The DTOs have no optional construction paths on the wire: absent is
        // an explicit marker (D1), never a missing key.
        foreach (['lab_pdf.schema.json', 'intake_form.schema.json'] as $file) {
            $schema = $this->schema($file);
            $required = $schema['required'] ?? [];
            $this->assertIsArray($required);
            sort($required);
            $this->assertSame($this->propertyNames($schema), $required, "{$file}: every root property must be required");
        }
    }
}

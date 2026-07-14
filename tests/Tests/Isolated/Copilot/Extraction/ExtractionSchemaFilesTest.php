<?php

/**
 * FROZEN acceptance tests — TRO-13: committed extraction JSON Schemas (W2_ARCHITECTURE §3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the canonical extraction contracts are committed JSON
 * Schema files (not the implementation, not what the model returns). This test
 * pins their existence and well-formedness; the DTO <-> schema agreement itself
 * is TRO-15's contract suite.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Extraction;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExtractionSchemaFilesTest extends TestCase
{
    private const SCHEMA_DIR = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/schemas/extraction';

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function schemaFileProvider(): array
    {
        return [
            'lab_pdf' => ['lab_pdf.schema.json'],
            'intake_form' => ['intake_form.schema.json'],
        ];
    }

    #[DataProvider('schemaFileProvider')]
    public function testSchemaFileExists(string $fileName): void
    {
        $this->assertFileExists(self::SCHEMA_DIR . '/' . $fileName);
    }

    #[DataProvider('schemaFileProvider')]
    public function testSchemaFileIsWellFormedJsonSchema(string $fileName): void
    {
        $raw = file_get_contents(self::SCHEMA_DIR . '/' . $fileName);
        $this->assertIsString($raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "$fileName must be valid JSON");
        $this->assertArrayHasKey('$schema', $decoded, "$fileName must declare its \$schema dialect");
        $this->assertArrayHasKey('$id', $decoded, "$fileName must declare a stable \$id");
        $this->assertArrayHasKey('title', $decoded, "$fileName must carry a title");
        $this->assertArrayHasKey('type', $decoded, "$fileName must declare a root type");
    }
}

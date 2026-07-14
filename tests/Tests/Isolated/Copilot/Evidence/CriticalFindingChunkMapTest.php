<?php

/**
 * FROZEN acceptance tests — TRO-31: deterministic finding-type → chunk map (W2_ARCHITECTURE §6; PS-10).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: critical-value evidence is MAPPED, not searched. The
 * map is a unit-tested pure table: PanicLab findings with an exactly-matching
 * analyte hint (case-insensitive, trimmed — never substring/fuzzy matching)
 * resolve to their critical.* chunk; an unknown or missing analyte falls back
 * to critical.response-general; the non-lab finding types map to the general
 * response chunk. The map is total over CriticalFindingType (the edge can
 * never fire into a missing target), and — the CI invariant — every mapped
 * chunk id exists in the real corpus manifest.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Evidence;

use OpenEMR\Modules\Copilot\Corpus\CorpusManifest;
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Evidence\CriticalFindingChunkMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CriticalFindingChunkMapTest extends TestCase
{
    private const CORPUS_DIR = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/corpus';

    /**
     * @return array<string, array{string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function analyteProvider(): array
    {
        return [
            'potassium' => ['potassium', 'critical.potassium'],
            'glucose' => ['glucose', 'critical.glucose'],
            'sodium' => ['sodium', 'critical.sodium'],
            'hemoglobin' => ['hemoglobin', 'critical.hemoglobin'],
            'platelets' => ['platelets', 'critical.platelets'],
            'case-insensitive' => ['Potassium', 'critical.potassium'],
            'padded' => ['  sodium  ', 'critical.sodium'],
        ];
    }

    #[DataProvider('analyteProvider')]
    public function testPanicLabWithKnownAnalyteMapsToItsChunk(string $analyte, string $expectedChunkId): void
    {
        $this->assertSame(
            $expectedChunkId,
            CriticalFindingChunkMap::chunkIdFor(CriticalFindingType::PanicLab, $analyte),
        );
    }

    /**
     * @return array<string, array{?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unknownAnalyteProvider(): array
    {
        return [
            'null hint' => [null],
            'unknown analyte' => ['troponin'],
            'abbreviation is not exact match' => ['K'],
            'substring is not exact match' => ['serum potassium level'],
        ];
    }

    #[DataProvider('unknownAnalyteProvider')]
    public function testPanicLabWithUnknownAnalyteFallsBackToGeneralResponse(?string $analyte): void
    {
        $this->assertSame(
            'critical.response-general',
            CriticalFindingChunkMap::chunkIdFor(CriticalFindingType::PanicLab, $analyte),
        );
    }

    public function testMapIsTotalOverEveryCriticalFindingType(): void
    {
        foreach (CriticalFindingType::cases() as $type) {
            $chunkId = CriticalFindingChunkMap::chunkIdFor($type, null);
            $this->assertNotSame('', trim($chunkId), "type {$type->name} must map to a chunk id");
        }
    }

    public function testEveryDeclaredEntryResolvesInTheRealCorpusManifest(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);
        $manifestChunkIds = array_map(static fn ($e) => $e->chunkId, $manifest->chunkInventory());

        $entries = CriticalFindingChunkMap::entries();
        $this->assertNotEmpty($entries);

        foreach ($entries as $findingKey => $chunkId) {
            $this->assertContains(
                $chunkId,
                $manifestChunkIds,
                "map entry '{$findingKey}' -> '{$chunkId}' must exist in the corpus manifest (the edge can never fire into a missing target)",
            );
        }
    }

    public function testEveryEnumCaseHasADeclaredEntry(): void
    {
        $entries = CriticalFindingChunkMap::entries();

        foreach (CriticalFindingType::cases() as $type) {
            $matches = array_filter(
                array_keys($entries),
                static fn (string $key): bool => str_contains($key, CriticalFindingChunkMap::keyFor($type)),
            );
            $this->assertNotEmpty($matches, "enum case {$type->name} must appear in the declared entries table");
        }
    }
}

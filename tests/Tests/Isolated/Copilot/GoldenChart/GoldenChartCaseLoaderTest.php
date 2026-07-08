<?php

/**
 * FROZEN acceptance tests — T11: golden-chart fixture loader (ARCHITECTURE.md §6).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file (or the
 * fixture files under fixtures/).
 *
 * Contract under test: adjudicated labels enter the harness only as fixture
 * files with an explicit adjudicated flag — the loader parses them strictly
 * (malformed input is an error naming the file, never a silently skipped
 * case) and NEVER invents or repairs labels. The shipped fixture is synthetic
 * scaffolding (adjudicated=false); real labels are Phase 0 human output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\GoldenChart;

use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartCase;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartCaseLoader;
use PHPUnit\Framework\TestCase;

class GoldenChartCaseLoaderTest extends TestCase
{
    private static function fixtures(string $subdir): string
    {
        return __DIR__ . '/fixtures/' . $subdir;
    }

    public function testLoadsTheSyntheticSmokeCase(): void
    {
        $cases = (new GoldenChartCaseLoader())->loadFromDirectory(self::fixtures('cases'));

        $this->assertCount(1, $cases);
        $case = $cases[0];
        $this->assertInstanceOf(GoldenChartCase::class, $case);
        $this->assertSame('synthetic-smoke', $case->id);
        $this->assertFalse($case->adjudicated, 'The shipped fixture is scaffolding, never adjudicated ground truth.');
        $this->assertSame(['synthetic-panic-k', 'synthetic-ddi'], $case->labels->mustNotMiss);
        $this->assertSame(['synthetic-fact-a1c'], $case->labels->keyFacts);
    }

    public function testMalformedFixtureIsAnErrorNamingTheFile(): void
    {
        try {
            (new GoldenChartCaseLoader())->loadFromDirectory(self::fixtures('malformed'));
            $this->fail('A malformed fixture must never be silently skipped.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('broken.json', $e->getMessage());
        }
    }

    public function testMissingAdjudicatedFlagIsMalformed(): void
    {
        $this->expectException(\RuntimeException::class);
        (new GoldenChartCaseLoader())->loadFromDirectory(self::fixtures('missing-flag'));
    }

    public function testEmptyDirectoryLoadsZeroCases(): void
    {
        $cases = (new GoldenChartCaseLoader())->loadFromDirectory(self::fixtures('empty'));
        $this->assertSame([], $cases);
    }
}

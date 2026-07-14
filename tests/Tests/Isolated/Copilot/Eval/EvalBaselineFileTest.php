<?php

/**
 * FROZEN acceptance tests — TRO-36 residual, delivered with TRO-35: the
 * committed baseline results file and its explicit regeneration command
 * (W2_ARCHITECTURE.md §7 "Baseline + comparator"; PS-11;
 * eval/goldenset/README.md "Baseline + regeneration").
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Contract under test:
 * Eval\EvalBaselineFile parses the committed baseline into the comparator's
 * inputs (an EvalRunResult plus the per-category pass floors), refuses
 * malformed baselines loud, and the baseline itself is the all-pass record
 * of the frozen golden set — with the three hard-zero floors that carry the
 * founder's 2026-07-09 metric-model decision (hard zeros for the critical
 * subset and factual consistency; PHI-in-logs fails closed).
 *
 * The baseline is a GENERATED, REVIEWED artifact: produced only by the
 * explicit regeneration command, never in CI (the Week 1
 * no-fixture-regeneration rule extended to baselines).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Eval;

use OpenEMR\Modules\Copilot\Eval\EvalBaselineFile;
use PHPUnit\Framework\TestCase;

class EvalBaselineFileTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot';
    private const BASELINE_PATH = self::MODULE_DIR . '/eval/goldenset/baseline.json';
    private const REGEN_COMMAND_PATH = self::MODULE_DIR . '/bin/regenerate-eval-goldenset.php';

    /** category => [passed, total] — all-pass over the frozen set's rubric totals. */
    private const FROZEN_BASELINE = [
        'citation_present' => [13, 13],
        'critical_subset' => [14, 14],
        'factually_consistent' => [39, 39],
        'no_phi_in_logs' => [50, 50],
        'safe_refusal' => [13, 13],
        'schema_valid' => [15, 15],
    ];

    /** The hard-zero floors; every other category rides the >5pp comparator arithmetic. */
    private const FROZEN_FLOORS = [
        'critical_subset' => 1.0,
        'factually_consistent' => 1.0,
        'no_phi_in_logs' => 1.0,
    ];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function tempBaseline(array $body): string
    {
        $path = sys_get_temp_dir() . '/copilot-baseline-' . uniqid('', true) . '.json';
        file_put_contents($path, (string) json_encode($body, JSON_PRETTY_PRINT));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function validBaselineBody(): array
    {
        $categories = [];
        foreach (self::FROZEN_BASELINE as $category => [$passed, $total]) {
            $categories[$category] = ['passed' => $passed, 'total' => $total];
        }

        return [
            '_meta' => ['generated_by' => 'bin/regenerate-eval-goldenset.php'],
            'categories' => $categories,
            'floors' => self::FROZEN_FLOORS,
        ];
    }

    public function testCommittedBaselineIsTheAllPassRecordOfTheFrozenSet(): void
    {
        $baseline = EvalBaselineFile::load(self::BASELINE_PATH);

        $categories = $baseline->result()->categories();
        sort($categories);
        $this->assertSame(array_keys(self::FROZEN_BASELINE), $categories, 'exactly the six comparator categories');

        foreach (self::FROZEN_BASELINE as $category => [$passed, $total]) {
            $score = $baseline->result()->scoreFor($category);
            $this->assertSame($passed, $score->passed, "baseline {$category} passed");
            $this->assertSame($total, $score->total, "baseline {$category} total");
        }

        $floors = $baseline->floors();
        ksort($floors);
        $this->assertSame(self::FROZEN_FLOORS, $floors, 'the three hard-zero floors, exactly');
    }

    public function testCommittedBaselineRecordsItsRegenerationCommand(): void
    {
        $raw = file_get_contents(self::BASELINE_PATH);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        $meta = $decoded['_meta'] ?? null;
        $this->assertIsArray($meta);
        $generatedBy = $meta['generated_by'] ?? null;
        $this->assertIsString($generatedBy);
        $this->assertStringContainsString(
            'regenerate-eval-goldenset.php',
            $generatedBy,
            'the baseline names the only sanctioned path to changing it',
        );
    }

    public function testTheExplicitRegenerationCommandIsCommitted(): void
    {
        $this->assertFileExists(
            self::REGEN_COMMAND_PATH,
            'the reviewed regeneration command is a committed artifact — baselines never ratchet any other way',
        );
    }

    public function testMissingFloorsFailLoud(): void
    {
        $body = self::validBaselineBody();
        unset($body['floors']);

        $this->expectException(\DomainException::class);
        EvalBaselineFile::load($this->tempBaseline($body));
    }

    public function testFloorForAnUnknownCategoryFailsLoud(): void
    {
        $body = self::validBaselineBody();
        $floors = $body['floors'];
        $this->assertIsArray($floors);
        $floors['not_a_category'] = 1.0;
        $body['floors'] = $floors;

        $this->expectException(\DomainException::class);
        EvalBaselineFile::load($this->tempBaseline($body));
    }

    public function testMissingCategoriesFailLoud(): void
    {
        $body = self::validBaselineBody();
        unset($body['categories']);

        $this->expectException(\DomainException::class);
        EvalBaselineFile::load($this->tempBaseline($body));
    }

    public function testMalformedCategoryScoreFailsLoud(): void
    {
        $body = self::validBaselineBody();
        $categories = $body['categories'];
        $this->assertIsArray($categories);
        $categories['schema_valid'] = ['passed' => 'fifteen', 'total' => 15];
        $body['categories'] = $categories;

        $this->expectException(\DomainException::class);
        EvalBaselineFile::load($this->tempBaseline($body));
    }

    public function testAbsentFileFailsLoud(): void
    {
        $this->expectException(\DomainException::class);
        EvalBaselineFile::load(sys_get_temp_dir() . '/copilot-baseline-does-not-exist.json');
    }
}

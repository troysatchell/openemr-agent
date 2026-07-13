<?php

/**
 * FROZEN acceptance tests — TRO-36: the eval baseline comparator (W2_ARCHITECTURE §7; PS-11).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the gate fails when any rubric category regresses more
 * than 5 PERCENTAGE POINTS against the committed baseline, or drops below its
 * configured pass floor. Quantization is intent, not accident: at N=10 a
 * single case flip is a 10pp drop and fails the build; at larger N the same
 * single flip is within tolerance unless a floor catches it. Exactly 5pp is
 * NOT a failure (the clause reads "more than 5%"). Category sets must agree
 * exactly — a category that vanished from the run is a coverage regression
 * and fails loud. The comparator is pure and never auto-ratchets: improving
 * runs update the baseline only via the explicit, reviewed regeneration
 * command (never in CI).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Eval;

use OpenEMR\Modules\Copilot\Eval\BaselineComparator;
use OpenEMR\Modules\Copilot\Eval\CategoryScore;
use OpenEMR\Modules\Copilot\Eval\EvalRunResult;
use PHPUnit\Framework\TestCase;

class BaselineComparatorTest extends TestCase
{
    /**
     * @param array<string, array{int, int}> $scores
     */
    private function runResult(array $scores): EvalRunResult
    {
        $categoryScores = [];
        foreach ($scores as $category => [$passed, $total]) {
            $categoryScores[] = new CategoryScore($category, $passed, $total);
        }

        return new EvalRunResult($categoryScores);
    }

    public function testIdenticalRunsPass(): void
    {
        $baseline = $this->runResult(['schema_valid' => [10, 10], 'citation_present' => [9, 10]]);
        $current = $this->runResult(['schema_valid' => [10, 10], 'citation_present' => [9, 10]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, []);

        $this->assertTrue($verdict->passed);
        $this->assertSame([], $verdict->failures);
    }

    public function testSingleFlipAtTenCasesFailsTheBuild(): void
    {
        // 10/10 -> 9/10 is a 10pp drop: the intended clinical bar (PS-11).
        $baseline = $this->runResult(['factually_consistent' => [10, 10]]);
        $current = $this->runResult(['factually_consistent' => [9, 10]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, []);

        $this->assertFalse($verdict->passed);
        $this->assertCount(1, $verdict->failures);
        $this->assertStringContainsString('factually_consistent', $verdict->failures[0]);
    }

    public function testSingleFlipAtLargerNIsWithinTolerance(): void
    {
        // 100/100 -> 96/100 is a 4pp drop: within the >5pp clause.
        $baseline = $this->runResult(['citation_present' => [100, 100]]);
        $current = $this->runResult(['citation_present' => [96, 100]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, []);

        $this->assertTrue($verdict->passed);
    }

    public function testExactlyFivePointDropIsNotARegression(): void
    {
        // 20/20 -> 19/20 is exactly 5pp; the clause is STRICTLY more than 5.
        $baseline = $this->runResult(['safe_refusal' => [20, 20]]);
        $current = $this->runResult(['safe_refusal' => [19, 20]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, []);

        $this->assertTrue($verdict->passed);
    }

    public function testFloorCatchesWhatToleranceMisses(): void
    {
        // 4pp drop passes tolerance but violates a 0.98 floor.
        $baseline = $this->runResult(['citation_present' => [100, 100]]);
        $current = $this->runResult(['citation_present' => [96, 100]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, ['citation_present' => 0.98]);

        $this->assertFalse($verdict->passed);
        $this->assertStringContainsString('citation_present', $verdict->failures[0]);
    }

    public function testHardZeroCategoryRidesAFloorOfOne(): void
    {
        $baseline = $this->runResult(['critical_subset' => [12, 12]]);
        $current = $this->runResult(['critical_subset' => [11, 12]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, ['critical_subset' => 1.0]);

        $this->assertFalse($verdict->passed, 'a critical miss is a build failure, never a percentage');
    }

    public function testVanishedCategoryFailsLoud(): void
    {
        $baseline = $this->runResult(['schema_valid' => [10, 10], 'no_phi_in_logs' => [10, 10]]);
        $current = $this->runResult(['schema_valid' => [10, 10]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, []);

        $this->assertFalse($verdict->passed);
        $this->assertStringContainsString('no_phi_in_logs', $verdict->failures[0]);
    }

    public function testUnknownNewCategoryFailsLoud(): void
    {
        // A category without a baseline cannot be compared; adding one goes
        // through the explicit baseline regeneration, never silently.
        $baseline = $this->runResult(['schema_valid' => [10, 10]]);
        $current = $this->runResult(['schema_valid' => [10, 10], 'brand_new' => [10, 10]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, []);

        $this->assertFalse($verdict->passed);
        $this->assertStringContainsString('brand_new', $verdict->failures[0]);
    }

    public function testImprovementPassesWithoutMutatingAnything(): void
    {
        $baseline = $this->runResult(['schema_valid' => [8, 10]]);
        $current = $this->runResult(['schema_valid' => [10, 10]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, []);

        $this->assertTrue($verdict->passed);
        $this->assertSame(0.8, $baseline->scoreFor('schema_valid')->rate(), 'the comparator never ratchets the baseline');
    }

    public function testMultipleFailuresAreAllNamed(): void
    {
        $baseline = $this->runResult(['a' => [10, 10], 'b' => [10, 10]]);
        $current = $this->runResult(['a' => [8, 10], 'b' => [7, 10]]);

        $verdict = (new BaselineComparator())->compare($baseline, $current, []);

        $this->assertFalse($verdict->passed);
        $this->assertCount(2, $verdict->failures);
    }

    public function testCategoryScoreRejectsImpossibleCounts(): void
    {
        $this->expectException(\DomainException::class);
        new CategoryScore('schema_valid', 11, 10);
    }

    public function testEvalRunResultRejectsDuplicateCategories(): void
    {
        $this->expectException(\DomainException::class);
        new EvalRunResult([new CategoryScore('a', 1, 1), new CategoryScore('a', 1, 1)]);
    }
}

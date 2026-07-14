<?php

/**
 * The eval gate's PR-blocking comparator: current run vs. committed baseline
 * (W2_ARCHITECTURE.md §7; docs/W2_PRD_SEEDS.md PS-11).
 *
 * Rules, in the order they are checked:
 *
 *  1. Category sets must agree exactly. A baseline category absent from the
 *     current run is a coverage regression (something stopped being
 *     measured) and fails loud by name. A current category with no baseline
 *     entry cannot be compared — quality regressions need a "was" to compare
 *     against — and also fails loud by name; adding a brand-new rubric
 *     category goes through the explicit, reviewed baseline regeneration
 *     command, never silently via a CI run that happens to include it.
 *  2. Regression: a category present in both fails when its pass rate drops
 *     by MORE than 5 percentage points (strictly more — exactly 5pp is not a
 *     regression, PS-11). At ~10 cases/category this collapses to
 *     any-single-flip-fails, which is the intended clinical bar; the
 *     percentage form is what matters once a category's N grows.
 *  3. Floor: a category with a configured pass floor additionally fails if
 *     its current rate drops below that floor, independent of the
 *     regression math — this is how a hard-zero category (e.g. the critical
 *     subset) can be pinned at a floor of 1.0 and made to fail on ANY miss,
 *     never tolerated as "just one flip."
 *
 * Every failure is a single human-readable string naming the category and
 * the reason; every applicable rule is checked (not just the first match),
 * so a PR sees every regressed/vanished/unbaselined category in one gate
 * run.
 *
 * Float discipline: the 5pp regression check is evaluated in INTEGER
 * arithmetic to dodge float noise entirely. Percentages compare as
 * cross-multiplied fractions:
 *
 *     baselineRate - currentRate > 1/20
 *     bp/bt - cp/ct > 1/20                              (bp/bt/cp/ct all > 0)
 *   ⟺ 20 * (bp*ct - cp*bt) > bt*ct                       (multiply through by
 *                                                          the positive
 *                                                          denominator
 *                                                          20*bt*ct)
 *
 * This is exact integer comparison — no floating-point rate is ever computed
 * for the regression decision itself. The floor check unavoidably involves a
 * configured float (the floor itself is data, e.g. 0.98), so it multiplies
 * through (floor * total) and applies a small epsilon so float
 * representation noise never flips a case sitting exactly on the floor.
 *
 * Pure function: no I/O, no clock, no mutation of either input
 * EvalRunResult. This comparator NEVER ratchets a baseline — an improving
 * run passes cleanly, but updating the committed baseline file itself is a
 * separate, explicit, reviewed regeneration command, never an automatic
 * side effect of a green CI run (the Week 1 no-fixture-regeneration rule
 * extended to baselines).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final class BaselineComparator
{
    /** Regression fails when the pp drop exceeds 1 / this denominator (5%). */
    private const REGRESSION_DENOMINATOR = 20;

    /** Guards the floor check against float representation noise. */
    private const FLOOR_EPSILON = 1.0e-9;

    /**
     * @param array<string, float> $floors per-category pass-rate floors, e.g. ['critical_subset' => 1.0]
     */
    public function compare(EvalRunResult $baseline, EvalRunResult $current, array $floors): ComparatorVerdict
    {
        $failures = [];

        $baselineCategories = $baseline->categories();
        $currentCategories = $current->categories();

        $currentCategorySet = array_flip($currentCategories);
        $baselineCategorySet = array_flip($baselineCategories);

        foreach ($baselineCategories as $category) {
            if (!isset($currentCategorySet[$category])) {
                $failures[] = "category '{$category}' missing from current run (coverage regression)";
            }
        }

        foreach ($currentCategories as $category) {
            if (!isset($baselineCategorySet[$category])) {
                $failures[] = "category '{$category}' has no baseline to compare against — new categories are added via explicit baseline regeneration, never silently";
            }
        }

        foreach ($baselineCategories as $category) {
            if (!isset($currentCategorySet[$category])) {
                // Already reported above as a vanished category.
                continue;
            }

            $baselineScore = $baseline->scoreFor($category);
            $currentScore = $current->scoreFor($category);

            if ($this->regressed($baselineScore, $currentScore)) {
                $failures[] = sprintf(
                    "category '%s' regressed from %d/%d to %d/%d, more than the 5 percentage point tolerance",
                    $category,
                    $baselineScore->passed,
                    $baselineScore->total,
                    $currentScore->passed,
                    $currentScore->total,
                );

                continue;
            }

            if (array_key_exists($category, $floors) && $this->belowFloor($currentScore, $floors[$category])) {
                $failures[] = sprintf(
                    "category '%s' fell below its configured pass floor of %.4f (actual %d/%d)",
                    $category,
                    $floors[$category],
                    $currentScore->passed,
                    $currentScore->total,
                );
            }
        }

        return new ComparatorVerdict($failures === [], $failures);
    }

    /**
     * True iff (baselineRate - currentRate) > 1/20, decided in integer
     * arithmetic. Derivation:
     *
     *   bp/bt - cp/ct > 1/20
     *   20 * (bp*ct - cp*bt) > bt*ct    (both totals are strictly positive,
     *                                    enforced by CategoryScore, so the
     *                                    multiply-through preserves the
     *                                    inequality direction)
     */
    private function regressed(CategoryScore $baseline, CategoryScore $current): bool
    {
        $lhs = self::REGRESSION_DENOMINATOR * ($baseline->passed * $current->total - $current->passed * $baseline->total);
        $rhs = $baseline->total * $current->total;

        return $lhs > $rhs;
    }

    /**
     * True iff currentRate < floor, i.e. currentPassed < floor * currentTotal.
     * floor is externally configured data (a float), so the comparison
     * necessarily touches floating point; an epsilon guards the boundary so
     * a case landing exactly on the floor is never flipped by
     * representation noise.
     */
    private function belowFloor(CategoryScore $current, float $floor): bool
    {
        $thresholdCount = $floor * $current->total;

        return $current->passed < ($thresholdCount - self::FLOOR_EPSILON);
    }
}

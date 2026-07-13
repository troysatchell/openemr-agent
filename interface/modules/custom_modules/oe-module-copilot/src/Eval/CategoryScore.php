<?php

/**
 * A single rubric category's pass/total tally from one eval run
 * (W2_ARCHITECTURE.md §7; docs/W2_PRD_SEEDS.md PS-11).
 *
 * A pure value object: category name is non-blank, total is strictly
 * positive (a category with zero cases cannot be scored), passed is
 * non-negative, and passed never exceeds total — an impossible count is a
 * \DomainException, never clamped or silently accepted. rate() derives the
 * pass rate as a float for reporting only; the comparator itself never
 * compares on this float directly (float noise), it re-derives the same
 * ratio in integer arithmetic.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final readonly class CategoryScore
{
    public function __construct(
        public string $category,
        public int $passed,
        public int $total,
    ) {
        if (trim($category) === '') {
            throw new \DomainException('CategoryScore requires a non-blank category name');
        }

        if ($total <= 0) {
            throw new \DomainException("CategoryScore for '{$category}' requires a strictly positive total");
        }

        if ($passed < 0) {
            throw new \DomainException("CategoryScore for '{$category}' requires a non-negative passed count");
        }

        if ($passed > $total) {
            throw new \DomainException("CategoryScore for '{$category}' cannot have passed ({$passed}) exceed total ({$total})");
        }
    }

    public function rate(): float
    {
        return $this->passed / $this->total;
    }
}

<?php

/**
 * One eval run's full set of per-category scores (W2_ARCHITECTURE.md §7;
 * docs/W2_PRD_SEEDS.md PS-11).
 *
 * Ties together every CategoryScore produced by a single run (baseline or
 * current) so the comparator can look categories up by name. Every element
 * must be a CategoryScore — the constructor boundary contains whatever an
 * assembling caller (JSON decode of a committed baseline file, a live run's
 * scorer output) produced; a non-CategoryScore element throws rather than
 * being silently coerced or skipped. Category names must be unique within a
 * run — a duplicate is an assembly bug, never resolved by last-write-wins.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final readonly class EvalRunResult
{
    /** @var array<string, CategoryScore> */
    private array $scoresByCategory;

    /**
     * $categoryScores arrives untyped at this boundary (assembled from a
     * decoded baseline file or a live scorer): elements are validated with
     * instanceof, never assumed from the caller's declared type.
     *
     * @param list<mixed> $categoryScores
     */
    public function __construct(array $categoryScores)
    {
        $scoresByCategory = [];

        foreach ($categoryScores as $categoryScore) {
            if (!$categoryScore instanceof CategoryScore) {
                throw new \DomainException('EvalRunResult requires every element to be a CategoryScore');
            }

            if (isset($scoresByCategory[$categoryScore->category])) {
                throw new \DomainException("EvalRunResult received duplicate category '{$categoryScore->category}'");
            }

            $scoresByCategory[$categoryScore->category] = $categoryScore;
        }

        $this->scoresByCategory = $scoresByCategory;
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return array_keys($this->scoresByCategory);
    }

    public function scoreFor(string $category): CategoryScore
    {
        return $this->scoresByCategory[$category]
            ?? throw new \DomainException("EvalRunResult has no score for unknown category '{$category}'");
    }
}

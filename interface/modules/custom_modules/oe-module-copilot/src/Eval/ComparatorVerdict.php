<?php

/**
 * The outcome of comparing one eval run against the committed baseline
 * (W2_ARCHITECTURE.md §7; docs/W2_PRD_SEEDS.md PS-11).
 *
 * A pure value object carrying a boolean gate outcome plus every
 * human-readable failure reason that produced it — never just the first
 * one, so a PR-blocking failure names every regressed/vanished/unbaselined
 * category in one read. The invariant `passed === (failures === [])` is
 * enforced at construction: there is no code path where the gate can be
 * reported green with a failure reason attached, or red with none.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final readonly class ComparatorVerdict
{
    /** @var list<string> */
    public array $failures;

    /**
     * $failures arrives untyped at this boundary: elements are validated as
     * strings, never assumed from the caller's declared type.
     *
     * @param list<mixed> $failures
     */
    public function __construct(
        public bool $passed,
        array $failures,
    ) {
        $validatedFailures = [];
        foreach ($failures as $failure) {
            if (!is_string($failure) || trim($failure) === '') {
                throw new \DomainException('ComparatorVerdict requires every failure to be a non-blank string');
            }

            $validatedFailures[] = $failure;
        }

        if ($passed !== ($validatedFailures === [])) {
            throw new \DomainException('ComparatorVerdict invariant violated: passed must equal (failures === [])');
        }

        $this->failures = $validatedFailures;
    }
}

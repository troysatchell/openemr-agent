<?php

/**
 * One PHI-pattern-detector finding: a line number and a rule name, nothing
 * else (TRO-41; W2_ARCHITECTURE.md §7, §9; PS-8).
 *
 * Findings never carry the matched text. A detector that echoes what it
 * found would launder the very PHI it is meant to keep out of CI logs back
 * into those logs — so the shape of this DTO is the guarantee: exactly two
 * public properties, `lineNumber` and `rule`. There is nowhere to put the
 * matched substring even by accident.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final readonly class PhiViolation
{
    public function __construct(
        public int $lineNumber,
        public string $rule,
    ) {
        if ($lineNumber < 1) {
            throw new \DomainException('PhiViolation lineNumber must be 1 or greater (lines are 1-indexed)');
        }
        if (trim($rule) === '') {
            throw new \DomainException('PhiViolation rule must be non-empty (an unnamed rule is not traceable)');
        }
    }
}

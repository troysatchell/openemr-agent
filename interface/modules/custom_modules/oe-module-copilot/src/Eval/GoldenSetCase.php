<?php

/**
 * One parsed golden-set case (TRO-35; eval/goldenset/README.md "Case
 * schema"; W2_ARCHITECTURE.md §7).
 *
 * Parse-don't-validate boundary output: {@see GoldenSetCaseLoader} is the
 * only place that constructs this DTO, after every contract check the
 * README's case schema requires has already passed. `inputs`/`expected` stay
 * loosely-typed maps (kind-specific shape, enumerated in the README, not
 * repeated here as a rigid DTO) — {@see GoldenSetRunner} narrows each key it
 * reads at the point of use, the same "parse the boundary, narrow at the
 * read site" discipline the rest of this module follows for untrusted wire
 * data.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final readonly class GoldenSetCase
{
    /**
     * @param list<string> $rubrics
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $expected
     */
    public function __construct(
        public string $id,
        public GoldenCaseKind $kind,
        public string $category,
        public array $rubrics,
        public string $guardsAgainst,
        public string $provenance,
        public array $inputs,
        public array $expected,
    ) {
        if (trim($id) === '') {
            throw new \DomainException('GoldenSetCase id must be non-blank');
        }
        if (trim($category) === '') {
            throw new \DomainException('GoldenSetCase category must be non-blank');
        }
    }
}

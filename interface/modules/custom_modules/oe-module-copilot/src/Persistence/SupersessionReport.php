<?php

/**
 * The result of one `DerivedObservationSupersession::reconcile()` run
 * (W2_ARCHITECTURE.md §2 step 5 "Dedup is one-directional by invariant";
 * PS-5, docs/W2_PRD_SEEDS.md).
 *
 * Two disjoint outcome lists, both keyed by the `procedure_result_id` of
 * the DERIVED result — a real observation's id never appears in either
 * list, because only a derived record may ever be suppressed or flagged:
 *
 *  - `supersededResultIds`: derived results with exactly one matching real
 *    observation. The lineage row was annotated
 *    `superseded_by_result_id` + `superseded_at`.
 *  - `ambiguousResultIds`: derived results with MORE than one matching real
 *    observation. Both real candidates are kept, nothing is merged; the
 *    lineage row was flagged `ambiguous_flag = 1` instead
 *    (`superseded_at` stays NULL) — a wrong merge is data loss, a
 *    duplicate is only a provenance-distinguished annoyance (PS-5).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Persistence;

final readonly class SupersessionReport
{
    /** @var list<int> */
    public array $supersededResultIds;

    /** @var list<int> */
    public array $ambiguousResultIds;

    /**
     * Both lists arrive untyped at this boundary (assembled from a loop over
     * query results): elements are validated as positive integers, never
     * assumed from the caller's declared type.
     *
     * @param list<mixed> $supersededResultIds
     * @param list<mixed> $ambiguousResultIds
     */
    public function __construct(array $supersededResultIds, array $ambiguousResultIds)
    {
        $this->supersededResultIds = self::validatedIds($supersededResultIds, 'supersededResultIds');
        $this->ambiguousResultIds = self::validatedIds($ambiguousResultIds, 'ambiguousResultIds');
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private static function validatedIds(array $ids, string $label): array
    {
        $validated = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \DomainException('SupersessionReport ' . $label . ' must all be positive integers');
            }
            $validated[] = $id;
        }

        return $validated;
    }
}

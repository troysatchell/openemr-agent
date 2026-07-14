<?php

/**
 * The result of persisting an intake extraction as reconciliation candidates
 * (W2_ARCHITECTURE.md §2 step 5, §10; PS-4).
 *
 * Carries the ids of the `mod_copilot_intake_candidates` rows just inserted
 * by IntakeCandidateWriter::persist(). Unlike PersistedDerivedObservations,
 * an empty list is a legitimate outcome here: an intake form with every
 * field group absent (or a re-extraction that produced nothing new) is a
 * valid, non-erroneous result — persisting zero cited candidates, never
 * inventing one (D1).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Persistence;

final readonly class IntakeCandidateSet
{
    /** @var list<int> */
    public array $candidateIds;

    /**
     * $candidateIds arrives untyped at this boundary (the caller assembles
     * it from a loop of insert-id results): elements are validated as
     * positive integers, never assumed from the caller's declared type —
     * the same boundary-validation idiom PersistedDerivedObservations uses.
     * An empty list is explicitly allowed (see class docblock).
     *
     * @param list<mixed> $candidateIds
     */
    public function __construct(array $candidateIds)
    {
        $validated = [];
        foreach ($candidateIds as $candidateId) {
            if (!is_int($candidateId) || $candidateId <= 0) {
                throw new \DomainException('IntakeCandidateSet candidate ids must all be positive integers');
            }
            $validated[] = $candidateId;
        }

        $this->candidateIds = $validated;
    }
}

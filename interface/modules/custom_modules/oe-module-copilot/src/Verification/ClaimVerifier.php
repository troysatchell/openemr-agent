<?php

/**
 * Claim verification layer (T14; R6/R10; ARCHITECTURE.md §2 VER, §3.4).
 *
 * LLM output is untrusted draft prose until grounded. verify() partitions a
 * list of DraftClaims into grounded (every cited source token resolves
 * against the chart's ReferenceIndex) and rejected (no citations, or at
 * least one citation fails to resolve). Grounding is all-or-nothing: a
 * claim citing one unresolvable source is rejected wholesale rather than
 * grounded on its resolvable subset, because partial grounding is not
 * grounding — a reader cannot tell which half of the sentence the missing
 * citation was propping up.
 *
 * Pure: no I/O, no clock, no globals. Claim text passes through
 * byte-identical — this layer grounds, it never rewrites (the model owns
 * prose, code owns truth). Rejected claims are returned as the same
 * DraftClaim instances they were given, never re-dropped and never
 * silently discarded (R11) — callers decide how to surface them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Verification;

use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final class ClaimVerifier
{
    /**
     * @param list<DraftClaim> $claims
     */
    public function verify(array $claims, ReferenceIndex $index): VerifiedAnswer
    {
        $grounded = [];
        $rejected = [];

        foreach ($claims as $claim) {
            $resolvedSources = $this->resolveAll($claim, $index);

            if ($resolvedSources === null) {
                $rejected[] = $claim;
                continue;
            }

            $grounded[] = new GroundedClaim($claim->text, $resolvedSources);
        }

        return new VerifiedAnswer($grounded, $rejected);
    }

    /**
     * Resolves every sourceId on the claim against the index, collapsing
     * duplicate tokens to one SourceRef while preserving citation order.
     * Returns null (rather than a partial list) the moment any citation
     * fails to resolve or the claim cites nothing — the all-or-nothing rule.
     *
     * @return list<SourceRef>|null
     */
    private function resolveAll(DraftClaim $claim, ReferenceIndex $index): ?array
    {
        if ($claim->sourceIds === []) {
            return null;
        }

        $resolved = [];
        $seenTokens = [];

        foreach ($claim->sourceIds as $sourceId) {
            if (isset($seenTokens[$sourceId])) {
                continue;
            }

            $source = $index->resolve($sourceId);
            if ($source === null) {
                return null;
            }

            $seenTokens[$sourceId] = true;
            $resolved[] = $source;
        }

        return $resolved;
    }
}

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
 * Week 2 no-grounding-by-proxy invariant (W2_ARCHITECTURE.md §4;
 * docs/W2_PRD_SEEDS.md PS-6): a resolved SourceRef of sourceType
 * `derived_observation` is a pointer, never evidence, and cannot terminate a
 * citation chain by itself. Resolving such a ref additionally requires the
 * injected DerivedObservationGrounding port to confirm the source document
 * it points back to still exists; the source document being gone makes the
 * claim ungrounded, fail closed — never grounded-by-proxy. A ClaimVerifier
 * built without the port (the Week 1 zero-arg constructor call still
 * compiles unchanged) therefore fails closed on every derived-observation
 * ref by construction: there is no port to ask, so the answer defaults to
 * "not grounded," never to "assume yes." Non-derived refs are untouched and
 * never consult the port — this preserves Week 1 behavior exactly. The
 * all-or-nothing rule composes with this: one derived ref whose source
 * document is gone rejects the whole claim, same as any other unresolvable
 * citation.
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
    private const DERIVED_OBSERVATION_SOURCE_TYPE = 'derived_observation';

    public function __construct(
        private readonly ?DerivedObservationGrounding $derivedGrounding = null,
    ) {
    }

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
     * fails to resolve, fails the no-grounding-by-proxy check, or the claim
     * cites nothing — the all-or-nothing rule.
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

            if (!$this->isGrounded($source)) {
                return null;
            }

            $seenTokens[$sourceId] = true;
            $resolved[] = $source;
        }

        return $resolved;
    }

    /**
     * A resolved SourceRef is grounded on its own unless it is a derived
     * observation, in which case grounding resolves through to the source
     * document the derived record points at (§4 no-grounding-by-proxy). No
     * port wired, or the port reporting the source document gone, both mean
     * ungrounded — fail closed, never grounded-by-proxy.
     */
    private function isGrounded(SourceRef $source): bool
    {
        if ($source->sourceType !== self::DERIVED_OBSERVATION_SOURCE_TYPE) {
            return true;
        }

        return $this->derivedGrounding !== null
            && $this->derivedGrounding->sourceDocumentExists($source->sourceId);
    }
}

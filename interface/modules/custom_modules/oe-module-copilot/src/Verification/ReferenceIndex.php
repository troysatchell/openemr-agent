<?php

/**
 * Lookup table from citation token to the chart's SourceRef it names
 * (T14; R6/R10; ARCHITECTURE.md §3.4).
 *
 * tokenFor() is the ONE canonical mint for the citation token format —
 * anything that flattens a ChartSnapshot, a document extraction, or a
 * retrieved guideline chunk into an LLM-facing payload MUST use this same
 * method to label its citable facts, or the tokens the model echoes back
 * will never resolve. The one-mint rule now spans all source classes
 * (W2_ARCHITECTURE.md §4): a SourceRef with a null fieldOrChunkId mints the
 * unchanged Week 1 "sourceType:sourceId" token; a SourceRef carrying a
 * fieldOrChunkId mints "sourceType:sourceId#fieldOrChunkId" — without the
 * fragment, two chunks of one guideline document would collapse into a
 * single token and provenance would silently blur.
 *
 * fromChart() walks every section of a ChartSnapshot (medications, labs,
 * allergies, AND follow-ups — all four are citable) and indexes every
 * SourceRef of every entry; duplicate tokens collapse to one.
 *
 * resolve() is an exact array-key lookup only — no trimming, no case
 * folding, no prefix matching. Chart content (and anything an LLM echoes
 * back) is untrusted free text (AUDIT D1); fuzzy matching here would
 * manufacture provenance that was never actually cited.
 *
 * fromRefs() is the §4 one-mint entry point for non-chart source classes:
 * document extractions, guideline chunks, and derived-observation refs all
 * enter the SAME index the citation tokens are minted from — fromChart()
 * remains the chart-snapshot path, unchanged. Every element must be a
 * SourceRef (parse-boundary discipline: this is a boundary between untrusted
 * assembly code and the verifier, so the shape is checked, not assumed); a
 * non-SourceRef element throws rather than silently coercing or skipping.
 * Duplicate tokens collapse to the FIRST ref supplied (first-write-wins) —
 * the opposite of fromChart()'s last-write-wins-by-iteration-order; callers
 * control precedence by ordering the input list.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Verification;

use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final readonly class ReferenceIndex
{
    /**
     * @param array<string, SourceRef> $sourcesByToken
     */
    private function __construct(
        private array $sourcesByToken,
    ) {
    }

    public static function tokenFor(SourceRef $ref): string
    {
        $base = $ref->sourceType . ':' . $ref->sourceId;

        if ($ref->fieldOrChunkId === null) {
            return $base;
        }

        return $base . '#' . $ref->fieldOrChunkId;
    }

    public static function fromChart(ChartSnapshot $chart): self
    {
        $sourcesByToken = [];

        foreach ($chart->medications as $medication) {
            foreach ($medication->sources as $source) {
                $sourcesByToken[self::tokenFor($source)] = $source;
            }
        }
        foreach ($chart->labs as $lab) {
            foreach ($lab->sources as $source) {
                $sourcesByToken[self::tokenFor($source)] = $source;
            }
        }
        foreach ($chart->allergies as $allergy) {
            foreach ($allergy->sources as $source) {
                $sourcesByToken[self::tokenFor($source)] = $source;
            }
        }
        foreach ($chart->followUps as $followUp) {
            foreach ($followUp->sources as $source) {
                $sourcesByToken[self::tokenFor($source)] = $source;
            }
        }

        return new self($sourcesByToken);
    }

    /**
     * The refs arrive untyped at this boundary (extraction output and
     * retrieval results are assembled from untrusted parses): elements are
     * validated with instanceof, never assumed from a declared type.
     *
     * @param list<mixed> $refs
     */
    public static function fromRefs(array $refs): self
    {
        $sourcesByToken = [];

        foreach ($refs as $ref) {
            if (!$ref instanceof SourceRef) {
                throw new \DomainException('ReferenceIndex::fromRefs requires every element to be a SourceRef');
            }

            $token = self::tokenFor($ref);
            if (isset($sourcesByToken[$token])) {
                continue;
            }

            $sourcesByToken[$token] = $ref;
        }

        return new self($sourcesByToken);
    }

    public function resolve(string $token): ?SourceRef
    {
        return $this->sourcesByToken[$token] ?? null;
    }
}

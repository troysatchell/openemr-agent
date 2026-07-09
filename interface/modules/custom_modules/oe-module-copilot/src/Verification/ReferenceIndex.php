<?php

/**
 * Lookup table from citation token to the chart's SourceRef it names
 * (T14; R6/R10; ARCHITECTURE.md §3.4).
 *
 * tokenFor() is the ONE canonical mint for the "sourceType:sourceId" token
 * format — anything that flattens a ChartSnapshot into an LLM-facing
 * payload MUST use this same method to label its citable facts, or the
 * tokens the model echoes back will never resolve.
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
        return $ref->sourceType . ':' . $ref->sourceId;
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

    public function resolve(string $token): ?SourceRef
    {
        return $this->sourcesByToken[$token] ?? null;
    }
}

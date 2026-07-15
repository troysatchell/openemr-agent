<?php

/**
 * Non-token vendor unit accounting for one step (TRO-46; PS-9;
 * W2_ARCHITECTURE.md §8 observability).
 *
 * `TokenUsage` carries LLM prompt/completion token counts for the Anthropic
 * crossing; this value object generalizes the same pattern to every OTHER
 * vendor pricing model Week 2 introduces — Cohere embed tokens, Cohere
 * rerank search units, VLM document/vision units, and any future
 * non-token-billed vendor call. `unitKind` names what is being counted
 * (`embed_token_estimated`, `rerank_search_unit`, ...) so a mixed trace never
 * conflates two different billing units under one number. The unit count,
 * the versioned price, and the already-computed cost travel together —
 * exactly like `TokenUsage` — so a later price change can never silently
 * reprice an already-recorded call: `priceVersion` pins the price this
 * specific call was billed at.
 *
 * Refuses to exist in a self-contradictory state at construction
 * (\DomainException on a blank vendor/unitKind/priceVersion or a negative
 * units/costUsd) — the same validate-at-construction discipline as every
 * other trace value object in this namespace.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final readonly class VendorUnits
{
    public function __construct(
        public string $vendor,
        public string $unitKind,
        public int $units,
        public string $priceVersion,
        public float $costUsd,
    ) {
        if (trim($vendor) === '') {
            throw new \DomainException('VendorUnits requires a non-blank vendor');
        }

        if (trim($unitKind) === '') {
            throw new \DomainException('VendorUnits requires a non-blank unitKind');
        }

        if ($units < 0) {
            throw new \DomainException('VendorUnits units must be >= 0');
        }

        if (trim($priceVersion) === '') {
            throw new \DomainException('VendorUnits requires a non-blank priceVersion');
        }

        if ($costUsd < 0.0) {
            throw new \DomainException('VendorUnits costUsd must be >= 0');
        }
    }
}

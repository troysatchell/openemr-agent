<?php

/**
 * Model identity and token accounting for one LLM step (T17;
 * ARCHITECTURE.md §6 observability; AUDIT C4/C5).
 *
 * Timestamps and user attribution alone are not an audit trail for a
 * vendor-model crossing — the model identity and token counts are what let
 * an operator reconstruct cost and which model answered. Cost is computed by
 * the adapter that knows current pricing; this value object only carries the
 * already-computed figure.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final readonly class TokenUsage
{
    public function __construct(
        public string $modelId,
        public int $inputTokens,
        public int $outputTokens,
        public float $costUsd,
    ) {
        if (trim($modelId) === '') {
            throw new \DomainException('TokenUsage requires a non-blank modelId — the vendor does not log model identity for us (C4)');
        }

        if ($inputTokens < 0) {
            throw new \DomainException('TokenUsage inputTokens must be >= 0');
        }

        if ($outputTokens < 0) {
            throw new \DomainException('TokenUsage outputTokens must be >= 0');
        }

        if ($costUsd < 0.0) {
            throw new \DomainException('TokenUsage costUsd must be >= 0');
        }
    }
}

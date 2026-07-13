<?php

/**
 * Per-field extraction confidence (W2_ARCHITECTURE §3).
 *
 * A bounded domain primitive in [0.0, 1.0]. Out-of-range values are a
 * \DomainException, never clamped — a confidence the model could not produce
 * is a bug in the caller, not a value to silently coerce.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Extraction;

final readonly class ExtractionConfidence
{
    public function __construct(
        public float $value,
    ) {
        if ($value < 0.0 || $value > 1.0) {
            throw new \DomainException('ExtractionConfidence must be within [0.0, 1.0] — a confidence the model could not produce is a caller bug, not a value to clamp');
        }
    }
}

<?php

/**
 * The extracted-field present/absent marker (W2_ARCHITECTURE §3).
 *
 * Every extracted field is EITHER present — carrying a non-blank value, a
 * per-field confidence, and a source citation it is grounded in — OR
 * explicitly absent (D1: a field the VLM could not ground in a source region
 * is absent, never defaulted). A present field cannot exist without its
 * citation; an absent field carries no value, confidence, or citation.
 *
 * A present field may also carry an optional {@see BoundingBox} — the page
 * region the value was read from (TRO-44). The box is a UI affordance only:
 * it is never required, never verification ground, and its absence never
 * degrades the field's validity (R-W3).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Extraction;

use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final readonly class ExtractedField
{
    private function __construct(
        public bool $isPresent,
        public ?string $value,
        public ?ExtractionConfidence $confidence,
        public ?SourceRef $citation,
        public ?BoundingBox $bbox = null,
    ) {
        if ($isPresent) {
            if ($value === null || $confidence === null || $citation === null) {
                throw new \DomainException('A present ExtractedField must carry a non-null value, confidence, and citation (D1: no partial grounding)');
            }
        } elseif ($value !== null || $confidence !== null || $citation !== null) {
            throw new \DomainException('An absent ExtractedField must carry no value, confidence, or citation (D1: absent is absent, never defaulted)');
        }
    }

    public static function present(string $value, ExtractionConfidence $confidence, SourceRef $citation, ?BoundingBox $bbox = null): self
    {
        if (trim($value) === '') {
            throw new \DomainException('A present ExtractedField requires a non-blank value — blank is absence, not a value (D1)');
        }

        return new self(true, $value, $confidence, $citation, $bbox);
    }

    public static function absent(): self
    {
        return new self(false, null, null, null);
    }
}

<?php

/**
 * One claim whose provenance has been verified against the live chart
 * (T14; R6/R10; ARCHITECTURE.md §3.4).
 *
 * The text is carried byte-identical from the DraftClaim that produced it —
 * the verifier grounds, it never rewrites the model's prose. sources is the
 * resolved SourceRef list backing that text; a GroundedClaim without
 * provenance is a contradiction in terms, so the constructor refuses to
 * build one.
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

final readonly class GroundedClaim
{
    /**
     * @param list<SourceRef> $sources
     */
    public function __construct(
        public string $text,
        public array $sources,
    ) {
        if ($sources === []) {
            throw new \DomainException('GroundedClaim requires at least one SourceRef (a grounded claim without provenance is a contradiction in terms)');
        }
    }
}

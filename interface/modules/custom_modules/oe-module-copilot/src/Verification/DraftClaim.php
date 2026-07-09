<?php

/**
 * One unverified claim from LLM draft prose (T14; R6/R10;
 * ARCHITECTURE.md §3.4).
 *
 * A DraftClaim is a sentence of model output paired with the reference
 * tokens the model claims support it, before those tokens have been checked
 * against the live chart. It carries zero or more citations — zero is the
 * common case for unattributable filler prose, which the verifier must
 * reject rather than silently pass through as fact.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Verification;

final readonly class DraftClaim
{
    /**
     * @param list<string> $sourceIds reference tokens the model cites in
     *   support of this claim (see ReferenceIndex::tokenFor()); may be empty
     */
    public function __construct(
        public string $text,
        public array $sourceIds,
    ) {
        if (trim($text) === '') {
            throw new \DomainException('DraftClaim text must be non-blank');
        }
        foreach ($sourceIds as $sourceId) {
            if (trim($sourceId) === '') {
                throw new \DomainException('DraftClaim sourceIds must not contain a blank/whitespace-only id');
            }
        }
    }
}

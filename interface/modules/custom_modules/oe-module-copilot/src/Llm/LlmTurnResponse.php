<?php

/**
 * Raw model output for one conversational turn (T12; UC2;
 * ARCHITECTURE.md §2 ORCH, §3.5).
 *
 * Claims are untrusted draft prose — this value object carries them
 * unverified, byte-identical from the model. Grounding against the live
 * chart happens downstream in ClaimVerifier; nothing here is fact until then
 * (R6/R10).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

use OpenEMR\Modules\Copilot\Verification\DraftClaim;

final readonly class LlmTurnResponse
{
    /**
     * @param list<DraftClaim> $claims
     */
    public function __construct(
        public array $claims,
    ) {
    }
}

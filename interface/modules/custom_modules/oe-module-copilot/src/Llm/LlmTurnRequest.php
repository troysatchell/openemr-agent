<?php

/**
 * One grounded conversational turn sent to the model (T12; UC2; R11;
 * ARCHITECTURE.md §2 ORCH, §3.5).
 *
 * The DisclosedPayload is the only fact source: prior turns ride along to
 * inform phrasing and intent only — never facts. The model's own earlier
 * output is never a source; every turn re-grounds against the freshly read,
 * freshly disclosed chart. A blank question is refused at construction
 * (\DomainException), before anything is logged or sent — there is nothing
 * to answer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

final readonly class LlmTurnRequest
{
    /**
     * @param list<string> $priorTurns prior Q/A turns, phrasing context only — never a fact source (§3.5)
     */
    public function __construct(
        public DisclosedPayload $payload,
        public string $question,
        public array $priorTurns,
    ) {
        if (trim($question) === '') {
            throw new \DomainException(
                'A blank question cannot be answered — refused before anything is logged or sent'
            );
        }
    }
}

<?php

/**
 * Payload + disclosure, born together (T3).
 *
 * The only way to obtain an LLM-bound payload is alongside the Disclosure
 * record that enumerates it (AUDIT C1/C5; ARCHITECTURE §3.4, Decision 5).
 * The constructor refuses any mismatch in either direction: a payload data
 * class missing from the disclosure would be an unlogged PHI send; a
 * disclosed class missing from the payload would be a false audit record.
 * An empty payload is refused outright — there is no such thing as a
 * disclosed nothing. The type system, not caller discipline, forbids
 * undisclosed payloads.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

use OpenEMR\Modules\Copilot\Audit\Disclosure;

final readonly class DisclosedPayload
{
    /**
     * @param array<string, mixed> $payload data class => entries, exactly as sent to the LLM
     */
    public function __construct(
        public array $payload,
        public Disclosure $disclosure,
    ) {
        if ($payload === []) {
            throw new \DomainException(
                'A disclosed payload must be non-empty — a send with nothing in it is a refusal, not a disclosure (C5)'
            );
        }

        foreach ($disclosure->dataClasses as $dataClass) {
            if (!array_key_exists($dataClass, $payload)) {
                throw new \DomainException(
                    sprintf('Disclosure lists data class "%s" but the payload does not contain it — the audit record would overstate the send (C1)', $dataClass)
                );
            }
        }

        foreach (array_keys($payload) as $payloadClass) {
            if (!in_array($payloadClass, $disclosure->dataClasses, true)) {
                throw new \DomainException(
                    sprintf('Payload contains data class "%s" that the disclosure does not list — no PHI crosses undisclosed (C1)', $payloadClass)
                );
            }
        }
    }
}

<?php

/**
 * Minimum-necessary payload enforcement at the LLM boundary (T3).
 *
 * What crosses to the LLM is decided by a per-task field allowlist, not by
 * whoever assembles the prompt (AUDIT R1/C5; ARCHITECTURE §3.4, Decision 5).
 * Fields outside the allowlist are structurally unreachable: this builder is
 * the only constructor path to a DisclosedPayload, and it copies only
 * allowlisted keys out of the chart data. Chart entries are untrusted data
 * (never instructions); anything malformed is dropped, never coerced —
 * dropping fails closed (less disclosed, never more). A build in which
 * nothing survives the allowlist is a \DomainException: a refusal, not an
 * empty disclosure.
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

final class MinimumNecessaryPayloadBuilder
{
    /** @var array<string, FieldAllowlist> */
    private readonly array $policies;

    /**
     * @param array<array-key, mixed> $policies CopilotTask backing value => FieldAllowlist
     */
    public function __construct(array $policies)
    {
        $parsed = [];
        foreach ($policies as $taskValue => $allowlist) {
            if (!is_string($taskValue) || trim($taskValue) === '') {
                throw new \DomainException(
                    'Policy map keys must be non-blank CopilotTask backing values (C5)'
                );
            }
            if (!$allowlist instanceof FieldAllowlist) {
                throw new \DomainException(
                    sprintf('Policy for task "%s" must be a FieldAllowlist (C5)', $taskValue)
                );
            }
            $parsed[$taskValue] = $allowlist;
        }

        $this->policies = $parsed;
    }

    /**
     * @param array<string, mixed> $chartData data class => list of flat entry arrays (data-trust layer output)
     */
    public function build(
        CopilotTask $task,
        array $chartData,
        string $userId,
        int $patientPid,
        \DateTimeImmutable $when,
    ): DisclosedPayload {
        $policy = $this->policies[$task->value] ?? null;
        if ($policy === null) {
            throw new \DomainException(
                sprintf('No minimum-necessary policy for task "%s" — a task without a policy cannot send anything (C5)', $task->value)
            );
        }

        $payload = [];
        foreach ($policy->fieldsByDataClass() as $dataClass => $allowedFields) {
            if (!array_key_exists($dataClass, $chartData)) {
                continue;
            }

            $entries = $chartData[$dataClass];
            if (!is_array($entries)) {
                // Malformed data class container: drop it (fail closed).
                continue;
            }

            $survivors = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    // Malformed entry: drop it (fail closed).
                    continue;
                }

                $kept = [];
                foreach ($allowedFields as $field) {
                    if (array_key_exists($field, $entry)) {
                        $kept[$field] = $entry[$field];
                    }
                }

                if ($kept !== []) {
                    $survivors[] = $kept;
                }
            }

            if ($survivors !== []) {
                $payload[$dataClass] = $survivors;
            }
        }

        if ($payload === []) {
            throw new \DomainException(
                sprintf('Nothing in the chart data survives the "%s" allowlist — a send with no grounded payload is a refusal, not an empty disclosure (C5)', $task->value)
            );
        }

        return new DisclosedPayload(
            $payload,
            new Disclosure($userId, $patientPid, array_keys($payload), $task->value, $when),
        );
    }
}

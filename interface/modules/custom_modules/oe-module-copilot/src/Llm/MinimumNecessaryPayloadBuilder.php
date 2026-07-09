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
 * Absence markers (AUDIT D1; ARCHITECTURE §3): every allowlisted data class
 * WITHOUT disclosed entries is marked in a separate 'chart_assessment'
 * payload channel via the one canonical CurrencyWire mapper — a class key
 * present with zero entries crosses as known-absent ('none-recorded', e.g.
 * NKDA); a class key absent (or whose entries could not cross) crosses as
 * the canonical Unknown token. Minimum-necessary is a COMPRESSION rule;
 * honest-uncertainty is a PRESERVATION rule — trimming never destroys the
 * known-absent vs never-assessed distinction. The marker channel carries no
 * PHI, is policy-scoped, and is disclosed like any other crossing (C1).
 * Known-absent is knowledge, so it can carry a send alone; a wholly
 * unknown chart stays a refusal.
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
    /** Reserved payload channel for per-data-class absence markers (D1). */
    public const ASSESSMENT_CLASS = 'chart_assessment';

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
            if (array_key_exists(self::ASSESSMENT_CLASS, $allowlist->fieldsByDataClass())) {
                throw new \DomainException(
                    sprintf(
                        'Policy for task "%s" claims the reserved "%s" channel — absence markers are builder-owned, never chart data (D1)',
                        $taskValue,
                        self::ASSESSMENT_CLASS,
                    )
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
        ?string $correlationId = null,
    ): DisclosedPayload {
        $policy = $this->policies[$task->value] ?? null;
        if ($policy === null) {
            throw new \DomainException(
                sprintf('No minimum-necessary policy for task "%s" — a task without a policy cannot send anything (C5)', $task->value)
            );
        }

        $payload = [];
        $assessment = [];
        foreach ($policy->fieldsByDataClass() as $dataClass => $allowedFields) {
            if (!array_key_exists($dataClass, $chartData)) {
                // Key absent = the chart was never assessed for this class.
                $assessment[$dataClass] = CurrencyWire::UNKNOWN;
                continue;
            }

            $entries = $chartData[$dataClass];
            if (!is_array($entries)) {
                // Malformed data class container: drop it (fail closed) —
                // undisclosable, so unknown at the boundary, never known-absent.
                $assessment[$dataClass] = CurrencyWire::UNKNOWN;
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
                continue;
            }

            // Key present with zero entries = assessed-and-empty (known-absent,
            // e.g. NKDA). Entries that existed but could not cross are unknown
            // at the boundary — claiming known-absent would launder them (D1).
            $assessment[$dataClass] = $entries === []
                ? CurrencyWire::KNOWN_ABSENT
                : CurrencyWire::UNKNOWN;
        }

        // Known-absent is knowledge and can carry a send alone; a chart that
        // is wholly unknown to this task still has nothing to disclose.
        $hasKnownAbsent = in_array(CurrencyWire::KNOWN_ABSENT, $assessment, true);
        if ($payload === [] && !$hasKnownAbsent) {
            throw new \DomainException(
                sprintf('Nothing in the chart data survives the "%s" allowlist — a send with no grounded payload is a refusal, not an empty disclosure (C5)', $task->value)
            );
        }

        if ($assessment !== []) {
            $payload[self::ASSESSMENT_CLASS] = $assessment;
        }

        return new DisclosedPayload(
            $payload,
            new Disclosure($userId, $patientPid, array_keys($payload), $task->value, $when, $correlationId),
        );
    }
}

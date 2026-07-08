<?php

/**
 * Per-task field allowlist for the LLM boundary (T3).
 *
 * Maps a data class (e.g. 'medications') to the only entry fields permitted
 * to cross to the LLM for that task (minimum necessary — AUDIT C5;
 * ARCHITECTURE §3.4, Decision 5). Parsed at construction: a blank data
 * class, an empty field list, or a blank field is a \DomainException, never
 * silently accepted — a malformed policy fails loudly at wiring time, not
 * quietly at send time.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

final readonly class FieldAllowlist
{
    /**
     * Runtime invariant: every list is non-empty and every field non-blank
     * (enforced in the constructor).
     *
     * @var array<string, list<string>>
     */
    private array $fieldsByDataClass;

    /**
     * @param array<array-key, mixed> $fieldsByDataClass data class => non-empty list of field names
     */
    public function __construct(array $fieldsByDataClass)
    {
        $parsed = [];
        foreach ($fieldsByDataClass as $dataClass => $fields) {
            if (!is_string($dataClass) || trim($dataClass) === '') {
                throw new \DomainException(
                    'Field allowlist data classes must be non-blank strings — a blank class hides what the policy permits (C5)'
                );
            }

            if (!is_array($fields) || $fields === []) {
                throw new \DomainException(
                    sprintf('Field allowlist for data class "%s" must be a non-empty list of field names (C5)', $dataClass)
                );
            }

            $parsedFields = [];
            foreach ($fields as $field) {
                if (!is_string($field) || trim($field) === '') {
                    throw new \DomainException(
                        sprintf('Field allowlist for data class "%s" contains a blank or non-string field name (C5)', $dataClass)
                    );
                }
                $parsedFields[] = $field;
            }

            $parsed[$dataClass] = $parsedFields;
        }

        $this->fieldsByDataClass = $parsed;
    }

    /**
     * Iteration surface for the payload builder: data class => allowed fields.
     *
     * @return array<string, list<string>>
     */
    public function fieldsByDataClass(): array
    {
        return $this->fieldsByDataClass;
    }
}

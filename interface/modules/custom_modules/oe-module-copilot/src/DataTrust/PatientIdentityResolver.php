<?php

/**
 * Patient identity resolution / duplicate detection (T7).
 *
 * patient_data has no natural-key uniqueness (AUDIT D8): the same human can
 * exist under multiple pids, so duplicate candidates are grouped by
 * normalized demographics (given name + family name + DOB + sex).
 *
 * Conservative rule (risk R2 — patient-data conflation): a row with ANY
 * unknown component (D1 empty string, whitespace-only value, missing or
 * unparseable date per D0/D6) is never grouped with anything. We never merge
 * people on incomplete evidence.
 *
 * Note: the trimming / strict-date helpers below intentionally duplicate
 * logic from the DataTrust normalizers being authored concurrently
 * (UnknownValues / ClinicalDate); a later ticket consolidates them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\DataTrust;

final class PatientIdentityResolver
{
    /**
     * Component separator for the normalized identity key. The ASCII unit
     * separator cannot collide with name/sex/date content the way a
     * printable delimiter could.
     */
    private const KEY_SEPARATOR = "\x1f";

    /**
     * Groups patients that share the same normalized demographics.
     *
     * @param list<PatientDemographics> $patients
     *
     * @return list<non-empty-list<PatientDemographics>> Groups of 2+ rows
     *         sharing normalized firstName + lastName + dob + sex. Rows with
     *         any unknown component never appear in any group.
     */
    public function duplicateGroups(array $patients): array
    {
        /** @var array<string, non-empty-list<PatientDemographics>> $buckets */
        $buckets = [];
        foreach ($patients as $patient) {
            $key = $this->identityKey($patient);
            if ($key === null) {
                continue;
            }
            $buckets[$key][] = $patient;
        }

        $groups = [];
        foreach ($buckets as $bucket) {
            if (count($bucket) >= 2) {
                $groups[] = $bucket;
            }
        }

        return $groups;
    }

    /**
     * Builds the normalized identity key, or null when any component is
     * unknown (conservative rule: unknown disqualifies the row entirely).
     */
    private function identityKey(PatientDemographics $patient): ?string
    {
        $firstName = $this->normalizeName($patient->firstName);
        $lastName = $this->normalizeName($patient->lastName);
        $dob = $this->normalizeDob($patient->dob);
        $sex = $this->normalizeSex($patient->sex);

        if ($firstName === null || $lastName === null || $dob === null || $sex === null) {
            return null;
        }

        return implode(self::KEY_SEPARATOR, [$firstName, $lastName, $dob, $sex]);
    }

    /**
     * Case- and whitespace-insensitive name normalization. Empty or
     * whitespace-only values are unknown (D1) and return null.
     */
    private function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($name));
        if ($collapsed === null || $collapsed === '') {
            return null;
        }

        return mb_strtolower($collapsed);
    }

    /**
     * Case-insensitive sex normalization. Empty or whitespace-only values
     * are unknown (D1) and return null.
     */
    private function normalizeSex(?string $sex): ?string
    {
        if ($sex === null) {
            return null;
        }

        $trimmed = trim($sex);
        if ($trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed);
    }

    /**
     * Strict 'Y-m-d' date validation with no rollover: the parsed value must
     * round-trip to the exact input, so '0000-00-00' (D0), '' (D1), free
     * text, and non-canonical forms (D6) all resolve to unknown (null).
     */
    private function normalizeDob(?string $dob): ?string
    {
        if ($dob === null) {
            return null;
        }

        $trimmed = trim($dob);
        if ($trimmed === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed);
        if ($parsed === false || $parsed->format('Y-m-d') !== $trimmed) {
            return null;
        }

        return $trimmed;
    }
}

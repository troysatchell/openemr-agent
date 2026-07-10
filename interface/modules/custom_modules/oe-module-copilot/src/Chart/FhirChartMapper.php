<?php

/**
 * Defensive FHIR-to-chart-domain mapper (T20; AUDIT D0/D1/D4/D6/D7/D8/D9/D10;
 * ARCHITECTURE.md §3).
 *
 * Consumes untrusted decoded FHIR JSON (a RawChartBundle) and produces the
 * synthesis-ready value objects the rest of the pipeline already trusts:
 * MedicationEntry, LabResultEntry, AllergyEntry (via map()), and
 * PatientDemographics (via demographics()). Every field is narrowed
 * defensively — is_array()/is_string() checks, never a cast — because this
 * is untrusted decoded JSON, not a validated internal type.
 *
 * Data-trust rules enforced here:
 *  - Blank/missing is unknown, never a guessed default (D1).
 *  - An unrecognizable or missing status/clinicalStatus maps to
 *    CurrencyStatus::Unknown — never guessed as current or resolved (D4/D10).
 *  - Dates are parsed strictly (ISO-8601/ATOM, 'Y-m-d H:i:s', 'Y-m-d' with
 *    round-trip validation); anything else, including free text like
 *    'not-a-date', fails to parse rather than being guessed (D0/D6).
 *  - A row missing a citable id, or missing the field that makes it usable
 *    (name/analyte/substance, a numeric lab value, a lab unit), is
 *    UNMAPPABLE and is counted via MappedChart::$unmappableRowCount —
 *    never silently dropped and never silently included.
 *  - A lab date is three-state: parseable → carried; ABSENT (missing key or
 *    a data-absent-reason extension) → the row is carried with
 *    resultedAt=null so the composer can surface it as unevaluable against
 *    a last-visit date (D0/D6) — a known unknown, not a mapping failure;
 *    a date STRING that fails strict parsing → garbage poisons the row,
 *    which stays unmappable.
 *  - Rows that are simply out of v1 scope by design (non-laboratory
 *    Observations) are skipped WITHOUT counting — that is selection, not a
 *    mapping failure.
 *  - Exactly one Patient resource is required per requested uuid; zero or
 *    more than one fails loud via \DomainException rather than silently
 *    picking one or conflating identities (D8).
 *  - The trusted pid is never read from FHIR content — demographics() takes
 *    it as a caller-supplied parameter, sourced from the DB uuid registry
 *    (D7). This mapper has no opinion on where that pid came from.
 *
 * Named v1 gap: Condition (problems) is read into RawChartBundle but not
 * mapped to FollowUpEntry here — open follow-up detection needs a source
 * (care-plan/appointment data) not yet mapped on the live path, so
 * OpenFollowUpDetector currently sees no live follow-up entries. This is a
 * known, deliberate gap (tracked, not silently missing) — see MappedChart's
 * class docblock.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\DataTrust\UnknownValues;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final readonly class FhirChartMapper
{
    /**
     * FHIR AllergyIntolerance/Observation category code identifying the rows
     * in v1 scope: only laboratory-category Observations are labs.
     */
    private const LABORATORY_CATEGORY_CODE = 'laboratory';

    /** @var list<string> lowercased MedicationRequest/AllergyIntolerance statuses treated as currently active */
    private const CURRENT_MEDICATION_STATUSES = ['active'];

    /** @var list<string> lowercased MedicationRequest statuses treated as no-longer-current */
    private const NOT_CURRENT_MEDICATION_STATUSES = ['stopped', 'completed', 'cancelled', 'entered-in-error'];

    /** @var list<string> lowercased AllergyIntolerance clinicalStatus codes treated as currently active */
    private const CURRENT_ALLERGY_STATUSES = ['active'];

    /** @var list<string> lowercased AllergyIntolerance clinicalStatus codes treated as no-longer-current */
    private const NOT_CURRENT_ALLERGY_STATUSES = ['inactive', 'resolved'];

    /**
     * Maps the four synthesizable raw FHIR sources into synthesis-ready
     * entries. Condition (problems) is intentionally not mapped in v1 — see
     * class docblock.
     */
    public function map(RawChartBundle $bundle): MappedChart
    {
        $unmappableRowCount = 0;

        $medications = [];
        foreach ($bundle->medications as $resource) {
            $entry = $this->mapMedication($resource);
            if ($entry === null) {
                $unmappableRowCount++;
                continue;
            }
            $medications[] = $entry;
        }

        $labs = [];
        foreach ($bundle->observations as $resource) {
            if (!$this->isLaboratoryObservation($resource)) {
                // Out of v1 scope by design (selection, not omission) —
                // never counted as unmappable.
                continue;
            }

            $entry = $this->mapLabResult($resource);
            if ($entry === null) {
                $unmappableRowCount++;
                continue;
            }
            $labs[] = $entry;
        }

        $allergies = [];
        foreach ($bundle->allergies as $resource) {
            $entry = $this->mapAllergy($resource);
            if ($entry === null) {
                $unmappableRowCount++;
                continue;
            }
            $allergies[] = $entry;
        }

        return new MappedChart($medications, $labs, $allergies, [], $unmappableRowCount);
    }

    /**
     * Builds patient demographics from the bundle's Patient resource. The
     * pid is a caller-supplied parameter — the trusted uuid->pid resolution
     * is the DB registry's job (D7), never read from FHIR content here.
     *
     * @throws \DomainException when the bundle does not carry exactly one
     *         Patient resource for the requested uuid (D8: never conflate)
     */
    public function demographics(RawChartBundle $bundle, int $pid, string $uuid): PatientDemographics
    {
        if (count($bundle->patient) !== 1) {
            throw new \DomainException(sprintf(
                'Expected exactly one Patient resource for uuid "%s", found %d (D8: identity must never be conflated or guessed)',
                $uuid,
                count($bundle->patient),
            ));
        }

        $resource = $bundle->patient[0];

        return new PatientDemographics(
            $pid,
            $uuid,
            $this->patientGivenName($resource),
            $this->patientFamilyName($resource),
            $this->extractString($resource['birthDate'] ?? null),
            $this->extractString($resource['gender'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function mapMedication(array $resource): ?MedicationEntry
    {
        $id = $this->resourceId($resource);
        if ($id === null) {
            return null;
        }

        $name = $this->codeableConceptText($resource['medicationCodeableConcept'] ?? null);
        if ($name === null) {
            return null;
        }

        $statusRaw = $resource['status'] ?? null;
        $status = $this->currencyFromStatus(
            $this->extractString($statusRaw),
            self::CURRENT_MEDICATION_STATUSES,
            self::NOT_CURRENT_MEDICATION_STATUSES,
        );

        return new MedicationEntry($name, $status, [new SourceRef('MedicationRequest', $id)]);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function mapLabResult(array $resource): ?LabResultEntry
    {
        $id = $this->resourceId($resource);
        if ($id === null) {
            return null;
        }

        $analyte = $this->codeableConceptText($resource['code'] ?? null);
        if ($analyte === null) {
            return null;
        }

        $valueQuantity = $resource['valueQuantity'] ?? null;
        if (!is_array($valueQuantity)) {
            return null;
        }

        $value = $this->numericValue($valueQuantity['value'] ?? null);
        if ($value === null) {
            return null;
        }

        $unit = $this->extractString($valueQuantity['unit'] ?? null);
        if ($unit === null) {
            return null;
        }

        // Three-state date (see class docblock): absent → carried undated (a
        // known unknown the composer surfaces as unevaluable, D0/D6);
        // present-but-unparseable → garbage poisons the row (unmappable).
        $rawEffective = $this->extractString($resource['effectiveDateTime'] ?? null);
        $resultedAt = null;
        if ($rawEffective !== null) {
            $resultedAt = $this->parseEffectiveDateTime($rawEffective);
            if ($resultedAt === null) {
                return null;
            }
        }

        return new LabResultEntry($analyte, $value, $unit, $resultedAt, [new SourceRef('Observation', $id)]);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function mapAllergy(array $resource): ?AllergyEntry
    {
        $id = $this->resourceId($resource);
        if ($id === null) {
            return null;
        }

        $substance = $this->codeableConceptText($resource['code'] ?? null);
        if ($substance === null) {
            return null;
        }

        return new AllergyEntry($substance, $this->allergyClinicalStatus($resource), [new SourceRef('AllergyIntolerance', $id)]);
    }

    /**
     * True only when at least one category.coding[*].code equals
     * 'laboratory' — any other category, or a missing/malformed one, is out
     * of v1 scope by design (never counted as unmappable).
     *
     * @param array<string, mixed> $resource
     */
    private function isLaboratoryObservation(array $resource): bool
    {
        $categories = $resource['category'] ?? null;
        if (!is_array($categories)) {
            return false;
        }

        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            $codings = $category['coding'] ?? null;
            if (!is_array($codings)) {
                continue;
            }

            foreach ($codings as $coding) {
                if (!is_array($coding)) {
                    continue;
                }

                $code = $coding['code'] ?? null;
                if (is_string($code) && $code === self::LABORATORY_CATEGORY_CODE) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * clinicalStatus.coding[0].code -> CurrencyStatus. A missing or
     * malformed clinicalStatus maps to Unknown — never assumed active or
     * resolved (D4/D10).
     *
     * @param array<string, mixed> $resource
     */
    private function allergyClinicalStatus(array $resource): CurrencyStatus
    {
        $clinicalStatus = $resource['clinicalStatus'] ?? null;
        if (!is_array($clinicalStatus)) {
            return CurrencyStatus::Unknown;
        }

        $codings = $clinicalStatus['coding'] ?? null;
        if (!is_array($codings) || !isset($codings[0]) || !is_array($codings[0])) {
            return CurrencyStatus::Unknown;
        }

        $code = $this->extractString($codings[0]['code'] ?? null);

        return $this->currencyFromStatus($code, self::CURRENT_ALLERGY_STATUSES, self::NOT_CURRENT_ALLERGY_STATUSES);
    }

    /**
     * @param list<string> $currentValues lowercased status values mapping to Current
     * @param list<string> $notCurrentValues lowercased status values mapping to NotCurrent
     */
    private function currencyFromStatus(?string $status, array $currentValues, array $notCurrentValues): CurrencyStatus
    {
        if ($status === null) {
            return CurrencyStatus::Unknown;
        }

        $normalized = mb_strtolower(trim($status), 'UTF-8');

        if (in_array($normalized, $currentValues, true)) {
            return CurrencyStatus::Current;
        }
        if (in_array($normalized, $notCurrentValues, true)) {
            return CurrencyStatus::NotCurrent;
        }

        return CurrencyStatus::Unknown;
    }

    /**
     * Extracts a CodeableConcept's display text: .text, falling back to
     * .coding[0].display. Missing/blank/non-string after trim -> null (D1).
     */
    private function codeableConceptText(mixed $concept): ?string
    {
        if (!is_array($concept)) {
            return null;
        }

        $text = $this->extractString($concept['text'] ?? null);
        if ($text !== null) {
            return $text;
        }

        $codings = $concept['coding'] ?? null;
        if (!is_array($codings) || !isset($codings[0]) || !is_array($codings[0])) {
            return null;
        }

        return $this->extractString($codings[0]['display'] ?? null);
    }

    /**
     * FHIR resource id. Missing/blank -> null: an uncitable row can never be
     * grounded (R6/R10).
     *
     * @param array<string, mixed> $resource
     */
    private function resourceId(array $resource): ?string
    {
        return $this->extractString($resource['id'] ?? null);
    }

    /**
     * value must be int|float — never a numeric-string coercion. int is
     * widened to float; anything else (including a numeric string) -> null.
     */
    private function numericValue(mixed $value): ?float
    {
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_float($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Strictly parses an Observation.effectiveDateTime against the three
     * audited shapes (ISO-8601/ATOM, 'Y-m-d H:i:s', 'Y-m-d'), each validated
     * by exact round-trip formatting so PHP's lenient parser can never guess
     * or roll over an invalid date (D0/D6). Anything else, including
     * unparseable free text, returns null.
     */
    private function parseEffectiveDateTime(mixed $raw): ?\DateTimeImmutable
    {
        $trimmed = $this->extractString($raw);
        if ($trimmed === null) {
            return null;
        }

        $formats = [
            '!' . \DateTimeInterface::ATOM => \DateTimeInterface::ATOM,
            '!Y-m-d H:i:s' => 'Y-m-d H:i:s',
            '!Y-m-d' => 'Y-m-d',
        ];

        foreach ($formats as $parseFormat => $canonicalFormat) {
            $parsed = \DateTimeImmutable::createFromFormat($parseFormat, $trimmed);
            if ($parsed !== false && $parsed->format($canonicalFormat) === $trimmed) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $resource Patient resource
     */
    private function patientGivenName(array $resource): ?string
    {
        $name = $this->firstHumanName($resource);
        if ($name === null) {
            return null;
        }

        $given = $name['given'] ?? null;
        if (!is_array($given) || !isset($given[0])) {
            return null;
        }

        return $this->extractString($given[0]);
    }

    /**
     * @param array<string, mixed> $resource Patient resource
     */
    private function patientFamilyName(array $resource): ?string
    {
        $name = $this->firstHumanName($resource);
        if ($name === null) {
            return null;
        }

        return $this->extractString($name['family'] ?? null);
    }

    /**
     * @param array<string, mixed> $resource Patient resource
     *
     * @return array<mixed, mixed>|null Patient.name[0], defensively narrowed
     */
    private function firstHumanName(array $resource): ?array
    {
        $names = $resource['name'] ?? null;
        if (!is_array($names) || !isset($names[0]) || !is_array($names[0])) {
            return null;
        }

        return $names[0];
    }

    /**
     * Narrows a mixed value to a trimmed, non-blank string, or null (D1) —
     * never a cast.
     */
    private function extractString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return UnknownValues::normalize($value);
    }
}

<?php

/**
 * Parse-don't-validate boundary for VLM extraction output (W2_ARCHITECTURE.md
 * §2 step 4, Decision W2; §3; PS-7).
 *
 * VLM JSON is untrusted draft data — the same posture as LLM prose. This
 * parser consumes the wire shape mirrored by the committed JSON Schemas
 * (`schemas/extraction/lab_pdf.schema.json`,
 * `schemas/extraction/intake_form.schema.json`) and produces the strict
 * extraction DTOs, or throws {@see ExtractionParseException}. Failure is
 * WHOLE: a schema violation anywhere in the document — malformed JSON, an
 * unrecognized key, a missing key, a wrong-shaped field, an out-of-range
 * confidence, a DTO invariant broken by the parsed data — fails the entire
 * extraction. A partially-valid extraction is never partially accepted (one
 * bad analyte fails the whole panel).
 *
 * The schema boundary is containment, not just validation (PS-7): every
 * level enforces a STRICT key allowlist (mirroring the schemas'
 * `additionalProperties: false`), so instruction-like content smuggled in as
 * an unrecognized key is refused outright. Instruction-like content inside a
 * recognized field's *value* survives byte-identical as data — sanitizing or
 * acting on it is the answer model's read-time concern (Week 1
 * untrusted-content treatment), never this boundary's.
 *
 * The one OPTIONAL key in the allowlist is `bbox` on every ExtractedField
 * wire object (TRO-44): a UI-only page-region hint, parsed via
 * {@see BoundingBox::fromWire()}. Per R-W3 ("a sloppy box degrades UX, never
 * correctness"), a malformed or missing bbox degrades to null WITHOUT
 * rejecting the field — the strictness this boundary guarantees is about
 * unrecognized *keys*, not about the bbox value's own well-formedness.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Extraction;

use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final class VlmExtractionParser
{
    /** @var list<string> */
    private const CITATION_KEYS = [
        'source_type',
        'source_id',
        'page_or_section',
        'field_or_chunk_id',
        'quote_or_value',
    ];

    /** @var list<string> */
    private const EXTRACTED_FIELD_REQUIRED_KEYS = ['isPresent', 'value', 'confidence', 'citation'];

    /**
     * bbox is the one OPTIONAL key on an ExtractedField wire object
     * (TRO-44) — omitting it stays valid (backward compatibility with every
     * pre-bbox fixture and live wire).
     *
     * @var list<string>
     */
    private const EXTRACTED_FIELD_OPTIONAL_KEYS = ['bbox'];

    private function __construct()
    {
    }

    public static function parseLabPdf(string $json): LabPdfExtraction
    {
        $root = self::decodeMap($json, 'lab_pdf root');
        self::assertExactKeys($root, ['documentId', 'analytes'], 'lab_pdf root');

        $documentId = self::extractUntrustedDocumentId($root, 'lab_pdf root');
        $analytesWire = self::extractList($root, 'analytes', 'lab_pdf root');

        $analytes = [];
        foreach ($analytesWire as $index => $analyteWire) {
            $analytes[] = self::parseAnalyte($analyteWire, sprintf('analytes[%d]', $index));
        }

        try {
            return new LabPdfExtraction($documentId, $analytes);
        } catch (\DomainException $e) {
            throw new ExtractionParseException('lab_pdf extraction failed validation at "lab_pdf root"', 0, $e);
        }
    }

    public static function parseIntakeForm(string $json): IntakeFormExtraction
    {
        $root = self::decodeMap($json, 'intake_form root');
        self::assertExactKeys($root, [
            'documentId',
            'chiefConcern',
            'currentMedications',
            'allergies',
            'familyHistory',
            'demographics',
        ], 'intake_form root');

        $documentId = self::extractUntrustedDocumentId($root, 'intake_form root');
        $chiefConcern = self::parseExtractedField($root['chiefConcern'], 'chiefConcern');
        $currentMedications = self::parseExtractedFieldGroup($root, 'currentMedications');
        $allergies = self::parseExtractedFieldGroup($root, 'allergies');
        $familyHistory = self::parseExtractedFieldGroup($root, 'familyHistory');
        $demographics = self::parseExtractedFieldGroup($root, 'demographics');

        try {
            return new IntakeFormExtraction(
                $documentId,
                $chiefConcern,
                $currentMedications,
                $allergies,
                $familyHistory,
                $demographics,
            );
        } catch (\DomainException $e) {
            throw new ExtractionParseException('intake_form extraction failed validation at "intake_form root"', 0, $e);
        }
    }

    private static function parseAnalyte(mixed $wire, string $fieldPath): LabAnalyteExtraction
    {
        $data = self::requireMap($wire, $fieldPath);
        self::assertExactKeys($data, [
            'testName',
            'value',
            'unit',
            'referenceRange',
            'abnormalFlag',
            'collectionDate',
        ], $fieldPath);

        $testName = self::parseExtractedField($data['testName'], $fieldPath . '.testName');
        $value = self::parseExtractedField($data['value'], $fieldPath . '.value');
        $unit = self::parseExtractedField($data['unit'], $fieldPath . '.unit');
        $referenceRange = self::parseExtractedField($data['referenceRange'], $fieldPath . '.referenceRange');
        $abnormalFlag = self::parseExtractedField($data['abnormalFlag'], $fieldPath . '.abnormalFlag');
        $collectionDateRaw = self::extractNullableString($data, 'collectionDate', $fieldPath);

        try {
            return new LabAnalyteExtraction($testName, $value, $unit, $referenceRange, $abnormalFlag, $collectionDateRaw);
        } catch (\DomainException $e) {
            throw new ExtractionParseException(sprintf('Analyte at "%s" failed validation', $fieldPath), 0, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $root
     *
     * @return list<ExtractedField>
     */
    private static function parseExtractedFieldGroup(array $root, string $key): array
    {
        $wireList = self::extractList($root, $key, 'intake_form root');

        $fields = [];
        foreach ($wireList as $index => $wire) {
            $fields[] = self::parseExtractedField($wire, sprintf('%s[%d]', $key, $index));
        }

        return $fields;
    }

    private static function parseExtractedField(mixed $wire, string $fieldPath): ExtractedField
    {
        $data = self::requireMap($wire, $fieldPath);
        self::assertAllowedKeys($data, self::EXTRACTED_FIELD_REQUIRED_KEYS, self::EXTRACTED_FIELD_OPTIONAL_KEYS, $fieldPath);

        $isPresent = self::extractBool($data, 'isPresent', $fieldPath);

        if (!$isPresent) {
            if ($data['value'] !== null || $data['confidence'] !== null || $data['citation'] !== null) {
                throw new ExtractionParseException(
                    sprintf('"%s" is marked absent but carries a value, confidence, or citation', $fieldPath),
                );
            }

            return ExtractedField::absent();
        }

        $value = self::extractRequiredString($data, 'value', $fieldPath);
        $confidence = self::parseConfidence($data, $fieldPath);
        $citation = self::parseCitation($data['citation'], $fieldPath . '.citation');
        $bbox = array_key_exists('bbox', $data) ? BoundingBox::fromWire($data['bbox']) : null;

        try {
            return ExtractedField::present($value, $confidence, $citation, $bbox);
        } catch (\DomainException $e) {
            throw new ExtractionParseException(sprintf('"%s" failed validation', $fieldPath), 0, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function parseConfidence(array $data, string $fieldPath): ExtractionConfidence
    {
        if (!array_key_exists('confidence', $data)) {
            throw new ExtractionParseException(sprintf('"%s" is missing required key "confidence"', $fieldPath));
        }

        $value = $data['confidence'];
        if (!is_int($value) && !is_float($value)) {
            throw new ExtractionParseException(sprintf('"%s.confidence" must be numeric', $fieldPath));
        }

        try {
            return new ExtractionConfidence((float) $value);
        } catch (\DomainException $e) {
            throw new ExtractionParseException(sprintf('"%s.confidence" is out of range', $fieldPath), 0, $e);
        }
    }

    private static function parseCitation(mixed $wire, string $fieldPath): SourceRef
    {
        $data = self::requireMap($wire, $fieldPath);
        self::assertExactKeys($data, self::CITATION_KEYS, $fieldPath);

        $sourceType = self::extractRequiredString($data, 'source_type', $fieldPath);
        $sourceId = self::extractRequiredString($data, 'source_id', $fieldPath);
        $pageOrSection = self::extractNullableString($data, 'page_or_section', $fieldPath);
        $fieldOrChunkId = self::extractNullableString($data, 'field_or_chunk_id', $fieldPath);
        $quoteOrValue = self::extractNullableString($data, 'quote_or_value', $fieldPath);

        try {
            return new SourceRef($sourceType, $sourceId, $pageOrSection, $fieldOrChunkId, $quoteOrValue);
        } catch (\DomainException $e) {
            throw new ExtractionParseException(sprintf('"%s" citation failed validation', $fieldPath), 0, $e);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodeMap(string $json, string $fieldPath): array
    {
        try {
            $decoded = json_decode(self::unwrapJsonObject($json), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExtractionParseException(sprintf('"%s" is not valid JSON', $fieldPath), 0, $e);
        }

        return self::requireMap($decoded, $fieldPath);
    }

    /**
     * Returns the outermost JSON object substring (first `{` to last `}`).
     *
     * The recorded fixtures were always pristine JSON, but a LIVE model
     * commonly wraps its object in a ```json code fence or a sentence of
     * preamble/postamble. Slicing to the outermost braces tolerates that
     * wrapper without loosening the strict key/shape validation that follows
     * — schema containment (PS-7) is unchanged; only the envelope is peeled.
     * A string with no braces is returned unchanged so json_decode still
     * produces the original, honest parse error.
     */
    private static function unwrapJsonObject(string $raw): string
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return $raw;
        }

        return substr($raw, $start, $end - $start + 1);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function requireMap(mixed $value, string $fieldPath): array
    {
        if (!is_array($value)) {
            throw new ExtractionParseException(sprintf('"%s" must be a JSON object', $fieldPath));
        }

        return $value;
    }

    /**
     * STRICT key allowlist (schema-as-containment, PS-7): every key present
     * must be one of $keys, and every key in $keys must be present. Because
     * every committed schema's `required` list equals its `properties` list
     * (`additionalProperties: false`), one check enforces both directions.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $keys
     */
    private static function assertExactKeys(array $data, array $keys, string $fieldPath): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $keys, true)) {
                throw new ExtractionParseException(sprintf('"%s" contains an unrecognized key', $fieldPath));
            }
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ExtractionParseException(sprintf('"%s" is missing required key "%s"', $fieldPath, $key));
            }
        }
    }

    /**
     * Like {@see assertExactKeys()}, but a subset of the allowlist is
     * OPTIONAL: every key present must be required-or-optional, and every
     * required key must be present, but an optional key may be omitted
     * (TRO-44's `bbox` — backward compatibility with every pre-bbox wire).
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $requiredKeys
     * @param list<string> $optionalKeys
     */
    private static function assertAllowedKeys(array $data, array $requiredKeys, array $optionalKeys, string $fieldPath): void
    {
        $allowedKeys = [...$requiredKeys, ...$optionalKeys];

        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                throw new ExtractionParseException(sprintf('"%s" contains an unrecognized key', $fieldPath));
            }
        }

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ExtractionParseException(sprintf('"%s" is missing required key "%s"', $fieldPath, $key));
            }
        }
    }

    /**
     * The wire documentId is MODEL OUTPUT and therefore untrusted by design:
     * the ingestion service discards it and stamps the real attached
     * document id before anything persists. Rejecting an otherwise-perfect
     * extraction because the model chose a blank placeholder enforces
     * provenance on a value that is thrown away (live-smoke failure,
     * 2026-07-14: the model faithfully copied an empty-string example and
     * every extraction whole-failed at the DTO). A blank id normalizes to
     * the '0' placeholder here, at the untrusted boundary; the DTO's
     * non-blank rule stays intact for every trusted construction path.
     *
     * @param array<array-key, mixed> $data
     */
    private static function extractUntrustedDocumentId(array $data, string $fieldPath): string
    {
        $documentId = self::extractRequiredString($data, 'documentId', $fieldPath);

        return trim($documentId) === '' ? '0' : $documentId;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function extractRequiredString(array $data, string $key, string $fieldPath): string
    {
        if (!array_key_exists($key, $data)) {
            throw new ExtractionParseException(sprintf('"%s" is missing required key "%s"', $fieldPath, $key));
        }

        $value = $data[$key];
        if (!is_string($value)) {
            throw new ExtractionParseException(sprintf('"%s.%s" must be a string', $fieldPath, $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function extractNullableString(array $data, string $key, string $fieldPath): ?string
    {
        if (!array_key_exists($key, $data)) {
            throw new ExtractionParseException(sprintf('"%s" is missing required key "%s"', $fieldPath, $key));
        }

        $value = $data[$key];
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new ExtractionParseException(sprintf('"%s.%s" must be a string or null', $fieldPath, $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function extractBool(array $data, string $key, string $fieldPath): bool
    {
        if (!array_key_exists($key, $data)) {
            throw new ExtractionParseException(sprintf('"%s" is missing required key "%s"', $fieldPath, $key));
        }

        $value = $data[$key];
        if (!is_bool($value)) {
            throw new ExtractionParseException(sprintf('"%s.%s" must be a boolean', $fieldPath, $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    private static function extractList(array $data, string $key, string $fieldPath): array
    {
        if (!array_key_exists($key, $data)) {
            throw new ExtractionParseException(sprintf('"%s" is missing required key "%s"', $fieldPath, $key));
        }

        $value = $data[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ExtractionParseException(sprintf('"%s.%s" must be a JSON array', $fieldPath, $key));
        }

        return $value;
    }
}

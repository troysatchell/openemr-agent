<?php

/**
 * The intake_form extraction root (W2_ARCHITECTURE §3).
 *
 * Carries a chief concern plus list-shaped current medications, allergies,
 * family history, and demographics — each entry an ExtractedField with its
 * own per-group citation — tied to a non-blank source document id. Absent
 * groups are empty lists, never guessed content; every list entry must be an
 * ExtractedField — the schema boundary contains whatever the extraction step
 * produced.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Extraction;

final readonly class IntakeFormExtraction
{
    /** @var list<ExtractedField> */
    public array $currentMedications;

    /** @var list<ExtractedField> */
    public array $allergies;

    /** @var list<ExtractedField> */
    public array $familyHistory;

    /** @var list<ExtractedField> */
    public array $demographics;

    /**
     * The four field-group arrays arrive untyped at this boundary
     * (extraction output is untrusted draft data): elements are validated
     * with instanceof, never assumed from the caller's declared type.
     *
     * @param list<mixed> $currentMedications
     * @param list<mixed> $allergies
     * @param list<mixed> $familyHistory
     * @param list<mixed> $demographics
     */
    public function __construct(
        public string $documentId,
        public ExtractedField $chiefConcern,
        array $currentMedications,
        array $allergies,
        array $familyHistory,
        array $demographics,
    ) {
        if (trim($documentId) === '') {
            throw new \DomainException('IntakeFormExtraction requires a non-blank source document id — provenance is mandatory');
        }

        $this->currentMedications = self::validatedFieldGroup('currentMedications', $currentMedications);
        $this->allergies = self::validatedFieldGroup('allergies', $allergies);
        $this->familyHistory = self::validatedFieldGroup('familyHistory', $familyHistory);
        $this->demographics = self::validatedFieldGroup('demographics', $demographics);
    }

    /**
     * @param list<mixed> $group
     *
     * @return list<ExtractedField>
     */
    private static function validatedFieldGroup(string $groupName, array $group): array
    {
        $validated = [];
        foreach ($group as $entry) {
            if (!$entry instanceof ExtractedField) {
                throw new \DomainException("IntakeFormExtraction {$groupName} entries must all be ExtractedField instances — the schema boundary contains malformed extraction output");
            }
            $validated[] = $entry;
        }

        return $validated;
    }
}

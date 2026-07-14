<?php

/**
 * Persists list-shaped intake facts as module-owned reconciliation
 * candidates (W2_ARCHITECTURE.md §2 step 5, §10; PS-4).
 *
 * List-shaped clinical facts extracted from an intake form — chief concern,
 * current medications, allergies, family history, demographics — are NOT
 * observation-shaped, and they do not take the DerivedObservationWriter
 * path. They stay module-owned extraction records surfaced to the physician
 * as cited reconciliation candidates. Writing them into the native `lists`
 * (medication_list, allergy) or `prescriptions` tables would be medication
 * reconciliation — a clinical act that exceeds the two-write amendment
 * (CLAUDE.md bright line: auto-writing patient-reported meds is write-back
 * through the side door). This writer never touches those tables; it only
 * ever inserts into the module-owned `mod_copilot_intake_candidates` table
 * (IntakeCandidatesSchema).
 *
 * Every present field in the extraction becomes one candidate row, carrying
 * its citation (field path, page, confidence) so the physician sees it as
 * cited evidence, not an assertion. Absent fields (D1: absent is absent,
 * never defaulted) simply produce no row — an intake form with nothing
 * extractable persists zero candidates, which is a legitimate outcome, not
 * an error.
 *
 * Re-persisting a document (re-extraction) versions the candidate set
 * rather than silently overwriting it (§10): every prior active row for
 * that document is stamped `superseded_at` and retained — never deleted —
 * before the new row set is inserted. This applies even when the new
 * extraction is empty: a re-extraction that grounds nothing still means the
 * document's *previously* extracted candidates are stale relative to the
 * latest read of the source, so they are superseded and zero new rows take
 * their place, rather than leaving stale candidates active under a document
 * that no longer supports them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Persistence;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Extraction\ExtractedField;
use OpenEMR\Modules\Copilot\Extraction\IntakeFormExtraction;

final class IntakeCandidateWriter
{
    /**
     * Identifies which extraction pipeline version produced a candidate
     * row. Bump this when the VLM extraction prompt/schema changes
     * materially — it is what lets a future audit tell which extractor
     * produced a given candidate.
     */
    public const EXTRACTOR_VERSION = 'vlm-extractor/1.0';

    /**
     * @throws \DomainException if the patient pid or source document id is invalid — always thrown before any write
     * @throws \RuntimeException if any statement in the transaction fails — the whole transaction is rolled back before rethrowing
     */
    public function persist(PhysicianContext $physician, int $patientPid, IntakeFormExtraction $extraction): IntakeCandidateSet
    {
        if ($patientPid <= 0) {
            throw new \DomainException('IntakeCandidateWriter requires a positive patient pid');
        }

        if (!ctype_digit($extraction->documentId)) {
            throw new \DomainException(
                'IntakeCandidateWriter requires a numeric source document id'
                . ' — it is bound as the mod_copilot_intake_candidates.document_id foreign key (W2_ARCHITECTURE §10)'
            );
        }
        $documentId = (int) $extraction->documentId;

        IntakeCandidatesSchema::ensureInstalled();

        try {
            return QueryUtils::inTransaction(
                fn (): IntakeCandidateSet => $this->supersedeAndInsert($patientPid, $documentId, $extraction),
            );
        } catch (\Throwable $e) {
            // inTransaction() has already rolled back; wrap generically (R11).
            throw new \RuntimeException('intake-candidate persistence failed', 0, $e);
        }
    }

    private function supersedeAndInsert(int $patientPid, int $documentId, IntakeFormExtraction $extraction): IntakeCandidateSet
    {
        // Retain, never delete (§10): prior active rows for this document
        // are stamped superseded, the new extraction defines the active set.
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . IntakeCandidatesSchema::CANDIDATES_TABLE
                . ' SET superseded_at = NOW() WHERE document_id = ? AND superseded_at IS NULL',
            [$documentId],
        );

        $candidateIds = [];

        if ($extraction->chiefConcern->isPresent) {
            $candidateIds[] = $this->insertCandidate($patientPid, $documentId, 'chiefConcern', $extraction->chiefConcern);
        }

        foreach ($this->fieldGroups($extraction) as $groupName => $fields) {
            foreach ($fields as $field) {
                if (!$field->isPresent) {
                    continue;
                }
                $candidateIds[] = $this->insertCandidate($patientPid, $documentId, $groupName, $field);
            }
        }

        return new IntakeCandidateSet($candidateIds);
    }

    /**
     * The list-shaped field groups, keyed by the field_group name recorded
     * on each candidate row (the DTO property name, unchanged) — never
     * `currentMedications`/`allergies` rows in the native `lists` table or
     * `prescriptions` table.
     *
     * @return array<string, list<ExtractedField>>
     */
    private function fieldGroups(IntakeFormExtraction $extraction): array
    {
        return [
            'currentMedications' => $extraction->currentMedications,
            'allergies' => $extraction->allergies,
            'familyHistory' => $extraction->familyHistory,
            'demographics' => $extraction->demographics,
        ];
    }

    private function insertCandidate(int $patientPid, int $documentId, string $fieldGroup, ExtractedField $field): int
    {
        $citation = $field->citation;

        return QueryUtils::sqlInsert(
            'INSERT INTO ' . IntakeCandidatesSchema::CANDIDATES_TABLE
                . ' (patient_pid, document_id, field_group, value_text, field_path, page, confidence, extractor_version)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $patientPid,
                $documentId,
                $fieldGroup,
                $field->value,
                $citation->fieldOrChunkId ?? $fieldGroup,
                $citation?->pageOrSection,
                $field->confidence?->value,
                self::EXTRACTOR_VERSION,
            ],
        );
    }
}

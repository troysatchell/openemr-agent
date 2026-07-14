<?php

/**
 * One-directional supersession of derived lab observations by real ones
 * (W2_ARCHITECTURE.md §2 step 5 "Dedup is one-directional by invariant";
 * PS-5, docs/W2_PRD_SEEDS.md).
 *
 * The derived record is the ONLY thing this reconciler may ever suppress.
 * A real observation (interface lab feed, manual entry — a
 * `procedure_result` row with NO `mod_copilot_extraction_lineage` row) is
 * never mutated or hidden: `reconcile()` finds, for every not-yet-reconciled
 * derived observation belonging to a patient, the real observations that
 * match it on patient + normalized analyte name + normalized unit +
 * collection-date tolerance window, and:
 *
 *  - exactly ONE match → the derived result is superseded: its lineage row
 *    is annotated `superseded_by_result_id` + `superseded_at`.
 *  - MORE than one match → ambiguous: both real candidates are kept,
 *    nothing merges; the derived result's lineage row is flagged
 *    `ambiguous_flag = 1` instead (`superseded_at` stays NULL) — a wrong
 *    merge is data loss, a duplicate is only a provenance-distinguished
 *    annoyance (PS-5).
 *  - ZERO matches → the derived result is left untouched.
 *
 * **Impossible-by-construction suppression of a real row.** The only
 * statements this class executes besides SELECTs are UPDATEs against the
 * module-owned lineage table (`ExtractionLineageSchema::LINEAGE_TABLE`) —
 * a table a real observation never has a row in, because only
 * `DerivedObservationWriter` ever inserts a lineage row. There is no code
 * path here that writes to `procedure_result`, `procedure_report`, or
 * `procedure_order` at all: this is an API-shape guarantee, not a runtime
 * check (PS-5's acceptance criterion (c)).
 *
 * **Matching semantics.** Analyte name and unit are compared
 * case-insensitively after trimming (`LOWER(TRIM(...))` both sides) —
 * "Potassium"/"mmol/L" matches "POTASSIUM"/"MMOL/L" but never
 * "Potassium"/"mg/dL". The collection-date window is
 * `COLLECTION_WINDOW_HOURS` hours either direction, absorbing a same-day
 * draw logged at a different time of day (a fasting AM draw vs. an
 * afternoon interface timestamp) without reaching into the next collection
 * cycle. A candidate or real row with no collection date at all can never
 * satisfy the window (D0/D6: an unknown date stays unknown, never treated
 * as a match).
 *
 * **Idempotent by construction.** The candidate query only selects derived
 * results with `superseded_at IS NULL AND ambiguous_flag = 0`, so an
 * already-superseded record is excluded from every later run, and so is an
 * already-ambiguous-flagged one — a flagged record is the physician's
 * review queue, not this reconciler's, and is never silently re-processed.
 * A second call against the same patient therefore reports nothing new.
 *
 * **Scope.** Derived-vs-real only. Derived-vs-derived reconciliation
 * (re-extraction versioning, where re-processing a document produces a new
 * derived set that supersedes a prior extraction of the same source) is a
 * separate concern named in §2 step 5 and is not handled by this class.
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

final class DerivedObservationSupersession
{
    /**
     * Collection-date tolerance window for matching a derived observation
     * to a real one, in hours either direction (W2_ARCHITECTURE.md §2 step
     * 5: "collection-date window").
     */
    public const COLLECTION_WINDOW_HOURS = 24;

    /**
     * @throws \DomainException if $patientPid is not positive
     */
    public function reconcile(int $patientPid): SupersessionReport
    {
        if ($patientPid <= 0) {
            throw new \DomainException('DerivedObservationSupersession requires a positive patient pid');
        }

        SupersessionSchema::ensureInstalled();

        $superseded = [];
        $ambiguous = [];

        foreach ($this->unreconciledDerivedCandidates($patientPid) as $candidate) {
            $matches = $this->realMatches($patientPid, $candidate);

            if (count($matches) === 1) {
                $this->markSuperseded($candidate['procedureResultId'], $matches[0]);
                $superseded[] = $candidate['procedureResultId'];
            } elseif (count($matches) > 1) {
                $this->markAmbiguous($candidate['procedureResultId']);
                $ambiguous[] = $candidate['procedureResultId'];
            }
        }

        return new SupersessionReport($superseded, $ambiguous);
    }

    /**
     * Derived results for this patient not yet reconciled: a lineage row
     * exists (it is derived), and neither `superseded_at` nor
     * `ambiguous_flag` has been set by a prior run.
     *
     * @return list<array{procedureResultId: int, resultText: string, units: string, resultDate: ?string}>
     */
    private function unreconciledDerivedCandidates(int $patientPid): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT prr.procedure_result_id AS procedure_result_id,'
                . ' prr.result_text AS result_text,'
                . ' prr.units AS units,'
                . ' prr.date AS result_date'
                . ' FROM procedure_result prr'
                . ' JOIN procedure_report pr ON prr.procedure_report_id = pr.procedure_report_id'
                . ' JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id'
                . ' JOIN ' . ExtractionLineageSchema::LINEAGE_TABLE . ' lin'
                . ' ON lin.procedure_result_id = prr.procedure_result_id'
                . ' WHERE po.patient_id = ?'
                . ' AND lin.superseded_at IS NULL'
                . ' AND lin.ambiguous_flag = 0',
            [$patientPid],
        );

        return $this->narrowCandidateRows($rows);
    }

    /**
     * Real observations for this patient (NO lineage row at all — never a
     * derived result) matching the candidate's normalized analyte, unit,
     * and collection-date window.
     *
     * @param array{procedureResultId: int, resultText: string, units: string, resultDate: ?string} $candidate
     * @return list<int>
     */
    private function realMatches(int $patientPid, array $candidate): array
    {
        if ($candidate['resultDate'] === null) {
            // D0/D6: an unknown collection date can never satisfy a window match.
            return [];
        }

        $rows = QueryUtils::fetchRecords(
            'SELECT prr.procedure_result_id AS procedure_result_id'
                . ' FROM procedure_result prr'
                . ' JOIN procedure_report pr ON prr.procedure_report_id = pr.procedure_report_id'
                . ' JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id'
                . ' LEFT JOIN ' . ExtractionLineageSchema::LINEAGE_TABLE . ' lin'
                . ' ON lin.procedure_result_id = prr.procedure_result_id'
                . ' WHERE po.patient_id = ?'
                . ' AND lin.procedure_result_id IS NULL'
                . ' AND LOWER(TRIM(prr.result_text)) = LOWER(TRIM(?))'
                . ' AND LOWER(TRIM(prr.units)) = LOWER(TRIM(?))'
                . ' AND prr.date IS NOT NULL'
                . ' AND ABS(TIMESTAMPDIFF(HOUR, ?, prr.date)) <= ' . self::COLLECTION_WINDOW_HOURS,
            [$patientPid, $candidate['resultText'], $candidate['units'], $candidate['resultDate']],
        );

        $ids = [];
        foreach ($rows as $row) {
            $resultId = $row['procedure_result_id'] ?? null;
            if (!is_numeric($resultId)) {
                throw new \RuntimeException('Supersession real-match query returned a non-numeric procedure_result_id');
            }
            $ids[] = (int) $resultId;
        }

        return $ids;
    }

    /**
     * Annotates the DERIVED result's lineage row only — never touches
     * `procedure_result`, `procedure_report`, or `procedure_order`.
     */
    private function markSuperseded(int $derivedResultId, int $realResultId): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . ExtractionLineageSchema::LINEAGE_TABLE
                . ' SET superseded_by_result_id = ?, superseded_at = NOW()'
                . ' WHERE procedure_result_id = ?',
            [$realResultId, $derivedResultId],
        );
    }

    /**
     * Annotates the DERIVED result's lineage row only — never touches
     * `procedure_result`, `procedure_report`, or `procedure_order`.
     */
    private function markAmbiguous(int $derivedResultId): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . ExtractionLineageSchema::LINEAGE_TABLE
                . ' SET ambiguous_flag = 1'
                . ' WHERE procedure_result_id = ?',
            [$derivedResultId],
        );
    }

    /**
     * Parses untrusted DB row shapes into the candidate array shape —
     * narrow, don't cast: an unexpected column type fails loudly rather
     * than being silently coerced.
     *
     * @param list<array<mixed>> $rows
     * @return list<array{procedureResultId: int, resultText: string, units: string, resultDate: ?string}>
     */
    private function narrowCandidateRows(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $procedureResultId = $row['procedure_result_id'] ?? null;
            $resultText = $row['result_text'] ?? null;
            $units = $row['units'] ?? null;
            $resultDate = $row['result_date'] ?? null;

            if (!is_numeric($procedureResultId) || !is_string($resultText) || !is_string($units)) {
                throw new \RuntimeException('Supersession candidate query returned an unexpected column type');
            }

            if ($resultDate !== null && !is_string($resultDate)) {
                throw new \RuntimeException('Supersession candidate query returned a non-string result date');
            }

            $candidates[] = [
                'procedureResultId' => (int) $procedureResultId,
                'resultText' => $resultText,
                'units' => $units,
                'resultDate' => $resultDate,
            ];
        }

        return $candidates;
    }
}

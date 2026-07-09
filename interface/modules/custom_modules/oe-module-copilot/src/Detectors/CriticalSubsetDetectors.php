<?php

/**
 * Fixed-order composition of the four must-not-miss detectors (T12; UC2;
 * R13; ARCHITECTURE.md §2 ORCH, §6).
 *
 * The critical subset — panic labs, drug-drug interactions, drug-allergy
 * conflicts, open follow-ups — is guaranteed by deterministic detectors in
 * code, never left to model salience. detectAll() runs all four against one
 * synthesized ChartSnapshot (AUDIT D9: reconciliation happens in one pass,
 * not per-source) in a fixed order so callers can rely on report position.
 * withDraftTables() wires the DRAFT clinical-content tables (PanicThresholds,
 * InteractionPairs, AllergyClassMap) pending human sign-off — see each
 * table's draftV1() docblock; do not extend or tune the underlying content
 * here.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Detectors;

use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;

final readonly class CriticalSubsetDetectors
{
    public function __construct(
        private PanicLabDetector $panicLabDetector,
        private DrugDrugInteractionDetector $drugDrugInteractionDetector,
        private DrugAllergyConflictDetector $drugAllergyConflictDetector,
        private OpenFollowUpDetector $openFollowUpDetector,
    ) {
    }

    /**
     * Runs every detector against the same snapshot in a fixed order: panic
     * labs, drug-drug interactions, drug-allergy conflicts, open follow-ups.
     *
     * @return list<DetectorReport>
     */
    public function detectAll(ChartSnapshot $chart, \DateTimeImmutable $today): array
    {
        return [
            $this->panicLabDetector->detect($chart),
            $this->drugDrugInteractionDetector->detect($chart),
            $this->drugAllergyConflictDetector->detect($chart),
            $this->openFollowUpDetector->detect($chart, $today),
        ];
    }

    /**
     * DRAFT clinical-content wiring pending human sign-off — see
     * PanicThresholds::draftV1(), InteractionPairs::draftV1(), and
     * AllergyClassMap::draftV1() for provenance and scope. Do not extend or
     * tune the underlying tables without human clinical review.
     */
    public static function withDraftTables(): self
    {
        return new self(
            new PanicLabDetector(PanicThresholds::draftV1()),
            new DrugDrugInteractionDetector(InteractionPairs::draftV1()),
            new DrugAllergyConflictDetector(AllergyClassMap::draftV1()),
            new OpenFollowUpDetector(),
        );
    }
}

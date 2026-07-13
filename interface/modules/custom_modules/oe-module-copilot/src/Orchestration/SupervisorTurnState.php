<?php

/**
 * The typed inputs `Supervisor::plan()` routes on for one turn
 * (W2_ARCHITECTURE.md §6; PS-10).
 *
 * Pure data — no live dependency, no worker invocation. The supervisor is a
 * function from this state to a plan; every flag here must be resolved by
 * the caller (chart snapshot detection, pending-document lookup, question
 * classification, critical-subset findings, physician engagement signal)
 * before construction, so `plan()` never reaches back into the chart or the
 * request itself.
 *
 * A state claiming `physicianEngagedCriticalFinding` while
 * `criticalFindingPresent` is false is a contradiction — engagement with a
 * flag that does not exist — and refuses to exist (\DomainException).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

final readonly class SupervisorTurnState
{
    /**
     * @param bool $isSnapshotTurn                   the latency-critical UC1/UC3 snapshot or pre-chart turn —
     *                                                zero-RAG, no exceptions (§5)
     * @param bool $hasPendingUnextractedDocument     a document is attached to this patient and not yet extracted
     * @param bool $questionAsksForEvidence           the physician's question asks for guideline recommendation
     *                                                support (UC7)
     * @param bool $criticalFindingPresent            the deterministic critical subset surfaced a finding this turn
     *                                                (UC6 feeding UC4)
     * @param bool $physicianEngagedCriticalFinding   the physician engaged the present critical finding this turn
     *                                                (asked about it, or opened the flag) — the one conditional
     *                                                edge's second condition
     */
    public function __construct(
        public bool $isSnapshotTurn,
        public bool $hasPendingUnextractedDocument,
        public bool $questionAsksForEvidence,
        public bool $criticalFindingPresent,
        public bool $physicianEngagedCriticalFinding,
    ) {
        if ($physicianEngagedCriticalFinding && !$criticalFindingPresent) {
            throw new \DomainException(
                'physicianEngagedCriticalFinding cannot be true when criticalFindingPresent is false — '
                . 'a state cannot claim engagement with a critical finding that is not present'
            );
        }
    }
}

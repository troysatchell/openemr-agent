<?php

/**
 * Port for the §4 no-grounding-by-proxy invariant (W2_ARCHITECTURE.md §4;
 * docs/W2_PRD_SEEDS.md PS-6).
 *
 * A derived observation (the native `procedure_result` row a Week 2
 * extraction is persisted as — the scoped write amendment's write (b)) is a
 * pointer, never evidence: it can never terminate a citation chain on its
 * own. A claim citing a `derived_observation` SourceRef is grounded only if
 * the source document that derived record points back to still exists.
 * `sourceDocumentExists()` answers exactly that question for the derived
 * record identified by `$derivedObservationId` (the SourceRef's sourceId).
 *
 * The DB-backed implementation lands with the persistence spine (TRO-20/22)
 * and resolves through the provenance link to the uploaded source document.
 * This interface is deliberately I/O-free at this layer — `ClaimVerifier`
 * stays pure — so the check is injected as a collaborator.
 *
 * MUST be fail-closed at the caller: a `ClaimVerifier` constructed without a
 * port, or a port that answers `false`, both mean a claim citing a derived
 * observation is UNGROUNDED. There is no default-to-grounded fallback — that
 * would be grounding-by-proxy, exactly the failure mode this port exists to
 * prevent.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Verification;

interface DerivedObservationGrounding
{
    /**
     * Does the source document the derived observation `$derivedObservationId`
     * points back to still exist? Answering `false` (or throwing) must be
     * treated by the caller as ungrounded — never as "unknown, assume yes."
     */
    public function sourceDocumentExists(string $derivedObservationId): bool;
}

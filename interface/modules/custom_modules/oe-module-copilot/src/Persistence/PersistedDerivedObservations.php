<?php

/**
 * The result of persisting an extraction as a derived native procedure chain
 * (W2_ARCHITECTURE.md §2 step 5, §10; PS-4; the two-write amendment, write b).
 *
 * Carries the ids of the rows DerivedObservationWriter::persist() just
 * inserted: the procedure_order and procedure_report that anchor the chain,
 * and every procedure_result row created for an analyte with a present
 * value. All three ids are positive integers and the result-id list is
 * non-empty — a writer that persisted nothing would have refused before
 * ever constructing this object (see DerivedObservationWriter).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Persistence;

final readonly class PersistedDerivedObservations
{
    /** @var list<int> */
    public array $procedureResultIds;

    /**
     * $procedureResultIds arrives untyped at this boundary (the caller
     * assembles it from a loop of insert-id results): elements are
     * validated as positive integers, never assumed from the caller's
     * declared type — the same boundary-validation idiom LabPdfExtraction
     * uses for its analyte list.
     *
     * @param list<mixed> $procedureResultIds
     */
    public function __construct(
        public int $procedureOrderId,
        public int $procedureReportId,
        array $procedureResultIds,
    ) {
        if ($procedureOrderId <= 0) {
            throw new \DomainException('PersistedDerivedObservations requires a positive procedure_order id');
        }

        if ($procedureReportId <= 0) {
            throw new \DomainException('PersistedDerivedObservations requires a positive procedure_report id');
        }

        if ($procedureResultIds === []) {
            throw new \DomainException('PersistedDerivedObservations requires at least one persisted procedure_result id');
        }

        $validatedResultIds = [];
        foreach ($procedureResultIds as $resultId) {
            if (!is_int($resultId) || $resultId <= 0) {
                throw new \DomainException('PersistedDerivedObservations procedure_result ids must all be positive integers');
            }
            $validatedResultIds[] = $resultId;
        }

        $this->procedureResultIds = $validatedResultIds;
    }
}

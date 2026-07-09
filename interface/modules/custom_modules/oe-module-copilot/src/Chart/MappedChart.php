<?php

/**
 * Data-trust-mapped chart entries from one raw FHIR bundle (T20; AUDIT
 * D0/D1/D4/D6/D9/D10).
 *
 * The output of FhirChartMapper::map(): synthesis-ready entries for the four
 * mappable sources, plus an honest count of rows that could not be mapped.
 * Demographics are deliberately NOT carried here — identity resolution needs
 * the trusted pid (D7), which only the caller (ReadThroughChartSnapshotProvider)
 * holds via its injected uuid->pid resolver, so demographics are built by a
 * separate FhirChartMapper::demographics() call rather than folded into this
 * value object.
 *
 * followUps is always [] in v1: open follow-up detection needs a source
 * (care-plan/appointment data) not yet mapped on the live path, so
 * OpenFollowUpDetector sees no live entries yet. This is a known, named gap —
 * not an oversight (see FhirChartMapper's class docblock).
 *
 * unmappableRowCount is the count of rows the mapper could not use at all
 * (missing/blank required fields, unparseable dates, non-numeric values,
 * missing ids). It is counted, never silently dropped and never silently
 * included (D1) — rows that are simply out of v1 scope by design (e.g.
 * non-laboratory Observations) do NOT increment this count.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;

final readonly class MappedChart
{
    /**
     * @param list<MedicationEntry> $medications
     * @param list<LabResultEntry> $labs
     * @param list<AllergyEntry> $allergies
     * @param list<FollowUpEntry> $followUps always [] in v1 — see class docblock
     */
    public function __construct(
        public array $medications,
        public array $labs,
        public array $allergies,
        public array $followUps,
        public int $unmappableRowCount,
    ) {
        if ($unmappableRowCount < 0) {
            throw new \DomainException('MappedChart unmappableRowCount must be >= 0 (it is a count, never a signal)');
        }
    }
}

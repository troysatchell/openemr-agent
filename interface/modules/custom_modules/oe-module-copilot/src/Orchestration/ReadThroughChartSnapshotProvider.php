<?php

/**
 * Live, delegated ChartSnapshotProvider: FHIR read-through (T20; UC1/UC2;
 * AUDIT D0/D1/D4/D6/D7/D8/D9/D10; ARCHITECTURE.md §3, §3.5, §4).
 *
 * The v1 live path: read (five FHIR sources, delegated physician) -> data-trust
 * mapping (FhirChartMapper) -> ONE synthesis pass (ChartSnapshotSynthesizer,
 * D9: interactions live between sources, never per-source summaries) ->
 * ProvidedChart. TurnOrchestrator calls provide() once per turn (§3.5) — this
 * class performs no caching of its own, so every turn re-reads the live
 * chart rather than trusting stale context.
 *
 * The trusted pid never comes from FHIR content: it is resolved by the
 * injected uuid->pid \Closure, which stands in for the DB uuid registry (D7).
 * FhirChartMapper::demographics() rejects pid <= 0 via PatientDemographics's
 * own constructor guard.
 *
 * ChartReader's own blank-uuid \DomainException and FhirReadFailedException
 * propagate untouched: a failed or malformed read is never laundered into an
 * empty chart (omission is the enemy).
 *
 * unmappableRowCount on the intermediate MappedChart is currently
 * informational only — a future ticket threads it onto the turn's trace
 * (ARCHITECTURE.md §6 observability) so a physician can see "N rows could
 * not be evaluated" without it silently vanishing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Chart\ChartReader;
use OpenEMR\Modules\Copilot\Chart\FhirChartMapper;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;

final class ReadThroughChartSnapshotProvider implements ChartSnapshotProvider
{
    /**
     * @param \Closure(string): int $pidResolver Resolves the TRUSTED pid for
     *        a patient uuid from the DB uuid registry (D7) — the FHIR content
     *        this class reads is never the pid source. Contract:
     *        function (string $patientUuid): int.
     */
    public function __construct(
        private readonly ChartReader $reader,
        private readonly FhirChartMapper $mapper,
        private readonly ChartSnapshotSynthesizer $synthesizer,
        private readonly \Closure $pidResolver,
    ) {
    }

    public function provide(PhysicianContext $physician, string $patientUuid): ProvidedChart
    {
        $bundle = $this->reader->readChart($physician, $patientUuid);
        $mapped = $this->mapper->map($bundle);

        $chart = $this->synthesizer->synthesize(
            $mapped->medications,
            $mapped->labs,
            $mapped->allergies,
            $mapped->followUps,
        );

        $pid = ($this->pidResolver)($patientUuid);

        return new ProvidedChart(
            $this->mapper->demographics($bundle, $pid, $patientUuid),
            $chart,
        );
    }
}

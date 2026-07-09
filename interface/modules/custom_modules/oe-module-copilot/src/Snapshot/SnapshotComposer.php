<?php

/**
 * Composes the glanceable pre-visit snapshot from detector output and a
 * reconciled chart (T13; UC1; ARCHITECTURE.md §1/§6; AUDIT D0/D1/D6/D9/D10).
 *
 * Pure: no I/O, no clock reads, no globals — the last-visit reference date
 * is injected by the caller. Detector findings and unevaluable items are
 * carried verbatim in report order (same object instances) — the detectors
 * own must-not-miss content; this class owns layout only. The "what changed
 * since last visit" delta is computed here because it needs a reference
 * date the detectors do not have: with no last-visit date, no delta may be
 * claimed (ChangesBasis::UnknownNoLastVisit, AUDIT D1); with a last-visit
 * date, an undated lab cannot be placed in the timeline and is surfaced as
 * unevaluable rather than silently skipped (AUDIT D0/D6).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Snapshot;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\DetectorReport;
use OpenEMR\Modules\Copilot\Detectors\UnevaluableItem;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;

final class SnapshotComposer
{
    /**
     * @param list<DetectorReport> $reports
     */
    public function compose(
        PatientDemographics $patient,
        ChartSnapshot $chart,
        array $reports,
        ?\DateTimeImmutable $lastVisit,
    ): GlanceableSnapshot {
        [$mustNotMiss, $unevaluableFromReports] = $this->concatenateReports($reports);
        [$changesBasis, $newLabs, $undatedLabItems] = $this->computeDelta($chart, $lastVisit);

        return new GlanceableSnapshot(
            patient: $patient,
            mustNotMiss: $mustNotMiss,
            unevaluable: [...$unevaluableFromReports, ...$undatedLabItems],
            unknownCurrency: $chart->unknownCurrencyEntries(),
            changesBasis: $changesBasis,
            newLabs: $newLabs,
            currentMedications: $chart->currentMedications(),
            currentAllergies: $this->currentAllergies($chart),
        );
    }

    /**
     * @param list<DetectorReport> $reports
     *
     * @return array{0: list<CriticalFinding>, 1: list<UnevaluableItem>}
     */
    private function concatenateReports(array $reports): array
    {
        $mustNotMiss = [];
        $unevaluable = [];
        foreach ($reports as $report) {
            $mustNotMiss = [...$mustNotMiss, ...$report->findings];
            $unevaluable = [...$unevaluable, ...$report->unevaluable];
        }

        return [$mustNotMiss, $unevaluable];
    }

    /**
     * @return list<AllergyEntry>
     */
    private function currentAllergies(ChartSnapshot $chart): array
    {
        return array_values(array_filter(
            $chart->allergies,
            static fn (AllergyEntry $entry): bool => $entry->status === CurrencyStatus::Current,
        ));
    }

    /**
     * Computes the "what changed since last visit" delta. With no last-visit
     * date, no delta may be claimed — every lab, dated or not, is left out
     * of newLabs and no undated-lab items are generated, because no
     * timeline was claimed in the first place (AUDIT D1). With a last-visit
     * date, a lab is "new" only when resulted strictly after that date; an
     * undated lab cannot be placed relative to it and is surfaced as its own
     * unevaluable item rather than silently dropped (AUDIT D0/D6).
     *
     * @return array{0: ChangesBasis, 1: list<LabResultEntry>, 2: list<UnevaluableItem>}
     */
    private function computeDelta(ChartSnapshot $chart, ?\DateTimeImmutable $lastVisit): array
    {
        if ($lastVisit === null) {
            return [ChangesBasis::UnknownNoLastVisit, [], []];
        }

        $newLabs = [];
        $undatedLabItems = [];
        foreach ($chart->labs as $lab) {
            if ($lab->resultedAt === null) {
                $undatedLabItems[] = new UnevaluableItem(
                    sprintf(
                        '%s result has no resulted-at date and cannot be placed relative to the last visit (D0/D6)',
                        $lab->analyte,
                    ),
                    $lab->sources,
                );
                continue;
            }
            if ($lab->resultedAt > $lastVisit) {
                $newLabs[] = $lab;
            }
        }

        return [ChangesBasis::SinceLastVisit, $newLabs, $undatedLabItems];
    }
}

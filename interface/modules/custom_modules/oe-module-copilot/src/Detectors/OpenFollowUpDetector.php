<?php

/**
 * Deterministic open follow-up detector (T10; R13, UC4; ARCHITECTURE.md §6
 * — "the thread from last time").
 *
 * Every OPEN follow-up is a finding: an open loop is must-not-miss
 * regardless of due date, and an undated open loop (AUDIT D0/D6: dates are
 * NULL, zero, or free text) is still an open loop. A loop whose due date
 * is date-only earlier than the injected $today is marked overdue; due
 * today is not overdue. Closed follow-ups are silent. Pure: no clock reads
 * — $today is injected for deterministic testing.
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

final class OpenFollowUpDetector
{
    public function detect(ChartSnapshot $snapshot, \DateTimeImmutable $today): DetectorReport
    {
        $todayDate = $today->format('Y-m-d');
        $findings = [];

        foreach ($snapshot->openFollowUps() as $followUp) {
            $due = $followUp->dueDate;
            if ($due === null) {
                $summary = sprintf('Open follow-up (no due date recorded): %s', $followUp->description);
            } elseif ($due->format('Y-m-d') < $todayDate) {
                $summary = sprintf(
                    'Open follow-up overdue since %s: %s',
                    $due->format('Y-m-d'),
                    $followUp->description,
                );
            } else {
                $summary = sprintf(
                    'Open follow-up due %s: %s',
                    $due->format('Y-m-d'),
                    $followUp->description,
                );
            }

            $findings[] = new CriticalFinding(
                CriticalFindingType::OpenFollowUp,
                $summary,
                $followUp->sources,
            );
        }

        return new DetectorReport($findings, []);
    }
}

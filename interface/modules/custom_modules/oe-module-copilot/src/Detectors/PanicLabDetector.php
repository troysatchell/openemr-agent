<?php

/**
 * Deterministic panic-lab detector (T10; R13, UC4; ARCHITECTURE.md §6).
 *
 * Panic labs are a code guarantee, never model judgment. Each lab whose
 * analyte is tracked by the threshold table is either evaluated (outside a
 * bound => finding; whether the bound value itself is panic is the entry's
 * own lowInclusive/highInclusive property, default strictly-outside) or
 * surfaced as unevaluable when the value is missing or the unit is
 * absent/mismatched (AUDIT D0/D1/D6) — never silently skipped. Untracked
 * analytes are out of this detector's contract. Pure: no I/O, no clock,
 * no globals.
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

final class PanicLabDetector
{
    public function __construct(private readonly PanicThresholds $thresholds)
    {
    }

    public function detect(ChartSnapshot $snapshot): DetectorReport
    {
        $findings = [];
        $unevaluable = [];

        foreach ($snapshot->labs as $lab) {
            $threshold = $this->thresholds->thresholdFor($lab->analyte);
            if ($threshold === null) {
                // Untracked analyte: not this detector's contract.
                continue;
            }

            if ($lab->value === null) {
                $unevaluable[] = new UnevaluableItem(
                    sprintf('Lab "%s" is tracked for panic values but carries no numeric value', $lab->analyte),
                    $lab->sources,
                );
                continue;
            }

            if ($lab->unit === null) {
                $unevaluable[] = new UnevaluableItem(
                    sprintf(
                        'Lab "%s" is tracked for panic values but carries no unit (expected %s)',
                        $lab->analyte,
                        $threshold['unit'],
                    ),
                    $lab->sources,
                );
                continue;
            }

            if (strtolower($lab->unit) !== strtolower($threshold['unit'])) {
                $unevaluable[] = new UnevaluableItem(
                    sprintf(
                        'Lab "%s" unit "%s" does not match the panic threshold unit "%s"; value cannot be evaluated',
                        $lab->analyte,
                        $lab->unit,
                        $threshold['unit'],
                    ),
                    $lab->sources,
                );
                continue;
            }

            $low = $threshold['low'];
            $lowFires = $low !== null
                && ($threshold['lowInclusive'] ? $lab->value <= $low : $lab->value < $low);
            if ($lowFires) {
                $findings[] = new CriticalFinding(
                    CriticalFindingType::PanicLab,
                    sprintf(
                        'Panic lab: %s %s %s is %s the low panic bound of %s %s',
                        $lab->analyte,
                        $lab->value,
                        $lab->unit,
                        $threshold['lowInclusive'] ? 'at or below' : 'below',
                        $low,
                        $threshold['unit'],
                    ),
                    $lab->sources,
                );
                continue;
            }

            $high = $threshold['high'];
            $highFires = $high !== null
                && ($threshold['highInclusive'] ? $lab->value >= $high : $lab->value > $high);
            if ($highFires) {
                $findings[] = new CriticalFinding(
                    CriticalFindingType::PanicLab,
                    sprintf(
                        'Panic lab: %s %s %s is %s the high panic bound of %s %s',
                        $lab->analyte,
                        $lab->value,
                        $lab->unit,
                        $threshold['highInclusive'] ? 'at or above' : 'above',
                        $high,
                        $threshold['unit'],
                    ),
                    $lab->sources,
                );
            }
        }

        return new DetectorReport($findings, $unevaluable);
    }
}

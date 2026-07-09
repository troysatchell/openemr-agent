<?php

/**
 * Human-adjudicated labels for one golden-chart case (T11; ARCHITECTURE.md §6;
 * two-track rework T15).
 *
 * mustNotMiss are the critical-subset label ids (panic labs, drug-drug,
 * drug-allergy, open follow-ups) that gate TRACK 1 (hard zero: any miss or any
 * false flag on an adjudicated case fails the build; ARCHITECTURE.md §6). keyFacts
 * are the adjudicated facts a synthesis is graded against for commission — also
 * TRACK 1 (any incorrect stated fact fails; the rate is a production monitor
 * only). judgmentItems are §3b judgment-based items (care gaps, trends) — the one
 * place a tunable precision/recall tradeoff exists (TRACK 2, provisional
 * regression thresholds). No judgment item is adjudicated yet, so this list is
 * empty in every fixture today; the field exists so the track has somewhere to
 * measure once governance supplies labels. Empty lists are legitimate (a quiet
 * chart has nothing to surface). Labels are HUMAN inputs — this value object
 * validates them but never generates or repairs them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\GoldenChart;

final readonly class GoldenChartLabels
{
    /**
     * @param list<string> $mustNotMiss
     * @param list<string> $keyFacts
     * @param list<string> $judgmentItems
     */
    public function __construct(
        public array $mustNotMiss,
        public array $keyFacts,
        public array $judgmentItems = [],
    ) {
        foreach ($mustNotMiss as $labelId) {
            if (trim($labelId) === '') {
                throw new \DomainException('Golden-chart must-not-miss label id must not be blank.');
            }
        }
        foreach ($keyFacts as $factId) {
            if (trim($factId) === '') {
                throw new \DomainException('Golden-chart key-fact label id must not be blank.');
            }
        }
        foreach ($judgmentItems as $judgmentId) {
            if (trim($judgmentId) === '') {
                throw new \DomainException('Golden-chart judgment label id must not be blank.');
            }
        }
    }
}

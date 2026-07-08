<?php

/**
 * Human-adjudicated labels for one golden-chart case (T11; ARCHITECTURE.md §6).
 *
 * mustNotMiss are the critical-subset label ids (panic labs, drug-drug,
 * drug-allergy, open follow-ups) whose omission fails the build (R13). keyFacts
 * are the adjudicated facts a synthesis is graded against for commission (R6).
 * Empty lists are legitimate (a quiet chart has nothing to surface). Labels are
 * HUMAN inputs — this value object validates them but never generates or repairs
 * them.
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
     */
    public function __construct(
        public array $mustNotMiss,
        public array $keyFacts,
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
    }
}

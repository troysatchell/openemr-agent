<?php

/**
 * One golden-chart case: a (chart-state, visit) fixture and its human labels
 * (T11; ARCHITECTURE.md §6).
 *
 * `adjudicated` is explicit and load-bearing: only adjudicated cases arm the
 * clinical-accuracy gate. Synthetic scaffolding ships as adjudicated=false so it
 * exercises the harness without ever gating the build — real labels are a Phase 0
 * human deliverable, not regenerable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\GoldenChart;

final readonly class GoldenChartCase
{
    public function __construct(
        public string $id,
        public bool $adjudicated,
        public GoldenChartLabels $labels,
    ) {
        if (trim($id) === '') {
            throw new \DomainException('Golden-chart case id must not be blank.');
        }
    }
}

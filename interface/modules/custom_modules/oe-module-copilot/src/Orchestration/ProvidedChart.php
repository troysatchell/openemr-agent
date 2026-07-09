<?php

/**
 * A freshly read patient identity paired with its one-pass synthesized chart
 * (T12; UC2; AUDIT D7/D9; ARCHITECTURE.md §2 ORCH, §3.5).
 *
 * The pairing exists so a caller can never hold a ChartSnapshot without the
 * trusted pid (D7) it belongs to — the surrogate key the disclosure audit
 * record and the LLM payload both key off of.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;

final readonly class ProvidedChart
{
    public function __construct(
        public PatientDemographics $patient,
        public ChartSnapshot $chart,
    ) {
    }
}

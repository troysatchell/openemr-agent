<?php

/**
 * Port for recording one traced orchestrator step (T17; ARCHITECTURE.md §6
 * observability; AUDIT S4/C4/C5; founder decision 5, 2026-07-09).
 *
 * Recording is per-step, not per-turn: a crash mid-turn must still leave the
 * prior steps on disk, so a partial trace is a real trace, never nothing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

interface TraceRecorder
{
    public function record(TraceContext $context, StepRecord $step): void;
}

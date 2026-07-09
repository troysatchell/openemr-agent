<?php

/**
 * No-op TraceRecorder (T17; ARCHITECTURE.md §6 observability).
 *
 * The default when no recorder is injected, so every frozen positional
 * construction of TurnOrchestrator predating T17 stays green without
 * tracing anywhere.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final class NullTraceRecorder implements TraceRecorder
{
    public function record(TraceContext $context, StepRecord $step): void
    {
    }
}

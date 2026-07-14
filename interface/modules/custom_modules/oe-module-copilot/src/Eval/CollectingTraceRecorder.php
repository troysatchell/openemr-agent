<?php

/**
 * A collecting {@see TraceRecorder} for the eval gate (TRO-35;
 * W2_ARCHITECTURE.md §6, §7).
 *
 * Every {@see StepRecord} written during one case's turn is retained, in
 * record order, so {@see GoldenSetRunner} can (a) assert `trace_step_names`
 * exactly, (b) count retrieval steps, and (c) render the trace surface text
 * {@see PhiPatternDetector} scans for the `no_phi_in_logs` rubric. This is a
 * genuine (non-mock) `TraceRecorder` implementation — the gate never plants
 * worker- or port-level test doubles (§6) — it simply keeps what it is
 * given instead of writing it to a sink.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceRecorder;

final class CollectingTraceRecorder implements TraceRecorder
{
    /** @var list<StepRecord> */
    private array $steps = [];

    public function record(TraceContext $context, StepRecord $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * @return list<StepRecord>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<string>
     */
    public function stepNames(): array
    {
        return array_map(static fn (StepRecord $step): string => $step->step, $this->steps);
    }

    /**
     * A PHI-free rendering of the collected steps — step names, outcomes,
     * and error classes only, mirroring what the trace's own schema carries
     * (never claim text, never chart values). This is the text
     * {@see PhiPatternDetector} scans for the `no_phi_in_logs` rubric.
     */
    public function renderSurface(): string
    {
        $lines = [];
        foreach ($this->steps as $step) {
            $lines[] = sprintf(
                '%s outcome=%s error=%s grounded=%s rejected=%s',
                $step->step,
                $step->outcome->value,
                $step->errorClass ?? 'none',
                $step->groundedCount === null ? 'n/a' : (string) $step->groundedCount,
                $step->rejectedCount === null ? 'n/a' : (string) $step->rejectedCount,
            );
        }

        return implode("\n", $lines);
    }
}

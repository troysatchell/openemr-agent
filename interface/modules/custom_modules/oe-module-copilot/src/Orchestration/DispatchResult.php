<?php

/**
 * Typed result of one supervised-plan dispatch (TRO-32; W2_ARCHITECTURE §6;
 * PS-10).
 *
 * `plan` is the ordered `SupervisorStep` list `Supervisor::plan()` returned
 * for this turn — each step already carries the stated reason it was routed
 * (`SupervisorStep::$reason`), so the plan on this result plus the handoff
 * `StepRecord`s the dispatcher writes to the trace TOGETHER reconstruct the
 * full route: which workers ran, why, and what they returned. Neither half
 * carries that information alone.
 *
 * `intake` / `evidence` are the worker outcomes when their corresponding
 * plan step ran, `null` when it did not (e.g. the zero-RAG snapshot path
 * plans only `ComposeAnswer`, so both are null).
 *
 * `plan` is boundary-validated the same way `RetrievalOutcome::$chunks` is:
 * a `list<mixed>` from the caller's perspective, refused at construction
 * unless every element is actually a `SupervisorStep` — a caller cannot
 * smuggle an untyped or partial step through this DTO.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Rag\RetrievalOutcome;

final readonly class DispatchResult
{
    /**
     * @param list<mixed> $plan the ordered plan the supervisor returned for this turn
     */
    public function __construct(
        public array $plan,
        public ?IntakeExtractionOutcome $intake,
        public ?RetrievalOutcome $evidence,
    ) {
        foreach ($this->plan as $step) {
            if (!$step instanceof SupervisorStep) {
                throw new \DomainException('DispatchResult plan must contain only SupervisorStep instances');
            }
        }
    }
}

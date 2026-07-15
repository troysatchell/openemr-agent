<?php

/**
 * One traced orchestrator step (T17; ARCHITECTURE.md §6 observability;
 * AUDIT S4/C4/C5; founder decision 5, 2026-07-09).
 *
 * A failed tool call must trace, not vanish: this value object refuses to
 * exist in a self-contradictory state. An Ok outcome with an error class is a
 * contradiction; a Failed outcome without one hides what failed — both are
 * refused at construction (\DomainException). Only the error CLASS is ever
 * recorded here, never the exception message or internals — the trace is
 * PHI-free and internals-free by construction, not by discipline.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final readonly class StepRecord
{
    /**
     * groundedCount / rejectedCount (T19) carry the ClaimVerifier verdict on
     * the `ground` step only — counts, never claim content (the trace stays
     * PHI-free by schema). Null on every other step.
     *
     * vendorUnits (TRO-46) carries non-token vendor pricing (Cohere embed /
     * rerank units, ...) on the steps that incur it — {@see VendorUnits}
     * generalizes {@see TokenUsage} to those vendor models. Null on every
     * step that carries no such vendor cost.
     */
    public function __construct(
        public string $step,
        public \DateTimeImmutable $startedAt,
        public float $durationMs,
        public StepOutcome $outcome,
        public ?string $errorClass = null,
        public ?TokenUsage $tokenUsage = null,
        public ?int $groundedCount = null,
        public ?int $rejectedCount = null,
        public ?VendorUnits $vendorUnits = null,
    ) {
        if (trim($step) === '') {
            throw new \DomainException('StepRecord requires a non-blank step name');
        }

        if ($durationMs < 0.0) {
            throw new \DomainException('StepRecord durationMs must be >= 0');
        }

        if (($groundedCount !== null && $groundedCount < 0) || ($rejectedCount !== null && $rejectedCount < 0)) {
            throw new \DomainException('StepRecord grounded/rejected counts must be >= 0 when present');
        }

        if ($outcome === StepOutcome::Ok && $errorClass !== null) {
            throw new \DomainException('An ok step must not carry an errorClass — that is a contradiction');
        }

        if ($outcome === StepOutcome::Failed && $errorClass === null) {
            throw new \DomainException('A failed step must carry an errorClass — silence would hide what failed');
        }
    }
}

<?php

/**
 * The outcome of one grounded conversational turn (T12; UC2; R11;
 * ARCHITECTURE.md §2 ORCH, §3.5).
 *
 * The deterministic critical subset (mustNotMiss, unevaluable) bypasses the
 * model entirely and survives whatever the model says — including model
 * failure. The model-derived answer and the degraded state are mutually
 * exclusive and jointly exhaustive, enforced at construction
 * (\DomainException on violation): a degraded turn has no answer and a
 * non-blank reason; a non-degraded turn has an answer and no reason. There
 * is no third state and no silent partial result — a model failure never
 * looks like a quiet, correct answer (R11).
 *
 * The optional trailing correlationId (T17) lets the caller echo the turn's
 * trace correlation ID back for support/audit lookups (ARCHITECTURE.md §6
 * observability).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\UnevaluableItem;
use OpenEMR\Modules\Copilot\Verification\VerifiedAnswer;

final readonly class TurnResult
{
    /**
     * @param list<CriticalFinding> $mustNotMiss deterministic critical-subset findings; survive model failure
     * @param list<UnevaluableItem> $unevaluable deterministic critical-subset items the detectors could not evaluate
     */
    public function __construct(
        public array $mustNotMiss,
        public array $unevaluable,
        public ?VerifiedAnswer $answer,
        public bool $degraded,
        public ?string $degradedReason,
        public ?Disclosure $disclosure,
        public ?string $correlationId = null,
    ) {
        if ($degraded) {
            if ($answer !== null) {
                throw new \DomainException(
                    'A degraded turn must not carry an answer — a model failure must never look like a quiet, correct answer (R11)'
                );
            }
            if ($degradedReason === null || trim($degradedReason) === '') {
                throw new \DomainException(
                    'A degraded turn requires a non-blank degradedReason — silence is not an honest degradation (R11)'
                );
            }

            return;
        }

        if ($answer === null) {
            throw new \DomainException(
                'A non-degraded turn requires an answer — there is no third state between answered and degraded (R11)'
            );
        }
        if ($degradedReason !== null) {
            throw new \DomainException(
                'A non-degraded turn must not carry a degradedReason'
            );
        }
    }
}

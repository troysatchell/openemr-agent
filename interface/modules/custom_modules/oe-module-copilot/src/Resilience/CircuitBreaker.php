<?php

/**
 * A clock-driven, per-dependency circuit breaker (TRO-47; W2_ARCHITECTURE.md
 * §8 "Resilience": "every outbound LLM/VLM/embed/rerank call has a timeout
 * and bounded retry; repeated failures trip a per-dependency circuit breaker
 * that degrades the turn honestly (Week 1's R11 posture) instead of hanging
 * it."; PS-12 asymmetric-degradation family).
 *
 * Three states, computed deterministically from an injected PSR-20
 * `ClockInterface` — never wall time, so tests never sleep:
 *
 *  - **closed**: calls are allowed; consecutive failures are counted.
 *    Reaching `$failureThreshold` opens the breaker.
 *  - **open**: calls are refused (`allows()` false) until `$cooldownSeconds`
 *    have elapsed since the breaker opened.
 *  - **half_open**: the cooldown has elapsed; exactly one probe is
 *    conceptually admitted (`allows()` true, `state()` reports `half_open`
 *    for as long as no outcome has been recorded). `recordSuccess()` closes
 *    and resets the failure count; `recordFailure()` re-opens with a FRESH
 *    cooldown timed from that failure, not the original one.
 *
 * A dependency vendor call is the caller's concern, not this class's: this
 * is pure state-machine logic over an injected clock, unit-tested in
 * isolation (`CircuitBreakerContractTest`) and composed into the vendor
 * clients (`CohereRerankClient`, `CohereEmbedClient`, `AnthropicLlmClient`)
 * as an optional constructor collaborator — an open breaker fails the call
 * immediately with the client's own typed unavailability exception, WITHOUT
 * invoking the transport, so the turn degrades honestly (R11) instead of
 * hanging on a vendor that is already known to be down.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Resilience;

use Psr\Clock\ClockInterface;

final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;

    private int $consecutiveFailures = 0;

    private ?\DateTimeImmutable $openedAt = null;

    public function __construct(
        private readonly string $dependency,
        private readonly int $failureThreshold,
        private readonly int $cooldownSeconds,
        private readonly ClockInterface $clock,
    ) {
        if (trim($this->dependency) === '') {
            throw new \DomainException('CircuitBreaker dependency name must be non-blank');
        }

        if ($this->failureThreshold < 1) {
            throw new \DomainException('CircuitBreaker failureThreshold must be >= 1');
        }

        if ($this->cooldownSeconds < 0) {
            throw new \DomainException('CircuitBreaker cooldownSeconds must be >= 0');
        }
    }

    /** The dependency name this breaker guards — used to name it in an unavailability message. */
    public function dependency(): string
    {
        return $this->dependency;
    }

    /** True unless the breaker is currently open (a cooling-down `open` state that has not yet reached `half_open`). */
    public function allows(): bool
    {
        return $this->effectiveState() !== self::STATE_OPEN;
    }

    /** 'closed' | 'open' | 'half_open' — the tri-state read purely from the injected clock, never wall time. */
    public function state(): string
    {
        return $this->effectiveState();
    }

    /**
     * Records a failed call. In `closed`, counts toward `$failureThreshold`
     * and opens the breaker on reaching it. In `half_open` (a cooling-down
     * `open` breaker whose cooldown just elapsed), the probe failed — the
     * breaker re-opens immediately with a fresh cooldown timed from now,
     * regardless of `$failureThreshold` (one bad probe is enough to keep it
     * open). A failure recorded while genuinely still `open` is a no-op on
     * the timer — it does not extend an already-running cooldown.
     */
    public function recordFailure(): void
    {
        $effective = $this->effectiveState();

        if ($effective === self::STATE_CLOSED) {
            $this->consecutiveFailures++;
            if ($this->consecutiveFailures >= $this->failureThreshold) {
                $this->open();
            }

            return;
        }

        if ($effective === self::STATE_HALF_OPEN) {
            $this->open();
        }
    }

    /** Records a successful call: closes the breaker (from `closed` or a `half_open` probe) and resets the failure count. */
    public function recordSuccess(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->consecutiveFailures = 0;
        $this->openedAt = null;
    }

    private function open(): void
    {
        $this->state = self::STATE_OPEN;
        $this->openedAt = $this->clock->now();
    }

    /**
     * The internal `$state` field only ever holds 'closed' or 'open' —
     * 'half_open' is never stored, only derived: an 'open' breaker whose
     * cooldown has elapsed reports 'half_open' until the next recorded
     * outcome resolves it one way or the other.
     */
    private function effectiveState(): string
    {
        if ($this->state !== self::STATE_OPEN || $this->openedAt === null) {
            return $this->state;
        }

        $elapsedSeconds = $this->clock->now()->getTimestamp() - $this->openedAt->getTimestamp();
        if ($elapsedSeconds >= $this->cooldownSeconds) {
            return self::STATE_HALF_OPEN;
        }

        return self::STATE_OPEN;
    }
}

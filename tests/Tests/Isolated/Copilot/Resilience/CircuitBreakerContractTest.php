<?php

/**
 * FROZEN acceptance tests — TRO-47: circuit breakers, bounded retry, and
 * per-dependency degraded readiness.
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract:
 *  - A clock-driven CircuitBreaker (closed → open after N consecutive
 *    failures; open short-circuits; half-open after the cooldown admits one
 *    probe; probe success closes and resets, probe failure re-opens).
 *    Deterministic via injected PSR-20 ClockInterface — never wall time.
 *  - Cohere clients accept an optional breaker: an OPEN breaker fails the
 *    call with the client's own unavailability exception WITHOUT invoking
 *    the transport (Week 1 R11 posture: degrade honestly, never hang);
 *    transport failures feed the breaker.
 *  - Bounded retry: one retry on transport failure (two attempts total),
 *    then the typed unavailability exception; a retry that succeeds is a
 *    success (breaker stays closed).
 *  - /ready is tri-state per dependency: a probe may return true ('ok'),
 *    false ('failed'), or the literal string 'degraded'; degraded names
 *    itself in the report WITHOUT failing readiness, failed still fails it.
 *  - SLOs are committed as-built in docs/SLOS.md: named p95 targets and
 *    alarm conditions, every number labeled MEASURED or PENDING
 *    MEASUREMENT — invented numbers are worse than none.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Resilience;

use OpenEMR\Modules\Copilot\Observability\ReadinessCheck;
use OpenEMR\Modules\Copilot\Rag\CohereRerankClient;
use OpenEMR\Modules\Copilot\Rag\RerankUnavailableException;
use OpenEMR\Modules\Copilot\Resilience\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class CircuitBreakerContractTest extends TestCase
{
    private const SLOS_PATH = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/docs/SLOS.md';

    public function testBreakerOpensAfterConsecutiveFailuresAndRecoversViaHalfOpenProbe(): void
    {
        $clock = new SteppingClock('2026-07-15T12:00:00+00:00');
        $breaker = new CircuitBreaker('cohere-rerank', 3, 60, $clock);

        $this->assertSame('closed', $breaker->state());
        $this->assertTrue($breaker->allows());

        $breaker->recordFailure();
        $breaker->recordFailure();
        $this->assertSame('closed', $breaker->state(), 'below the threshold the breaker stays closed');

        $breaker->recordFailure();
        $this->assertSame('open', $breaker->state(), 'the third consecutive failure trips it');
        $this->assertFalse($breaker->allows(), 'open short-circuits');

        $clock->advanceSeconds(59);
        $this->assertFalse($breaker->allows(), 'still cooling down');

        $clock->advanceSeconds(2);
        $this->assertTrue($breaker->allows(), 'cooldown elapsed — one probe is admitted');
        $this->assertSame('half_open', $breaker->state());

        $breaker->recordSuccess();
        $this->assertSame('closed', $breaker->state(), 'probe success closes and resets');
        $this->assertTrue($breaker->allows());
    }

    public function testHalfOpenProbeFailureReopensWithAFreshCooldown(): void
    {
        $clock = new SteppingClock('2026-07-15T12:00:00+00:00');
        $breaker = new CircuitBreaker('cohere-embed', 1, 30, $clock);

        $breaker->recordFailure();
        $this->assertSame('open', $breaker->state());

        $clock->advanceSeconds(31);
        $this->assertTrue($breaker->allows());
        $breaker->recordFailure();

        $this->assertSame('open', $breaker->state(), 'probe failure re-opens');
        $this->assertFalse($breaker->allows());
        $clock->advanceSeconds(29);
        $this->assertFalse($breaker->allows(), 'the cooldown restarted at the probe failure');
    }

    public function testSuccessResetsTheConsecutiveFailureCount(): void
    {
        $clock = new SteppingClock('2026-07-15T12:00:00+00:00');
        $breaker = new CircuitBreaker('llm', 2, 60, $clock);

        $breaker->recordFailure();
        $breaker->recordSuccess();
        $breaker->recordFailure();

        $this->assertSame('closed', $breaker->state(), 'failures must be consecutive to trip the breaker');
    }

    public function testBreakerRefusesNonsense(): void
    {
        $clock = new SteppingClock('2026-07-15T12:00:00+00:00');

        $this->expectException(\DomainException::class);
        new CircuitBreaker(' ', 0, -1, $clock);
    }

    public function testAnOpenBreakerShortCircuitsTheRerankCallWithoutTouchingTheVendor(): void
    {
        $clock = new SteppingClock('2026-07-15T12:00:00+00:00');
        $breaker = new CircuitBreaker('cohere-rerank', 1, 60, $clock);
        $breaker->recordFailure();
        $this->assertSame('open', $breaker->state());

        $calls = 0;
        $transport = static function (array $requestBody) use (&$calls): array {
            $calls++;

            return [200, ['results' => []]];
        };

        $client = new CohereRerankClient($transport, 'rerank-english-v3.0', null, $breaker);

        try {
            $client->rerank('q', ['d1'], 1);
            $this->fail('an open breaker must fail the call');
        } catch (RerankUnavailableException) {
            // expected — the turn degrades honestly (R11), it never hangs
        }

        $this->assertSame(0, $calls, 'the vendor is never invoked while the breaker is open');
    }

    public function testTransportFailuresFeedTheBreakerAndRetryIsBounded(): void
    {
        $clock = new SteppingClock('2026-07-15T12:00:00+00:00');
        $breaker = new CircuitBreaker('cohere-rerank', 2, 60, $clock);

        $calls = 0;
        $failingTransport = static function (array $requestBody) use (&$calls): array {
            $calls++;
            throw new \RuntimeException('vendor unreachable');
        };

        $client = new CohereRerankClient($failingTransport, 'rerank-english-v3.0', null, $breaker);

        try {
            $client->rerank('q', ['d1'], 1);
            $this->fail('a failing transport must surface the typed unavailability');
        } catch (RerankUnavailableException) {
            // expected
        }

        $this->assertSame(2, $calls, 'bounded retry: exactly two attempts, never an unbounded loop');
        $this->assertSame('open', $breaker->state(), 'the exhausted call counts as failure(s) reaching the threshold');
    }

    public function testARetryThatSucceedsIsASuccess(): void
    {
        $calls = 0;
        $flakyTransport = static function (array $requestBody) use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('transient blip');
            }

            return [200, ['results' => [['index' => 0, 'relevance_score' => 0.9]]]];
        };

        $clock = new SteppingClock('2026-07-15T12:00:00+00:00');
        $breaker = new CircuitBreaker('cohere-rerank', 1, 60, $clock);
        $client = new CohereRerankClient($flakyTransport, 'rerank-english-v3.0', null, $breaker);

        $result = $client->rerank('q', ['d1'], 1);

        $this->assertNotSame([], $result, 'the retried call returns the real result');
        $this->assertSame(2, $calls);
        $this->assertSame('closed', $breaker->state(), 'a recovered call never trips the breaker');
    }

    public function testReadinessIsTriStatePerDependency(): void
    {
        $check = new ReadinessCheck([
            'db' => static fn (): bool => true,
            'reranker' => static fn (): string => 'degraded',
            'vector-index' => static fn (): bool => false,
        ]);

        $report = $check->run();

        $this->assertFalse($report->ready, 'a failed dependency still fails readiness');
        $this->assertSame('ok', $report->statuses['db'] ?? null);
        $this->assertSame('degraded', $report->statuses['reranker'] ?? null, 'degraded names itself instead of hiding behind a boolean');
        $this->assertSame('failed', $report->statuses['vector-index'] ?? null);
    }

    public function testDegradedAloneDoesNotFailReadiness(): void
    {
        $check = new ReadinessCheck([
            'db' => static fn (): bool => true,
            'reranker' => static fn (): string => 'degraded',
        ]);

        $report = $check->run();

        $this->assertTrue($report->ready, 'degraded is a warning with a name, not an outage');
        $this->assertSame('degraded', $report->statuses['reranker'] ?? null);
    }

    public function testSlosAreCommittedAsBuiltWithHonestLabels(): void
    {
        $this->assertFileExists(self::SLOS_PATH, 'SLOs are a committed artifact');
        $raw = file_get_contents(self::SLOS_PATH);
        $this->assertIsString($raw);

        $this->assertStringContainsString('p95', $raw);
        $this->assertStringContainsStringIgnoringCase('document-ingestion', $raw);
        $this->assertStringContainsStringIgnoringCase('evidence-retrieval', $raw);
        $this->assertStringContainsStringIgnoringCase('extraction failure rate', $raw);
        $this->assertStringContainsStringIgnoringCase('eval regression', $raw);
        $this->assertStringContainsString('5%', $raw);

        // Per-section, not global (COPILOT-TEST-001): every target/alarm
        // section must carry its own label — an intro mention can never
        // carry a section that lost its own honesty marker.
        $sections = preg_split('/^### /m', $raw);
        $this->assertIsArray($sections);
        $labeledSections = array_slice($sections, 1);
        $this->assertNotSame([], $labeledSections, 'SLO targets and alarms are organized as ### sections');
        foreach ($labeledSections as $section) {
            $hasLabel = str_contains($section, 'MEASURED') || str_contains($section, 'PENDING MEASUREMENT');
            $heading = strtok($section, "\n");
            $this->assertTrue($hasLabel, "section '{$heading}' must label its numbers MEASURED or PENDING MEASUREMENT — invented numbers are worse than none");
        }
    }
}

final class SteppingClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $start)
    {
        $this->now = new \DateTimeImmutable($start);
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

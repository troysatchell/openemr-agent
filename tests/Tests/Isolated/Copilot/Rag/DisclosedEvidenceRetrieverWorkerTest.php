<?php

/**
 * FROZEN acceptance tests — Wave K.3: the Cohere crossing is a disclosed
 * crossing (C1/C5; CLAUDE.md bright line "never send PHI without the
 * disclosure being logged").
 *
 * Authored by the orchestrator and frozen. Contract under test:
 * Rag\DisclosedEvidenceRetrieverWorker decorates the EvidenceRetrieverWorker
 * port so the physician's QUESTION TEXT — free text that can carry patient
 * identifiers — never crosses to the embed/rerank vendor without a logged
 * disclosure. Gap found 2026-07-14: nothing in src/Rag logged the query
 * crossing; the Week 1 rule was applied to the Anthropic payload and the
 * Week 2 VLM crossing but not here.
 *
 * Semantics mirror the VLM crossing exactly: LOG THEN SEND — a crash
 * mid-retrieval must leave a logged crossing, never an unlogged one; the
 * disclosure carries the physician, the patient pid, an evidence-query data
 * class, and the turn's correlation id (the only join key, S4); the inner
 * worker's outcome passes through untouched.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Rag;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Orchestration\EvidenceRetrieverWorker;
use OpenEMR\Modules\Copilot\Rag\DisclosedEvidenceRetrieverWorker;
use OpenEMR\Modules\Copilot\Rag\RetrievalOutcome;
use OpenEMR\Modules\Copilot\Rag\RetrievedChunk;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Frozen-test support: ordered spy for the log-then-send sequence.
 */
final class QueryDisclosureSpy implements DisclosureLogger
{
    public ?Disclosure $last = null;

    /** @var list<string> */
    public array $sequence = [];

    public function record(Disclosure $disclosure): void
    {
        $this->last = $disclosure;
        $this->sequence[] = 'disclosure-logged';
    }
}

/**
 * Frozen-test support: scripted inner worker recording call order.
 */
final class SequencedInnerWorker implements EvidenceRetrieverWorker
{
    public ?string $questionSeen = null;

    public function __construct(
        private readonly QueryDisclosureSpy $spy,
        private readonly ?RetrievalOutcome $outcome,
    ) {
    }

    public function run(string $question, int $topK, TraceContext $workerSpan): RetrievalOutcome
    {
        $this->spy->sequence[] = 'vendor-called';
        $this->questionSeen = $question;

        if ($this->outcome === null) {
            throw new \RuntimeException('retrieval blew up mid-send');
        }

        return $this->outcome;
    }
}

class DisclosedEvidenceRetrieverWorkerTest extends TestCase
{
    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-07-14 12:00:00', new \DateTimeZone('UTC'));
            }
        };
    }

    private function outcome(): RetrievalOutcome
    {
        return new RetrievalOutcome(
            [new RetrievedChunk('htn.bp-target', 'protocol-htn-v1', 'Blood-pressure target', 'body', 0.9)],
            false,
            false,
        );
    }

    public function testCrossingIsDisclosedBeforeTheVendorIsCalled(): void
    {
        $spy = new QueryDisclosureSpy();
        $inner = new SequencedInnerWorker($spy, $this->outcome());
        $span = TraceContext::start('turn', new \DateTimeImmutable('2026-07-14 12:00:00'));

        $worker = new DisclosedEvidenceRetrieverWorker(
            $inner,
            $spy,
            new PhysicianContext('ellis.tran', 7),
            42,
            $this->fixedClock(),
        );
        $result = $worker->run('Is Alma Reyes due for a statin per our protocol?', 5, $span);

        $this->assertSame(['disclosure-logged', 'vendor-called'], $spy->sequence, 'log THEN send (C1)');
        $this->assertSame($this->outcome()->chunks[0]->chunkId, $result->chunks[0]->chunkId, 'the outcome passes through untouched');
        $this->assertSame('Is Alma Reyes due for a statin per our protocol?', $inner->questionSeen, 'the question passes through untouched');

        $disclosure = $spy->last;
        $this->assertNotNull($disclosure);
        $this->assertSame('ellis.tran', $disclosure->userId);
        $this->assertSame(42, $disclosure->patientPid);
        $this->assertContains('evidence-query', $disclosure->dataClasses, 'the crossing names its data class');
        $this->assertSame($span->correlationId, $disclosure->correlationId, 'the correlation id is the only join key (S4)');
        $this->assertStringNotContainsString(
            'Alma',
            $disclosure->purpose,
            'the disclosure purpose never embeds the question text itself — the log records THAT a query crossed, not the query',
        );
    }

    public function testAFailedRetrievalStillLeavesALoggedCrossing(): void
    {
        $spy = new QueryDisclosureSpy();
        $inner = new SequencedInnerWorker($spy, null);
        $span = TraceContext::start('turn', new \DateTimeImmutable('2026-07-14 12:00:00'));

        $worker = new DisclosedEvidenceRetrieverWorker(
            $inner,
            $spy,
            new PhysicianContext('ellis.tran', 7),
            42,
            $this->fixedClock(),
        );

        try {
            $worker->run('any question', 5, $span);
            $this->fail('the inner failure must propagate');
        } catch (\RuntimeException) {
            // expected — the decorator never swallows.
        }

        $this->assertSame(['disclosure-logged', 'vendor-called'], $spy->sequence, 'a crash mid-send leaves a logged crossing, never an unlogged one');
    }

    public function testLiveCompositionUsesTheDisclosedWorker(): void
    {
        $bootstrap = file_get_contents(
            __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot/src/Bootstrap.php',
        );
        $this->assertIsString($bootstrap);
        $this->assertStringContainsString(
            'DisclosedEvidenceRetrieverWorker',
            $bootstrap,
            'the live evidence path wraps the real worker in the disclosure decorator',
        );
    }
}

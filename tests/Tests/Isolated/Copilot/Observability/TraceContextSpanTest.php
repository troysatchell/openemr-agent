<?php

/**
 * FROZEN acceptance tests — TRO-33: TraceContext parent/child spans (W2_ARCHITECTURE §6, §8).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: TraceContext grows span support — every context carries
 * a non-blank spanId (auto-minted when not supplied) and a nullable
 * parentSpanId (null = root/turn span). child() derives a worker/sub-call span:
 * SAME correlationId (Week 1's explicit-carry rule — the correlation ID threads
 * through every span, never ambient state, S4), fresh unique spanId, and
 * parentSpanId = the parent's spanId. Existing three-argument construction and
 * start() keep working as root spans — same schema family, no parallel
 * convention.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Observability;

use OpenEMR\Modules\Copilot\Observability\TraceContext;
use PHPUnit\Framework\TestCase;

class TraceContextSpanTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-13 12:00:00');
    }

    public function testThreeArgumentConstructionRemainsValidAsRootSpan(): void
    {
        $context = new TraceContext('corr-1', 'snapshot', $this->now());

        $this->assertSame('corr-1', $context->correlationId);
        $this->assertSame('snapshot', $context->turnKind);
        $this->assertNotSame('', trim($context->spanId));
        $this->assertNull($context->parentSpanId);
    }

    public function testStartMintsARootSpan(): void
    {
        $context = TraceContext::start('question', $this->now());

        $this->assertNotSame('', trim($context->spanId));
        $this->assertNull($context->parentSpanId);
    }

    public function testChildCarriesCorrelationIdExplicitly(): void
    {
        $parent = TraceContext::start('question', $this->now());
        $child = $parent->child('intake-extractor', $this->now());

        $this->assertSame($parent->correlationId, $child->correlationId);
    }

    public function testChildLinksToParentSpanAndMintsFreshSpanId(): void
    {
        $parent = TraceContext::start('question', $this->now());
        $child = $parent->child('intake-extractor', $this->now());

        $this->assertSame($parent->spanId, $child->parentSpanId);
        $this->assertNotSame($parent->spanId, $child->spanId);
        $this->assertSame('intake-extractor', $child->turnKind);
    }

    public function testGrandchildChainsThroughItsOwnParent(): void
    {
        $root = TraceContext::start('question', $this->now());
        $worker = $root->child('evidence-retriever', $this->now());
        $subCall = $worker->child('rerank', $this->now());

        $this->assertSame($root->correlationId, $subCall->correlationId);
        $this->assertSame($worker->spanId, $subCall->parentSpanId);
        $this->assertNotSame($root->spanId, $subCall->spanId);
        $this->assertNotSame($worker->spanId, $subCall->spanId);
    }

    public function testSiblingChildrenGetDistinctSpanIds(): void
    {
        $root = TraceContext::start('question', $this->now());
        $a = $root->child('intake-extractor', $this->now());
        $b = $root->child('evidence-retriever', $this->now());

        $this->assertNotSame($a->spanId, $b->spanId);
        $this->assertSame($a->parentSpanId, $b->parentSpanId);
    }

    public function testBlankChildTurnKindIsRejected(): void
    {
        $root = TraceContext::start('question', $this->now());

        $this->expectException(\DomainException::class);
        $root->child('   ', $this->now());
    }

    public function testParentIsUnchangedByDerivingAChild(): void
    {
        $root = TraceContext::start('question', $this->now());
        $spanIdBefore = $root->spanId;
        $root->child('intake-extractor', $this->now());

        $this->assertSame($spanIdBefore, $root->spanId);
        $this->assertNull($root->parentSpanId);
    }
}

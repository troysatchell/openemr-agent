<?php

/**
 * FROZEN acceptance tests — TRO-23: no grounding-by-proxy (W2_ARCHITECTURE §4; PS-6).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: a derived observation is a pointer, never evidence —
 * it can never TERMINATE a citation chain. A claim citing a
 * `derived_observation` SourceRef is grounded only if the grounding port
 * confirms its source document still exists; the document gone means the
 * claim is UNGROUNDED — fail closed. A verifier constructed without the port
 * also fails closed on derived refs. Non-derived refs keep Week 1 behavior
 * exactly and never consult the port. All-or-nothing grounding is preserved:
 * one proxy-broken citation rejects the whole claim.
 *
 * ReferenceIndex::fromRefs is the §4 one-mint entry point: extraction facts
 * and guideline chunks enter the same index the tokens are minted from.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Verification;

use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\Modules\Copilot\Verification\DerivedObservationGrounding;
use OpenEMR\Modules\Copilot\Verification\DraftClaim;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;
use PHPUnit\Framework\TestCase;

class NoGroundingByProxyTest extends TestCase
{
    private function derivedRef(): SourceRef
    {
        return new SourceRef('derived_observation', 'pr-2', null, null, '6.8 mmol/L');
    }

    private function chartRef(): SourceRef
    {
        return new SourceRef('lab_result', 'lr-77');
    }

    private function index(): ReferenceIndex
    {
        return ReferenceIndex::fromRefs([$this->derivedRef(), $this->chartRef()]);
    }

    private function claimCiting(SourceRef ...$refs): DraftClaim
    {
        $tokens = array_map(static fn (SourceRef $ref): string => ReferenceIndex::tokenFor($ref), $refs);

        return new DraftClaim('Potassium is critically elevated.', array_values($tokens));
    }

    public function testDerivedRefWithLivingSourceDocumentGrounds(): void
    {
        $port = new StubDerivedObservationGrounding(true);
        $verifier = new ClaimVerifier($port);

        $answer = $verifier->verify([$this->claimCiting($this->derivedRef())], $this->index());

        $this->assertCount(1, $answer->grounded);
        $this->assertCount(0, $answer->rejected);
        $this->assertSame(['pr-2'], $port->consulted);
    }

    public function testSourceDocumentGoneMeansUngroundedFailClosed(): void
    {
        $verifier = new ClaimVerifier(new StubDerivedObservationGrounding(false));

        $answer = $verifier->verify([$this->claimCiting($this->derivedRef())], $this->index());

        $this->assertCount(0, $answer->grounded);
        $this->assertCount(1, $answer->rejected);
    }

    public function testNoPortWiredMeansDerivedRefsFailClosed(): void
    {
        $verifier = new ClaimVerifier();

        $answer = $verifier->verify([$this->claimCiting($this->derivedRef())], $this->index());

        $this->assertCount(0, $answer->grounded);
        $this->assertCount(1, $answer->rejected);
    }

    public function testNonDerivedRefsNeverConsultThePort(): void
    {
        $port = new StubDerivedObservationGrounding(true);
        $verifier = new ClaimVerifier($port);

        $answer = $verifier->verify([$this->claimCiting($this->chartRef())], $this->index());

        $this->assertCount(1, $answer->grounded);
        $this->assertSame([], $port->consulted, 'chart refs keep Week 1 behavior; the port is for derived refs only');
    }

    public function testOneProxyBrokenCitationRejectsTheWholeClaim(): void
    {
        $verifier = new ClaimVerifier(new StubDerivedObservationGrounding(false));

        $answer = $verifier->verify(
            [$this->claimCiting($this->chartRef(), $this->derivedRef())],
            $this->index(),
        );

        $this->assertCount(0, $answer->grounded);
        $this->assertCount(1, $answer->rejected, 'all-or-nothing grounding survives the write amendment');
    }

    public function testFromRefsMintsAndResolvesAcrossSourceClasses(): void
    {
        $guideline = new SourceRef('guideline', 'protocol-htn-v1', null, 'htn.bp-target', 'target <130/80');
        $index = ReferenceIndex::fromRefs([$this->chartRef(), $guideline]);

        $this->assertSame($guideline, $index->resolve('guideline:protocol-htn-v1#htn.bp-target'));
        $this->assertSame('lab_result:lr-77', ReferenceIndex::tokenFor($this->chartRef()));
        $this->assertNotNull($index->resolve('lab_result:lr-77'));
    }
}

/**
 * Frozen-test support: stub grounding port recording what it was asked.
 */
final class StubDerivedObservationGrounding implements DerivedObservationGrounding
{
    /** @var list<string> */
    public array $consulted = [];

    public function __construct(private readonly bool $sourceDocumentExists)
    {
    }

    public function sourceDocumentExists(string $derivedObservationId): bool
    {
        $this->consulted[] = $derivedObservationId;

        return $this->sourceDocumentExists;
    }
}

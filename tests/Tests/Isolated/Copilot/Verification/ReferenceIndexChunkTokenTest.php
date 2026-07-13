<?php

/**
 * FROZEN acceptance tests — TRO-14: chunk-aware citation token mint (W2_ARCHITECTURE §4).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: ReferenceIndex::tokenFor stays the ONE canonical mint.
 * A SourceRef with a null fieldOrChunkId mints the unchanged Week 1 token
 * "sourceType:sourceId" (wire shape of existing records unchanged — §13
 * migration note). A SourceRef carrying a fieldOrChunkId mints
 * "sourceType:sourceId#fieldOrChunkId" — without the fragment, two chunks of
 * the same guideline document would collapse into one token and provenance
 * would silently blur (R6/R10).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Verification;

use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;
use PHPUnit\Framework\TestCase;

class ReferenceIndexChunkTokenTest extends TestCase
{
    public function testWeekOneTokenFormatIsUnchangedForNullChunkId(): void
    {
        $ref = new SourceRef('lab_result', 'lr-77');

        $this->assertSame('lab_result:lr-77', ReferenceIndex::tokenFor($ref));
    }

    public function testChunkBearingRefMintsFragmentToken(): void
    {
        $ref = new SourceRef('guideline', 'protocol-htn-v1', 'Blood-pressure target', 'htn.bp-target', 'target <130/80');

        $this->assertSame('guideline:protocol-htn-v1#htn.bp-target', ReferenceIndex::tokenFor($ref));
    }

    public function testTwoChunksOfOneDocumentMintDistinctTokens(): void
    {
        $a = new SourceRef('guideline', 'protocol-htn-v1', null, 'htn.bp-target', 'x');
        $b = new SourceRef('guideline', 'protocol-htn-v1', null, 'htn.first-line-pharma', 'y');

        $this->assertNotSame(ReferenceIndex::tokenFor($a), ReferenceIndex::tokenFor($b));
    }

    public function testPageAloneDoesNotAlterTheToken(): void
    {
        $plain = new SourceRef('lab_pdf', 'doc-42');
        $paged = new SourceRef('lab_pdf', 'doc-42', '3');

        $this->assertSame(ReferenceIndex::tokenFor($plain), ReferenceIndex::tokenFor($paged));
    }
}

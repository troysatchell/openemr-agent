<?php

/**
 * FROZEN acceptance tests — TRO-24: corpus CI invariants (corpus README §5; W2_ARCHITECTURE §5, §11).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * This suite IS the corpus CI gate: it runs the README §5 invariants against
 * the real committed corpus. The corpus files are data under a documented
 * contract (not frozen fixtures): if a file violates an invariant, the fix is
 * a minimal corpus correction — but chunk IDs are append-only and must never
 * be renamed (golden retrieval cases point at them).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Corpus;

use OpenEMR\Modules\Copilot\Corpus\CorpusChunker;
use OpenEMR\Modules\Copilot\Corpus\CorpusManifest;
use PHPUnit\Framework\TestCase;

class CorpusIntegrityTest extends TestCase
{
    private const CORPUS_DIR = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/corpus';

    /**
     * @return list<\OpenEMR\Modules\Copilot\Corpus\CorpusChunk>
     */
    private function allChunks(CorpusManifest $manifest): array
    {
        $chunks = [];
        foreach ($manifest->documents() as $document) {
            foreach (CorpusChunker::chunkFile(self::CORPUS_DIR . '/' . $document->file) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    public function testInventoryAndMarkersAgreeBidirectionally(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);

        $inventoryIds = array_map(static fn ($e) => $e->chunkId, $manifest->chunkInventory());
        $markerIds = array_map(static fn ($c) => $c->chunkId, $this->allChunks($manifest));

        sort($inventoryIds);
        sort($markerIds);

        $this->assertSame($inventoryIds, $markerIds, 'chunk inventory and file markers must agree exactly (no orphans, no missing)');
    }

    public function testChunkIdsAreUniqueAcrossTheCorpus(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);
        $markerIds = array_map(static fn ($c) => $c->chunkId, $this->allChunks($manifest));

        $this->assertSame($markerIds, array_values(array_unique($markerIds)), 'a chunk id resolving to two markers is ambiguous provenance');
    }

    public function testDeclaredChunkCountMatchesMarkersFound(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);

        $this->assertCount(count($manifest->chunkInventory()), $this->allChunks($manifest));
    }

    public function testEveryMarkerSourceMatchesItsFilenameStemAndTheDocumentsTable(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);
        $documentSourceIds = array_map(static fn ($d) => $d->sourceId, $manifest->documents());

        foreach ($manifest->documents() as $document) {
            $stem = pathinfo($document->file, PATHINFO_FILENAME);
            foreach (CorpusChunker::chunkFile(self::CORPUS_DIR . '/' . $document->file) as $chunk) {
                $this->assertSame($stem, $chunk->sourceId, "chunk {$chunk->chunkId}: marker source must equal its filename stem");
                $this->assertContains($chunk->sourceId, $documentSourceIds, "chunk {$chunk->chunkId}: marker source must be a manifest source_id");
            }
        }
    }

    public function testEveryChunkCarriesANonEmptyDerivedFrom(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);

        foreach ($this->allChunks($manifest) as $chunk) {
            $this->assertNotSame('', trim($chunk->derivedFrom), "chunk {$chunk->chunkId} must carry derived_from provenance");
        }
    }

    public function testReadmeExampleMarkerIsNotAPhantomChunk(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);
        $files = array_map(static fn ($d) => $d->file, $manifest->documents());

        $this->assertNotContains('README.md', $files, 'the manifest whitelist must never include the README itself');
    }

    public function testCriticalValueChunksNeverRestateNumericCutoffs(): void
    {
        $chunks = CorpusChunker::chunkFile(self::CORPUS_DIR . '/protocol-critical-values-v1.md');
        $this->assertNotEmpty($chunks);

        $cutoffPattern = '/\d+(\.\d+)?\s*(mmol\/L|mEq\/L|mg\/dL|g\/dL|x\s?10|×\s?10)/i';
        foreach ($chunks as $chunk) {
            $this->assertDoesNotMatchRegularExpression(
                $cutoffPattern,
                $chunk->body,
                "chunk {$chunk->chunkId}: numeric cutoffs are detector-table authority, never corpus prose (§10 one-source-of-truth)"
            );
        }
    }
}

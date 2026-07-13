<?php

/**
 * FROZEN acceptance tests — TRO-24: corpus manifest + chunker API (W2_ARCHITECTURE §5, §11).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: CorpusManifest parses the corpus README's §3 index (the
 * machine-readable source of truth for what documents and chunk IDs exist);
 * CorpusChunker splits a corpus file on `<!-- chunk: ... -->` markers into
 * chunks carrying id, sourceId, derivedFrom, heading, and body. The chunker is
 * manifest-driven: it reads exactly the files the manifest lists, so a stray
 * example marker elsewhere (the README's own §2 illustration included) can
 * never become a phantom chunk.
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

class CorpusManifestTest extends TestCase
{
    private const CORPUS_DIR = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/corpus';

    public function testManifestListsTheSevenDocuments(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);
        $documents = $manifest->documents();

        $this->assertCount(7, $documents);

        $sourceIds = array_map(static fn ($d) => $d->sourceId, $documents);
        $this->assertContains('protocol-htn-v1', $sourceIds);
        $this->assertContains('protocol-critical-values-v1', $sourceIds);
        $this->assertContains('uspstf-statin-2022', $sourceIds);
    }

    public function testEveryDocumentCarriesFileTypeAndLicense(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);

        foreach ($manifest->documents() as $document) {
            $this->assertNotSame('', trim($document->sourceId));
            $this->assertNotSame('', trim($document->file));
            $this->assertNotSame('', trim($document->sourceType));
            $this->assertNotSame('', trim($document->license));
        }
    }

    public function testManifestDeclaresThirtyThreeChunks(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);

        $this->assertCount(33, $manifest->chunkInventory());
    }

    public function testInventoryEntriesCarryChunkIdSourceIdAndSection(): void
    {
        $manifest = CorpusManifest::fromDirectory(self::CORPUS_DIR);

        foreach ($manifest->chunkInventory() as $entry) {
            $this->assertNotSame('', trim($entry->chunkId));
            $this->assertNotSame('', trim($entry->sourceId));
            $this->assertNotSame('', trim($entry->section));
        }
    }

    public function testChunkerSplitsAFileOnMarkersWithMetadata(): void
    {
        $chunks = CorpusChunker::chunkFile(self::CORPUS_DIR . '/protocol-htn-v1.md');

        $this->assertNotEmpty($chunks);

        $byId = [];
        foreach ($chunks as $chunk) {
            $byId[$chunk->chunkId] = $chunk;
            $this->assertSame('protocol-htn-v1', $chunk->sourceId);
            $this->assertNotSame('', trim($chunk->derivedFrom));
            $this->assertNotSame('', trim($chunk->body));
        }

        $this->assertArrayHasKey('htn.bp-target', $byId);
        $this->assertStringContainsString('Blood-pressure target', $byId['htn.bp-target']->heading);
    }

    public function testMissingManifestFailsLoud(): void
    {
        $this->expectException(\Throwable::class);
        CorpusManifest::fromDirectory(self::CORPUS_DIR . '/no-such-dir');
    }
}

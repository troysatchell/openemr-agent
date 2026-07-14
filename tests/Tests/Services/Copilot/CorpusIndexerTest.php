<?php

/**
 * FROZEN acceptance tests — TRO-26: the committed corpus indexer (W2_ARCHITECTURE §5, §11; PS-12).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract under test: CorpusIndexer::rebuild() rebuilds the whole
 * index from the committed corpus alone (reproducible from a clone — §11):
 * ensures the schema, replaces all chunk rows via the Wave A
 * manifest/chunker, embeds chunk text through the Cohere transport, and
 * stores vectors in the dense leg. Embedder unreachable at BUILD time is the
 * operator-facing stale-index path (PS-12): chunks still index, embeddings
 * are skipped, and the report says so — never a user-facing throw, never
 * half-written embeddings.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Rag\CohereEmbedClient;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexer;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use PHPUnit\Framework\TestCase;

class CorpusIndexerTest extends TestCase
{
    private const CORPUS_DIR = __DIR__
        . '/../../../../interface/modules/custom_modules/oe-module-copilot/corpus';

    protected function setUp(): void
    {
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::EMBEDDING_TABLE, []);
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::CHUNK_TABLE, []);
    }

    /**
     * A fake Cohere embed transport: returns a deterministic basis-ish
     * vector per input text, in the v2 wire shape our client parses.
     */
    private function embedTransport(): \Closure
    {
        return static function (array $requestBody): array {
            $texts = $requestBody['texts'] ?? null;
            if (!is_array($texts)) {
                throw new \RuntimeException('fake transport: texts missing');
            }
            $vectors = [];
            foreach (array_keys($texts) as $i) {
                $vector = array_fill(0, CorpusIndexSchema::EMBEDDING_DIMENSIONS, 0.001);
                $vector[$i % CorpusIndexSchema::EMBEDDING_DIMENSIONS] = 1.0;
                $vectors[] = $vector;
            }

            return [200, ['embeddings' => ['float' => $vectors]]];
        };
    }

    private function failingTransport(): \Closure
    {
        return static function (array $requestBody): array {
            throw new \RuntimeException('embed endpoint unreachable');
        };
    }

    private function rowCount(string $table): int
    {
        $count = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM ' . $table, 'c', []);

        return is_numeric($count) ? (int) $count : -1;
    }

    public function testRebuildIndexesEveryManifestChunkWithEmbeddings(): void
    {
        $indexer = new CorpusIndexer(new CohereEmbedClient($this->embedTransport(), 'embed-english-v3.0'));

        $report = $indexer->rebuild(self::CORPUS_DIR);

        $this->assertSame(33, $report->chunksIndexed, 'the committed corpus declares 33 chunks');
        $this->assertSame(33, $report->embeddingsStored);
        $this->assertFalse($report->embeddingsSkipped);
        $this->assertSame(33, $this->rowCount(CorpusIndexSchema::CHUNK_TABLE));
        $this->assertSame(33, $this->rowCount(CorpusIndexSchema::EMBEDDING_TABLE));
    }

    public function testChunkRowsCarryTheCitationMetadata(): void
    {
        $indexer = new CorpusIndexer(new CohereEmbedClient($this->embedTransport(), 'embed-english-v3.0'));
        $indexer->rebuild(self::CORPUS_DIR);

        $row = QueryUtils::querySingleRow(
            'SELECT source_id, heading, derived_from FROM ' . CorpusIndexSchema::CHUNK_TABLE . " WHERE chunk_id = 'htn.bp-target'",
            [],
        );
        $this->assertIsArray($row);
        $this->assertSame('protocol-htn-v1', $row['source_id']);
        $this->assertIsString($row['heading']);
        $this->assertStringContainsString('Blood-pressure target', $row['heading']);
        $this->assertIsString($row['derived_from']);
        $this->assertNotSame('', trim($row['derived_from']));
    }

    public function testRebuildIsIdempotentNoDuplicates(): void
    {
        $indexer = new CorpusIndexer(new CohereEmbedClient($this->embedTransport(), 'embed-english-v3.0'));
        $indexer->rebuild(self::CORPUS_DIR);
        $report = $indexer->rebuild(self::CORPUS_DIR);

        $this->assertSame(33, $report->chunksIndexed);
        $this->assertSame(33, $this->rowCount(CorpusIndexSchema::CHUNK_TABLE), 'rebuild replaces, never accumulates');
        $this->assertSame(33, $this->rowCount(CorpusIndexSchema::EMBEDDING_TABLE));
    }

    public function testEmbedderDownAtBuildTimeIsStaleIndexNotFailure(): void
    {
        $indexer = new CorpusIndexer(new CohereEmbedClient($this->failingTransport(), 'embed-english-v3.0'));

        $report = $indexer->rebuild(self::CORPUS_DIR);

        $this->assertSame(33, $report->chunksIndexed, 'the keyword leg must survive an embedder outage');
        $this->assertSame(0, $report->embeddingsStored);
        $this->assertTrue($report->embeddingsSkipped, 'the report IS the stale-index alarm (operator-facing, PS-12)');
        $this->assertSame(33, $this->rowCount(CorpusIndexSchema::CHUNK_TABLE));
        $this->assertSame(0, $this->rowCount(CorpusIndexSchema::EMBEDDING_TABLE));
    }

    public function testStoredVectorsAreQueryableByDistance(): void
    {
        $indexer = new CorpusIndexer(new CohereEmbedClient($this->embedTransport(), 'embed-english-v3.0'));
        $indexer->rebuild(self::CORPUS_DIR);

        $nearest = QueryUtils::fetchSingleValue(
            'SELECT chunk_id FROM ' . CorpusIndexSchema::EMBEDDING_TABLE
            . ' ORDER BY VEC_DISTANCE_COSINE(embedding, (SELECT embedding FROM '
            . CorpusIndexSchema::EMBEDDING_TABLE . " WHERE chunk_id = 'htn.bp-target')) LIMIT 1",
            'chunk_id',
            [],
        );

        $this->assertSame('htn.bp-target', $nearest, 'a stored vector must be nearest to itself');
    }
}

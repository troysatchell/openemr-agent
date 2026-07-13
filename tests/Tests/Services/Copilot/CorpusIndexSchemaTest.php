<?php

/**
 * FROZEN acceptance tests — TRO-25: module-owned corpus index tables (W2_ARCHITECTURE §5, §10).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED: runs against the real MariaDB (11.8+, FULLTEXT + native VECTOR)
 * — an in-memory fake of vector search is exactly the kind of fake that lies
 * (§7). Contract under test: CorpusIndexSchema::ensureInstalled() idempotently
 * creates two module-owned tables (no core schema edits): a chunk table
 * carrying the corpus text with a FULLTEXT index (keyword leg), and an
 * embeddings table with a NOT NULL VECTOR column + VECTOR index (dense leg)
 * keyed to chunk ids. Splitting the legs is deliberate: chunks can exist
 * without embeddings (embedder unreachable at build time → keyword-only,
 * PS-12), and the vector index's NOT NULL requirement never blocks chunk
 * ingestion.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use PHPUnit\Framework\TestCase;

class CorpusIndexSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        // Prove creation-from-nothing every run.
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::EMBEDDING_TABLE, []);
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::CHUNK_TABLE, []);
    }

    private function showCreate(string $table): string
    {
        $row = QueryUtils::querySingleRow('SHOW CREATE TABLE `' . $table . '`', []);
        $this->assertIsArray($row);
        $ddl = $row['Create Table'] ?? null;
        $this->assertIsString($ddl);

        return $ddl;
    }

    public function testEnsureInstalledCreatesBothModuleTables(): void
    {
        CorpusIndexSchema::ensureInstalled();

        $chunkDdl = $this->showCreate(CorpusIndexSchema::CHUNK_TABLE);
        $this->assertStringContainsStringIgnoringCase('FULLTEXT', $chunkDdl, 'the keyword leg needs a FULLTEXT index');

        $embeddingDdl = $this->showCreate(CorpusIndexSchema::EMBEDDING_TABLE);
        $this->assertStringContainsStringIgnoringCase(
            'vector(' . CorpusIndexSchema::EMBEDDING_DIMENSIONS . ')',
            $embeddingDdl,
            'the dense leg needs a native VECTOR column at the embedding dimension',
        );
        // MariaDB normalizes `VECTOR INDEX (col)` DDL to `VECTOR KEY ...` in
        // SHOW CREATE TABLE output (same normalization as FULLTEXT KEY).
        $this->assertStringContainsStringIgnoringCase('VECTOR KEY', $embeddingDdl, 'dense search needs the native vector index');
        $this->assertStringContainsStringIgnoringCase('NOT NULL', $embeddingDdl);
    }

    public function testEnsureInstalledIsIdempotent(): void
    {
        CorpusIndexSchema::ensureInstalled();
        CorpusIndexSchema::ensureInstalled();

        $this->assertStringContainsStringIgnoringCase('FULLTEXT', $this->showCreate(CorpusIndexSchema::CHUNK_TABLE));
    }

    public function testFullTextLegRoundTrips(): void
    {
        CorpusIndexSchema::ensureInstalled();

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . CorpusIndexSchema::CHUNK_TABLE
            . ' (chunk_id, source_id, heading, body, derived_from)'
            . " VALUES ('htn.bp-target', 'protocol-htn-v1', 'Blood-pressure target',"
            . " 'blood pressure target for most adults in the practice', 'AHA/ACC 2025')",
            [],
        );

        $found = QueryUtils::fetchSingleValue(
            'SELECT chunk_id FROM ' . CorpusIndexSchema::CHUNK_TABLE
            . " WHERE MATCH(heading, body) AGAINST('pressure' IN NATURAL LANGUAGE MODE)",
            'chunk_id',
            [],
        );

        $this->assertSame('htn.bp-target', $found);
    }

    /**
     * A basis vector string like '[0,...,1,...,0]' at the embedding dimension.
     */
    private function basisVector(int $hotIndex): string
    {
        $components = array_fill(0, CorpusIndexSchema::EMBEDDING_DIMENSIONS, 0);
        $components[$hotIndex] = 1;

        return '[' . implode(',', $components) . ']';
    }

    public function testDenseLegOrdersByVectorDistance(): void
    {
        CorpusIndexSchema::ensureInstalled();

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . CorpusIndexSchema::CHUNK_TABLE
            . ' (chunk_id, source_id, heading, body, derived_from)'
            . " VALUES ('a.one', 's', 'h', 'b', 'd'), ('a.two', 's', 'h', 'b', 'd')",
            [],
        );
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . CorpusIndexSchema::EMBEDDING_TABLE
            . ' (chunk_id, embedding, embedding_model)'
            . " VALUES ('a.one', VEC_FromText(?), 'test-model'), ('a.two', VEC_FromText(?), 'test-model')",
            [$this->basisVector(0), $this->basisVector(1)],
        );

        $nearest = QueryUtils::fetchSingleValue(
            'SELECT chunk_id FROM ' . CorpusIndexSchema::EMBEDDING_TABLE
            . ' ORDER BY VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) LIMIT 1',
            'chunk_id',
            [$this->basisVector(0)],
        );

        $this->assertSame('a.one', $nearest, 'native cosine ordering must surface the nearest chunk first');
    }

    public function testChunksCanExistWithoutEmbeddings(): void
    {
        // PS-12: embedder unreachable at build time leaves a keyword-only
        // index — chunk rows must never depend on embedding rows.
        CorpusIndexSchema::ensureInstalled();

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . CorpusIndexSchema::CHUNK_TABLE
            . ' (chunk_id, source_id, heading, body, derived_from)'
            . " VALUES ('lonely.chunk', 's', 'h', 'keyword only body', 'd')",
            [],
        );

        $count = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM ' . CorpusIndexSchema::CHUNK_TABLE . " WHERE chunk_id = 'lonely.chunk'",
            'c',
            [],
        );

        $this->assertEquals(1, $count);
    }
}

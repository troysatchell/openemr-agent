<?php

/**
 * FROZEN acceptance tests — TRO-27: hybrid retrieval — union → rerank → top-k (W2_ARCHITECTURE §5).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract under test: keyword (FULLTEXT) and dense (VECTOR)
 * candidates union (dedup by chunk id), the union goes to the Cohere
 * reranker, and exactly top-k reranked snippets return, highest relevance
 * first, each carrying its citation metadata (chunk id + source document +
 * section heading) and minting the §4 guideline SourceRef. Empty retrieval
 * returns an empty list — an explicit signal, never an exception and never
 * filler from model weights.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Rag\CohereRerankClient;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use OpenEMR\Modules\Copilot\Rag\HybridRetriever;
use PHPUnit\Framework\TestCase;

class HybridRetrieverTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private static array $rerankRequests = [];

    protected function setUp(): void
    {
        self::$rerankRequests = [];
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::EMBEDDING_TABLE, []);
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::CHUNK_TABLE, []);
        CorpusIndexSchema::ensureInstalled();
    }

    private function seedChunk(string $chunkId, string $body, ?int $hotComponent = null): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . CorpusIndexSchema::CHUNK_TABLE
            . ' (chunk_id, source_id, heading, body, derived_from)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [$chunkId, 'protocol-test-v1', 'Heading for ' . $chunkId, $body, 'Test Guideline 2026'],
        );

        if ($hotComponent !== null) {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO ' . CorpusIndexSchema::EMBEDDING_TABLE
                . ' (chunk_id, embedding, embedding_model) VALUES (?, VEC_FromText(?), ?)',
                [$chunkId, $this->vector($hotComponent), 'test-model'],
            );
        }
    }

    private function vector(int $hotComponent): string
    {
        $components = array_fill(0, CorpusIndexSchema::EMBEDDING_DIMENSIONS, 0);
        $components[$hotComponent] = 1;

        return '[' . implode(',', $components) . ']';
    }

    /**
     * Fake Cohere rerank transport: records requests and scores each
     * document by position using the supplied score list (v2 wire shape).
     *
     * @param list<float> $scoresByPosition
     */
    private function retriever(array $scoresByPosition = []): HybridRetriever
    {
        $transport = static function (array $requestBody) use ($scoresByPosition): array {
            $stringKeyed = [];
            foreach ($requestBody as $key => $value) {
                $stringKeyed[(string) $key] = $value;
            }
            self::$rerankRequests[] = $stringKeyed;
            $documents = $requestBody['documents'] ?? [];
            $results = [];
            if (is_array($documents)) {
                foreach (array_keys(array_values($documents)) as $i) {
                    $results[] = ['index' => $i, 'relevance_score' => $scoresByPosition[$i] ?? (1.0 - $i * 0.1)];
                }
            }
            usort($results, static fn (array $a, array $b): int => $b['relevance_score'] <=> $a['relevance_score']);

            return [200, ['results' => $results]];
        };

        return new HybridRetriever(new CohereRerankClient($transport, 'rerank-english-v3.0'));
    }

    public function testKeywordAndDenseCandidatesBothReachTheReranker(): void
    {
        // Keyword-only chunk: query words in the body, no embedding row.
        $this->seedChunk('kw.only', 'anticoagulation bleeding risk assessment protocol', null);
        // Dense-only chunk: no query words, embedding adjacent to the query vector.
        $this->seedChunk('dense.only', 'unrelated wording entirely', 3);

        $this->retriever()->retrieve('bleeding risk', $this->vector(3), 5);

        $this->assertCount(1, self::$rerankRequests, 'the union goes to the reranker exactly once');
        $documents = self::$rerankRequests[0]['documents'] ?? null;
        $this->assertIsArray($documents);
        $joined = (string) json_encode($documents);
        $this->assertStringContainsString('bleeding risk assessment', $joined, 'keyword candidate must reach the reranker');
        $this->assertStringContainsString('unrelated wording entirely', $joined, 'dense candidate must reach the reranker');
    }

    public function testRerankOrderWinsAndTopKIsExact(): void
    {
        $this->seedChunk('c.one', 'statin therapy risk discussion one', null);
        $this->seedChunk('c.two', 'statin therapy risk discussion two', null);
        $this->seedChunk('c.three', 'statin therapy risk discussion three', null);

        // Score position 2 highest, then 0, then 1 — rerank must override
        // any keyword-relevance ordering.
        $results = $this->retriever([0.5, 0.1, 0.9])->retrieve('statin therapy risk', null, 2);

        $this->assertCount(2, $results, 'top-k is exact (off-by-one caught here)');
        $this->assertNotSame($results[0]->chunkId, $results[1]->chunkId);
        $this->assertGreaterThanOrEqual($results[1]->score, $results[0]->score, 'highest relevance first');
    }

    public function testRetrievedChunkCarriesCitationMetadataAndMintsTheGuidelineRef(): void
    {
        $this->seedChunk('af.bleeding-risk', 'bleeding risk factors reviewed annually', null);

        $results = $this->retriever()->retrieve('bleeding risk factors', null, 3);

        $this->assertCount(1, $results);
        $chunk = $results[0];
        $this->assertSame('af.bleeding-risk', $chunk->chunkId);
        $this->assertSame('protocol-test-v1', $chunk->sourceId);
        $this->assertStringContainsString('af.bleeding-risk', $chunk->heading);

        $ref = $chunk->toSourceRef();
        $this->assertSame('guideline', $ref->sourceType);
        $this->assertSame('protocol-test-v1', $ref->sourceId);
        $this->assertSame('af.bleeding-risk', $ref->fieldOrChunkId);
        $this->assertNotNull($ref->quoteOrValue);
    }

    public function testEmptyRetrievalReturnsAnEmptyListNeverThrows(): void
    {
        $results = $this->retriever()->retrieve('nothing in the corpus matches this', null, 5);

        $this->assertSame([], $results);
        $this->assertSame([], self::$rerankRequests, 'no candidates means no vendor call at all');
    }

    public function testDuplicateCandidatesFromBothLegsCollapseBeforeRerank(): void
    {
        // One chunk that matches BOTH legs: query words in body AND an
        // embedding adjacent to the query vector.
        $this->seedChunk('both.legs', 'hypertension target discussion for adults', 7);

        $this->retriever()->retrieve('hypertension target', $this->vector(7), 5);

        $documents = self::$rerankRequests[0]['documents'] ?? null;
        $this->assertIsArray($documents);
        $this->assertCount(1, $documents, 'the union dedupes by chunk id — one candidate, not two');
    }
}

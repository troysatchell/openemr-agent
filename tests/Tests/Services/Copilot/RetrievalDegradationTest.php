<?php

/**
 * FROZEN acceptance tests — TRO-28: the asymmetric degradation pair + vector-index probe (PS-12; W2_ARCHITECTURE §5, §8).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * DB-BACKED. Contract under test: worse results beat no results — but never
 * silently. Reranker unreachable → hybrid candidates return in union order,
 * flagged rerankDegraded. Embedder unreachable at QUERY time → keyword-only
 * retrieval, flagged denseDegraded (build-time is TRO-26's stale-index path).
 * Both down → keyword-only, both flags, still results. The VectorIndexProbe
 * reports per-dependency DEGRADED (never binary down) with the dependency
 * named: fully-embedded index = Ok; chunks without embeddings = Degraded;
 * empty index = Degraded.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Rag\CohereEmbedClient;
use OpenEMR\Modules\Copilot\Rag\CohereRerankClient;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use OpenEMR\Modules\Copilot\Rag\EvidenceRetrievalService;
use OpenEMR\Modules\Copilot\Rag\HybridRetriever;
use OpenEMR\Modules\Copilot\Rag\ProbeStatus;
use OpenEMR\Modules\Copilot\Rag\VectorIndexProbe;
use PHPUnit\Framework\TestCase;

class RetrievalDegradationTest extends TestCase
{
    protected function setUp(): void
    {
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::EMBEDDING_TABLE, []);
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::CHUNK_TABLE, []);
        CorpusIndexSchema::ensureInstalled();
    }

    private function seedChunk(string $chunkId, string $body, ?int $hotComponent = null): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . CorpusIndexSchema::CHUNK_TABLE
            . ' (chunk_id, source_id, heading, body, derived_from) VALUES (?, ?, ?, ?, ?)',
            [$chunkId, 'protocol-test-v1', 'Heading ' . $chunkId, $body, 'Test Guideline 2026'],
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

    private function workingEmbed(): CohereEmbedClient
    {
        $transport = static function (array $requestBody): array {
            $texts = $requestBody['texts'] ?? [];
            $vectors = [];
            if (is_array($texts)) {
                foreach (array_keys($texts) as $i) {
                    $vector = array_fill(0, CorpusIndexSchema::EMBEDDING_DIMENSIONS, 0.001);
                    $vector[5] = 1.0;
                    $vectors[] = $vector;
                }
            }

            return [200, ['embeddings' => ['float' => $vectors]]];
        };

        return new CohereEmbedClient($transport, 'embed-english-v3.0');
    }

    private function brokenEmbed(): CohereEmbedClient
    {
        return new CohereEmbedClient(static function (array $requestBody): array {
            throw new \RuntimeException('embed endpoint unreachable');
        }, 'embed-english-v3.0');
    }

    private function workingRerank(): CohereRerankClient
    {
        $transport = static function (array $requestBody): array {
            $documents = $requestBody['documents'] ?? [];
            $results = [];
            if (is_array($documents)) {
                foreach (array_keys(array_values($documents)) as $i) {
                    $results[] = ['index' => $i, 'relevance_score' => 1.0 - $i * 0.1];
                }
            }

            return [200, ['results' => $results]];
        };

        return new CohereRerankClient($transport, 'rerank-english-v3.0');
    }

    private function brokenRerank(): CohereRerankClient
    {
        return new CohereRerankClient(static function (array $requestBody): array {
            throw new \RuntimeException('rerank endpoint unreachable');
        }, 'rerank-english-v3.0');
    }

    private function service(CohereEmbedClient $embed, CohereRerankClient $rerank): EvidenceRetrievalService
    {
        return new EvidenceRetrievalService($embed, new HybridRetriever($rerank));
    }

    public function testHealthyPathCarriesNoDegradationFlags(): void
    {
        $this->seedChunk('kw.chunk', 'statin therapy discussion for primary prevention', 5);

        $outcome = $this->service($this->workingEmbed(), $this->workingRerank())->search('statin therapy', 3);

        $this->assertNotEmpty($outcome->chunks);
        $this->assertFalse($outcome->denseDegraded);
        $this->assertFalse($outcome->rerankDegraded);
    }

    public function testRerankDownFallsBackToUnionOrderFlagged(): void
    {
        $this->seedChunk('a.chunk', 'anticoagulation bleeding risk one', null);
        $this->seedChunk('b.chunk', 'anticoagulation bleeding risk two', null);

        $outcome = $this->service($this->workingEmbed(), $this->brokenRerank())->search('bleeding risk', 2);

        $this->assertCount(2, $outcome->chunks, 'worse results beat no results');
        $this->assertTrue($outcome->rerankDegraded, 'never silently');
        $this->assertFalse($outcome->denseDegraded);
    }

    public function testEmbedderDownAtQueryTimeIsKeywordOnlyFlagged(): void
    {
        $this->seedChunk('kw.only', 'hypertension target blood pressure adults', null);

        $outcome = $this->service($this->brokenEmbed(), $this->workingRerank())->search('hypertension target', 3);

        $this->assertNotEmpty($outcome->chunks, 'keyword-only still answers');
        $this->assertTrue($outcome->denseDegraded);
        $this->assertFalse($outcome->rerankDegraded);
    }

    public function testBothVendorsDownStillReturnsKeywordResultsWithBothFlags(): void
    {
        $this->seedChunk('kw.only', 'diabetes screening interval adults', null);

        $outcome = $this->service($this->brokenEmbed(), $this->brokenRerank())->search('diabetes screening', 3);

        $this->assertNotEmpty($outcome->chunks);
        $this->assertTrue($outcome->denseDegraded);
        $this->assertTrue($outcome->rerankDegraded);
    }

    public function testVectorIndexProbeReportsOkWhenFullyEmbedded(): void
    {
        $this->seedChunk('c.one', 'body one', 1);
        $this->seedChunk('c.two', 'body two', 2);

        $result = (new VectorIndexProbe())->check();

        $this->assertSame(ProbeStatus::Ok, $result->status);
        $this->assertSame('vector-index', $result->dependency);
    }

    public function testVectorIndexProbeReportsDegradedNotDownWhenEmbeddingsMissing(): void
    {
        $this->seedChunk('c.one', 'body one', 1);
        $this->seedChunk('c.two', 'body two', null);

        $result = (new VectorIndexProbe())->check();

        $this->assertSame(ProbeStatus::Degraded, $result->status);
        $this->assertSame('vector-index', $result->dependency);
        $this->assertIsString($result->detail);
        $this->assertNotSame('', trim($result->detail), 'the failing dependency is named with a reason');
    }

    public function testVectorIndexProbeReportsDegradedOnAnEmptyIndex(): void
    {
        $result = (new VectorIndexProbe())->check();

        $this->assertSame(ProbeStatus::Degraded, $result->status);
    }
}

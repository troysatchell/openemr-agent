<?php

/**
 * Cohere Rerank adapter (W2_ARCHITECTURE.md §5 "Hybrid RAG + rerank").
 *
 * The candidate union produced by HybridRetriever's keyword + dense legs is
 * unranked (or ranked by two incompatible scoring systems); this adapter is
 * the single point where those candidates get a single, comparable
 * relevance order before any of them reach the answer model
 * (minimum-necessary applies to evidence too — only reranked, cited
 * snippets are ever forwarded downstream).
 *
 * The wire call is expressed as a `\Closure(array $requestBody): array{int,
 * array<string, mixed>}` transport — the same injected-transport idiom as
 * AnthropicLlmClient: it takes the JSON-serializable Cohere Rerank v2
 * request body and returns [HTTP status, decoded JSON response body].
 * Injecting the transport keeps the wire contract testable without a
 * network; a live HTTP transport is integration-only and not covered by the
 * isolated suite.
 *
 * Failure mapping: transport faults, non-200 responses, and
 * unparseable/malformed result entries (a non-integer index, an
 * out-of-range index, a non-numeric relevance score) all map to
 * RerankUnavailableException — this class never guesses at a partial or
 * best-effort ranking. Vendor response bodies and transport exception
 * messages are never echoed into a thrown message (AUDIT: never expose
 * internals in user-facing output) — the original throwable, if any, rides
 * on getPrevious() only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final readonly class CohereRerankClient
{
    /**
     * @param \Closure(array<string, mixed>): array{int, array<string, mixed>} $transport
     *        Sends the JSON-serializable Cohere Rerank v2 request body and
     *        returns [HTTP status code, decoded JSON response body].
     *        Injected so the wire contract is testable without a network.
     */
    public function __construct(
        private \Closure $transport,
        private string $modelId,
    ) {
        if (trim($this->modelId) === '') {
            throw new \DomainException('CohereRerankClient modelId must be non-blank');
        }
    }

    /**
     * Reranks the given documents against the query and returns them
     * ordered highest relevance first.
     *
     * @param list<string> $documents
     *
     * @return list<array{index: int, score: float}> ordered highest score first;
     *         `index` indexes back into the `$documents` argument as given
     */
    public function rerank(string $query, array $documents, int $topN): array
    {
        $requestBody = [
            'model' => $this->modelId,
            'query' => $query,
            'documents' => $documents,
            'top_n' => $topN,
        ];

        try {
            [$status, $body] = ($this->transport)($requestBody);
        } catch (\Throwable $e) {
            throw new RerankUnavailableException(
                'The rerank endpoint could not be reached',
                0,
                $e,
            );
        }

        if ($status !== 200) {
            throw new RerankUnavailableException(
                sprintf('The rerank endpoint returned HTTP %d', $status),
            );
        }

        return $this->parseResults($body, count($documents));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array{index: int, score: float}> ordered highest score first
     */
    private function parseResults(array $body, int $documentCount): array
    {
        $results = $body['results'] ?? null;
        if (!is_array($results)) {
            throw new RerankUnavailableException(
                'The rerank endpoint response is missing a usable results list',
            );
        }

        $parsed = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                throw new RerankUnavailableException(
                    'The rerank endpoint returned a malformed result entry',
                );
            }

            $index = $result['index'] ?? null;
            if (!is_int($index)) {
                throw new RerankUnavailableException(
                    'The rerank endpoint returned a non-integer result index',
                );
            }

            if ($index < 0 || $index >= $documentCount) {
                throw new RerankUnavailableException(
                    'The rerank endpoint returned a result index out of range',
                );
            }

            $relevanceScore = $result['relevance_score'] ?? null;
            if (!is_numeric($relevanceScore)) {
                throw new RerankUnavailableException(
                    'The rerank endpoint returned a non-numeric relevance score',
                );
            }

            $parsed[] = ['index' => $index, 'score' => (float) $relevanceScore];
        }

        usort($parsed, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $parsed;
    }
}

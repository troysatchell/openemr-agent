<?php

/**
 * Cohere embed v2 adapter — the dense leg's vendor boundary
 * (W2_ARCHITECTURE.md §5 "Hybrid RAG + rerank"; §11 reproducible-from-repo).
 *
 * Same shape as `AnthropicLlmClient`: the wire call is expressed as a
 * `\Closure(array $requestBody): array{int, array<string, mixed>}` transport
 * — takes the JSON-serializable Cohere embed request body and returns [HTTP
 * status, decoded JSON response body]. Injecting the transport keeps the
 * wire contract testable without a network; the live HTTP call is built
 * separately (see `bin/index-corpus.php`) and is integration-only.
 *
 * `modelId` is public — the indexer that drives this client also needs the
 * model identity to stamp `embedding_model` on every stored vector row, and
 * a getter method would add nothing a public readonly property doesn't
 * already give for free on a value-object-shaped client.
 *
 * Failure mapping: transport faults, non-200 status, and a malformed or
 * short embeddings block all collapse to {@see EmbeddingUnavailableException}
 * — the one failure type this vendor boundary has (PS-12's build-time half:
 * {@see CorpusIndexer::rebuild()} treats it as a stale-index signal, never a
 * thrown user-facing error). Vendor response bodies are never echoed into a
 * thrown message; the original throwable, if any, rides on getPrevious()
 * only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final readonly class CohereEmbedClient
{
    /**
     * @param \Closure(array<string, mixed>): array{int, array<string, mixed>} $transport
     *        Sends the JSON-serializable Cohere embed v2 request body and
     *        returns [HTTP status code, decoded JSON response body].
     *        Injected so the wire contract is testable without a network.
     * @param string $modelId the Cohere embed model identity, stamped onto
     *        every stored embedding row as `embedding_model`.
     */
    public function __construct(
        private \Closure $transport,
        public string $modelId,
    ) {
        if (trim($this->modelId) === '') {
            throw new \DomainException('CohereEmbedClient modelId must be non-blank');
        }
    }

    /**
     * Embeds a batch of texts in a single request.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     *
     * @throws EmbeddingUnavailableException if the transport faults, the
     *         endpoint returns a non-200 status, or the response body does
     *         not carry exactly one float vector per input text.
     */
    public function embed(array $texts): array
    {
        $requestBody = [
            'model' => $this->modelId,
            'texts' => $texts,
            'input_type' => 'search_document',
            'embedding_types' => ['float'],
        ];

        try {
            [$status, $body] = ($this->transport)($requestBody);
        } catch (\Throwable $e) {
            throw new EmbeddingUnavailableException(
                'The Cohere embed endpoint could not be reached',
                0,
                $e,
            );
        }

        if ($status !== 200) {
            throw new EmbeddingUnavailableException(
                sprintf('The Cohere embed endpoint returned HTTP %d', $status),
            );
        }

        return $this->parseVectors($body, count($texts));
    }

    /**
     * @param array<string, mixed> $body
     * @return list<list<float>>
     */
    private function parseVectors(array $body, int $expectedCount): array
    {
        $embeddings = $body['embeddings'] ?? null;
        if (!is_array($embeddings)) {
            throw new EmbeddingUnavailableException(
                'The Cohere embed response is missing a usable embeddings block',
            );
        }

        $float = $embeddings['float'] ?? null;
        if (!is_array($float)) {
            throw new EmbeddingUnavailableException(
                'The Cohere embed response is missing the float embedding list',
            );
        }

        $vectors = [];
        foreach ($float as $vector) {
            $vectors[] = $this->parseVector($vector);
        }

        if (count($vectors) !== $expectedCount) {
            throw new EmbeddingUnavailableException(
                'The Cohere embed response returned a mismatched embedding count',
            );
        }

        return $vectors;
    }

    /**
     * @return list<float>
     */
    private function parseVector(mixed $vector): array
    {
        if (!is_array($vector)) {
            throw new EmbeddingUnavailableException(
                'The Cohere embed response contained a non-list embedding vector',
            );
        }

        $floats = [];
        foreach ($vector as $component) {
            if (!is_int($component) && !is_float($component)) {
                throw new EmbeddingUnavailableException(
                    'The Cohere embed response contained a non-numeric embedding component',
                );
            }
            $floats[] = (float) $component;
        }

        return $floats;
    }
}

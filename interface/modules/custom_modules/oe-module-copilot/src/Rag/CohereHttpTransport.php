<?php

/**
 * Production Cohere HTTP transport (Wave K.2, TRO-44; W2_ARCHITECTURE.md §5
 * "Hybrid RAG + rerank"; §11 "reproducible from the repo alone").
 *
 * Mirrors {@see AnthropicLlmClient::forAnthropicApi()}'s own transport
 * construction and the live embed transport already built (integration-only,
 * uncovered by the isolated suite) in `bin/index-corpus.php`: a real Guzzle
 * client, `http_errors` disabled so non-2xx responses reach
 * `CohereEmbedClient`/`CohereRerankClient` as an ordinary `[status, body]`
 * pair rather than a Guzzle exception, and JSON decoded into a string-keyed
 * body before being handed back.
 *
 * `forEmbed()`/`forRerank()` return the injectable transport closure each
 * client's constructor expects — `\Closure(array<string, mixed>):
 * array{int, array<string, mixed>}` — hitting Cohere's v2 embed/rerank
 * endpoints respectively. The API key is read via `getenv('COHERE_API_KEY')`
 * — never `$_ENV` (empty under `variables_order=GPCS`) — at CALL time, not at
 * factory time, so a key that appears mid-process (or never appears) is
 * re-checked on every request rather than baked in once.
 *
 * No service-account or default-key fallback: when the key is unset or
 * blank, the closure throws before any network call. This is never a fatal
 * or a route error — both `CohereEmbedClient::embed()` and
 * `CohereRerankClient::rerank()` already catch `\Throwable` around their
 * transport call and map it to their own vendor-boundary exception
 * (`EmbeddingUnavailableException` / `RerankUnavailableException`), which
 * `EvidenceRetrievalService` degrades per PS-12 rather than propagating.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final class CohereHttpTransport
{
    private const BASE_URI = 'https://api.cohere.com';

    private const EMBED_PATH = '/v2/embed';

    private const RERANK_PATH = '/v2/rerank';

    private function __construct()
    {
        // Static factory only — no instance state.
    }

    /**
     * @return \Closure(array<string, mixed>): array{int, array<string, mixed>}
     */
    public static function forEmbed(): \Closure
    {
        return self::transport(self::EMBED_PATH);
    }

    /**
     * @return \Closure(array<string, mixed>): array{int, array<string, mixed>}
     */
    public static function forRerank(): \Closure
    {
        return self::transport(self::RERANK_PATH);
    }

    /**
     * @return \Closure(array<string, mixed>): array{int, array<string, mixed>}
     */
    private static function transport(string $path): \Closure
    {
        return static function (array $requestBody) use ($path): array {
            $apiKey = getenv('COHERE_API_KEY') ?: '';
            if (trim($apiKey) === '') {
                throw new \RuntimeException(
                    'COHERE_API_KEY is not configured — this vendor boundary has no service-account or default-key fallback',
                );
            }

            $httpClient = new \GuzzleHttp\Client([
                'base_uri' => self::BASE_URI,
                'timeout' => 60,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);

            $response = $httpClient->post($path, [
                'headers' => [
                    'Authorization' => 'bearer ' . $apiKey,
                    'content-type' => 'application/json',
                ],
                'json' => $requestBody,
            ]);

            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $body = self::stringKeyed($decoded);

            return [$response->getStatusCode(), $body];
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            throw new \RuntimeException('The Cohere API response body did not decode to a JSON object');
        }

        $body = [];
        foreach ($decoded as $key => $value) {
            $body[(string) $key] = $value;
        }

        return $body;
    }
}

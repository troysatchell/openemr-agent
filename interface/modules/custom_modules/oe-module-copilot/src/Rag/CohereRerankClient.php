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
 * Cost capture (TRO-46; PS-9): an optional trailing `TraceContext $span`
 * argument on `rerank()` and an optional trailing `TraceRecorder`
 * constructor argument (both additive — every pre-existing call site keeps
 * working unchanged and records nothing) let this vendor boundary record a
 * `rerank` `StepRecord` carrying a {@see VendorUnits} at the committed
 * `cohere-2026-07` price: $2.00 / 1,000 search units (`docs/COST_MODEL.md`
 * §1). One search unit is one query reranked against up to
 * `DOCUMENTS_PER_SEARCH_UNIT` documents; `HybridRetriever`'s candidate cap
 * keeps every live call inside one search unit today, but the unit count is
 * still computed from the actual document count rather than hardcoded to 1,
 * so a future larger candidate set is still billed honestly.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

use OpenEMR\Modules\Copilot\Observability\NullTraceRecorder;
use OpenEMR\Modules\Copilot\Observability\StepOutcome;
use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceRecorder;
use OpenEMR\Modules\Copilot\Observability\VendorUnits;

final readonly class CohereRerankClient
{
    private const PRICE_VERSION = 'cohere-2026-07';

    /** Committed Cohere Rerank v2 price (docs/COST_MODEL.md §1) — MEASURED: $2.00 / 1,000 search units. */
    private const USD_PER_SEARCH_UNIT = 0.002;

    /** A search unit covers a query reranked against up to this many documents — MEASURED vendor billing unit. */
    private const DOCUMENTS_PER_SEARCH_UNIT = 100;

    /**
     * Not promoted: an optional TraceRecorder is accepted as a nullable
     * constructor parameter and resolved into this property in the body
     * (same pattern as CohereEmbedClient/TurnOrchestrator).
     */
    private TraceRecorder $recorder;

    /**
     * @param \Closure(array<string, mixed>): array{int, array<string, mixed>} $transport
     *        Sends the JSON-serializable Cohere Rerank v2 request body and
     *        returns [HTTP status code, decoded JSON response body].
     *        Injected so the wire contract is testable without a network.
     */
    public function __construct(
        private \Closure $transport,
        private string $modelId,
        ?TraceRecorder $recorder = null,
    ) {
        if (trim($this->modelId) === '') {
            throw new \DomainException('CohereRerankClient modelId must be non-blank');
        }

        $this->recorder = $recorder ?? new NullTraceRecorder();
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
    public function rerank(string $query, array $documents, int $topN, ?TraceContext $span = null): array
    {
        $requestBody = [
            'model' => $this->modelId,
            'query' => $query,
            'documents' => $documents,
            'top_n' => $topN,
        ];

        $startedAt = new \DateTimeImmutable();
        $start = microtime(true);

        try {
            [$status, $body] = ($this->transport)($requestBody);
        } catch (\Throwable $e) {
            $this->recordFailure($span, $startedAt, $start, RerankUnavailableException::class);

            throw new RerankUnavailableException(
                'The rerank endpoint could not be reached',
                0,
                $e,
            );
        }

        if ($status !== 200) {
            $this->recordFailure($span, $startedAt, $start, RerankUnavailableException::class);

            throw new RerankUnavailableException(
                sprintf('The rerank endpoint returned HTTP %d', $status),
            );
        }

        try {
            $parsed = $this->parseResults($body, count($documents));
        } catch (RerankUnavailableException $e) {
            $this->recordFailure($span, $startedAt, $start, $e::class);

            throw $e;
        }

        $this->recordSuccess($span, $startedAt, $start, count($documents));

        return $parsed;
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

    /**
     * Records the `rerank` step's cost when a span is supplied — a no-op
     * when it is not, so every call site that predates TRO-46 stays
     * behaviorally unchanged.
     */
    private function recordSuccess(?TraceContext $span, \DateTimeImmutable $startedAt, float $start, int $documentCount): void
    {
        if ($span === null) {
            return;
        }

        $searchUnits = max(1, (int) ceil($documentCount / self::DOCUMENTS_PER_SEARCH_UNIT));
        $costUsd = $searchUnits * self::USD_PER_SEARCH_UNIT;

        $this->recorder->record($span, new StepRecord(
            'rerank',
            $startedAt,
            $this->elapsedMs($start),
            StepOutcome::Ok,
            vendorUnits: new VendorUnits('cohere', 'rerank_search_unit', $searchUnits, self::PRICE_VERSION, $costUsd),
        ));
    }

    private function recordFailure(?TraceContext $span, \DateTimeImmutable $startedAt, float $start, string $errorClass): void
    {
        if ($span === null) {
            return;
        }

        $this->recorder->record(
            $span,
            new StepRecord('rerank', $startedAt, $this->elapsedMs($start), StepOutcome::Failed, $errorClass),
        );
    }

    /**
     * Elapsed milliseconds since $startSeconds (microtime(true)) — a
     * measurement, not domain time (same convention as
     * SupervisedTurnDispatcher's trace timing).
     */
    private function elapsedMs(float $startSeconds): float
    {
        return (microtime(true) - $startSeconds) * 1000.0;
    }
}

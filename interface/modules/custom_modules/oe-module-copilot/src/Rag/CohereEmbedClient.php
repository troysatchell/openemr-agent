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
 * Cost capture (TRO-46; PS-9): an optional trailing `TraceContext $span`
 * argument on `embed()` and an optional trailing `TraceRecorder` constructor
 * argument (both additive — every pre-existing call site, in tests and in
 * `CorpusIndexer`, keeps working unchanged and records nothing) let this
 * vendor boundary record an `embed` `StepRecord` carrying a
 * {@see VendorUnits} at the committed `cohere-2026-07` price
 * (`$0.10 / 1,000,000 tokens`, `docs/COST_MODEL.md` §1). The Cohere embed v2
 * response never returns a token count, so the unit count fed to that price
 * is itself an ESTIMATE — ~4 characters/token over the input text, a
 * conservative upper bound (real tokenization is usually denser, so this
 * over-counts rather than under-counts) — labeled `embed_token_estimated`,
 * never presented as a vendor-measured figure. No recording happens (and no
 * span/recorder need be supplied) on the build-time indexing path.
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

final readonly class CohereEmbedClient
{
    private const PRICE_VERSION = 'cohere-2026-07';

    /** Committed Cohere embed v2 price (docs/COST_MODEL.md §1) — MEASURED. */
    private const USD_PER_MILLION_TOKENS = 0.10;

    /**
     * The Cohere embed v2 response carries no token count, so cost is
     * estimated from input character length. ~4 chars/token is a
     * conservative, ASSUMED, upper-bound heuristic — see the class docblock.
     */
    private const ESTIMATED_CHARS_PER_TOKEN = 4;

    /**
     * Not promoted: an optional TraceRecorder is accepted as a nullable
     * constructor parameter and resolved into this property in the body
     * (same pattern as TurnOrchestrator's traceRecorder — PHP forbids `new`
     * in a promoted property's default value).
     */
    private TraceRecorder $recorder;

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
        ?TraceRecorder $recorder = null,
    ) {
        if (trim($this->modelId) === '') {
            throw new \DomainException('CohereEmbedClient modelId must be non-blank');
        }

        $this->recorder = $recorder ?? new NullTraceRecorder();
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
    public function embed(array $texts, string $inputType = 'search_document', ?TraceContext $span = null): array
    {
        if (!in_array($inputType, ['search_document', 'search_query'], true)) {
            throw new \DomainException('CohereEmbedClient inputType must be search_document or search_query');
        }

        $requestBody = [
            'model' => $this->modelId,
            'texts' => $texts,
            'input_type' => $inputType,
            'embedding_types' => ['float'],
        ];

        $startedAt = new \DateTimeImmutable();
        $start = microtime(true);

        try {
            [$status, $body] = ($this->transport)($requestBody);
        } catch (\Throwable $e) {
            $this->recordFailure($span, $startedAt, $start, EmbeddingUnavailableException::class);

            throw new EmbeddingUnavailableException(
                'The Cohere embed endpoint could not be reached',
                0,
                $e,
            );
        }

        if ($status !== 200) {
            $this->recordFailure($span, $startedAt, $start, EmbeddingUnavailableException::class);

            throw new EmbeddingUnavailableException(
                sprintf('The Cohere embed endpoint returned HTTP %d', $status),
            );
        }

        try {
            $vectors = $this->parseVectors($body, count($texts));
        } catch (EmbeddingUnavailableException $e) {
            $this->recordFailure($span, $startedAt, $start, $e::class);

            throw $e;
        }

        $this->recordSuccess($span, $startedAt, $start, $texts);

        return $vectors;
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

    /**
     * Records the `embed` step's cost when a span is supplied — a no-op
     * (including for the estimate math) when it is not, so every call site
     * that predates TRO-46 stays behaviorally unchanged.
     *
     * @param list<string> $texts
     */
    private function recordSuccess(?TraceContext $span, \DateTimeImmutable $startedAt, float $start, array $texts): void
    {
        if ($span === null) {
            return;
        }

        $totalChars = 0;
        foreach ($texts as $text) {
            $totalChars += mb_strlen($text);
        }
        $estimatedTokens = (int) ceil($totalChars / self::ESTIMATED_CHARS_PER_TOKEN);
        $costUsd = $estimatedTokens * (self::USD_PER_MILLION_TOKENS / 1_000_000);

        $this->recorder->record($span, new StepRecord(
            'embed',
            $startedAt,
            $this->elapsedMs($start),
            StepOutcome::Ok,
            vendorUnits: new VendorUnits('cohere', 'embed_token_estimated', $estimatedTokens, self::PRICE_VERSION, $costUsd),
        ));
    }

    private function recordFailure(?TraceContext $span, \DateTimeImmutable $startedAt, float $start, string $errorClass): void
    {
        if ($span === null) {
            return;
        }

        $this->recorder->record(
            $span,
            new StepRecord('embed', $startedAt, $this->elapsedMs($start), StepOutcome::Failed, $errorClass),
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

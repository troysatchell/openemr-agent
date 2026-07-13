<?php

/**
 * Input-keyed vendor replay transport for the eval gate (W2_ARCHITECTURE.md
 * §7; PS-2).
 *
 * The eval gate's PS-1 statement is: vendor boundary fixture-stubbed at the
 * injectable transport, everything else — DB, module code, routing,
 * verification — runs for real. This class IS that stub, and it sits at the
 * exact seam production clients inject a transport at: the same
 * `\Closure(array<string, mixed>): array{int, array<string, mixed>}` shape
 * `AnthropicLlmClient` takes (see its constructor docblock), and the shape the
 * Cohere embed and Cohere rerank transports take. Anthropic vision, Anthropic
 * text, Cohere embed, and Cohere rerank all replay through this one class —
 * one seam, one contract, no per-vendor special-casing in the gate.
 *
 * It is deliberately NOT a fixed-output double. A fixed-output double answers
 * every call the same way regardless of what was sent, which would let a
 * data-trust bug upstream (garbled text, a mis-mapped field, a corrupted
 * extraction) silently reach a vendor call and still get back a canned
 * "correct" response — the exact failure mode PS-2 exists to close. Instead
 * this transport keys on a content hash of the CANONICALIZED request body: it
 * recursively sorts associative-array keys (so key order can never dodge the
 * seam) while preserving list order (lists are ordered data — reordering a
 * list of chunk ids or messages is a different request, not the same one
 * restated). An unseen key throws {@see UnexpectedVendorCallException} — no
 * default fallback, no silent fixture regeneration — so an input-side
 * regression turns into a red gate instead of a green pass on a stale
 * fixture.
 *
 * Recorded fixtures are committed, reproducible artifacts: given the same
 * fixture set and the same (canonicalized) inputs, replay is deterministic
 * across machines and CI runs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final class InputKeyedReplayTransport
{
    /**
     * Hashes of every request this transport has successfully replayed, in
     * call order. Only recorded on a hit (see __invoke) — an unseen key
     * throws before anything is logged, since there is nothing to record: the
     * gate assertion this backs is "these known requests were made," not "an
     * unknown request was attempted."
     *
     * @var list<string>
     */
    private array $seenKeys = [];

    /**
     * @param array<string, array{int, array<string, mixed>}> $fixtures
     *        Recorded responses keyed by {@see keyFor()} hash: hash => [HTTP
     *        status, decoded JSON response body].
     */
    private function __construct(private readonly array $fixtures)
    {
    }

    /**
     * Boundary-validates a raw fixture map and builds the transport.
     *
     * Fixtures are typically authored/loaded as loosely-typed arrays (from a
     * committed PHP or JSON file), so the shape is enforced here rather than
     * trusted from a type declaration: every key must be a non-blank string
     * (a hash produced by {@see keyFor()}), and every value must be an
     * exactly-two-element list of [int status, array body]. Malformed
     * fixture data fails at construction, not at first use.
     *
     * @param array<array-key, mixed> $fixtures
     */
    public static function fromFixtures(array $fixtures): self
    {
        /** @var array<string, array{int, array<string, mixed>}> $validated */
        $validated = [];

        foreach ($fixtures as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                throw new \DomainException('InputKeyedReplayTransport fixture keys must be non-blank strings (a keyFor() hash)');
            }

            if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
                throw new \DomainException(sprintf('InputKeyedReplayTransport fixture for key "%s" must be a [status, body] pair', $key));
            }

            [$status, $body] = $value;

            if (!is_int($status)) {
                throw new \DomainException(sprintf('InputKeyedReplayTransport fixture for key "%s" must carry an int HTTP status as element 0', $key));
            }

            if (!is_array($body)) {
                throw new \DomainException(sprintf('InputKeyedReplayTransport fixture for key "%s" must carry an array response body as element 1', $key));
            }

            /** @var array<string, mixed> $body */
            $validated[$key] = [$status, $body];
        }

        return new self($validated);
    }

    /**
     * Canonicalizes a request body and returns its sha256 hex digest.
     *
     * Canonicalization recursively key-sorts every ASSOCIATIVE array (so two
     * requests differing only in key order hash identically — key order
     * can never dodge the seam) while leaving every LIST array's element
     * order untouched (lists are ordered data: a reordered list of messages
     * or candidate chunk ids is a different request, not a restatement of
     * the same one). The canonical form is then JSON-encoded with stable
     * flags and hashed.
     *
     * @param array<string, mixed> $requestBody
     */
    public static function keyFor(array $requestBody): string
    {
        $canonical = self::canonicalize($requestBody);
        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $json);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        $canonicalizeItem = static fn (mixed $item): mixed => is_array($item) ? self::canonicalize($item) : $item;

        if (array_is_list($value)) {
            return array_map($canonicalizeItem, $value);
        }

        ksort($value);

        return array_map($canonicalizeItem, $value);
    }

    /**
     * Replays the recorded response for this request's canonical hash.
     *
     * Throws {@see UnexpectedVendorCallException} for an unseen key rather
     * than falling back to any default — PS-2's "no silent fixture
     * regeneration" guarantee. The exception message carries the hash only;
     * the request body (which may carry PHI) is never embedded in it.
     *
     * @param array<string, mixed> $requestBody
     * @return array{int, array<string, mixed>}
     */
    public function __invoke(array $requestBody): array
    {
        $key = self::keyFor($requestBody);

        if (!array_key_exists($key, $this->fixtures)) {
            throw new UnexpectedVendorCallException(
                sprintf('unexpected vendor call: no fixture recorded for request hash %s', $key),
            );
        }

        $this->seenKeys[] = $key;

        return $this->fixtures[$key];
    }

    /**
     * The hashes of every request successfully replayed so far, in call
     * order — the gate's assertion surface for "these specific vendor calls
     * were made" (e.g. asserting the snapshot path made zero retrieval
     * calls, or that a given evidence lookup fired exactly once).
     *
     * @return list<string>
     */
    public function seenKeys(): array
    {
        return $this->seenKeys;
    }
}

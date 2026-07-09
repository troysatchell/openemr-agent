<?php

/**
 * FROZEN acceptance tests — T18: Anthropic-direct LlmClient adapter (C5;
 * R6/R10/R11; ARCHITECTURE.md §2/§3.4/§4; endpoint decision 2026-07-09).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the adapter turns one LlmTurnRequest into one
 * Anthropic Messages API call through an injected transport closure
 * (\Closure(array $requestBody): array{int, array<string, mixed>} — status
 * code + decoded JSON body), so the wire contract is testable without a
 * network and the live call stays integration-only. The LLM sits OUTSIDE
 * the trust boundary: only the already-built DisclosedPayload crosses, the
 * response is parsed into DraftClaims whose citation tokens the verifier
 * grounds downstream — the model's prose is never trusted here. Token
 * usage is captured on first write (this adapter is the ONLY source of
 * token/cost data) with the model id taken from the RESPONSE — the audit
 * trail records what actually served, not what was requested. Transient
 * failure (429/5xx/529, transport faults, refusals, unparseable output)
 * maps to LlmUnavailableException so the orchestrator degrades honestly;
 * configuration errors (400/401/403/404) propagate — a misconfigured
 * deployment must fail loudly, never masquerade as a degraded turn.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Llm;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Llm\AnthropicLlmClient;
use OpenEMR\Modules\Copilot\Llm\DisclosedPayload;
use OpenEMR\Modules\Copilot\Llm\LlmTurnRequest;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use PHPUnit\Framework\TestCase;

class AnthropicLlmClientTest extends TestCase
{
    private const QUESTION = 'Is the anticoagulation current?';

    private static function request(): LlmTurnRequest
    {
        $payload = new DisclosedPayload(
            [
                'medications' => [
                    ['name' => 'Warfarin 5mg Tablet', 'status' => 'current', 'ref' => 'lists:med-warf'],
                ],
            ],
            new Disclosure(
                'ellis.tran',
                42,
                ['medications'],
                'followup_qa',
                new \DateTimeImmutable('2026-07-09 09:00:00'),
                'corr-test-1',
            ),
        );

        return new LlmTurnRequest(
            $payload,
            self::QUESTION,
            ['Q: On a blood thinner? A: Yes, warfarin.'],
            'corr-test-1',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function ok(array $body): \Closure
    {
        return static fn (array $requestBody): array => [200, $body];
    }

    /**
     * @param list<array{text: string, refs: list<string>}> $claims
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function messageBody(array $claims, array $overrides = []): array
    {
        return array_merge(
            [
                'model' => 'claude-served-model',
                'content' => [
                    ['type' => 'text', 'text' => json_encode(['claims' => $claims], JSON_THROW_ON_ERROR)],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
            ],
            $overrides,
        );
    }

    private static function client(\Closure $transport): AnthropicLlmClient
    {
        return new AnthropicLlmClient($transport, 'claude-test-model', 5.0, 25.0);
    }

    // ── The wire request ────────────────────────────────────────────────────

    public function testTheRequestCarriesModelPayloadQuestionAndJsonSchema(): void
    {
        $captured = null;
        $transport = static function (array $requestBody) use (&$captured): array {
            $captured = $requestBody;

            return [200, self::messageBody([['text' => 'On warfarin.', 'refs' => ['lists:med-warf']]])];
        };

        self::client($transport)->complete(self::request());

        $this->assertIsArray($captured);
        $this->assertSame('claude-test-model', $captured['model'], 'The model id is configuration, never hard-coded in business logic.');
        $this->assertIsInt($captured['max_tokens']);
        $this->assertGreaterThan(0, $captured['max_tokens']);
        $this->assertIsString($captured['system']);
        $this->assertNotSame('', trim($captured['system']));
        $this->assertSame(
            'json_schema',
            $captured['output_config']['format']['type'],
            'Claims come back schema-constrained — prose scraping is not a contract.',
        );

        $this->assertIsArray($captured['messages']);
        $this->assertSame('user', $captured['messages'][0]['role']);
        $serialized = json_encode($captured['messages'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Warfarin 5mg Tablet', $serialized, 'The DisclosedPayload is the only fact source and must cross.');
        $this->assertStringContainsString('lists:med-warf', $serialized, 'Citation tokens must reach the model or nothing can be grounded.');
        $this->assertStringContainsString(self::QUESTION, $serialized);
        $this->assertStringContainsString('On a blood thinner?', $serialized, 'Prior turns ride along as phrasing context.');
    }

    // ── Parsing the response ───────────────────────────────────────────────

    public function testClaimsAreParsedWithTheirCitationTokens(): void
    {
        $client = self::client(self::ok(self::messageBody([
            ['text' => 'On warfarin 5mg.', 'refs' => ['lists:med-warf']],
            ['text' => 'No interactions noted in the provided data.', 'refs' => []],
        ])));

        $response = $client->complete(self::request());

        $this->assertCount(2, $response->claims);
        $this->assertSame('On warfarin 5mg.', $response->claims[0]->text, 'Claim text crosses byte-identical — untrusted, unedited.');
        $this->assertSame(['lists:med-warf'], $response->claims[0]->sourceIds);
        $this->assertSame([], $response->claims[1]->sourceIds, 'Uncited filler survives parsing so the VERIFIER can reject it — the adapter never censors.');
    }

    public function testTokenUsageIsCapturedWithServedModelAndComputedCost(): void
    {
        $client = self::client(self::ok(self::messageBody([['text' => 'On warfarin.', 'refs' => ['lists:med-warf']]])));

        $response = $client->complete(self::request());

        $this->assertNotNull($response->tokenUsage, 'This adapter is the only source of token/cost data — capture is non-negotiable.');
        $this->assertSame('claude-served-model', $response->tokenUsage->modelId, 'The audit trail records what actually served, not what was requested.');
        $this->assertSame(1200, $response->tokenUsage->inputTokens);
        $this->assertSame(300, $response->tokenUsage->outputTokens);
        // 1200/1M * $5 + 300/1M * $25 = 0.006 + 0.0075
        $this->assertEqualsWithDelta(0.0135, $response->tokenUsage->costUsd, 1e-12);
    }

    // ── Transient failure → LlmUnavailableException (degrade honestly) ─────

    public function testRetryableHttpStatusesMapToLlmUnavailable(): void
    {
        foreach ([429, 500, 529] as $status) {
            $client = self::client(static fn (array $b): array => [$status, ['type' => 'error', 'error' => ['type' => 'x', 'message' => 'vendor-internals']]]);
            try {
                $client->complete(self::request());
                self::fail(sprintf('HTTP %d is transient — the orchestrator must get LlmUnavailableException to degrade honestly.', $status));
            } catch (LlmUnavailableException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testTransportFailureMapsToLlmUnavailableWithoutLeakingInternals(): void
    {
        $original = new \RuntimeException('connection refused to 10.0.0.7:443');
        $client = self::client(static fn (array $b): array => throw $original);

        try {
            $client->complete(self::request());
            self::fail('A transport fault is transient unavailability.');
        } catch (LlmUnavailableException $e) {
            $this->assertStringNotContainsString('10.0.0.7', $e->getMessage(), 'Wrap with a generic message; internals live on getPrevious() only.');
            $this->assertSame($original, $e->getPrevious());
        }
    }

    public function testARefusalDegradesInsteadOfPretendingToAnswer(): void
    {
        $client = self::client(self::ok([
            'model' => 'claude-served-model',
            'content' => [],
            'stop_reason' => 'refusal',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ]));

        $this->expectException(LlmUnavailableException::class);
        $client->complete(self::request());
    }

    public function testUnparseableModelOutputDegradesInsteadOfGuessing(): void
    {
        $client = self::client(self::ok(self::messageBody([], [
            'content' => [['type' => 'text', 'text' => 'not-json {{{']],
        ])));

        $this->expectException(LlmUnavailableException::class);
        $client->complete(self::request());
    }

    public function testAMissingUsageBlockIsAMalformedResponse(): void
    {
        $body = self::messageBody([['text' => 'On warfarin.', 'refs' => ['lists:med-warf']]]);
        unset($body['usage']);

        $this->expectException(LlmUnavailableException::class);
        self::client(self::ok($body))->complete(self::request());
    }

    // ── Config errors propagate — never masquerade as degradation ──────────

    public function testAuthAndRequestErrorsPropagateAsConfigFailures(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            $client = self::client(static fn (array $b): array => [$status, ['type' => 'error', 'error' => ['type' => 'x', 'message' => 'm']]]);
            try {
                $client->complete(self::request());
                self::fail(sprintf('HTTP %d is a configuration error — it must propagate loudly, not degrade quietly.', $status));
            } catch (LlmUnavailableException) {
                self::fail(sprintf('HTTP %d must NOT be treated as transient unavailability.', $status));
            } catch (\DomainException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testConstructionRefusesNonsenseConfiguration(): void
    {
        try {
            new AnthropicLlmClient(static fn (array $b): array => [200, []], ' ', 5.0, 25.0);
            self::fail('A blank model id is a wiring error.');
        } catch (\DomainException) {
        }

        $this->expectException(\DomainException::class);
        new AnthropicLlmClient(static fn (array $b): array => [200, []], 'claude-test-model', -1.0, 25.0);
    }
}

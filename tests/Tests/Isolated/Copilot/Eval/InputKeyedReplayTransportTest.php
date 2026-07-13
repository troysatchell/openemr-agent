<?php

/**
 * FROZEN acceptance tests — TRO-38: input-keyed vendor replay (W2_ARCHITECTURE §7; PS-2).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the eval gate's vendor stubs are input-keyed replays,
 * not fixed-output doubles. The transport keys on a content hash of the
 * CANONICALIZED request (recursively key-sorted JSON — key order can never
 * dodge the seam) and returns the recorded response for that input. An unseen
 * key THROWS UnexpectedVendorCallException — no default fallback, no silent
 * fixture regeneration — and the exception message carries the HASH only,
 * never the request body (requests carry PHI). This is what makes input-side
 * regressions fail: garbled text handed to a vendor produces an unseen key
 * and a red gate, not a canned answer and a green one.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Eval;

use OpenEMR\Modules\Copilot\Eval\InputKeyedReplayTransport;
use OpenEMR\Modules\Copilot\Eval\UnexpectedVendorCallException;
use PHPUnit\Framework\TestCase;

class InputKeyedReplayTransportTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function request(): array
    {
        return [
            'model' => 'claude-opus-4-8',
            'messages' => [['role' => 'user', 'content' => 'What supports the statin recommendation?']],
        ];
    }

    public function testRecordedInputReplaysItsResponse(): void
    {
        $key = InputKeyedReplayTransport::keyFor($this->request());
        $transport = InputKeyedReplayTransport::fromFixtures([
            $key => [200, ['content' => [['type' => 'text', 'text' => 'recorded']]]],
        ]);

        [$status, $body] = $transport($this->request());

        $this->assertSame(200, $status);
        $this->assertSame('recorded', $body['content'][0]['text']);
    }

    public function testKeyOrderCannotDodgeTheSeam(): void
    {
        $reordered = [
            'messages' => [['content' => 'What supports the statin recommendation?', 'role' => 'user']],
            'model' => 'claude-opus-4-8',
        ];

        $this->assertSame(
            InputKeyedReplayTransport::keyFor($this->request()),
            InputKeyedReplayTransport::keyFor($reordered),
            'canonicalization must sort keys recursively',
        );
    }

    public function testListOrderIsSignificant(): void
    {
        $a = ['candidates' => ['chunk-1', 'chunk-2']];
        $b = ['candidates' => ['chunk-2', 'chunk-1']];

        $this->assertNotSame(
            InputKeyedReplayTransport::keyFor($a),
            InputKeyedReplayTransport::keyFor($b),
            'lists are ordered data; reordering them is a different request',
        );
    }

    public function testUnseenInputThrowsInsteadOfDefaulting(): void
    {
        $transport = InputKeyedReplayTransport::fromFixtures([
            InputKeyedReplayTransport::keyFor($this->request()) => [200, ['ok' => true]],
        ]);

        $corrupted = $this->request();
        $corrupted['messages'][0]['content'] = 'Wh4t supp0rts the st4tin rec0mmendation?';

        $this->expectException(UnexpectedVendorCallException::class);
        $transport($corrupted);
    }

    public function testUnseenKeyExceptionCarriesTheHashNeverTheBody(): void
    {
        $transport = InputKeyedReplayTransport::fromFixtures([]);
        $phi = ['messages' => [['role' => 'user', 'content' => 'Potassium 6.8 mmol/L for Jane Doe']]];

        try {
            $transport($phi);
            $this->fail('expected UnexpectedVendorCallException');
        } catch (UnexpectedVendorCallException $e) {
            $this->assertStringContainsString(InputKeyedReplayTransport::keyFor($phi), $e->getMessage());
            $this->assertStringNotContainsString('Potassium', $e->getMessage());
            $this->assertStringNotContainsString('Jane', $e->getMessage());
        }
    }

    public function testSeenKeysAreRecordedForGateAssertions(): void
    {
        $key = InputKeyedReplayTransport::keyFor($this->request());
        $transport = InputKeyedReplayTransport::fromFixtures([$key => [200, ['ok' => true]]]);

        $transport($this->request());
        $transport($this->request());

        $this->assertSame([$key, $key], $transport->seenKeys());
    }

    public function testWorksAsATransportClosureForTheExistingClients(): void
    {
        // The existing clients take \Closure transports; the replay must
        // convert cleanly so the gate stubs at the SAME seam production uses.
        $key = InputKeyedReplayTransport::keyFor($this->request());
        $transport = InputKeyedReplayTransport::fromFixtures([$key => [200, ['ok' => true]]]);

        $closure = \Closure::fromCallable($transport);
        [$status] = $closure($this->request());

        $this->assertSame(200, $status);
    }
}

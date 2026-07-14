<?php

/**
 * FROZEN acceptance tests — Wave K.2: the live evidence wiring + the
 * browser upload wire (string-pinned committed-artifact contracts, like
 * the demo-surface and gate-workflow contracts).
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Pins:
 *
 *  1. panel.html sends `ask_evidence` (an explicit clinician toggle,
 *     id="ask-evidence") on the turn — UC7's evidence request is a stated
 *     intent, never an invented NLP classification — and uses the
 *     `file_content_b64` wire for uploads (a browser can actually send it).
 *  2. Bootstrap composes the SUPERVISED path for the live turn route:
 *     Supervisor + SupervisedTurnDispatcher + the real
 *     EvidenceRetrieverWorkerImpl, with Cohere embed/rerank on a real HTTP
 *     transport (CohereHttpTransport) keyed from getenv('COHERE_API_KEY')
 *     — env read via getenv, never $_ENV (variables_order gotcha). A
 *     missing key must compose the degraded pair (PS-12), never fail the
 *     route.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use PHPUnit\Framework\TestCase;

class EvidenceWiringContractTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot';
    private const PANEL_PATH = self::MODULE_DIR . '/public/panel.html';
    private const BOOTSTRAP_PATH = self::MODULE_DIR . '/src/Bootstrap.php';
    private const COHERE_TRANSPORT_PATH = self::MODULE_DIR . '/src/Rag/CohereHttpTransport.php';

    private static function read(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        return $raw;
    }

    public function testPanelSendsExplicitEvidenceIntentAndBrowserUploadWire(): void
    {
        $panel = self::read(self::PANEL_PATH);

        $this->assertStringContainsString('id="ask-evidence"', $panel, 'the evidence request is an explicit clinician toggle');
        $this->assertStringContainsString('ask_evidence', $panel, 'the toggle rides the turn wire');
        $this->assertStringContainsString('file_content_b64', $panel, 'uploads send content a browser can actually produce');
    }

    public function testLiveTurnRouteComposesTheSupervisedPath(): void
    {
        $bootstrap = self::read(self::BOOTSTRAP_PATH);

        $this->assertStringContainsString('ask_evidence', $bootstrap, 'the route consumes the explicit evidence intent');
        $this->assertStringContainsString('SupervisedTurnDispatcher', $bootstrap, 'the supervised graph runs live, not only in the gate');
        $this->assertStringContainsString('EvidenceRetrieverWorkerImpl', $bootstrap, 'the REAL retrieval worker — worker stubs never leave unit tests');
        $this->assertStringContainsString('CohereHttpTransport', $bootstrap, 'embed/rerank ride a real HTTP transport in the live composition');
    }

    public function testCohereTransportReadsItsKeyViaGetenv(): void
    {
        $this->assertFileExists(self::COHERE_TRANSPORT_PATH, 'the production Cohere transport is a committed class');
        $transport = self::read(self::COHERE_TRANSPORT_PATH);

        $this->assertStringContainsString('getenv', $transport, "env read via getenv — \$_ENV is empty under variables_order=GPCS");
        $this->assertStringContainsString('COHERE_API_KEY', $transport);
        $this->assertStringContainsString('api.cohere', $transport, 'the real vendor endpoint, mirrored on the AnthropicLlmClient pattern');
    }
}

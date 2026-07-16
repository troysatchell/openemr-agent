<?php

/**
 * FROZEN acceptance tests — Wave N (TRO-52/TRO-53): string-pinned
 * committed-artifact contracts for the SMART-launch wiring (same style as
 * EvidenceWiringContractTest / DemoSurfaceContractTest).
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file.
 *
 * RE-FROZEN 2026-07-15 (orchestrator, documented per the freeze protocol):
 * the founder decided D2 = **confidential client**, which the original pins
 * predated. A confidential client authenticates with a secret, so the code
 * exchange moves server-side and the PKCE verifier stays in the PHP session
 * — the panel no longer carries `code_verifier`, and a new server endpoint
 * (public/launch-exchange.php) performs the exchange. This is a
 * design-decision-driven contract change, NOT greening a red gate: no
 * behaviour was relaxed, the binding + PKCE + issuer-validation guarantees
 * are unchanged. Pins:
 *
 *  1. Bootstrap wires LaunchPatientBinding into the clinical routes from the
 *     token's launch-context patient (HttpRestRequest::getPatientUUIDString())
 *     and refuses with 403 — before any chart read (TRO-52).
 *  2. A committed launch entry page (public/launch.php) exists and drives
 *     the handshake through the tested pure core (AuthorizeRedirect +
 *     PkcePair) after the sanctioned session bootstrap (globals.php) — the
 *     issuer is validated, never trusted; the verifier is held in the PHP
 *     session, never handed to the browser (TRO-53).
 *  3. A committed server-side exchange endpoint (public/launch-exchange.php)
 *     performs the CONFIDENTIAL code exchange — session-bound, holding the
 *     client secret (COPILOT_SMART_CLIENT_SECRET) and the authorization_code
 *     grant server-side (TRO-53, D2-confidential).
 *  4. The panel completes the launch by calling that endpoint (never the
 *     token endpoint directly, never a client secret in the browser) and
 *     never asks for base/token/UUID in launch mode (TRO-53).
 *
 * RE-FROZEN 2026-07-16 (documented per the freeze protocol): the founder
 * directed an on-arrival glanceable snapshot for the launched panel, adding
 * a FOURTH clinical route (POST /api/copilot/snapshot) that — correctly —
 * enforces the same launch-patient binding as turn/document/source. The
 * binding count pin moves 3 -> 4 to match the deliberately grown surface.
 * Design-decision-driven contract change, NOT greening a red gate: the
 * binding guarantee itself is unchanged, and NOT binding the new route
 * would have been the actual regression.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Routes;

use PHPUnit\Framework\TestCase;

class LaunchBindingWiringContractTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot';
    private const BOOTSTRAP_PATH = self::MODULE_DIR . '/src/Bootstrap.php';
    private const LAUNCH_PATH = self::MODULE_DIR . '/public/launch.php';
    private const EXCHANGE_PATH = self::MODULE_DIR . '/public/launch-exchange.php';
    private const EXCHANGER_PATH = self::MODULE_DIR . '/src/Panel/SmartLaunch/ConfidentialTokenExchanger.php';
    private const PANEL_PATH = self::MODULE_DIR . '/public/panel.html';

    private static function read(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        return $raw;
    }

    public function testClinicalRoutesEnforceTheLaunchPatientBinding(): void
    {
        $bootstrap = self::read(self::BOOTSTRAP_PATH);

        $this->assertStringContainsString('LaunchPatientBinding', $bootstrap, 'the binding guard is composed in the route layer');
        $this->assertStringContainsString('getPatientUUIDString', $bootstrap, 'the bound patient comes from the token launch context, never the caller');
        $this->assertStringContainsString('LaunchPatientAccessDeniedException', $bootstrap, 'binding refusals are caught distinctly');
        // Each clinical route is individually declared AND the enforce count
        // matches their number, so an extra enforce on one route can never
        // mask a missing declaration or an unguarded route.
        foreach (['turn', 'snapshot', 'document', 'source'] as $route) {
            $this->assertStringContainsString("'POST /api/copilot/{$route}'", $bootstrap, "the {$route} route is declared");
        }
        $this->assertSame(
            4,
            substr_count($bootstrap, '->enforce('),
            'exactly the four clinical routes (turn, snapshot, document, source) enforce the binding'
        );
    }

    public function testLaunchEntryPageDrivesTheTestedHandshakeCore(): void
    {
        $this->assertFileExists(self::LAUNCH_PATH, 'the SMART EHR-launch entry page is a committed artifact');
        $launch = self::read(self::LAUNCH_PATH);

        $this->assertStringContainsString('globals.php', $launch, 'sanctioned module-page session bootstrap (cf. ajax.php)');
        $this->assertStringContainsString('AuthorizeRedirect', $launch, 'issuer validation + redirect building go through the tested pure core');
        $this->assertStringContainsString('PkcePair', $launch, 'PKCE pair from the tested generator, not ad-hoc crypto');
        $this->assertStringNotContainsString(
            'sessionStorage',
            $launch,
            'D2-confidential: the PKCE verifier is held in the PHP session, never handed to the browser'
        );
    }

    public function testServerSideExchangeIsConfidential(): void
    {
        $this->assertFileExists(self::EXCHANGE_PATH, 'the confidential code-exchange endpoint is a committed artifact');
        $exchange = self::read(self::EXCHANGE_PATH);

        $this->assertStringContainsString('globals.php', $exchange, 'the exchange is session-bound (holds the verifier + validates state against the session)');
        $this->assertStringContainsString('COPILOT_SMART_CLIENT_SECRET', $exchange, 'a confidential client authenticates with its secret — sourced server-side, never from the browser');
        $this->assertStringContainsString('ConfidentialTokenExchanger', $exchange, 'the endpoint delegates the token request to the confidential exchanger');

        // The confidential grant params live in the HTTP layer.
        $this->assertFileExists(self::EXCHANGER_PATH, 'the confidential token exchanger is a committed artifact');
        $exchanger = self::read(self::EXCHANGER_PATH);
        $this->assertStringContainsString("'client_secret'", $exchanger, 'the token request carries the client secret (confidential grant)');
        $this->assertStringContainsString('authorization_code', $exchanger, 'the authorization-code grant is exchanged server-side, not in the browser');
        $this->assertStringContainsString('code_verifier', $exchanger, 'PKCE is retained: the session verifier proves possession');
    }

    public function testPanelCompletesTheLaunchViaTheServerExchange(): void
    {
        $panel = self::read(self::PANEL_PATH);

        $this->assertStringContainsString('launch-exchange.php', $panel, 'the panel completes the launch through the server-side exchange endpoint');
        $this->assertStringContainsString('launch-mode', $panel, 'launch mode is an explicit panel state (Connection fieldset hidden — no base/token/UUID entry)');
        $this->assertStringNotContainsString(
            'client_secret',
            $panel,
            'D2-confidential: the client secret never appears in browser JS'
        );
        $this->assertStringNotContainsString(
            'code_verifier',
            $panel,
            'the PKCE verifier stays server-side — the browser never sees it under the confidential flow'
        );
    }
}

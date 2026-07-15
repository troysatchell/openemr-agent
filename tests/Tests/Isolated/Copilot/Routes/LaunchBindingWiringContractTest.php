<?php

/**
 * FROZEN acceptance tests — Wave N (TRO-52/TRO-53): string-pinned
 * committed-artifact contracts for the SMART-launch wiring (same style as
 * EvidenceWiringContractTest / DemoSurfaceContractTest).
 *
 * Authored by the orchestrator and frozen: implementation agents make these
 * pass and MUST NOT modify this file. Pins:
 *
 *  1. Bootstrap wires LaunchPatientBinding into the clinical routes from the
 *     token's launch-context patient (HttpRestRequest::getPatientUUIDString())
 *     and refuses with 403 — before any chart read (TRO-52).
 *  2. A committed launch entry page (public/launch.php) exists and drives
 *     the handshake through the tested pure core (AuthorizeRedirect +
 *     PkcePair) after the sanctioned session bootstrap (globals.php) — the
 *     issuer is validated, never trusted (TRO-53).
 *  3. The panel completes the code exchange with PKCE (code_verifier) and
 *     never asks for base/token/UUID in launch mode (TRO-53).
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
        $this->assertSame(
            3,
            substr_count($bootstrap, '->enforce('),
            'exactly the three clinical routes (turn, document, source) enforce the binding'
        );
    }

    public function testLaunchEntryPageDrivesTheTestedHandshakeCore(): void
    {
        $this->assertFileExists(self::LAUNCH_PATH, 'the SMART EHR-launch entry page is a committed artifact');
        $launch = self::read(self::LAUNCH_PATH);

        $this->assertStringContainsString('globals.php', $launch, 'sanctioned module-page session bootstrap (cf. ajax.php)');
        $this->assertStringContainsString('AuthorizeRedirect', $launch, 'issuer validation + redirect building go through the tested pure core');
        $this->assertStringContainsString('PkcePair', $launch, 'PKCE pair from the tested generator, not ad-hoc crypto');
    }

    public function testPanelCompletesThePkceExchangeWithoutManualCredentials(): void
    {
        $panel = self::read(self::PANEL_PATH);

        $this->assertStringContainsString('code_verifier', $panel, 'the code exchange carries the PKCE verifier');
        $this->assertStringContainsString('launch-mode', $panel, 'launch mode is an explicit panel state (Connection fieldset hidden — no base/token/UUID entry)');
    }
}

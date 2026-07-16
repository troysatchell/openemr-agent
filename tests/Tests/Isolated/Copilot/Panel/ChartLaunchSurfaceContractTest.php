<?php

/**
 * Acceptance tests — one-click chart launch, committed-artifact contracts
 * (the DemoSurfaceContractTest pattern: string-pinned commitments about the
 * launch redirect page and the bootstrap wiring, which are procedural files
 * the isolated suite cannot execute).
 *
 * Contract under test: `public/launch-from-chart.php` is a thin,
 * session-bound redirect that mints the SAME SMART launch context the chart
 * card mints (core SMARTLaunchToken, patient uuid from the SESSION pid —
 * never a request parameter) and hands off to the existing launch.php flow;
 * failures render the fixed generic page (R11 — no exception text). The
 * module bootstrap registers the PatientMenuEvent MENU_UPDATE listener that
 * places the entry in the chart's left menu.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use PHPUnit\Framework\TestCase;

class ChartLaunchSurfaceContractTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-copilot';
    private const PAGE_PATH = self::MODULE_DIR . '/public/launch-from-chart.php';
    private const BOOTSTRAP_PATH = self::MODULE_DIR . '/openemr.bootstrap.php';

    private static function page(): string
    {
        $raw = file_get_contents(self::PAGE_PATH);
        self::assertIsString($raw);

        return $raw;
    }

    private static function moduleBootstrap(): string
    {
        $raw = file_get_contents(self::BOOTSTRAP_PATH);
        self::assertIsString($raw);

        return $raw;
    }

    public function testRedirectPageMintsTheChartCardsLaunchContextFromTheSession(): void
    {
        $page = self::page();

        $this->assertStringContainsString("require_once __DIR__ . '/../../../../globals.php'", $page, 'the sanctioned module-page session bootstrap — same posture as launch.php');
        $this->assertStringContainsString('PatientSessionUtil::getPid()', $page, 'the patient comes from the SESSION - the same active-patient context the chart shows');
        $this->assertStringNotContainsString('INPUT_GET', $page, 'no request-supplied patient: a caller must never choose whose chart is launched');
        $this->assertStringNotContainsString('$_GET', $page, 'no raw superglobal reads at all');
        $this->assertStringContainsString('SMARTLaunchToken', $page, 'the launch context is the SAME core token the SMART card mints — one launch vocabulary');
        $this->assertStringContainsString('launch.php?launch=', $page, 'hands off to the existing launch flow — no second handshake implementation');
        $this->assertStringContainsString('ServerConfig', $page, 'iss comes from ServerConfig, never a hardcoded host');
    }

    public function testRedirectPageFailsGenericNeverEchoingInternals(): void
    {
        $page = self::page();

        $this->assertStringContainsString('$fail(', $page, 'failures render the fixed generic page');
        $this->assertStringNotContainsString('getMessage()', $page, 'exception text never reaches the browser (R11)');
        $this->assertStringContainsString('open a patient', strtolower($page), 'the no-active-patient failure tells the user the actionable step');
    }

    public function testModuleBootstrapPlacesTheEntryInThePatientChartMenu(): void
    {
        $bootstrap = self::moduleBootstrap();

        $this->assertStringContainsString('PatientMenuEvent::MENU_UPDATE', $bootstrap, 'the chart-menu listener is registered');
        $this->assertStringContainsString('ChartMenuItem::appendTo', $bootstrap, 'the entry is built by the tested menu class, not inline');
        $this->assertStringContainsString("xlt('Co-Pilot')", $bootstrap, 'translation happens at the wiring layer, where the translation globals exist');
    }
}

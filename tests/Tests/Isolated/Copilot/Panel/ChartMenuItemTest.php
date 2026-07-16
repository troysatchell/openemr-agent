<?php

/**
 * Acceptance tests — one-click chart launch: the "Co-Pilot" entry in the
 * patient chart's left menu (Dashboard / History / ... / External Data).
 *
 * Contract under test: ChartMenuItem::appendTo() adds exactly one entry to
 * the PatientMenuEvent menu array, shaped like the standard patient-menu
 * items (standard.json: target "main", top.restoreSession() on click,
 * requirement 0), pointing at the module's launch-from-chart redirect page,
 * and ACL'd identically to the existing main-menu tab (patients/med) so
 * MENU restriction hides it from users who cannot open charts. The label
 * arrives pre-translated from the wiring layer — this class never calls
 * the translation globals, so the isolated suite can exercise it.
 *
 * Timing constraint pinned here because it is easy to lose: the
 * PatientMenuEvent MENU_UPDATE dispatch happens AFTER PatientMenuRole has
 * already webroot-prefixed and translated the JSON menu, so an event-added
 * item must carry an absolute (webroot-rooted) url of its own.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use OpenEMR\Modules\Copilot\Panel\ChartMenuItem;
use PHPUnit\Framework\TestCase;

class ChartMenuItemTest extends TestCase
{
    /**
     * @return list<\stdClass>
     */
    private static function standardMenu(): array
    {
        $dashboard = new \stdClass();
        $dashboard->label = 'Dashboard';
        $dashboard->menu_id = 'dashboard';
        $dashboard->url = '/interface/patient_file/summary/demographics.php';

        $report = new \stdClass();
        $report->label = 'Report';
        $report->menu_id = 'report';
        $report->url = '/interface/patient_file/report/patient_report.php';

        return [$dashboard, $report];
    }

    public function testAppendsExactlyOneCopilotEntryAfterTheExistingItems(): void
    {
        $menu = self::standardMenu();

        $updated = ChartMenuItem::appendTo($menu, 'Co-Pilot');

        $this->assertCount(3, $updated);
        $this->assertSame('dashboard', $updated[0]->menu_id, 'existing items keep their order');
        $this->assertSame('report', $updated[1]->menu_id);
        $this->assertSame('copilot-launch', $updated[2]->menu_id, 'the co-pilot entry lands at the end of the chart menu');
    }

    public function testEntryIsShapedLikeAStandardPatientMenuItem(): void
    {
        $item = ChartMenuItem::appendTo([], 'Co-Pilot')[0];

        $this->assertSame('Co-Pilot', $item->label, 'the label arrives pre-translated from the wiring layer — never re-translated here');
        $this->assertSame('main', $item->target, 'loads in the chart content frame like Dashboard/History');
        $this->assertSame('top.restoreSession()', $item->on_click);
        $this->assertSame(0, $item->requirement);
        $this->assertSame('false', $item->pid);
        $this->assertSame([], $item->children);
        $this->assertSame(
            '/interface/modules/custom_modules/oe-module-copilot/public/launch-from-chart.php',
            $item->url,
            'absolute url: MENU_UPDATE fires after PatientMenuRole webroot-prefixing, so the item must be webroot-rooted already',
        );
    }

    public function testUrlCarriesTheConfiguredWebrootOnSubdirectoryInstalls(): void
    {
        $item = ChartMenuItem::appendTo([], 'Co-Pilot', '/openemr')[0];

        $this->assertSame(
            '/openemr/interface/modules/custom_modules/oe-module-copilot/public/launch-from-chart.php',
            $item->url,
            'a non-root install prefixes its webroot — the wiring layer passes the globals bag value',
        );
    }

    public function testEntryCarriesTheSameAclAsTheExistingTab(): void
    {
        $item = ChartMenuItem::appendTo([], 'Co-Pilot')[0];

        $this->assertSame(['patients', 'med'], $item->acl_req, 'identical ACL to the main-menu tab: hidden from users who cannot open charts');
    }
}

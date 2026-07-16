<?php

/**
 * The "Co-Pilot" entry in the patient chart's left menu (one-click launch).
 *
 * Appends a single entry — shaped like the standard patient-menu items
 * (interface/main/tabs/menu/menus/patient_menus/standard.json) — that opens
 * the module's launch-from-chart redirect page in the chart's content frame.
 * That page mints the SMART EHR-launch context for the session's active
 * patient, so the click lands in the launched, patient-scoped panel with no
 * token or uuid entry (TRO-53 flow; docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2).
 *
 * Two constraints this class exists to pin:
 *  - PatientMenuRole dispatches MENU_UPDATE AFTER it has webroot-prefixed
 *    and translated the JSON menu, so an event-added item must carry an
 *    absolute (webroot-rooted) url and an already-translated label. The
 *    label is a constructor-style parameter for exactly that reason — the
 *    wiring layer calls xlt(), this class never touches translation globals
 *    (which also keeps it exercisable by the isolated suite).
 *  - The ACL mirrors the module's existing main-menu tab (patients/med):
 *    PatientMenuRole's restriction pass hides the entry from users who
 *    cannot open charts. Menu visibility is convenience, not the guard —
 *    every route the launched panel calls re-checks authorization
 *    server-side (S5, GuardedRouteRegistrar).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel;

final readonly class ChartMenuItem
{
    public const LAUNCH_PAGE_URL = '/interface/modules/custom_modules/oe-module-copilot/public/launch-from-chart.php';

    /**
     * @param list<\stdClass> $menu  The PatientMenuEvent menu array.
     * @param string $translatedLabel The display label, already translated
     *        by the wiring layer (xlt) — passed in because MENU_UPDATE
     *        fires after PatientMenuRole's own translation pass.
     * @param string $webroot The deployment's configured web root (the
     *        globals bag's 'webroot'), prefixed here because MENU_UPDATE
     *        also fires after PatientMenuRole's webroot-prefixing pass —
     *        '' on root installs, '/subdir' when OpenEMR is not at the
     *        domain root.
     *
     * @return list<\stdClass> The menu with the co-pilot entry appended.
     */
    public static function appendTo(array $menu, string $translatedLabel, string $webroot = ''): array
    {
        $item = new \stdClass();
        $item->label = $translatedLabel;
        $item->menu_id = 'copilot-launch';
        $item->target = 'main';
        $item->on_click = 'top.restoreSession()';
        $item->url = $webroot . self::LAUNCH_PAGE_URL;
        $item->pid = 'false';
        $item->children = [];
        $item->requirement = 0;
        $item->acl_req = ['patients', 'med'];

        $menu[] = $item;

        return $menu;
    }
}

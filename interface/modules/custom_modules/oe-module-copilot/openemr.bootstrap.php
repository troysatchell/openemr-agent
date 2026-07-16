<?php

/**
 * Clinical Co-Pilot Module Bootstrap
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Menu\PatientMenuEvent;
use OpenEMR\Modules\Copilot\Bootstrap;
use OpenEMR\Modules\Copilot\Panel\ChartMenuItem;

$file = OEGlobalsBag::getInstance()->getProjectDir();
$classLoader = new ModulesClassLoader($file);
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\Copilot\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$eventDispatcher = OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher();
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();

// The main-menu "Co-Pilot" tab (T21) was removed 2026-07-16 (founder call):
// the patient chart menu's one-click SMART launch below replaced it as the
// physician-facing entry, and the top row lists global sections, not
// patient-scoped tools. public/index.php itself remains routable as the
// session-bound fallback surface.

/**
 * Adds the one-click "Co-Pilot" entry to the patient chart's LEFT menu
 * (Dashboard / History / ... / External Data), pointing at the SMART
 * launch-from-chart redirect so the click lands in the launched,
 * patient-scoped panel with no token or uuid entry (TRO-53 flow).
 *
 * The entry itself is built by ChartMenuItem (isolated-tested); this
 * wiring layer only supplies the translated label, because
 * PatientMenuEvent::MENU_UPDATE fires after PatientMenuRole's own
 * translation pass. Same ACL posture as the main-menu tab above: acl_req
 * controls whether the entry is offered; the launch flow and every panel
 * route enforce access server-side regardless (S4/S5).
 */
function oe_module_copilot_add_patient_menu_item(PatientMenuEvent $event): PatientMenuEvent
{
    $menu = $event->getMenu();
    if (is_array($menu)) {
        $event->setMenu(ChartMenuItem::appendTo(
            $menu,
            xlt('Co-Pilot'),
            OEGlobalsBag::getInstance()->getString('webroot'),
        ));
    }

    return $event;
}

$eventDispatcher->addListener(PatientMenuEvent::MENU_UPDATE, 'oe_module_copilot_add_patient_menu_item');

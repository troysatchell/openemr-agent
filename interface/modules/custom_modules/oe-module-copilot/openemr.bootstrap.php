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
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Modules\Copilot\Bootstrap;

$file = OEGlobalsBag::getInstance()->getProjectDir();
$classLoader = new ModulesClassLoader($file);
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\Copilot\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$eventDispatcher = OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher();
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();

/**
 * Adds the session-bound panel's "Co-Pilot" tab to the main menu (T21).
 *
 * Modeled on the faxsms module's menu listener
 * (interface/modules/custom_modules/oe-module-faxsms/openemr.bootstrap.php):
 * a plain stdClass menu item inserted right after the 'modimg' anchor, ACL'd
 * via acl_req so MenuEvent::MENU_RESTRICT hides the tab entirely for a user
 * without patients/med access (defense in depth — the panel's own
 * entry-file gate and SessionGate (S4/S5) are what actually enforce access;
 * this only controls whether the tab is offered).
 */
function oe_module_copilot_add_menu_item(MenuEvent $event): MenuEvent
{
    $menuItem = new stdClass();
    $menuItem->requirement = 0;
    $menuItem->target = 'copilot';
    $menuItem->menu_id = 'mod-copilot';
    $menuItem->label = xlt('Co-Pilot');
    $menuItem->url = '/interface/modules/custom_modules/oe-module-copilot/public/index.php';
    $menuItem->children = [];
    $menuItem->acl_req = ['patients', 'med'];

    $menu = $event->getMenu();
    $updatedMenu = [];
    $i = 0;
    foreach ($menu as $item) {
        $updatedMenu[$i] = $item;
        $i++;
        if ($item->menu_id === 'modimg') {
            $updatedMenu[$i] = $menuItem;
            $i++;
        }
    }
    $event->setMenu($updatedMenu);

    return $event;
}

$eventDispatcher->addListener(MenuEvent::MENU_UPDATE, 'oe_module_copilot_add_menu_item');

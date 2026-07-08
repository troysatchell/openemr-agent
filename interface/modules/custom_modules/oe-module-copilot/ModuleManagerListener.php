<?php

/**
 * Module-manager lifecycle listener for the Clinical Co-Pilot module.
 *
 * The co-pilot has no install/enable side effects in Phase 1 (no schema, no
 * background services — deliberately: AUDIT S6), so every action is
 * acknowledged unchanged.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\AbstractModuleActionListener;

/* Global namespace is required by the module installer's convention: it
 * instantiates "ModuleManagerListener" after require-ing this file. */

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function moduleManagerAction(mixed $methodName, mixed $modId, string $currentActionStatus = 'Success'): string
    {
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\Copilot\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }
}

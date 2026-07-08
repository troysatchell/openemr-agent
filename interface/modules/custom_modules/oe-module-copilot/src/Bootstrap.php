<?php

/**
 * Clinical Co-Pilot module bootstrap (ARCHITECTURE.md §2 — module + events,
 * never core edits).
 *
 * Subscribes to RestApiCreateEvent and contributes the module's API routes
 * exclusively through GuardedRouteRegistrar, so every copilot route enforces
 * an explicit ACL before its handler runs (AUDIT S5: OpenEMR has no
 * default-deny gate — this module supplies its own).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;
use OpenEMR\Modules\Copilot\Routes\GuardedRouteRegistrar;
use OpenEMR\RestControllers\Config\RestConfig;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(RestApiCreateEvent::EVENT_HANDLE, $this->registerApiRoutes(...));
    }

    public function registerApiRoutes(RestApiCreateEvent $event): void
    {
        $registrar = new GuardedRouteRegistrar(
            static function (HttpRestRequest $request, string $section, string $value): void {
                RestConfig::request_authorization_check($request, $section, $value);
            }
        );

        $registrar->register(
            'GET /api/copilot/ping',
            new AclRequirement('patients', 'demo'),
            static function (HttpRestRequest $request): array {
                return ['status' => 'ok'];
            }
        );

        $registrar->applyTo($event);
    }
}

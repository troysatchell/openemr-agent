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

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Modules\Copilot\Observability\ReadinessCheck;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;
use OpenEMR\Modules\Copilot\Routes\GuardedRouteRegistrar;
use OpenEMR\RestControllers\Config\RestConfig;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    /**
     * Default trace-sink path (T17). Not a class constant because
     * sys_get_temp_dir() is not a compile-time constant expression. A
     * composition-root concern: Wave 2 wiring makes this configurable; for
     * now it names a fixed location so the trace_sink readiness probe has
     * something concrete to check.
     */
    public static function defaultTracePath(): string
    {
        return sys_get_temp_dir() . '/copilot-trace.jsonl';
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

        $registrar->register(
            'GET /api/copilot/health',
            new AclRequirement('patients', 'demo'),
            static function (HttpRestRequest $request): array {
                // Process liveness only — no dependency checks belong here.
                return ['status' => 'alive'];
            }
        );

        $registrar->register(
            'GET /api/copilot/ready',
            new AclRequirement('patients', 'demo'),
            static function (HttpRestRequest $request): array {
                $tracePath = self::defaultTracePath();
                $check = new ReadinessCheck([
                    'db' => static function (): bool {
                        QueryUtils::fetchRecords('SELECT 1', []);

                        return true;
                    },
                    'trace_sink' => static fn (): bool => is_writable(dirname($tracePath)),
                    // Config-presence only until the T18 LLM adapter
                    // supplies a real endpoint probe.
                    'llm' => static fn (): bool => (getenv('ANTHROPIC_API_KEY') ?: '') !== '',
                ]);

                $report = $check->run();
                if (!$report->ready) {
                    http_response_code(503);
                }

                return ['ready' => $report->ready, 'checks' => $report->checks];
            }
        );

        $registrar->applyTo($event);
    }
}

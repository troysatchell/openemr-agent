<?php

/**
 * Default-deny route registrar for the copilot module (AUDIT S5).
 *
 * OpenEMR has no default-deny gate on API routes: every route closure must
 * remember to call the authorization check itself (AUDIT §4c). This registrar
 * removes that failure mode by construction — a route can only be registered
 * together with an explicit AclRequirement, and the closure exposed to the
 * route map is always a guard wrapper that runs the authorization check
 * before the handler. A wrapped route invoked without an HttpRestRequest
 * fails closed without evaluating anything.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Routes;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GuardedRouteRegistrar
{
    /** @var array<string, \Closure> route spec => guard-wrapped handler */
    private array $routes = [];

    /**
     * @param \Closure $authorizationCheck Contract:
     *        (HttpRestRequest $request, string $section, string $value): void —
     *        throws (e.g. AccessDeniedHttpException) to deny.
     */
    public function __construct(private readonly \Closure $authorizationCheck)
    {
    }

    /**
     * Register a handler for a route spec (e.g. "GET /api/copilot/ping").
     *
     * The stored closure is always a guard wrapper, never the bare handler.
     * At dispatch time the route map is invoked with the route's path
     * parameters plus trailing extras (the HttpRestRequest and the globals
     * bag — see HttpRestRouteHandler::dispatch), so the wrapper locates the
     * request positionally and passes every original argument through.
     */
    public function register(string $route, AclRequirement $acl, \Closure $handler): void
    {
        $authorizationCheck = $this->authorizationCheck;
        $this->routes[$route] = static function (mixed ...$args) use ($authorizationCheck, $acl, $handler): mixed {
            $request = null;
            foreach ($args as $arg) {
                if ($arg instanceof HttpRestRequest) {
                    $request = $arg;
                    break;
                }
            }
            if ($request === null) {
                // Fail closed (S5): without a request there is no principal to
                // authorize, so neither the check nor the handler may run.
                throw new AccessDeniedHttpException(
                    'Copilot route invoked without an HttpRestRequest; denying by default.'
                );
            }
            $authorizationCheck($request, $acl->section, $acl->value);
            return $handler(...$args);
        };
    }

    /**
     * @return array<string, \Closure> route spec => guard-wrapped handler
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Add every guarded route to the standard route map of the given event.
     */
    public function applyTo(RestApiCreateEvent $event): void
    {
        foreach ($this->routes as $route => $wrapped) {
            $event->addToRouteMap($route, $wrapped);
        }
    }
}

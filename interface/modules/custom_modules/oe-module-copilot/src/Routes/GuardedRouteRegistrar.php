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
 * The same wrapper is also the module's error-status seam (TRO-57). OpenEMR's
 * REST view-renderer (ViewRendererListener) wraps a bare array return in a
 * *200* JsonResponse, silently discarding any http_response_code() an error
 * branch set inside a handler — so malformed-JSON 400s, launch-binding 403s,
 * and /ready 503s all shipped as HTTP 200 with an error body. Because every
 * copilot route passes through this one wrapper, it is the correct place to
 * consume that legacy request-global and hand the kernel a typed Response it
 * renders verbatim (confining the superglobal read to the outermost boundary,
 * per CLAUDE.md), without editing every handler branch.
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
use Symfony\Component\HttpFoundation\JsonResponse;
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
     *
     * Registering the same route twice is a programming error and throws: a
     * silent overwrite could replace an earlier guard/ACL pairing (S5).
     */
    public function register(string $route, AclRequirement $acl, \Closure $handler): void
    {
        if (isset($this->routes[$route])) {
            throw new \LogicException(
                "Duplicate copilot route registration for '{$route}'; each route may be guarded exactly once."
            );
        }

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

            // Start from a clean 200 baseline so the status read-back below
            // reflects only what THIS handler set — never a code an earlier
            // request/handler left on the process-global of the same worker
            // (this also insulates handlers that set no status at all).
            http_response_code(200);
            $result = $handler(...$args);

            // Honor an error status the handler signalled via
            // http_response_code(): convert its array body into a typed
            // JsonResponse the kernel renders verbatim (TRO-57), instead of
            // letting the view-renderer force it to 200. A handler that
            // returns its own Response, or an array on the success path
            // (status 200), is passed through untouched — happy-path bodies
            // and their 200 status are byte-for-byte unchanged.
            if (is_array($result)) {
                $status = http_response_code();
                if (is_int($status) && $status >= 400) {
                    // Reset so a converted error status cannot bleed into the
                    // next handler served by the same worker.
                    http_response_code(200);

                    return new JsonResponse($result, $status);
                }
            }

            return $result;
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

<?php

/**
 * Interim default-deny safety net for AUDIT.md finding S5.
 *
 * OpenEMR has no central default-deny gate on REST routes: authorization is
 * applied by hand inside each route closure, so a new or edited route that
 * omits it exposes data with no compile-time or DI guard. Until a real
 * default-deny dispatch gate exists, this test fails CI if any registered
 * route carries none of the recognized in-closure authorization markers AND
 * is not on the reviewed KNOWN_UNGUARDED_ROUTES allow-list.
 *
 * Recognized authorization is layered — a route is considered guarded if its
 * closure invokes any of:
 *   - RestConfig::request_authorization_check() ....... ACL check
 *   - $controller->addAclRestrictions() ............... deferred ACL in the query
 *   - $request->isPatientRequest() .................... SMART patient-context branch
 *   - $request->getPatientUUIDString() ............... SMART patient-bound scoping
 * On top of these, every /api and /fhir route also passes an OAuth2/SMART scope
 * check at the dispatch layer, and every /portal route a portal session check;
 * this test guards the in-closure ACL layer specifically.
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\RestControllers\Config;

use Closure;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

class RouteAuthorizationCoverageTest extends TestCase
{
    /**
     * In-closure markers that count as an authorization decision.
     *
     * @var list<string>
     */
    private const AUTHZ_MARKERS = [
        'request_authorization_check',
        'addAclRestrictions',
        'isPatientRequest',
        'getPatientUUIDString',
    ];

    /**
     * Routes that legitimately carry no in-closure ACL marker, each with the
     * reviewed reason. A new markerless route NOT in this map fails the test;
     * a stale entry (now guarded, or removed) also fails it. Adding an entry
     * here is a deliberate, reviewable security decision.
     *
     * @var array<string, string>
     */
    private const KNOWN_UNGUARDED_ROUTES = [
        // Non-PHI service metadata.
        'GET /api/version' => 'public build/version info, no PHI',
        'GET /api/product' => 'public product info, no PHI',
        // Documented deliberate: advances only due services (cron-equivalent),
        // cannot force-run; OAuth2 scope resolves to background_service.c.
        'POST /api/background_service/$run' => 'documented deliberate; OAuth2 scope only (see route comment)',
        // FHIR/SMART spec-mandated public discovery endpoints.
        'GET /fhir/metadata' => 'FHIR CapabilityStatement, spec-public',
        'GET /fhir/.well-known/smart-configuration' => 'SMART discovery, spec-public',
        'GET /fhir/OperationDefinition' => 'FHIR OperationDefinition, spec-public',
        'GET /fhir/OperationDefinition/:operation' => 'FHIR OperationDefinition, spec-public',
        // FHIR resources gated by dispatch-layer OAuth2/SMART scope only; they
        // lack the in-closure ACL their sibling resources add. Defense-in-depth
        // follow-up: add addAclRestrictions (tracked against S5).
        'GET /fhir/Questionnaire' => 'dispatch-layer OAuth2 scope only; in-closure ACL follow-up',
        'GET /fhir/QuestionnaireResponse' => 'dispatch-layer OAuth2 scope only; in-closure ACL follow-up',
        'GET /fhir/QuestionnaireResponse/:uuid' => 'dispatch-layer OAuth2 scope only; in-closure ACL follow-up',
    ];

    /** @return list<string> absolute paths to the three REST route-map files */
    private static function routeFiles(): array
    {
        $base = dirname(__DIR__, 5) . '/apis/routes/';
        return [
            $base . '_rest_routes_standard.inc.php',
            $base . '_rest_routes_fhir_r4_us_core_3_1_0.inc.php',
            $base . '_rest_routes_portal.inc.php',
        ];
    }

    /** @return array<string, callable> the merged route map across all three files */
    private static function allRoutes(): array
    {
        $routes = [];
        foreach (self::routeFiles() as $file) {
            /** @var array<string, callable> $map */
            $map = require $file;
            foreach ($map as $route => $handler) {
                $routes[$route] = $handler;
            }
        }
        return $routes;
    }

    private static function sourceHasAuthzMarker(string $closureSource): bool
    {
        foreach (self::AUTHZ_MARKERS as $marker) {
            if (str_contains($closureSource, $marker)) {
                return true;
            }
        }
        return false;
    }

    private static function closureSource(callable $handler): string
    {
        $ref = new ReflectionFunction(Closure::fromCallable($handler));
        $file = $ref->getFileName();
        $lines = ($file !== false) ? file($file) : false;
        if ($lines === false) {
            return '';
        }
        $start = (int) $ref->getStartLine() - 1;
        $length = (int) $ref->getEndLine() - $start;
        return implode('', array_slice($lines, $start, $length));
    }

    /**
     * The set of registered routes whose closure carries no authorization
     * marker must be exactly the reviewed allow-list — no more (a new route
     * slipped through unguarded), no less (a stale allow-list entry).
     */
    public function testEveryRouteIsGuardedOrExplicitlyAllowlisted(): void
    {
        $markerless = [];
        foreach (self::allRoutes() as $route => $handler) {
            if (!self::sourceHasAuthzMarker(self::closureSource($handler))) {
                $markerless[] = $route;
            }
        }
        sort($markerless);

        $allowlisted = array_keys(self::KNOWN_UNGUARDED_ROUTES);
        sort($allowlisted);

        self::assertSame(
            $allowlisted,
            $markerless,
            "Routes lacking an in-closure authorization marker must match the reviewed "
            . "KNOWN_UNGUARDED_ROUTES allow-list. A new route here means an unguarded "
            . "endpoint; a missing one means a stale allow-list entry to remove.",
        );
    }

    /**
     * Self-test proving the detector actually distinguishes guarded from
     * unguarded closures (so the assertion above cannot silently pass).
     */
    public function testDetectorFlagsUnguardedSourceOnly(): void
    {
        self::assertTrue(self::sourceHasAuthzMarker('RestConfig::request_authorization_check($request, "patients", "demo");'));
        self::assertTrue(self::sourceHasAuthzMarker('$controller->addAclRestrictions("patients", "med");'));
        self::assertTrue(self::sourceHasAuthzMarker('$request->getPatientUUIDString()'));
        self::assertFalse(self::sourceHasAuthzMarker('return (new SomeController())->getAll();'));
    }
}

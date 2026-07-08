<?php

/**
 * FROZEN acceptance tests — T1: default-deny route wrapper for oe-module-copilot (S5).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test (OpenEMR has no default-deny route gate — S5 — so the
 * module supplies one by construction):
 *  - A route can only be registered together with an explicit AclRequirement.
 *  - Every registered handler is wrapped: the injected authorization check runs
 *    before the handler on every invocation.
 *  - Authorization failure means the handler is never invoked.
 *  - A wrapped route invoked without an HttpRestRequest argument fails closed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Routes;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;
use OpenEMR\Modules\Copilot\Routes\GuardedRouteRegistrar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class GuardedRouteRegistrarTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $authorizationCalls = [];

    /** @var list<string> */
    private array $invocationOrder = [];

    private function makeAllowingAuthorization(): \Closure
    {
        return function (HttpRestRequest $request, string $section, string $value): void {
            $this->authorizationCalls[] = [$section, $value];
            $this->invocationOrder[] = 'authz';
        };
    }

    private function makeDenyingAuthorization(): \Closure
    {
        return function (HttpRestRequest $request, string $section, string $value): void {
            $this->invocationOrder[] = 'authz';
            throw new AccessDeniedHttpException('denied');
        };
    }

    /**
     * @return array<string, mixed>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function emptyAclComponentProvider(): array
    {
        return [
            'empty section' => ['', 'demo'],
            'empty value' => ['patients', ''],
            'both empty' => ['', ''],
            'whitespace section' => ['   ', 'demo'],
            'whitespace value' => ['patients', "\t"],
        ];
    }

    #[DataProvider('emptyAclComponentProvider')]
    public function testAclRequirementRejectsEmptyComponents(string $section, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AclRequirement($section, $value);
    }

    public function testAclRequirementExposesSectionAndValue(): void
    {
        $acl = new AclRequirement('patients', 'demo');
        $this->assertSame('patients', $acl->section);
        $this->assertSame('demo', $acl->value);
    }

    public function testRegisteredRouteIsWrappedNotTheOriginalHandler(): void
    {
        $registrar = new GuardedRouteRegistrar($this->makeAllowingAuthorization());
        $handler = function (HttpRestRequest $request): string {
            return 'pong';
        };

        $registrar->register('GET /api/copilot/ping', new AclRequirement('patients', 'demo'), $handler);

        $routes = $registrar->routes();
        $this->assertArrayHasKey('GET /api/copilot/ping', $routes);
        $this->assertNotSame(
            $handler,
            $routes['GET /api/copilot/ping'],
            'The registered closure must be a guard wrapper, never the bare handler.'
        );
    }

    public function testAuthorizationRunsBeforeHandlerWithDeclaredAcl(): void
    {
        $registrar = new GuardedRouteRegistrar($this->makeAllowingAuthorization());
        $registrar->register(
            'GET /api/copilot/ping',
            new AclRequirement('patients', 'demo'),
            function (HttpRestRequest $request): string {
                $this->invocationOrder[] = 'handler';
                return 'pong';
            }
        );

        $result = ($registrar->routes()['GET /api/copilot/ping'])(new HttpRestRequest());

        $this->assertSame('pong', $result, 'Wrapper must return the handler result.');
        $this->assertSame([['patients', 'demo']], $this->authorizationCalls);
        $this->assertSame(['authz', 'handler'], $this->invocationOrder);
    }

    public function testHandlerIsNeverInvokedWhenAuthorizationDenies(): void
    {
        $handlerInvoked = false;
        $registrar = new GuardedRouteRegistrar($this->makeDenyingAuthorization());
        $registrar->register(
            'GET /api/copilot/ping',
            new AclRequirement('patients', 'demo'),
            function (HttpRestRequest $request) use (&$handlerInvoked): string {
                $handlerInvoked = true;
                return 'pong';
            }
        );

        $denied = false;
        try {
            ($registrar->routes()['GET /api/copilot/ping'])(new HttpRestRequest());
        } catch (AccessDeniedHttpException) {
            $denied = true;
        }

        $this->assertTrue($denied, 'Authorization denial must propagate.');
        $this->assertFalse($handlerInvoked, 'Handler must never run after a denial.');
    }

    public function testRouteArgumentsPassThroughToHandlerWithRequestInAnyPosition(): void
    {
        $registrar = new GuardedRouteRegistrar($this->makeAllowingAuthorization());
        $registrar->register(
            'GET /api/copilot/patient/:puuid/thing/:id',
            new AclRequirement('patients', 'demo'),
            function (string $puuid, string $id, HttpRestRequest $request): array {
                return [$puuid, $id];
            }
        );

        $result = ($registrar->routes()['GET /api/copilot/patient/:puuid/thing/:id'])(
            'some-uuid',
            '42',
            new HttpRestRequest()
        );

        $this->assertSame(['some-uuid', '42'], $result);
        $this->assertSame([['patients', 'demo']], $this->authorizationCalls);
    }

    public function testInvocationWithoutRequestArgumentFailsClosed(): void
    {
        $handlerInvoked = false;
        $registrar = new GuardedRouteRegistrar($this->makeAllowingAuthorization());
        $registrar->register(
            'GET /api/copilot/ping',
            new AclRequirement('patients', 'demo'),
            function () use (&$handlerInvoked): string {
                $handlerInvoked = true;
                return 'pong';
            }
        );

        $denied = false;
        try {
            ($registrar->routes()['GET /api/copilot/ping'])('not-a-request');
        } catch (AccessDeniedHttpException) {
            $denied = true;
        }

        $this->assertTrue($denied, 'A guarded route with no HttpRestRequest argument must fail closed.');
        $this->assertFalse($handlerInvoked);
        $this->assertSame([], $this->authorizationCalls, 'Authorization cannot be evaluated without a request.');
    }

    public function testApplyToEventAddsOnlyGuardedRoutes(): void
    {
        $registrar = new GuardedRouteRegistrar($this->makeAllowingAuthorization());
        $handler = function (HttpRestRequest $request): string {
            return 'pong';
        };
        $registrar->register('GET /api/copilot/ping', new AclRequirement('patients', 'demo'), $handler);
        $registrar->register('GET /api/copilot/status', new AclRequirement('admin', 'super'), $handler);

        $event = new RestApiCreateEvent([], [], []);
        $registrar->applyTo($event);

        $routeMap = $event->getRouteMap();
        $this->assertArrayHasKey('GET /api/copilot/ping', $routeMap);
        $this->assertArrayHasKey('GET /api/copilot/status', $routeMap);
        $this->assertNotSame($handler, $routeMap['GET /api/copilot/ping']);

        $routeMap['GET /api/copilot/status'](new HttpRestRequest());
        $this->assertSame([['admin', 'super']], $this->authorizationCalls);
    }
}

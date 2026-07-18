<?php

/**
 * TRO-57: the guarded route wrapper must translate a handler's error status
 * into the transported HTTP status code.
 *
 * Failure mode guarded against: OpenEMR's REST view-renderer wraps a bare
 * array return in a 200 JsonResponse, discarding any http_response_code() an
 * error branch set inside the handler. Without the wrapper's translation a
 * malformed-JSON 400, a launch-binding 403, and a /ready 503 all reach the
 * client as HTTP 200 with an error body, so clients that branch on
 * resp.ok / the status line treat a refusal as success. These tests assert
 * the wrapper hands the kernel a typed JsonResponse carrying the intended
 * status, while leaving the success path (and the frozen default-deny
 * contract in GuardedRouteRegistrarTest) byte-for-byte unchanged.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Routes;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;
use OpenEMR\Modules\Copilot\Routes\GuardedRouteRegistrar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GuardedRouteRegistrarStatusCodeTest extends TestCase
{
    protected function setUp(): void
    {
        // Deterministic baseline: no error status carried in from elsewhere.
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        // Never leave an error status on the process-global for the next test.
        http_response_code(200);
    }

    private function allowingAuthorization(): \Closure
    {
        return static function (HttpRestRequest $request, string $section, string $value): void {
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function invoke(?int $status, array $body): mixed
    {
        // A null status models a handler that sets no code at all (the real
        // success path, e.g. /ping) — distinct from a handler that explicitly
        // sets 200.
        $registrar = new GuardedRouteRegistrar($this->allowingAuthorization());
        $registrar->register(
            'POST /api/copilot/thing',
            new AclRequirement('patients', 'med'),
            static function (HttpRestRequest $request) use ($status, $body): array {
                if ($status !== null) {
                    http_response_code($status);
                }

                return $body;
            }
        );

        return ($registrar->routes()['POST /api/copilot/thing'])(new HttpRestRequest());
    }

    /**
     * @return array<string, array{int}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function errorStatusProvider(): array
    {
        return [
            'malformed request 400' => [400],
            'refusal 403' => [403],
            'not implemented 501' => [501],
            'system error 500' => [500],
            'not-ready 503' => [503],
        ];
    }

    #[DataProvider('errorStatusProvider')]
    public function testHandlerErrorStatusIsTransportedAsTheResponseStatus(int $status): void
    {
        $result = $this->invoke($status, ['error' => 'refused']);

        $this->assertInstanceOf(
            JsonResponse::class,
            $result,
            'An error branch must be converted to a typed Response, not a bare array (which the kernel forces to 200).'
        );
        $this->assertSame($status, $result->getStatusCode());
    }

    public function testConvertedErrorBodyPreservesTheOriginalArrayShape(): void
    {
        $result = $this->invoke(403, ['error' => 'Access token is not bound to the requested patient.']);

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(
            ['error' => 'Access token is not bound to the requested patient.'],
            json_decode((string) $result->getContent(), true),
            'The {"error": ...} body contract must survive the status translation unchanged.'
        );
    }

    public function testConvertedErrorResetsTheProcessGlobalSoItCannotBleed(): void
    {
        $this->invoke(500, ['error' => 'refused']);

        $this->assertSame(
            200,
            http_response_code(),
            'After converting an error status the wrapper must reset the global to 200.'
        );
    }

    public function testSuccessArrayReturnIsPassedThroughUnchanged(): void
    {
        // No http_response_code() set by the handler: the array must reach the
        // view-renderer as-is (the 200 success path), never wrapped.
        $result = $this->invoke(null, ['status' => 'ok']);

        $this->assertIsArray($result);
        $this->assertSame(['status' => 'ok'], $result);
    }

    public function testStaleErrorGlobalDoesNotContaminateASuccessHandler(): void
    {
        // Simulate a prior request/handler on the same worker leaving a 500 on
        // the process-global. The wrapper's clean-baseline reset must ensure a
        // success handler that sets no status still returns its array verbatim.
        http_response_code(500);

        $registrar = new GuardedRouteRegistrar($this->allowingAuthorization());
        $registrar->register(
            'GET /api/copilot/ping',
            new AclRequirement('patients', 'demo'),
            static function (HttpRestRequest $request): array {
                return ['status' => 'ok'];
            }
        );

        $result = ($registrar->routes()['GET /api/copilot/ping'])(new HttpRestRequest());

        $this->assertIsArray($result);
        $this->assertSame(['status' => 'ok'], $result);
    }

    public function testHandlerReturningItsOwnResponseIsPassedThrough(): void
    {
        $registrar = new GuardedRouteRegistrar($this->allowingAuthorization());
        $own = new JsonResponse(['made' => 'here'], Response::HTTP_CREATED);
        $registrar->register(
            'POST /api/copilot/thing',
            new AclRequirement('patients', 'med'),
            static function (HttpRestRequest $request) use ($own): JsonResponse {
                return $own;
            }
        );

        $result = ($registrar->routes()['POST /api/copilot/thing'])(new HttpRestRequest());

        $this->assertSame($own, $result, 'A handler that returns its own Response must be handed through untouched.');
    }
}

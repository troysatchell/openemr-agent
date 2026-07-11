<?php

/**
 * Frozen acceptance test for AUDIT.md finding S9 — the /_routing_test
 * affordance must only answer in a dev environment, never in production.
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\BC;

use OpenEMR\BC\FallbackRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S9 (AUDIT.md): pure decision behind handleRoutingTestIfRequested — the hook
 * confirms the routing layer to anonymous callers, so it must be gated to dev.
 */
class RoutingTestGateTest extends TestCase
{
    /**
     * @return array<string, array{string, bool, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function gateProvider(): array
    {
        return [
            // uri, isDev, expectedServe
            'routing-test uri in dev serves'          => ['/apis/default/_routing_test', true, true],
            'routing-test uri in production is silent' => ['/apis/default/_routing_test', false, false],
            'oauth routing-test uri in dev serves'    => ['/oauth2/default/_routing_test', true, true],
            'oauth routing-test uri in prod is silent' => ['/oauth2/default/_routing_test', false, false],
            'non routing-test uri never serves (dev)' => ['/apis/default/api/patient', true, false],
            'non routing-test uri never serves (prod)' => ['/apis/default/api/patient', false, false],
        ];
    }

    #[DataProvider('gateProvider')]
    public function testShouldServeRoutingTest(string $requestUri, bool $isDev, bool $expected): void
    {
        self::assertSame(
            $expected,
            FallbackRouter::shouldServeRoutingTest($requestUri, $isDev),
            "shouldServeRoutingTest($requestUri, " . var_export($isDev, true) . ')',
        );
    }
}

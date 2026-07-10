<?php

/**
 * Frozen acceptance test for AUDIT.md finding S7 — PHP error display must be
 * forced off outside an explicit dev environment, regardless of the DB-driven
 * user_php_debug global.
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Environment;

use OpenEMR\Common\Environment\ErrorDisplayPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S7 (AUDIT.md): only an exact 'dev' environment may display PHP errors; every
 * other value (including empty/unset, matching Kernel::isDev semantics) forces
 * display_errors off so paths/SQL/stack context never reach the client.
 */
class ErrorDisplayPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function environmentProvider(): array
    {
        return [
            'dev shows errors'                 => ['dev', false],
            'empty forces off (unset env)'     => ['', true],
            'production forces off'            => ['production', true],
            'prod forces off'                 => ['prod', true],
            'uppercase DEV forces off (exact)' => ['DEV', true],
            'padded dev forces off (exact)'   => [' dev ', true],
        ];
    }

    #[DataProvider('environmentProvider')]
    public function testShouldForceOff(string $environment, bool $expected): void
    {
        self::assertSame(
            $expected,
            ErrorDisplayPolicy::shouldForceOff($environment),
            "shouldForceOff('$environment')",
        );
    }
}

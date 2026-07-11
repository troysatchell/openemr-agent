<?php

/**
 * Frozen acceptance test for AUDIT.md finding S8 — the AuthUtils constructor
 * runs on every login attempt (authenticated or not); it must not write to the
 * database, so an unauthenticated attacker cannot trigger INSERT/UPDATE and the
 * auth path can run against a least-privilege / read-only DB user.
 *
 * Source-scan assertion (no instantiation): the dummy-hash / expiry bootstrap
 * moves to a Doctrine migration, with an in-memory fallback in the constructor.
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Auth;

use OpenEMR\Common\Auth\AuthUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AuthUtilsConstructorReadOnlyTest extends TestCase
{
    private static function constructorSource(): string
    {
        $ref = new ReflectionMethod(AuthUtils::class, '__construct');
        $file = $ref->getFileName();
        $lines = ($file !== false) ? file($file) : false;
        if ($lines === false) {
            self::fail('could not read AuthUtils source');
        }
        $start = (int) $ref->getStartLine() - 1;
        $length = (int) $ref->getEndLine() - $start;
        return implode('', array_slice($lines, $start, $length));
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function writeMarkerProvider(): array
    {
        return [
            'privStatement (the write/exec API)' => ['privStatement('],
            'raw INSERT'                         => ['INSERT INTO'],
            'raw UPDATE'                         => ['UPDATE `'],
            'sqlInsert helpers'                  => ['sqlInsert'],
        ];
    }

    #[DataProvider('writeMarkerProvider')]
    public function testConstructorContainsNoDatabaseWrite(string $writeMarker): void
    {
        self::assertStringNotContainsString(
            $writeMarker,
            self::constructorSource(),
            "S8 (AUDIT.md): AuthUtils::__construct must perform no DB writes; found '$writeMarker'",
        );
    }

    public function testConstructorStillReadsViaPrivQuery(): void
    {
        // Sanity: reads (privQuery) are allowed and expected — the constructor
        // still looks up the persisted dummy hash; it just must not persist.
        self::assertStringContainsString('privQuery(', self::constructorSource());
    }
}

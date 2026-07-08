<?php

/**
 * FROZEN acceptance tests — T4: API error responses must not leak internals (S1, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: a pure formatter the API dispatcher's last-resort
 * catch block uses instead of echoing $e->getMessage() to unauthenticated
 * callers (apis/dispatch.php). For ANY throwable the client-facing body is a
 * single generic error — no message, class name, file path, or trace.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Http;

use OpenEMR\Common\Http\ApiErrorResponseFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiErrorResponseFormatterTest extends TestCase
{
    private const GENERIC_BODY = ['error' => 'An error occurred while processing the request.'];

    /**
     * @return array<string, array{\Throwable}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function leakyThrowableProvider(): array
    {
        return [
            'sql details' => [new \RuntimeException(
                "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'openemr.patient_data' doesn't exist"
            )],
            'file path details' => [new \LogicException(
                'include(/var/www/localhost/htdocs/openemr/sites/default/sqlconf.php): failed to open stream'
            )],
            'credential-looking details' => [new \RuntimeException(
                "Access denied for user 'openemr'@'localhost' (using password: YES)"
            )],
            'type error' => [new \TypeError(
                'OpenEMR\Services\PatientService::getOne(): Argument #1 ($uuid) must be of type string, null given'
            )],
            'chained exception' => [new \RuntimeException(
                'outer wrapper message',
                0,
                new \PDOException('inner: SELECT * FROM users WHERE password = ...')
            )],
            'empty message' => [new \RuntimeException('')],
        ];
    }

    #[DataProvider('leakyThrowableProvider')]
    public function testFormatsEveryThrowableToTheSameGenericBody(\Throwable $throwable): void
    {
        $this->assertSame(ApiErrorResponseFormatter::format($throwable), self::GENERIC_BODY);
    }

    #[DataProvider('leakyThrowableProvider')]
    public function testNoInternalDetailSurvivesFormatting(\Throwable $throwable): void
    {
        $encoded = json_encode(ApiErrorResponseFormatter::format($throwable), JSON_THROW_ON_ERROR);

        if ($throwable->getMessage() !== '') {
            $this->assertStringNotContainsString($throwable->getMessage(), $encoded);
        }
        $this->assertStringNotContainsString($throwable::class, $encoded);
        $this->assertStringNotContainsString($throwable->getFile(), $encoded);

        $previous = $throwable->getPrevious();
        if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
            $this->assertStringNotContainsString($previous->getMessage(), $encoded);
        }
    }

    public function testOutputShapeIsExactlyOneErrorKey(): void
    {
        $body = ApiErrorResponseFormatter::format(new \RuntimeException('whatever'));
        $this->assertSame(['error'], array_keys($body));
    }
}

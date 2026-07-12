<?php

/**
 * S6 (AUDIT.md) — the runtime gate the BackgroundServiceRunner consults.
 *
 * isPermittedToRun() equals isAllowed() in production (the shipped static
 * allow-list). A dev/test-only escape hatch also permits callables named in
 * OPENEMR_BACKGROUND_EXTRA_ALLOWED_CALLABLES, which is UNSET in production.
 * The seam must NEVER widen the pure allow-list, and unrelated env values must
 * never permit an attacker-supplied callable — that is the security invariant
 * these tests pin. (The seam exists so the CLI integration test can exercise
 * the runner with a probe callable without adding a test-only name to the
 * shipped list; it does not widen the S6 threat model, since a DB-write-only
 * attacker cannot set the server process environment.)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Background;

use OpenEMR\Services\Background\BackgroundServiceCallableAllowlist;
use PHPUnit\Framework\TestCase;

class BackgroundServiceRuntimePermissionTest extends TestCase
{
    private const ENV = 'OPENEMR_BACKGROUND_EXTRA_ALLOWED_CALLABLES';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        parent::tearDown();
    }

    private function clearEnv(): void
    {
        putenv(self::ENV);
        unset($_ENV[self::ENV], $_SERVER[self::ENV]);
    }

    private function setEnv(string $value): void
    {
        putenv(self::ENV . '=' . $value);
        $_ENV[self::ENV] = $value;
        $_SERVER[self::ENV] = $value;
    }

    public function testWithoutEnvItMatchesTheStaticAllowList(): void
    {
        self::assertTrue(BackgroundServiceCallableAllowlist::isPermittedToRun('phimail_check'));
        self::assertFalse(BackgroundServiceCallableAllowlist::isPermittedToRun('attacker_supplied_payload'));
    }

    public function testShippedCallableIsPermittedRegardlessOfEnv(): void
    {
        $this->setEnv('some_unrelated_probe');
        self::assertTrue(BackgroundServiceCallableAllowlist::isPermittedToRun('start_MedEx'));
    }

    public function testEnvListedCallablesArePermitted(): void
    {
        $this->setEnv('OpenEMR\\Tests\\Services\\Background\\Probe\\markCliProbeSentinel, another_probe');
        self::assertTrue(
            BackgroundServiceCallableAllowlist::isPermittedToRun('OpenEMR\\Tests\\Services\\Background\\Probe\\markCliProbeSentinel')
        );
        self::assertTrue(BackgroundServiceCallableAllowlist::isPermittedToRun('another_probe'));
    }

    public function testEnvSeamNeverWidensThePureAllowList(): void
    {
        // Security invariant: the env extension is a runtime convenience only.
        // The frozen static allow-list (isAllowed) must be untouched by it.
        $this->setEnv('sneaky_payload');
        self::assertFalse(
            BackgroundServiceCallableAllowlist::isAllowed('sneaky_payload'),
            'isAllowed() is the S6 boundary and must ignore the env seam entirely'
        );
    }

    public function testUnrelatedEnvDoesNotPermitAnAttackerCallable(): void
    {
        $this->setEnv('probe_a, probe_b');
        self::assertFalse(BackgroundServiceCallableAllowlist::isPermittedToRun('attacker_supplied_payload'));
    }

    public function testBlankEnvPermitsNothingExtra(): void
    {
        $this->setEnv('   ,  ');
        self::assertFalse(BackgroundServiceCallableAllowlist::isPermittedToRun('attacker_supplied_payload'));
    }
}

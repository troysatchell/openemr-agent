<?php

/**
 * Kernel::isDev() must recognise a dev environment whether OPENEMR__ENVIRONMENT
 * arrives via $_ENV or only via the OS process environment. Under this runtime's
 * variables_order=GPCS, $_ENV is not auto-populated, so a $_ENV-only check
 * silently returns false even when OPENEMR__ENVIRONMENT=dev is set.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Core;

use OpenEMR\Core\Kernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('core')]
class KernelIsDevTest extends TestCase
{
    private bool $hadEnvSuper = false;
    private ?string $savedEnvSuper = null;
    private string|false $savedGetenv = false;

    protected function setUp(): void
    {
        $this->hadEnvSuper = array_key_exists('OPENEMR__ENVIRONMENT', $_ENV);
        $this->savedEnvSuper = $this->hadEnvSuper ? (string) $_ENV['OPENEMR__ENVIRONMENT'] : null;
        $this->savedGetenv = getenv('OPENEMR__ENVIRONMENT');
        unset($_ENV['OPENEMR__ENVIRONMENT']);
        putenv('OPENEMR__ENVIRONMENT');
    }

    protected function tearDown(): void
    {
        if ($this->hadEnvSuper) {
            $_ENV['OPENEMR__ENVIRONMENT'] = $this->savedEnvSuper;
        } else {
            unset($_ENV['OPENEMR__ENVIRONMENT']);
        }
        if ($this->savedGetenv === false) {
            putenv('OPENEMR__ENVIRONMENT');
        } else {
            putenv('OPENEMR__ENVIRONMENT=' . $this->savedGetenv);
        }
    }

    private function kernel(): Kernel
    {
        return new Kernel('/var/www/openemr', '/openemr');
    }

    public function testDevViaEnvSuperglobal(): void
    {
        $_ENV['OPENEMR__ENVIRONMENT'] = 'dev';
        self::assertTrue($this->kernel()->isDev());
    }

    public function testDevViaGetenvWhenSuperglobalAbsent(): void
    {
        // The GPCS case: $_ENV empty, OS env has it. Currently returns false.
        putenv('OPENEMR__ENVIRONMENT=dev');
        self::assertTrue($this->kernel()->isDev());
    }

    public function testUnsetIsNotDev(): void
    {
        self::assertFalse($this->kernel()->isDev());
    }

    public function testNonDevValueIsNotDev(): void
    {
        $_ENV['OPENEMR__ENVIRONMENT'] = 'production';
        self::assertFalse($this->kernel()->isDev());
    }
}

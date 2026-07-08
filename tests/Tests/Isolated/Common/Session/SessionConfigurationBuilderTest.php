<?php

/**
 * FROZEN acceptance tests — T4: session cookie hardening (S2/S3, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test:
 *  - S2: the core preset no longer downgrades cookie_httponly to false.
 *  - S3: core and portal presets are secure-by-default; plain-HTTP deployments
 *    must opt out explicitly (fail closed, not open). API/OAuth stay always-secure.
 *  - Existing hardening (SameSite, strict mode, cookie-only sessions) regresses nowhere.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Session;

use OpenEMR\Common\Session\SessionConfigurationBuilder;
use OpenEMR\Common\Session\SessionUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SessionConfigurationBuilderTest extends TestCase
{
    public function testCoreCookieIsHttpOnly(): void
    {
        $settings = SessionConfigurationBuilder::forCore();
        $this->assertTrue(
            $settings['cookie_httponly'],
            'S2: the core session cookie must be HttpOnly — script-readable session ids are a breach precursor.'
        );
    }

    public function testCoreCookieIsSecureByDefault(): void
    {
        $settings = SessionConfigurationBuilder::forCore();
        $this->assertTrue(
            $settings['cookie_secure'],
            'S3: the core session cookie must default to Secure; plain HTTP requires an explicit opt-out.'
        );
    }

    public function testCoreCookieSecureCanBeExplicitlyDisabledForPlainHttpDev(): void
    {
        $settings = SessionConfigurationBuilder::forCore('', true, false);
        $this->assertFalse($settings['cookie_secure']);
        $this->assertTrue(
            $settings['cookie_httponly'],
            'HttpOnly must hold even when the secure flag is opted out for plain-HTTP dev.'
        );
    }

    public function testPortalCookieIsSecureByDefaultAndHttpOnly(): void
    {
        $settings = SessionConfigurationBuilder::forPortal();
        $this->assertTrue($settings['cookie_secure'], 'S3 applies to the portal preset as well.');
        $this->assertTrue($settings['cookie_httponly']);
    }

    public function testPortalCookieSecureCanBeExplicitlyDisabledForPlainHttpDev(): void
    {
        $settings = SessionConfigurationBuilder::forPortal('', true, false);
        $this->assertFalse($settings['cookie_secure']);
        $this->assertTrue($settings['cookie_httponly']);
    }

    public function testApiAndOauthPresetsRemainAlwaysSecure(): void
    {
        $this->assertTrue(SessionConfigurationBuilder::forApi()['cookie_secure']);
        $this->assertTrue(SessionConfigurationBuilder::forOAuth()['cookie_secure']);
        $this->assertTrue(SessionConfigurationBuilder::forApi()['cookie_httponly']);
        $this->assertTrue(SessionConfigurationBuilder::forOAuth()['cookie_httponly']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function hardenedPresetProvider(): array
    {
        return [
            'core' => [SessionConfigurationBuilder::forCore()],
            'portal' => [SessionConfigurationBuilder::forPortal()],
            'api' => [SessionConfigurationBuilder::forApi()],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('hardenedPresetProvider')]
    public function testBaselineHardeningIsPreserved(array $settings): void
    {
        $this->assertTrue($settings['use_strict_mode'], 'Strict session id mode must be preserved.');
        $this->assertTrue($settings['use_only_cookies'], 'Cookie-only sessions must be preserved.');
        $this->assertSame('Strict', $settings['cookie_samesite'], 'SameSite=Strict must be preserved.');
    }

    public function testOauthPresetKeepsSameSiteNoneForRedirectFlows(): void
    {
        $settings = SessionConfigurationBuilder::forOAuth();
        $this->assertSame(
            'None',
            $settings['cookie_samesite'],
            'OAuth redirect flows depend on SameSite=None; hardening must not regress them.'
        );
    }

    public function testCorePresetIdentityAndPathBehaviorUnchanged(): void
    {
        $default = SessionConfigurationBuilder::forCore();
        $this->assertSame(SessionUtil::CORE_SESSION_ID, $default['name']);
        $this->assertSame('/', $default['cookie_path']);

        $withRoot = SessionConfigurationBuilder::forCore('/openemr');
        $this->assertSame('/openemr/', $withRoot['cookie_path']);
    }
}

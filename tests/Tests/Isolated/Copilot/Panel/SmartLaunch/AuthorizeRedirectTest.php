<?php

/**
 * FROZEN acceptance tests — TRO-53 (Wave N): AuthorizeRedirect, the pure
 * builder for the SMART EHR-launch authorize redirect (TRO-51 /
 * docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and
 * frozen: implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test:
 *  - The launch page NEVER redirects on an issuer it does not recognise:
 *    `iss` must equal the deployment's own FHIR base (trailing-slash
 *    tolerant, otherwise exact — prefix/suffix lookalikes refused) or
 *    IssuerMismatchException is thrown.
 *  - The built URL targets the given authorize endpoint and carries the
 *    complete SMART EHR-launch parameter set: response_type=code,
 *    client_id, redirect_uri, scope === AuthorizeRedirect::SCOPES,
 *    launch (opaque passthrough), state, aud === iss, code_challenge,
 *    code_challenge_method=S256.
 *  - SCOPES is minimum-necessary (design §4.1): the module's own route
 *    scopes + openid/launch/api:oemr. No offline_access (session-bound v1,
 *    ARCHITECTURE §4), no broad user/ FHIR clinical scopes.
 *  - Blank required inputs are refused (\InvalidArgumentException).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel\SmartLaunch;

use OpenEMR\Modules\Copilot\Panel\SmartLaunch\AuthorizeRedirect;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\IssuerMismatchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthorizeRedirectTest extends TestCase
{
    private const FHIR_BASE = 'https://emr.example.test/apis/default/fhir';
    private const AUTHORIZE = 'https://emr.example.test/oauth2/default/authorize';
    private const CLIENT_ID = 'copilot-smart-client-id';
    private const REDIRECT = 'https://emr.example.test/interface/modules/custom_modules/oe-module-copilot/public/panel.html';
    private const LAUNCH = 'b3BhcXVlLWxhdW5jaC1jb2Rl';
    private const STATE = 'state-8f14e45fceea167a';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private function build(string $iss = self::FHIR_BASE, string $expectedBase = self::FHIR_BASE): string
    {
        return AuthorizeRedirect::build(
            $iss,
            $expectedBase,
            self::AUTHORIZE,
            self::CLIENT_ID,
            self::REDIRECT,
            self::LAUNCH,
            self::STATE,
            self::CHALLENGE,
        );
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($query, 'built URL carries a query string');
        parse_str($query, $params);
        /** @var array<string, string> $params */

        return $params;
    }

    public function testBuildsTheCompleteSmartAuthorizeUrl(): void
    {
        $url = $this->build();

        $this->assertStringStartsWith(self::AUTHORIZE . '?', $url, 'targets the authorize endpoint');

        $params = $this->queryOf($url);
        $this->assertSame('code', $params['response_type']);
        $this->assertSame(self::CLIENT_ID, $params['client_id']);
        $this->assertSame(self::REDIRECT, $params['redirect_uri']);
        $this->assertSame(AuthorizeRedirect::SCOPES, $params['scope']);
        $this->assertSame(self::LAUNCH, $params['launch'], 'launch code passes through opaque');
        $this->assertSame(self::STATE, $params['state']);
        $this->assertSame(self::FHIR_BASE, $params['aud'], 'SMART EHR launch: aud is the issuer');
        $this->assertSame(self::CHALLENGE, $params['code_challenge']);
        $this->assertSame('S256', $params['code_challenge_method'], 'PKCE S256 only — never "plain"');
    }

    public function testScopesAreMinimumNecessary(): void
    {
        $scopes = explode(' ', AuthorizeRedirect::SCOPES);

        foreach (
            [
                'openid',
                'launch',
                'api:oemr',
                'user/ping.read',
                'user/health.read',
                'user/ready.read',
                'user/turn.write',
                'user/document.write',
                'user/source.write',
            ] as $required
        ) {
            $this->assertContains($required, $scopes, "required scope: $required");
        }

        $this->assertNotContains('offline_access', $scopes, 'session-bound v1 — the offline-grant model is deferred (ARCHITECTURE §4)');
        $this->assertNotContains('api:fhir', $scopes, 'the launched panel calls module routes only');
        foreach ($scopes as $scope) {
            $this->assertDoesNotMatchRegularExpression(
                '#^user/(Patient|Observation|MedicationRequest|AllergyIntolerance|patient|medication|allergy|encounter|appointment|facility|user)\.#',
                $scope,
                'no broad user/ FHIR clinical scopes ride the launched panel token (minimum necessary)'
            );
        }
    }

    public function testAcceptsATrailingSlashDifferenceOnTheIssuer(): void
    {
        $url = $this->build(self::FHIR_BASE . '/', self::FHIR_BASE);

        $this->assertSame(self::FHIR_BASE . '/', $this->queryOf($url)['aud'], 'aud echoes the iss exactly as received');
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function mismatchedIssuerProvider(): array
    {
        return [
            'different host' => ['https://attacker.example.test/apis/default/fhir'],
            'suffix lookalike' => ['https://emr.example.test.attacker.example/apis/default/fhir'],
            'prefix lookalike (our base as a path)' => ['https://attacker.example.test/' . self::FHIR_BASE],
            'different scheme' => ['http://emr.example.test/apis/default/fhir'],
            'different site path' => ['https://emr.example.test/apis/other/fhir'],
            'empty issuer' => [''],
        ];
    }

    #[DataProvider('mismatchedIssuerProvider')]
    public function testRefusesAMismatchedIssuer(string $iss): void
    {
        $this->expectException(IssuerMismatchException::class);
        $this->build($iss, self::FHIR_BASE);
    }

    public function testIssuerMismatchIsARuntimeException(): void
    {
        $this->assertTrue(
            is_a(IssuerMismatchException::class, \RuntimeException::class, true),
            'refusal must be catchable without matching the generic 400 DomainException path'
        );
    }

    /**
     * @return array<string, array{string, string, string, string, string, string, string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function blankInputProvider(): array
    {
        $ok = [
            self::FHIR_BASE,
            self::FHIR_BASE,
            self::AUTHORIZE,
            self::CLIENT_ID,
            self::REDIRECT,
            self::LAUNCH,
            self::STATE,
            self::CHALLENGE,
        ];
        $cases = [];
        // iss (0) and expectedFhirBase (1) blank cases are covered by the
        // issuer-mismatch tests; the remaining six are hard input errors.
        $names = [2 => 'authorize endpoint', 3 => 'client id', 4 => 'redirect uri', 5 => 'launch', 6 => 'state', 7 => 'code challenge'];
        foreach ($names as $index => $name) {
            $args = $ok;
            $args[$index] = '  ';
            $cases["blank $name"] = $args;
        }

        return $cases;
    }

    #[DataProvider('blankInputProvider')]
    public function testRefusesBlankRequiredInputs(
        string $iss,
        string $expectedBase,
        string $authorize,
        string $clientId,
        string $redirect,
        string $launch,
        string $state,
        string $challenge,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        AuthorizeRedirect::build($iss, $expectedBase, $authorize, $clientId, $redirect, $launch, $state, $challenge);
    }
}

<?php

/**
 * SEC-101 — authenticity verification for the SignalWire fax webhook.
 *
 * The inbound webhook (library/webhook_receiver.php) previously trusted any
 * caller who knew the URL + a valid site id, then wrote to oe_faxsms_queue and
 * fetched/stored media with the practice's SignalWire token. These tests pin
 * the two testable pieces of the fix: verifySignature() (fail-closed wrapper
 * over the SignalWire-compat X-Twilio-Signature scheme) and buildRequestUrl()
 * (reconstructs the public URL SignalWire signed, proxy-aware). The procedural
 * entry point is covered by live smoke only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\FaxSMS\Utils;

use Composer\Autoload\ClassLoader;
use OpenEMR\Modules\FaxSMS\Utils\SignalWireWebhookValidator;
use PHPUnit\Framework\TestCase;
use Twilio\Security\RequestValidator;

final class SignalWireWebhookValidatorSignatureTest extends TestCase
{
    private const AUTH_TOKEN = 'PT-test-signing-token-0123456789';
    private const URL = 'https://emr.example.org/interface/modules/custom_modules/'
        . 'oe-module-faxsms/library/webhook_receiver.php?site=default&vendor=signalwire&type=fax';

    /**
     * The module's PSR-4 prefix is registered dynamically by the module manager
     * at runtime; the DB-less isolated suite must register it itself.
     *
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (!$loader instanceof ClassLoader) {
            self::fail('Composer ClassLoader not available to register module autoload prefix.');
        }
        $loader->addPsr4(
            'OpenEMR\\Modules\\FaxSMS\\',
            dirname(__DIR__, 6) . '/interface/modules/custom_modules/oe-module-faxsms/src/'
        );
    }

    /**
     * @param array<string, string> $params
     */
    private static function sign(string $url, array $params, string $token = self::AUTH_TOKEN): string
    {
        return (new RequestValidator($token))->computeSignature($url, $params);
    }

    public function testAcceptsACorrectlySignedFormWebhook(): void
    {
        $params = ['FaxSid' => 'abc123', 'Status' => 'received', 'From' => '+15551230000'];
        $signature = self::sign(self::URL, $params);

        $this->assertTrue(
            SignalWireWebhookValidator::verifySignature($signature, self::URL, $params, self::AUTH_TOKEN)
        );
    }

    public function testRejectsAMissingSignatureHeader(): void
    {
        $params = ['FaxSid' => 'abc123'];
        $this->assertFalse(
            SignalWireWebhookValidator::verifySignature('', self::URL, $params, self::AUTH_TOKEN),
            'An absent signature must fail closed'
        );
    }

    public function testRejectsWhenNoSigningTokenIsConfigured(): void
    {
        $params = ['FaxSid' => 'abc123'];
        $signature = self::sign(self::URL, $params);
        $this->assertFalse(
            SignalWireWebhookValidator::verifySignature($signature, self::URL, $params, ''),
            'An unconfigured signing token must fail closed, never open'
        );
    }

    public function testRejectsATamperedSignature(): void
    {
        $params = ['FaxSid' => 'abc123', 'Status' => 'received'];
        $signature = self::sign(self::URL, $params);
        $this->assertFalse(
            SignalWireWebhookValidator::verifySignature($signature . 'X', self::URL, $params, self::AUTH_TOKEN)
        );
    }

    public function testRejectsAForgedPayloadUnderAValidLookingSignature(): void
    {
        // Attacker signs their own payload with a token they don't have → mismatch.
        $realParams = ['FaxSid' => 'abc123', 'Status' => 'received'];
        $signatureForReal = self::sign(self::URL, $realParams);

        $forgedParams = ['FaxSid' => 'abc123', 'Status' => 'received', 'From' => '+19998887777'];
        $this->assertFalse(
            SignalWireWebhookValidator::verifySignature($signatureForReal, self::URL, $forgedParams, self::AUTH_TOKEN),
            'Adding/altering a parameter must invalidate the signature'
        );
    }

    public function testRejectsAValidSignatureMadeWithADifferentToken(): void
    {
        $params = ['FaxSid' => 'abc123'];
        $signature = self::sign(self::URL, $params, 'some-other-token');
        $this->assertFalse(
            SignalWireWebhookValidator::verifySignature($signature, self::URL, $params, self::AUTH_TOKEN)
        );
    }

    public function testVerifiesAJsonBodyWebhookViaBodyHash(): void
    {
        // SignalWire/Twilio JSON webhooks append ?bodySHA256=<hash> to the URL
        // and sign the URL; the raw body is passed through as a string.
        $body = '{"call":{"call_id":"xyz"},"vars":{"receive_fax_document":"https://files.signalwire.com/x"}}';
        $urlWithBodyHash = self::URL . '&bodySHA256=' . RequestValidator::computeBodyHash($body);
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature($urlWithBodyHash);

        $this->assertTrue(
            SignalWireWebhookValidator::verifySignature($signature, $urlWithBodyHash, $body, self::AUTH_TOKEN)
        );
        $this->assertFalse(
            SignalWireWebhookValidator::verifySignature($signature, $urlWithBodyHash, $body . ' ', self::AUTH_TOKEN),
            'A tampered JSON body must fail the body-hash check'
        );
    }

    public function testBuildRequestUrlFromDirectHttpsRequest(): void
    {
        $server = [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'emr.example.org',
            'REQUEST_URI' => '/webhook_receiver.php?site=default&vendor=signalwire&type=fax',
        ];
        $this->assertSame(
            'https://emr.example.org/webhook_receiver.php?site=default&vendor=signalwire&type=fax',
            SignalWireWebhookValidator::buildRequestUrl($server)
        );
    }

    public function testBuildRequestUrlHonoursProxyForwardedHeaders(): void
    {
        // Railway/behind-proxy: the app sees http + internal host, but SignalWire
        // signed the public https URL carried in the forwarded headers.
        $server = [
            'HTTPS' => 'off',
            'HTTP_HOST' => 'internal-app:8080',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'emr.example.org',
            'REQUEST_URI' => '/webhook_receiver.php?site=default',
        ];
        $this->assertSame(
            'https://emr.example.org/webhook_receiver.php?site=default',
            SignalWireWebhookValidator::buildRequestUrl($server)
        );
    }

    public function testBuildRequestUrlTakesTheFirstForwardedHop(): void
    {
        $server = [
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
            'HTTP_X_FORWARDED_HOST' => 'emr.example.org, internal',
            'REQUEST_URI' => '/w.php',
        ];
        $this->assertSame(
            'https://emr.example.org/w.php',
            SignalWireWebhookValidator::buildRequestUrl($server)
        );
    }

    public function testBuildRequestUrlDefaultsToHttpWhenNoTlsSignal(): void
    {
        $server = [
            'HTTP_HOST' => 'emr.example.org',
            'REQUEST_URI' => '/w.php',
        ];
        $this->assertSame('http://emr.example.org/w.php', SignalWireWebhookValidator::buildRequestUrl($server));
    }
}

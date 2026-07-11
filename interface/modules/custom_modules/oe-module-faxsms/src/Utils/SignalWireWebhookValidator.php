<?php

/**
 * SignalWire Webhook Receiver
 * Handles incoming fax notifications from SignalWire
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    SignalWire Integration
 * @copyright Copyright (c) 2026
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\FaxSMS\Utils;

use SensitiveParameter;
use Twilio\Security\RequestValidator;

/**
 * SignalWire webhook input validator helpers
 *
 */
final class SignalWireWebhookValidator
{
    /**
     * SEC-101: verify inbound webhook authenticity before trusting its input.
     *
     * SignalWire's compatibility (LAML) API signs webhooks with the
     * Twilio-compatible `X-Twilio-Signature`: HMAC-SHA1 of the request URL with
     * the alphabetically-sorted POST params appended, keyed by the project's
     * auth token, base64-encoded (for JSON bodies the URL carries a
     * `bodySHA256` param instead and the raw body is passed through). This
     * delegates the crypto to Twilio's vetted RequestValidator (constant-time
     * compare; tries the URL with and without an explicit port).
     *
     * Fails closed: a missing signature header OR an unconfigured signing token
     * returns false, so an outage or misconfiguration rejects webhooks rather
     * than accepting forged ones. Never returns true on the strength of the
     * caller's input alone.
     *
     * @param string $signatureHeader the `X-Twilio-Signature` request header
     * @param string $requestUrl the public URL SignalWire signed (see buildRequestUrl())
     * @param array<array-key, mixed>|string $payload POST params for form webhooks, or the raw body for JSON
     * @param string $authToken the SignalWire project auth token (the signing key)
     */
    public static function verifySignature(
        string $signatureHeader,
        string $requestUrl,
        array|string $payload,
        #[SensitiveParameter] string $authToken,
    ): bool {
        if ($signatureHeader === '' || $authToken === '') {
            return false;
        }

        return (new RequestValidator($authToken))->validate($signatureHeader, $requestUrl, $payload);
    }

    /**
     * Reconstructs the public URL SignalWire signed, from the request's server
     * variables. Proxy-aware: prefers `X-Forwarded-Proto`/`X-Forwarded-Host`
     * (set by Railway and similar front proxies) over the app-visible scheme
     * and host, taking the first hop of a comma-listed header. Spoofing these
     * headers cannot forge a valid signature — a mismatched URL only makes a
     * legitimate request fail verification, never the reverse.
     *
     * @param array<array-key, mixed> $server the request server bag (e.g. $_SERVER)
     */
    public static function buildRequestUrl(array $server): string
    {
        $proto = self::firstHeaderValue($server, 'HTTP_X_FORWARDED_PROTO');
        if ($proto === '') {
            $proto = self::stringValue($server, 'HTTPS') === 'on' ? 'https' : 'http';
        }

        $host = self::firstHeaderValue($server, 'HTTP_X_FORWARDED_HOST');
        if ($host === '') {
            $host = self::stringValue($server, 'HTTP_HOST');
        }

        return $proto . '://' . $host . self::stringValue($server, 'REQUEST_URI');
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function stringValue(array $server, string $key): string
    {
        $value = $server[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * First value of a possibly comma-listed forwarded header, trimmed.
     *
     * @param array<array-key, mixed> $server
     */
    private static function firstHeaderValue(array $server, string $key): string
    {
        $value = self::stringValue($server, $key);
        if ($value === '') {
            return '';
        }

        return trim(explode(',', $value)[0]);
    }

    /**
     * @param string $faxId
     * @return string
     */
    public static function validateFaxId(string $faxId): string
    {
        // Remove any characters that aren't alphanumeric, hyphens, or underscores
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $faxId);
        // Limit length to prevent DoS
        return substr($sanitized ?? '', 0, 255);
    }

    /**
     * @param string $status
     * @return string
     */
    public static function validateFaxStatus(string $status): string
    {
        $allowedStatuses = [
            'queued', 'processing', 'sending', 'sent', 'delivered',
            'receiving', 'received', 'failed', 'no-answer', 'busy',
            'canceled', 'unknown'
        ];
        $status = strtolower(trim($status));
        return in_array($status, $allowedStatuses, true) ? $status : 'unknown';
    }

    /**
     * @param string $phone
     * @return string
     */
    public static function validatePhoneNumber(string $phone): string
    {
        // Remove all characters except digits and + for international format
        $sanitized = preg_replace('/[^0-9+]/', '', $phone);
        // Limit length (E.164 max is 15 digits + country code)
        return substr($sanitized ?? '', 0, 20);
    }

    /**
     * @param mixed $value
     * @param int   $min
     * @param int   $max
     * @return int
     */
    public static function validateInteger(mixed $value, int $min, int $max): int
    {
        $intValue = filter_var($value, FILTER_VALIDATE_INT);
        if ($intValue === false) {
            return $min;
        }
        return max($min, min($max, $intValue));
    }

    /**
     * @param string $direction
     * @return string
     */
    public static function validateDirection(string $direction): string
    {
        $allowedDirections = ['inbound', 'outbound', 'outbound-api', 'outbound-call'];
        $direction = strtolower(trim($direction));
        return in_array($direction, $allowedDirections, true) ? $direction : 'inbound';
    }

    /**
     * @param string $input
     * @param int    $maxLength
     * @return string
     */
    public static function validateString(string $input, int $maxLength): string
    {
        // Remove control characters but preserve newlines for error messages
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        return substr($sanitized ?? '', 0, $maxLength);
    }

    /**
     * @param string $siteId
     * @return string
     */
    public static function validateSiteId(string $siteId): string
    {
        // Sanitize to prevent path traversal and injection attacks
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $siteId);
        return !empty($sanitized) ? $sanitized : 'default';
    }

    /**
     * @param string $url
     * @return bool
     */
    public static function isValidSignalWireUrl(string $url): bool
    {
        // Parse and validate URL structure
        $parsedUrl = parse_url($url);

        if ($parsedUrl === false || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
            return false;
        }

        // Only allow HTTPS protocol to prevent file:// and other protocol attacks
        if ($parsedUrl['scheme'] !== 'https') {
            return false;
        }

        // Whitelist of allowed SignalWire domains to prevent SSRF
        $allowedDomains = [
            'files.signalwire.com',
            'api.signalwire.com'
        ];

        $host = strtolower($parsedUrl['host']);

        // Check if host matches allowed domains exactly or is a subdomain
        foreach ($allowedDomains as $allowedDomain) {
            if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
                return true;
            }
        }

        return false;
    }
}

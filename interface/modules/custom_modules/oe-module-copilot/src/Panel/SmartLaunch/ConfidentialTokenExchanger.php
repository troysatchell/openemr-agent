<?php

/**
 * Server-side authorization-code → token exchange for the confidential SMART
 * client (TRO-53 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2, decision
 * D2-confidential).
 *
 * A confidential client authenticates to the token endpoint with its secret;
 * this exchange therefore runs on the server (`launch-exchange.php`), never in
 * browser JS. PKCE is retained — the `code_verifier` (held in the PHP session)
 * is sent alongside the secret as additional proof of possession.
 *
 * Mirrors {@see \OpenEMR\Modules\Copilot\Rag\CohereHttpTransport}: a real
 * Guzzle client with `http_errors` disabled so a non-2xx token response comes
 * back as an ordinary `[status, body]` pair for the caller to shape into a
 * generic failure, rather than a Guzzle exception carrying vendor internals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel\SmartLaunch;

use GuzzleHttp\ClientInterface;

final class ConfidentialTokenExchanger
{
    /**
     * Exchange an authorization code for an access token. Returns
     * `[httpStatus, decodedBody]`; the caller decides what a non-2xx or a
     * body without an `access_token` means (always a generic failure — token
     * responses carry OAuth error details that must not reach the user).
     *
     * The HTTP client is injectable for testing/substitution; the default is a
     * real Guzzle client with `http_errors` disabled so a non-2xx token
     * response comes back as an ordinary `[status, body]` pair rather than a
     * Guzzle exception (mirrors {@see \OpenEMR\Modules\Copilot\Rag\CohereHttpTransport}).
     *
     * @return array{int, array<string, mixed>}
     */
    public static function exchange(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
        string $codeVerifier,
        ?ClientInterface $httpClient = null,
    ): array {
        $httpClient ??= new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);

        $response = $httpClient->request('POST', $tokenEndpoint, [
            'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code_verifier' => $codeVerifier,
            ],
        ]);

        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The token endpoint response body did not decode to a JSON object.');
        }

        $body = [];
        foreach ($decoded as $key => $value) {
            $body[(string) $key] = $value;
        }

        return [$response->getStatusCode(), $body];
    }
}

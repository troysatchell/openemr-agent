<?php

/**
 * Pure builder for the SMART EHR-launch authorize redirect
 * (TRO-53 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2).
 *
 * The launch page never redirects on an issuer it does not recognise: `iss`
 * must equal this deployment's own FHIR base (trailing-slash tolerant,
 * otherwise exact — prefix/suffix lookalikes are refused) or
 * {@see IssuerMismatchException} is thrown before any URL is built.
 *
 * SCOPES is minimum-necessary (design §4.1): the module's own route scopes
 * (`user/{ping,health,ready}.read`, `user/{turn,document,source}.write`)
 * plus the standard `openid`/`launch`/`api:oemr` triad the SMART EHR-launch
 * handshake requires. No `offline_access` (session-bound v1 — the
 * offline-grant model is deferred, ARCHITECTURE §4) and no broad `user/`
 * FHIR clinical scopes — the launched panel calls this module's own guarded
 * routes only; chart reads happen in-process as the delegated physician.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel\SmartLaunch;

final class AuthorizeRedirect
{
    // Untyped const: this project's floor is PHP 8.2 (composer.json), and
    // typed class constants are an 8.3+ feature — matching the untyped
    // constants used elsewhere in this module (e.g. CohereHttpTransport).
    public const SCOPES = 'openid launch api:oemr user/ping.read user/health.read user/ready.read user/turn.write user/document.write user/source.write';

    private function __construct()
    {
        // Static factory only — no instance state.
    }

    /**
     * @throws IssuerMismatchException when $iss cannot be reconciled with
     *         $expectedFhirBase (trailing-slash tolerant, otherwise exact)
     * @throws \InvalidArgumentException when any other required argument is
     *         blank or whitespace-only
     */
    public static function build(
        string $iss,
        string $expectedFhirBase,
        string $authorizeEndpoint,
        string $clientId,
        string $redirectUri,
        string $launch,
        string $state,
        string $codeChallenge,
    ): string {
        if (rtrim($iss, '/') === '' || rtrim($iss, '/') !== rtrim($expectedFhirBase, '/')) {
            throw new IssuerMismatchException('This launch request could not be verified against this server.');
        }

        $required = [
            'authorize endpoint' => $authorizeEndpoint,
            'client id' => $clientId,
            'redirect uri' => $redirectUri,
            'launch' => $launch,
            'state' => $state,
            'code challenge' => $codeChallenge,
        ];
        foreach ($required as $label => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException(sprintf('SMART authorize redirect %s must not be blank.', $label));
            }
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'launch' => $launch,
            'state' => $state,
            'aud' => $iss,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return $authorizeEndpoint . '?' . $query;
    }
}

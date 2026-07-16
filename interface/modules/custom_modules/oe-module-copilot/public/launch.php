<?php

/**
 * SMART EHR-launch entry page for the Clinical Co-Pilot panel
 * (TRO-53 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2).
 *
 * This file requires interface/globals.php — the sanctioned module-page
 * session bootstrap (cf. public/ajax.php's own docblock, and
 * oe-module-faxsms/messageUI.php), used ONLY to establish the logged-in
 * physician's session: the EHR launch always happens from inside an
 * authenticated EMR session, opened by clicking the co-pilot's card on the
 * patient chart (src/FHIR/SMART/SmartLaunchController.php). No patient data
 * is read on this page at all — it only mints a PKCE pair, validates the
 * launch's `iss` against this deployment's own FHIR base, and redirects the
 * browser into the standard OAuth2 authorization-code flow.
 *
 * Issuer validation is never skipped: {@see AuthorizeRedirect::build()}
 * throws {@see IssuerMismatchException} on anything but an exact match
 * (trailing-slash tolerant) against {@see ServerConfig::getFhirUrl()} — this
 * page NEVER redirects to an authorize endpoint on the strength of an
 * unrecognised issuer (R11 generic failure, no redirect).
 *
 * The PKCE verifier and state are held in the PHP session (server-side) — a
 * confidential client (founder decision D2, 2026-07-15) authenticates to the
 * token endpoint with its secret, so the exchange runs server-side in
 * public/launch-exchange.php; the verifier is proof of possession and never
 * touches the browser. panel.html completes the round trip by POSTing the
 * returned {code,state} to that endpoint (TRO-53).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\FHIR\Config\ServerConfig;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\AuthorizeRedirect;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\IssuerMismatchException;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\PkcePair;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\SmartLaunchSession;

require_once __DIR__ . '/../../../../globals.php';

/**
 * Renders a minimal, generic failure page and terminates the request.
 * Never echoes exception messages or request internals (R11) — the
 * message here is always a fixed, human-actionable string chosen by the
 * caller.
 */
$fail = static function (int $statusCode, string $message): never {
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Clinical Co-Pilot &mdash; launch failed</title></head><body>'
        . '<h1>Clinical Co-Pilot</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</body></html>';
    exit;
};

// filter_input(), not $_GET directly (project convention: no raw superglobal
// access outside the abstraction layer) — equivalent read, typed narrowing
// happens immediately below.
$launchParam = filter_input(INPUT_GET, 'launch');
$issParam = filter_input(INPUT_GET, 'iss');
if (!is_string($launchParam) || trim($launchParam) === '' || !is_string($issParam) || trim($issParam) === '') {
    $fail(400, 'This launch link is missing required parameters. Please relaunch from the patient chart.');
}
$launch = $launchParam;
$iss = $issParam;

$serverConfig = new ServerConfig();
$expectedFhirBase = $serverConfig->getFhirUrl();
$authorizeEndpoint = $serverConfig->getAuthorizeUrl();

$clientId = getenv('COPILOT_SMART_CLIENT_ID');
if ($clientId === false || trim($clientId) === '') {
    $fail(500, 'SMART client not configured. An operator must set COPILOT_SMART_CLIENT_ID for this deployment.');
}

// The module's own launched panel, addressed the same way ServerConfig
// builds every other deployment-relative URL it hands out (oauth address +
// web root) — never a hardcoded host.
$panelRelativePath = '/interface/modules/custom_modules/oe-module-copilot/public/panel.html';
$redirectUri = $serverConfig->getOauthAddress() . $serverConfig->getWebRoot() . $panelRelativePath;

$pair = PkcePair::generate();
$state = bin2hex(random_bytes(16));

try {
    $authorizeUrl = AuthorizeRedirect::build(
        $iss,
        $expectedFhirBase,
        $authorizeEndpoint,
        $clientId,
        $redirectUri,
        $launch,
        $state,
        $pair->challenge,
    );
} catch (IssuerMismatchException $e) {
    // Issuer mismatch is a security-relevant signal (a launch pointed at a
    // foreign authorization server) — record it server-side for audit; the
    // user still sees only the fixed generic page (R11, never $e->getMessage).
    ServiceContainer::getLogger()->warning('copilot SMART launch rejected: issuer mismatch', ['exception' => $e]);
    $fail(400, 'This launch request could not be verified against this server. Please relaunch from the patient chart.');
} catch (\InvalidArgumentException $e) {
    ServiceContainer::getLogger()->warning('copilot SMART launch rejected: malformed launch parameters', ['exception' => $e]);
    $fail(400, 'This launch request is malformed. Please relaunch from the patient chart.');
}

// The verifier + state live in the PHP SESSION, never the browser: a
// confidential client (D2) exchanges the code server-side with its secret,
// and the verifier is proof of possession. launch-exchange.php reads these
// back — keyed to this same session — and clears them once the code is
// exchanged (single use). redirect_uri is stored too so the exchange sends
// the identical value the authorize request used (OAuth requires the match).
SmartLaunchSession::store(
    SessionWrapperFactory::getInstance()->getActiveSession(),
    $pair->verifier,
    $state,
    $redirectUri,
);

// Plain server-side redirect — the browser only needs to reach the authorize
// endpoint; nothing secret rides the URL (the challenge is public, the
// verifier stayed server-side).
header('Location: ' . $authorizeUrl);
http_response_code(302);
exit;

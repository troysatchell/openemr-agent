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
 * The PKCE verifier, state, and the endpoints/identifiers the panel needs to
 * complete the code exchange are handed to the browser via `sessionStorage`
 * only (never a cookie, never a server-side session-keyed store) — the
 * verifier is a bearer of authorization-code exchange, not clinical data,
 * and public/panel.html reads it back after the redirect completes the
 * round trip (TRO-53).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\FHIR\Config\ServerConfig;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\AuthorizeRedirect;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\IssuerMismatchException;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\PkcePair;

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
$tokenEndpoint = $serverConfig->getTokenUrl();

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
} catch (IssuerMismatchException) {
    $fail(400, 'This launch request could not be verified against this server. Please relaunch from the patient chart.');
} catch (\InvalidArgumentException) {
    $fail(400, 'This launch request is malformed. Please relaunch from the patient chart.');
}

// Handed to the browser via sessionStorage only — never a cookie, never a
// server-side session-keyed store. panel.html reads this back after the
// authorize redirect completes and clears it once the code exchange
// succeeds.
$handshakeState = [
    'verifier' => $pair->verifier,
    'state' => $state,
    'token_endpoint' => $tokenEndpoint,
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
];

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
try {
    $handshakeStateJson = json_encode($handshakeState, JSON_THROW_ON_ERROR | $jsonFlags);
    $authorizeUrlJson = json_encode($authorizeUrl, JSON_THROW_ON_ERROR | $jsonFlags);
} catch (\JsonException) {
    $fail(400, 'This launch request could not be processed. Please relaunch from the patient chart.');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clinical Co-Pilot &mdash; launching&hellip;</title>
</head>
<body>
<p>Launching Clinical Co-Pilot&hellip;</p>
<script>
"use strict";
sessionStorage.setItem('copilot_smart_launch', JSON.stringify(<?php echo $handshakeStateJson; ?>));
window.location.replace(<?php echo $authorizeUrlJson; ?>);
</script>
</body>
</html>

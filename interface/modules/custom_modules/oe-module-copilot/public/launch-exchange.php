<?php

/**
 * Server-side SMART code-exchange endpoint for the Clinical Co-Pilot launched
 * panel (TRO-53 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2, decision
 * D2-confidential).
 *
 * This file requires interface/globals.php — the sanctioned module-page
 * session bootstrap (cf. public/ajax.php, public/launch.php) — because the
 * exchange is bound to the physician's session: `launch.php` stashed the PKCE
 * verifier and the `state` nonce there before redirecting to the
 * authorization server, and this endpoint consumes them (once) to prove the
 * returning code belongs to the launch this same session initiated.
 *
 * Why server-side: a confidential client authenticates to the token endpoint
 * with its SECRET (COPILOT_SMART_CLIENT_SECRET), which must never reach
 * browser JS. The panel POSTs only the opaque {code, state}; the secret and
 * the verifier stay on the server. The response returns the access token and
 * the launch-context patient to the panel — the access token necessarily
 * reaches the browser (the panel calls the guarded REST routes with it), but
 * the secret does not.
 *
 * All failures return a generic JSON error (R11) — OAuth token-endpoint error
 * bodies carry internals and never reach the user.
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
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\ConfidentialTokenExchanger;
use OpenEMR\Modules\Copilot\Panel\SmartLaunch\SmartLaunchSession;

require_once __DIR__ . '/../../../../globals.php';

header('Content-Type: application/json');

/**
 * Emit a generic JSON error and terminate. Never echoes exception messages or
 * token-endpoint internals (R11) — a fixed, human-actionable string only.
 */
$fail = static function (int $statusCode, string $message): never {
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
};

// filter_input(INPUT_SERVER, ...), not $_SERVER directly (project convention:
// no raw superglobal access outside the abstraction layer).
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') !== 'POST') {
    $fail(405, 'Method not allowed.');
}

$rawBody = file_get_contents('php://input');
try {
    $decoded = json_decode($rawBody !== false ? $rawBody : '', true, 16, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    $fail(400, 'Invalid request.');
}
if (!is_array($decoded)) {
    $fail(400, 'Invalid request.');
}

$code = is_string($decoded['code'] ?? null) ? trim($decoded['code']) : '';
$state = is_string($decoded['state'] ?? null) ? $decoded['state'] : '';
if ($code === '' || $state === '') {
    $fail(400, 'This launch could not be completed. Please relaunch from the patient chart.');
}

// Consume the pending handshake for THIS session, validating the returned
// state against the stored nonce (constant-time). A mismatch, a missing
// handshake, or a replay all yield null — the binding is the session + state,
// not a CSRF token the static panel cannot carry.
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$handshake = SmartLaunchSession::consume($session, $state);
if ($handshake === null) {
    $fail(400, 'This launch could not be verified. Please relaunch from the patient chart.');
}

$clientId = getenv('COPILOT_SMART_CLIENT_ID');
$clientSecret = getenv('COPILOT_SMART_CLIENT_SECRET');
if (!is_string($clientId) || trim($clientId) === '' || !is_string($clientSecret) || trim($clientSecret) === '') {
    ServiceContainer::getLogger()->error('copilot SMART exchange failed: client id/secret not configured');
    $fail(500, 'SMART client not configured. An operator must set COPILOT_SMART_CLIENT_ID and COPILOT_SMART_CLIENT_SECRET.');
}

try {
    [$status, $body] = ConfidentialTokenExchanger::exchange(
        (new ServerConfig())->getTokenUrl(),
        $clientId,
        $clientSecret,
        $code,
        $handshake['redirect_uri'],
        $handshake['verifier'],
    );
} catch (\JsonException | \RuntimeException | \GuzzleHttp\Exception\GuzzleException $e) {
    ServiceContainer::getLogger()->error('copilot SMART exchange failed: token request error', ['exception' => $e]);
    $fail(502, 'The authorization server could not be reached. Please try again.');
}

$accessToken = $body['access_token'] ?? null;
if ($status < 200 || $status >= 300 || !is_string($accessToken) || $accessToken === '') {
    // Log the OAuth status server-side; the user sees only the generic page.
    ServiceContainer::getLogger()->warning('copilot SMART exchange rejected by authorization server', ['status' => $status]);
    $fail(400, 'This launch could not be completed. Please relaunch from the patient chart.');
}

// The launch-context patient uuid rides the token response (SMART context).
// It may be absent (a launch without patient context) — the panel handles
// that honestly rather than inventing one.
$patient = is_string($body['patient'] ?? null) ? $body['patient'] : null;

echo json_encode([
    'access_token' => $accessToken,
    'patient' => $patient,
]);
exit;

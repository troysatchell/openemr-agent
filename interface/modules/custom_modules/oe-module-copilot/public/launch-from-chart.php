<?php

/**
 * One-click SMART launch from the patient chart's left menu (TRO-53 flow;
 * docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2).
 *
 * This page is the redirect behind the "Co-Pilot" chart-menu entry
 * ({@see \OpenEMR\Modules\Copilot\Panel\ChartMenuItem}): it mints the SAME
 * EHR-launch context the chart's SMART card mints (core SMARTLaunchToken,
 * cf. src/FHIR/SMART/SmartLaunchController.php) for the SESSION's active
 * patient, then hands off to the module's existing launch.php — one launch
 * vocabulary, no second handshake implementation. From there the standard
 * flow runs: issuer validation, PKCE, confidential server-side code
 * exchange, panel scoped to the launch patient.
 *
 * This file requires interface/globals.php — the sanctioned module-page
 * session bootstrap (same posture as launch.php's own docblock) — used ONLY
 * to establish the logged-in physician's session and its active-patient
 * context. No patient data is read on this page: the pid is the session's,
 * never a request parameter (a caller must never choose whose chart is
 * launched), and the only lookup is pid -> uuid through the uuid registry.
 * Failures render a fixed generic page (R11) — never exception text.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\FHIR\Config\ServerConfig;
use OpenEMR\FHIR\SMART\SMARTLaunchToken;
use OpenEMR\Services\PatientService;

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

$pid = PatientSessionUtil::getPid();
if ($pid <= 0) {
    $fail(400, 'No active patient in this session. Open a patient chart first, then launch the Co-Pilot from its menu.');
}

try {
    // pid -> uuid through the registry (D7: the trusted surrogate key),
    // scoped to THIS patient only — never the table-wide backfill the chart
    // card runs, which scans and mutates every patient_data row on a click
    // path.
    $patientService = new PatientService();
    $binaryUuid = $patientService->getUuid((string) $pid);
    if (!is_string($binaryUuid) || $binaryUuid === '') {
        UuidRegistry::createMissingUuidForRow('patient_data', 'pid', $pid);
        $binaryUuid = $patientService->getUuid((string) $pid);
    }
    if (!is_string($binaryUuid) || $binaryUuid === '') {
        $fail(500, 'The active patient could not be resolved for launch. Please reopen the patient chart and try again.');
    }
    $patientUuid = UuidRegistry::uuidToString($binaryUuid);

    $token = new SMARTLaunchToken($patientUuid);
    $token->setIntent(SMARTLaunchToken::INTENT_MAIN_TAB);
    $serializedLaunch = $token->serialize();
    if (!is_string($serializedLaunch) || $serializedLaunch === '') {
        $fail(500, 'The launch context could not be created. Please reopen the patient chart and try again.');
    }

    // issuer and audience are the same in an EHR SMART launch, and both come
    // from ServerConfig — never a hardcoded host (and never the request
    // scheme, which lies behind a TLS-terminating proxy).
    $serverConfig = new ServerConfig();
    $issuer = $serverConfig->getFhirUrl();

    $launchPage = $serverConfig->getOauthAddress() . $serverConfig->getWebRoot()
        . '/interface/modules/custom_modules/oe-module-copilot/public/launch.php?launch='
        . urlencode($serializedLaunch)
        . '&iss=' . urlencode($issuer)
        . '&aud=' . urlencode($issuer);
} catch (\Throwable $e) {
    // Log server-side for diagnosis; the user sees only the fixed page (R11).
    ServiceContainer::getLogger()->error('copilot chart launch failed to mint its context', ['exception' => $e]);
    $fail(500, 'The Co-Pilot could not be launched. Please reopen the patient chart and try again.');
}

header('Location: ' . $launchPage);
http_response_code(302);
exit;

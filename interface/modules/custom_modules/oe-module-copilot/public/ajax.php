<?php

/**
 * Session-bound AJAX endpoint for the in-EMR Clinical Co-Pilot panel (T21;
 * UC1/UC2/UC3; AUDIT S4/S5; ARCHITECTURE.md §4 session-bound delegation).
 *
 * This file requires interface/globals.php — the sanctioned module-page
 * pattern (cf. oe-module-faxsms/messageUI.php), used ONLY to bootstrap the
 * session/CSRF/ACL machinery this entry file needs. The no-globals.php
 * bright line (CLAUDE.md) targets CLI/batch patient reads (S4: the native
 * background path sets $ignoreAuth = true) — it is not a prohibition on the
 * standard logged-in-user session page bootstrap every module uses. Every
 * actual patient read below still goes through the guarded service layer
 * (AppointmentService, EncounterService, the FHIR-backed chart snapshot
 * provider) — never a raw legacy-table query, never a service account.
 *
 * Composition root for this request: a single SessionGate (S4/S5 default
 * deny — CSRF, then ACL, then a named principal, in that fixed order) gates
 * every action before any handler is constructed. Each action carries its
 * own ACL requirement; the resulting PhysicianContext is the ONLY principal
 * handed to the Panel/* handlers. Error responses are always generic — never
 * $e->getMessage() — matching TurnEndpoint/SnapshotEndpoint's own "never
 * leak internals" convention (R11).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\Copilot\Bootstrap;
use OpenEMR\Modules\Copilot\DataTrust\ClinicalDate;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Panel\SessionAccessDeniedException;
use OpenEMR\Modules\Copilot\Panel\SessionGate;
use OpenEMR\Modules\Copilot\Panel\SnapshotEndpoint;
use OpenEMR\Modules\Copilot\Panel\TodayScheduleEndpoint;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;
use OpenEMR\Modules\Copilot\Routes\TurnEndpoint;
use OpenEMR\Modules\Copilot\Snapshot\SnapshotComposer;
use OpenEMR\Services\AppointmentService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\Search\DateSearchField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Services\Search\TokenSearchValue;
use Psr\Clock\ClockInterface;

require_once __DIR__ . '/../../../../globals.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
try {
    $decoded = json_decode($rawBody !== false ? $rawBody : '', true, 16, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$action = is_string($decoded['action'] ?? null) ? $decoded['action'] : null;
$csrfToken = is_string($decoded['csrf_token'] ?? null) ? $decoded['csrf_token'] : null;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

$gate = new SessionGate(
    csrfVerifier: static fn (string $token): bool => CsrfUtils::verifyCsrfToken($token, $session),
    aclVerifier: static fn (string $section, string $value): bool => (bool) AclMain::aclCheckCore($section, $value),
    principalReader: static fn (): array => [
        'username' => $session->get('authUser'),
        'userId' => $session->get('authUserID'),
    ],
);

$clock = new class implements ClockInterface {
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
};

/**
 * Today's-schedule reader: backs TodayScheduleEndpoint with
 * AppointmentService::search() — the guarded service surface, never raw
 * SQL — filtered to this provider's day.
 *
 * @return list<array<string, mixed>>
 */
$scheduleReader = static function (int $providerId, string $day): array {
    $service = new AppointmentService();
    $result = $service->search([
        'pc_aid' => new TokenSearchField('pc_aid', [new TokenSearchValue((string) $providerId)]),
        'pc_eventDate' => new DateSearchField('pc_eventDate', ['eq' . $day], DateSearchField::DATE_TYPE_DATE),
    ], true);

    // Scope decision, not a data-trust drop (D1): AppointmentService::search()
    // returns every calendar block on this provider's day, including
    // provider-only entries (lunch, blocked time) that carry no patient at
    // all. Those rows are out of scope for a *patient* dropdown, so they are
    // filtered out here at the reader boundary — TodayScheduleEndpoint
    // itself still carries and marks unselectable any row that HAS a pid but
    // fails to parse cleanly; that D1 "never drop" rule is unchanged there.
    $patientRows = [];
    foreach ($result->getData() as $row) {
        $pid = $row['pid'] ?? null;
        $isPositiveId = (is_int($pid) && $pid > 0)
            || (is_string($pid) && ctype_digit($pid) && (int) $pid > 0);
        if ($isPositiveId) {
            $patientRows[] = $row;
        }
    }

    return $patientRows;
};

/**
 * Last-visit resolver for SnapshotEndpoint: the most recent encounter date
 * on record for a pid, via EncounterService::getMostRecentEncounterForPatient()
 * (src/Services/EncounterService.php) — never raw SQL against
 * form_encounter. The date is parsed defensively via ClinicalDate (D0/D6):
 * NULL, '0000-00-00...', or free text all become null rather than throwing.
 * A genuine query failure is not caught here and propagates to the request's
 * top-level error mapping below.
 */
$lastVisitResolver = static function (int $pid): ?\DateTimeImmutable {
    $encounter = (new EncounterService())->getMostRecentEncounterForPatient($pid);
    $rawDate = $encounter['date'] ?? null;

    return ClinicalDate::tryParse(is_string($rawDate) ? $rawDate : null);
};

try {
    switch ($action) {
        case 'schedule':
            $physician = $gate->authorize(new AclRequirement('patients', 'appt'), $csrfToken);
            $endpoint = new TodayScheduleEndpoint($scheduleReader, $clock);
            $result = $endpoint->handle($physician);
            break;

        case 'snapshot':
            $physician = $gate->authorize(new AclRequirement('patients', 'med'), $csrfToken);
            $endpoint = new SnapshotEndpoint(
                Bootstrap::buildChartSnapshotProvider(),
                CriticalSubsetDetectors::withDraftTables(),
                new SnapshotComposer(),
                $lastVisitResolver,
                $clock,
            );
            $result = $endpoint->handle($physician, $decoded);
            break;

        case 'turn':
            $physician = $gate->authorize(new AclRequirement('patients', 'med'), $csrfToken);
            $endpoint = new TurnEndpoint(Bootstrap::buildTurnOrchestrator());
            $result = $endpoint->handle($physician, $decoded);
            break;

        default:
            throw new \DomainException('Unknown action.');
    }

    echo json_encode($result);
} catch (SessionAccessDeniedException) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
} catch (\DomainException) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
} catch (\Throwable $e) {
    error_log('oe-module-copilot ajax.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Request failed.']);
}

<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Background;

final class BackgroundServiceCallableAllowlist
{
    /**
     * Allow-list of callables permitted to run as background services.
     * S6 (AUDIT.md): the runner invokes a function named in a DB row; only
     * names shipped by core or a bundled module may run, so table-write access
     * cannot become code execution. New background services MUST add their
     * callable here in a reviewed change (see docs/security/background-services-policy.md).
     *
     * @var list<string>
     */
    public const ALLOWED_CALLABLES = [
        // core (sql/database.sql)
        'phimail_check',
        'start_MedEx',
        'start_X12_SFTP',
        'autoPopulateAllMissingUuids',
        'emailServiceRun',
        // oe-module-weno
        'downloadWenoPharmacy',
        'downloadWenoPrescriptionLog',
        // oe-module-claimrev-connect
        'start_X12_Claimrev_send_files',
        'start_X12_Claimrev_get_reports',
        'start_send_eligibility',
        'start_claimrev_notifications',
        'start_claimrev_watchdog',
        'start_eligibility_sweep',
        // oe-module-faxsms
        'send_faxsms_notifications',
    ];

    /**
     * Pure membership in the shipped, reviewed allow-list — the S6 security
     * boundary. Only these names may run in production. This method never
     * consults the environment; the runner uses isPermittedToRun() as its gate.
     */
    public static function isAllowed(string $function): bool
    {
        return in_array($function, self::ALLOWED_CALLABLES, true);
    }

    /**
     * The runtime gate the runner consults. Equals isAllowed() in production.
     *
     * A dev/test-only escape hatch additionally permits callables named in the
     * OPENEMR_BACKGROUND_EXTRA_ALLOWED_CALLABLES environment variable
     * (comma-separated). That variable is UNSET in production, so the
     * production gate is exactly ALLOWED_CALLABLES. The seam does not widen the
     * S6 threat model: S6 defends against a caller who can write a
     * `background_services` DB row (rogue admin, SQL injection) but cannot set
     * the server process environment — doing so requires shell/deploy access,
     * which already implies code execution. It exists so the CLI integration
     * test can exercise the runner with a probe callable without adding a
     * test-only name to the shipped list.
     */
    public static function isPermittedToRun(string $function): bool
    {
        if (self::isAllowed($function)) {
            return true;
        }

        return in_array($function, self::extraAllowedCallablesFromEnv(), true);
    }

    /**
     * Parses OPENEMR_BACKGROUND_EXTRA_ALLOWED_CALLABLES. Read via getenv() (not
     * $_ENV) so it works under this runtime's variables_order=GPCS, where $_ENV
     * is unpopulated.
     *
     * @return list<string>
     */
    private static function extraAllowedCallablesFromEnv(): array
    {
        $raw = getenv('OPENEMR_BACKGROUND_EXTRA_ALLOWED_CALLABLES');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $names = [];
        foreach (explode(',', $raw) as $name) {
            $trimmed = trim($name);
            if ($trimmed !== '') {
                $names[] = $trimmed;
            }
        }

        return $names;
    }
}

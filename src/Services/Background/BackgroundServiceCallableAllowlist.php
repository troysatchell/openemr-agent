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

    public static function isAllowed(string $function): bool
    {
        return in_array($function, self::ALLOWED_CALLABLES, true);
    }
}

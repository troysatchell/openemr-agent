<?php

/**
 * Frozen acceptance test for AUDIT.md finding S6 — background-service callables
 * are invoked by name from a DB row; only an explicit allow-list of shipped
 * callables may run, so table-write access cannot become code execution.
 *
 * Named BackgroundServiceCallableAllowlist to avoid colliding with the existing
 * production BackgroundServiceRegistry (the row-CRUD class).
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Background;

use OpenEMR\Services\Background\BackgroundServiceCallableAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S6 (AUDIT.md): the allow-list is the gate the runner consults before
 * dynamically invoking a background-service function named in a DB row.
 */
class BackgroundServiceCallableAllowlistTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function shippedCallableProvider(): array
    {
        return [
            // core (sql/database.sql)
            'phimail'      => ['phimail_check'],
            'medex'        => ['start_MedEx'],
            'x12 sftp'     => ['start_X12_SFTP'],
            'uuid service' => ['autoPopulateAllMissingUuids'],
            'email service' => ['emailServiceRun'],
            // bundled modules
            'weno pharmacy'   => ['downloadWenoPharmacy'],
            'weno rx log'     => ['downloadWenoPrescriptionLog'],
            'claimrev send'   => ['start_X12_Claimrev_send_files'],
            'claimrev reports' => ['start_X12_Claimrev_get_reports'],
            'claimrev elig'   => ['start_send_eligibility'],
            'claimrev notif'  => ['start_claimrev_notifications'],
            'claimrev watchdog' => ['start_claimrev_watchdog'],
            'claimrev sweep'  => ['start_eligibility_sweep'],
            'faxsms notif'    => ['send_faxsms_notifications'],
        ];
    }

    #[DataProvider('shippedCallableProvider')]
    public function testShippedCallablesAreAllowed(string $function): void
    {
        self::assertTrue(
            BackgroundServiceCallableAllowlist::isAllowed($function),
            "Shipped background-service callable '$function' must be allow-listed",
        );
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function rejectedCallableProvider(): array
    {
        return [
            'php builtin exec'   => ['exec'],
            'php builtin system' => ['system'],
            'php builtin passthru' => ['passthru'],
            'empty string'       => [''],
            'arbitrary function' => ['attacker_supplied_payload'],
            'case mismatch'      => ['PHIMAIL_CHECK'],
        ];
    }

    #[DataProvider('rejectedCallableProvider')]
    public function testUnlistedCallablesAreRejected(string $function): void
    {
        self::assertFalse(
            BackgroundServiceCallableAllowlist::isAllowed($function),
            "Non-allow-listed callable '$function' must be rejected",
        );
    }
}

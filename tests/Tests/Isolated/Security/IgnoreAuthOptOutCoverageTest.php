<?php

/**
 * Regression gate for AUDIT.md finding S4.
 *
 * OpenEMR enforces the staff auth gate only when $ignoreAuth is falsey, so any
 * file that sets $ignoreAuth = true (or $ignoreAuth_onsite_portal = true) opts
 * out of it. There is no central registry, so a new opt-out ships unnoticed.
 * This test scans the served source tree and fails if any opt-out file is not on
 * the reviewed allow-list (see docs/security/ignoreauth-allowlist.md). Adding a
 * file here is a deliberate, reviewable security decision.
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Security;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class IgnoreAuthOptOutCoverageTest extends TestCase
{
    /** Served source roots that are scanned for the opt-out. */
    private const SCAN_DIRS = ['interface', 'library', 'apis', 'portal', 'public'];

    /** Statement-line pattern (not a docblock/comment reference). */
    private const OPT_OUT_PATTERN = '/^\s*\$ignoreAuth(?:_onsite_portal)?\s*=\s*(?:true|1)\b/m';

    /**
     * Reviewed set of files that legitimately opt out of the staff auth gate,
     * each covered by a category in docs/security/ignoreauth-allowlist.md
     * (pre-auth necessity, patient-portal session, background/system, external
     * webhook [REVIEW], portal pre-auth [REVIEW], utility [REVIEW], test).
     *
     * @var list<string>
     */
    private const KNOWN_OPT_OUT_FILES = [
        'interface/forms/LBF/new.php',
        'interface/forms/eye_mag/taskman.php',
        'interface/forms/questionnaire_assessments/questionnaire_assessments.php',
        'interface/forms/questionnaire_assessments/save.php',
        'interface/forms/sdoh/new.php',
        'interface/forms/sdoh/save.php',
        'interface/globals.php',
        'interface/login/login.php',
        'interface/login_screen.php',
        'interface/modules/custom_modules/oe-module-comlink-telehealth/tests/bootstrap.php',
        'interface/modules/custom_modules/oe-module-faxsms/library/phone-services/voice_webhook.php',
        'interface/modules/custom_modules/oe-module-faxsms/library/rc_sms_notification.php',
        'interface/modules/custom_modules/oe-module-faxsms/library/webhook_receiver.php',
        'interface/smart/register-app.php',
        'interface/webhooks/payment/rainforest.php',
        'library/MedEx/MedEx.php',
        'library/MedEx/MedEx_background.php',
        'library/ajax/easipro_util.php',
        'library/ajax/execute_background_services.php',
        'library/ajax/sql_server_status.php',
        'library/ajax/upload.php',
        'portal/account/account.php',
        'portal/account/index_reset.php',
        'portal/account/verify.php',
        'portal/add_edit_event_user.php',
        'portal/find_appt_popup_user.php',
        'portal/get_patient_info.php',
        'portal/index.php',
        'portal/lib/doc_lib.php',
        'portal/lib/paylib.php',
        'portal/lib/persist.php',
        'portal/lib/track_portal_events.php',
        'portal/messaging/handle_note.php',
        'portal/messaging/messages.php',
        'portal/patient/_machine_config.php',
        'portal/portal_payment.php',
        'portal/portal_payment.rainforest.php',
        'portal/questionnaire_render.php',
        'portal/report/pat_ledger.php',
        'portal/report/portal_custom_report.php',
        'portal/report/portal_patient_report.php',
        'portal/sign/assets/signer_modal.php',
        'portal/sign/lib/save-signature.php',
        'portal/sign/lib/show-signature.php',
        'portal/verify_session.php',
    ];

    /** @return list<string> repo-relative paths that opt out of the staff auth gate */
    private static function scanOptOutFiles(): array
    {
        $root = dirname(__DIR__, 4);
        $found = [];
        foreach (self::SCAN_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                    continue;
                }
                $contents = file_get_contents($path);
                if ($contents !== false && preg_match(self::OPT_OUT_PATTERN, $contents) === 1) {
                    $found[] = str_replace($root . '/', '', $path);
                }
            }
        }
        sort($found);
        return $found;
    }

    public function testEveryIgnoreAuthOptOutIsReviewed(): void
    {
        $found = self::scanOptOutFiles();
        $allowed = self::KNOWN_OPT_OUT_FILES;
        sort($allowed);

        self::assertSame(
            $allowed,
            $found,
            "Files opting out of the staff auth gate must match the reviewed allow-list. "
            . "A new file here is an unreviewed unauthenticated surface (add it to the list "
            . "and docs/security/ignoreauth-allowlist.md only after confirming its alternative "
            . "control); a missing file is a stale entry to remove.",
        );
    }

    public function testDetectorMatchesStatementsButNotComments(): void
    {
        self::assertSame(1, preg_match(self::OPT_OUT_PATTERN, '$ignoreAuth = true;'));
        self::assertSame(1, preg_match(self::OPT_OUT_PATTERN, '    $ignoreAuth_onsite_portal = true;'));
        self::assertSame(0, preg_match(self::OPT_OUT_PATTERN, ' * background path sets $ignoreAuth = true) — a doc comment'));
        self::assertSame(0, preg_match(self::OPT_OUT_PATTERN, '// $ignoreAuth = true;'));
    }
}

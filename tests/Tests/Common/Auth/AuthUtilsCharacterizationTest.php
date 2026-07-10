<?php

/**
 * Characterization tests for the login core (AUDIT.md finding S11).
 *
 * AuthUtils / auth.inc.php (~1,400 lines) had no direct unit tests, so any
 * regression in the most security-critical code in the app shipped unguarded.
 * These tests pin the OBSERVABLE current behavior of the password-confirmation
 * entry point so the danger-zone refactors (S8, S4) can be made under a net.
 *
 * They add NO production code and run in the default AuthUtils mode
 * (`otherAuth`, the esign-style check), which — verified in
 * AuthUtils::confirmUserPassword() — deliberately SKIPS the IP/per-user
 * failed-login counter mutations (guarded by `loginAuth || apiAuth`). So these
 * failure-path assertions do not lock out the dev admin or mutate lockout
 * state; the only writes are benign audit-log rows.
 *
 * Deferred seams (need a transactional / throwaway-DB harness or an extracted
 * pure helper — the latter is an S8 production change, not allowed here):
 *   - IP-level lockout (setup/check/increment/force-block) — mode='login'/'api'
 *   - per-user failed-login lockout and email notification
 *   - preventTimingAttack() constant-time equalization
 *   - LDAP / Active Directory validation path
 * These are the branches to cover once S8 makes AuthUtils testable; tracked
 * against S11 for hand-off to SEC-S8.
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Common\Auth;

use OpenEMR\Common\Auth\AuthUtils;
use PHPUnit\Framework\TestCase;

class AuthUtilsCharacterizationTest extends TestCase
{
    /** A username that must not exist, so the "user not found" branch is exercised. */
    private static function unknownUsername(): string
    {
        return 'no_such_user_' . bin2hex(random_bytes(6));
    }

    // Characterized quirk of otherAuth mode: confirmPassword returns the
    // correct boolean, but does NOT populate getUserId() on success or
    // getErrorMessage() on failure (those are set only in login/api mode).
    // Pinning the boolean contract here; the id/message population belongs to
    // the login-mode branches deferred to SEC-S8's harness.

    public function testCorrectDevCredentialsConfirm(): void
    {
        $auth = new AuthUtils(); // '' => otherAuth mode (no lockout mutations)
        $password = 'pass';

        self::assertTrue($auth->confirmPassword('admin', $password), 'dev admin/pass must confirm in otherAuth mode');
    }

    public function testWrongPasswordIsRejectedWithoutMutatingLockout(): void
    {
        $auth = new AuthUtils();
        $password = 'wrong-' . bin2hex(random_bytes(6));

        self::assertFalse($auth->confirmPassword('admin', $password), 'a wrong password must be rejected');
    }

    public function testEmptyPasswordIsRejected(): void
    {
        $auth = new AuthUtils();
        $password = '';

        self::assertFalse($auth->confirmPassword('admin', $password));
    }

    public function testUnknownUserIsRejected(): void
    {
        $auth = new AuthUtils();
        $password = 'whatever-' . bin2hex(random_bytes(6));

        self::assertFalse($auth->confirmPassword(self::unknownUsername(), $password));
    }
}

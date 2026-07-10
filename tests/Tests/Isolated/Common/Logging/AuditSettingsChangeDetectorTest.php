<?php

/**
 * Frozen acceptance test for AUDIT.md finding S10 — changes to the granular
 * audit-event category toggles must be detectable so they can be logged, the
 * same way enable_auditlog / gbl_force_log_breakglass already are.
 *
 * @package   openemr
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Logging;

use OpenEMR\Common\Logging\AuditSettingsChangeDetector;
use PHPUnit\Framework\TestCase;

/**
 * S10 (AUDIT.md): the master enable_auditlog and gbl_force_log_breakglass are
 * already tamper-logged; this pure differ covers the previously-silent
 * granular audit_events_* toggles (HIPAA §164.312(b)).
 */
class AuditSettingsChangeDetectorTest extends TestCase
{
    /** @return array<string, string> a full, unchanged control map */
    private static function baseline(): array
    {
        $map = [];
        foreach (AuditSettingsChangeDetector::CONTROL_KEYS as $key) {
            $map[$key] = '1';
        }
        return $map;
    }

    public function testNoChangeReturnsEmpty(): void
    {
        self::assertSame([], AuditSettingsChangeDetector::changedControls(self::baseline(), self::baseline()));
    }

    public function testDisablingOneGranularToggleIsReported(): void
    {
        $old = self::baseline();
        $new = self::baseline();
        $new['audit_events_patient-record'] = '0';

        self::assertSame(
            [['key' => 'audit_events_patient-record', 'new' => '0']],
            AuditSettingsChangeDetector::changedControls($old, $new),
        );
    }

    public function testNonAuditKeyIsIgnored(): void
    {
        $old = self::baseline();
        $new = self::baseline();
        $old['language_default'] = 'English';
        $new['language_default'] = 'Spanish';

        self::assertSame([], AuditSettingsChangeDetector::changedControls($old, $new));
    }

    public function testMasterEnableAuditlogIsNotAGranularKey(): void
    {
        // enable_auditlog is logged separately in edit_globals.php; the
        // granular differ must not also report it (no double logging).
        self::assertNotContains('enable_auditlog', AuditSettingsChangeDetector::CONTROL_KEYS);
        self::assertNotContains('gbl_force_log_breakglass', AuditSettingsChangeDetector::CONTROL_KEYS);
    }

    public function testMultipleChangesReportedInControlKeyOrder(): void
    {
        $old = self::baseline();
        $new = self::baseline();
        $new['audit_events_order'] = '0';
        $new['audit_events_scheduling'] = '0';

        self::assertSame(
            [
                ['key' => 'audit_events_scheduling', 'new' => '0'],
                ['key' => 'audit_events_order', 'new' => '0'],
            ],
            AuditSettingsChangeDetector::changedControls($old, $new),
        );
    }

    public function testMissingKeyTreatedAsEmptyString(): void
    {
        $old = self::baseline();
        $new = self::baseline();
        unset($new['audit_events_query']);

        self::assertSame(
            [['key' => 'audit_events_query', 'new' => '']],
            AuditSettingsChangeDetector::changedControls($old, $new),
        );
    }
}

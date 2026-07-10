<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Common\Logging;

final class AuditSettingsChangeDetector
{
    /**
     * Granular audit-event category toggles (globals). S10 (AUDIT.md): these
     * were previously silent on change; the master enable_auditlog and
     * gbl_force_log_breakglass are tamper-logged separately in edit_globals.php.
     *
     * @var list<string>
     */
    public const CONTROL_KEYS = [
        'audit_events_patient-record',
        'audit_events_scheduling',
        'audit_events_order',
        'audit_events_lab-results',
        'audit_events_security-administration',
        'audit_events_backup',
        'audit_events_other',
        'audit_events_query',
        'audit_events_cdr',
        'audit_events_http-request',
    ];

    /**
     * @param array<string, string> $old gl_name => gl_value before save
     * @param array<string, string> $new gl_name => gl_value after save
     * @return list<array{key: string, new: string}>
     */
    public static function changedControls(array $old, array $new): array
    {
        $changes = [];
        foreach (self::CONTROL_KEYS as $key) {
            $before = $old[$key] ?? '';
            $after = $new[$key] ?? '';
            if ($before !== $after) {
                $changes[] = ['key' => $key, 'new' => $after];
            }
        }
        return $changes;
    }
}

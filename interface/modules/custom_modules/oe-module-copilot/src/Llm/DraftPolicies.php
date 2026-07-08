<?php

/**
 * DRAFT minimum-necessary policies, v1 (T3).
 *
 * DRAFT — field-list contents are a human-owned minimum-necessary policy
 * decision (C5) pending sign-off; the mechanism, not this list, is the
 * tested contract. Do not treat these field lists as approved: they exist so
 * the enforcement mechanism (MinimumNecessaryPayloadBuilder + FieldAllowlist
 * + DisclosedPayload) can be wired end-to-end before the policy call is
 * made. Changing a field list here is a clinical-governance / privacy
 * decision, not an engineering one — escalate, don't edit.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

final class DraftPolicies
{
    private function __construct()
    {
    }

    /**
     * @return array<string, FieldAllowlist> CopilotTask backing value => allowlist
     */
    public static function v1(): array
    {
        $sharedFields = [
            'medications' => ['name', 'status'],
            'lab_results' => ['analyte', 'value', 'unit'],
            'allergies' => ['substance', 'status'],
            'follow_ups' => ['description', 'due'],
        ];

        return [
            CopilotTask::Snapshot->value => new FieldAllowlist($sharedFields),
            CopilotTask::FollowUpQa->value => new FieldAllowlist($sharedFields),
            CopilotTask::PreChart->value => new FieldAllowlist($sharedFields),
        ];
    }
}

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
 * SIGNED OFF (founder, 2026-07-09): the `ref` field on every data class.
 * `ref` is the opaque `sourceType:sourceId` row pointer minted by
 * ReferenceIndex — no PHI content, no patient identifier — and is what lets
 * ClaimVerifier ground the model's claims against the live chart (R6/R10).
 * That one addition is authorized; the rest of each list remains DRAFT as
 * above. DraftPoliciesRefFieldTest pins the signed-off lists.
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
            'medications' => ['name', 'status', 'ref'],
            'lab_results' => ['analyte', 'value', 'unit', 'ref'],
            'allergies' => ['substance', 'status', 'ref'],
            'follow_ups' => ['description', 'due', 'ref'],
        ];

        // v1 DRAFT: the three tasks intentionally share one allowlist until
        // per-task minimum-necessary scopes are signed off (C5). Narrowing any
        // task's fields is a clinical-governance decision, not an engineering
        // one — see the class docblock; escalate, don't differentiate here.
        return [
            CopilotTask::Snapshot->value => new FieldAllowlist($sharedFields),
            CopilotTask::FollowUpQa->value => new FieldAllowlist($sharedFields),
            CopilotTask::PreChart->value => new FieldAllowlist($sharedFields),
        ];
    }
}

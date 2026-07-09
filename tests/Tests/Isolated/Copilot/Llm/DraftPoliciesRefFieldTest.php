<?php

/**
 * T16: the `ref` citation-token field in DraftPolicies::v1() (C5; R6/R10;
 * ARCHITECTURE.md §3.4).
 *
 * NOT frozen — but it pins a signed-off governance decision (founder,
 * 2026-07-09): every data class carries exactly its minimum-necessary
 * fields plus `ref`, the opaque `sourceType:sourceId` row pointer that
 * lets ClaimVerifier ground model claims. Weakening these lists is a
 * clinical-governance decision, not an engineering one — escalate, don't
 * edit (see the DraftPolicies docblock).
 *
 * Failure modes guarded: (1) a field silently added to a policy list would
 * widen every LLM crossing — the exact-list assertions make that a loud,
 * deliberate diff; (2) `ref` dropped from any class would sever citation
 * grounding end-to-end (the payload's tokens are the only thing the
 * verifier can resolve); (3) a patient-identifying field (pid/uuid/dob/
 * demographics) sneaking into any list would put PHI identifiers on the
 * wire beyond minimum necessary.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Llm;

use OpenEMR\Modules\Copilot\Llm\CopilotTask;
use OpenEMR\Modules\Copilot\Llm\DraftPolicies;
use PHPUnit\Framework\TestCase;

class DraftPoliciesRefFieldTest extends TestCase
{
    /**
     * The signed-off v1 lists (founder, 2026-07-09): the pre-existing
     * minimum-necessary fields plus the opaque `ref` row pointer.
     */
    private const SIGNED_OFF_FIELDS = [
        'medications' => ['name', 'status', 'ref'],
        'lab_results' => ['analyte', 'value', 'unit', 'ref'],
        'allergies' => ['substance', 'status', 'ref'],
        'follow_ups' => ['description', 'due', 'ref'],
    ];

    /**
     * Fields that identify the patient rather than describe the clinical
     * item — never permitted on any list (C5: minimum necessary; an
     * identifier that maps back to a person is PHI).
     */
    private const PATIENT_IDENTIFYING_FIELDS = [
        'pid', 'uuid', 'patient_name', 'first_name', 'last_name', 'dob',
        'date_of_birth', 'ssn', 'address', 'phone', 'email',
    ];

    public function testEveryTaskCarriesTheSignedOffListsIncludingRef(): void
    {
        $policies = DraftPolicies::v1();

        foreach (CopilotTask::cases() as $task) {
            $this->assertArrayHasKey($task->value, $policies, sprintf('Task "%s" has no allowlist.', $task->value));
            $this->assertSame(
                self::SIGNED_OFF_FIELDS,
                $policies[$task->value]->fieldsByDataClass(),
                sprintf(
                    'Task "%s" must carry exactly the signed-off v1 lists — any change here is a '
                    . 'clinical-governance decision (C5), and dropping "ref" severs claim grounding (R6/R10).',
                    $task->value,
                ),
            );
        }
    }

    public function testNoPolicyListContainsAPatientIdentifyingField(): void
    {
        foreach (DraftPolicies::v1() as $taskValue => $allowlist) {
            foreach ($allowlist->fieldsByDataClass() as $dataClass => $fields) {
                $this->assertSame(
                    [],
                    array_values(array_intersect($fields, self::PATIENT_IDENTIFYING_FIELDS)),
                    sprintf(
                        'Task "%s" / class "%s" permits a patient-identifying field — '
                        . 'ref is an opaque row pointer precisely so no identifier crosses (C5).',
                        $taskValue,
                        $dataClass,
                    ),
                );
            }
        }
    }
}

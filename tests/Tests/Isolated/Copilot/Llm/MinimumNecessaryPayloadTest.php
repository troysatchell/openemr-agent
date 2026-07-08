<?php

/**
 * FROZEN acceptance tests — T3: minimum-necessary payload enforcement (R1/C5;
 * ARCHITECTURE.md §3.4, Decision 5).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: what crosses the LLM boundary is decided by a per-task
 * field allowlist, not by whoever assembles the prompt. Fields outside the
 * allowlist are structurally unreachable; the payload and its Disclosure
 * record are born together and must agree exactly — there is no constructor
 * path for an undisclosed payload. Allowlist CONTENTS are a human-owned
 * policy (DRAFT until signed off); this file freezes the MECHANISM only.
 * v1 policy operates on flat entry fields (chart entries are flat after the
 * data-trust layer).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Llm;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Llm\CopilotTask;
use OpenEMR\Modules\Copilot\Llm\DisclosedPayload;
use OpenEMR\Modules\Copilot\Llm\FieldAllowlist;
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
use PHPUnit\Framework\TestCase;

class MinimumNecessaryPayloadTest extends TestCase
{
    private static function when(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-08 21:30:00', new \DateTimeZone('UTC'));
    }

    private static function builder(): MinimumNecessaryPayloadBuilder
    {
        return new MinimumNecessaryPayloadBuilder([
            CopilotTask::Snapshot->value => new FieldAllowlist([
                'medications' => ['name', 'status'],
                'lab_results' => ['analyte', 'value', 'unit'],
            ]),
        ]);
    }

    public function testTaskValuesAreStableAuditIdentifiers(): void
    {
        $this->assertSame('between-patient-snapshot', CopilotTask::Snapshot->value);
        $this->assertSame('follow-up-qa', CopilotTask::FollowUpQa->value);
        $this->assertSame('pre-chart', CopilotTask::PreChart->value);
    }

    public function testFieldsOutsideTheAllowlistNeverReachThePayload(): void
    {
        $disclosed = self::builder()->build(
            CopilotTask::Snapshot,
            [
                'medications' => [
                    ['name' => 'Lisinopril 10mg', 'status' => 'current', 'ssn' => '123-45-6789', 'insurance_id' => 'X99'],
                ],
            ],
            'ellis.tran',
            42,
            self::when(),
        );

        $this->assertSame(
            [['name' => 'Lisinopril 10mg', 'status' => 'current']],
            $disclosed->payload['medications'],
        );
        $encoded = json_encode($disclosed->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('123-45-6789', $encoded);
        $this->assertStringNotContainsString('insurance_id', $encoded);
    }

    public function testDataClassesOutsideTheAllowlistAreDroppedEntirely(): void
    {
        $disclosed = self::builder()->build(
            CopilotTask::Snapshot,
            [
                'medications' => [['name' => 'Lisinopril 10mg', 'status' => 'current']],
                'billing_notes' => [['note' => 'owes copay', 'amount' => '25.00']],
            ],
            'ellis.tran',
            42,
            self::when(),
        );

        $this->assertArrayNotHasKey('billing_notes', $disclosed->payload);
    }

    public function testDisclosureEnumeratesExactlyWhatThePayloadContains(): void
    {
        $disclosed = self::builder()->build(
            CopilotTask::Snapshot,
            [
                'medications' => [['name' => 'Lisinopril 10mg', 'status' => 'current']],
                'lab_results' => [['analyte' => 'Potassium', 'value' => 4.1, 'unit' => 'mmol/L']],
            ],
            'ellis.tran',
            42,
            self::when(),
        );

        $payloadClasses = array_keys($disclosed->payload);
        $disclosedClasses = $disclosed->disclosure->dataClasses;
        sort($payloadClasses);
        $disclosedClassesSorted = $disclosedClasses;
        sort($disclosedClassesSorted);

        $this->assertSame($payloadClasses, $disclosedClassesSorted);
        $this->assertSame('ellis.tran', $disclosed->disclosure->userId);
        $this->assertSame(42, $disclosed->disclosure->patientPid);
        $this->assertSame(CopilotTask::Snapshot->value, $disclosed->disclosure->purpose);
        $this->assertSame(self::when()->format(DATE_ATOM), $disclosed->disclosure->occurredAt->format(DATE_ATOM));
    }

    public function testNothingSurvivingTheAllowlistIsARefusalNotAnEmptySend(): void
    {
        $this->expectException(\DomainException::class);
        self::builder()->build(
            CopilotTask::Snapshot,
            ['billing_notes' => [['note' => 'owes copay']]],
            'ellis.tran',
            42,
            self::when(),
        );
    }

    public function testTaskWithoutAPolicyCannotSendAnything(): void
    {
        $this->expectException(\DomainException::class);
        self::builder()->build(
            CopilotTask::PreChart,
            ['medications' => [['name' => 'Lisinopril 10mg', 'status' => 'current']]],
            'ellis.tran',
            42,
            self::when(),
        );
    }

    public function testDisclosedPayloadRejectsAPayloadKeyMissingFromTheDisclosure(): void
    {
        $disclosure = new Disclosure('ellis.tran', 42, ['medications'], 'between-patient-snapshot', self::when());

        $this->expectException(\DomainException::class);
        new DisclosedPayload(
            [
                'medications' => [['name' => 'Lisinopril 10mg']],
                'lab_results' => [['analyte' => 'Potassium']],
            ],
            $disclosure,
        );
    }

    public function testDisclosedPayloadRejectsADisclosedClassMissingFromThePayload(): void
    {
        $disclosure = new Disclosure(
            'ellis.tran',
            42,
            ['medications', 'lab_results'],
            'between-patient-snapshot',
            self::when(),
        );

        $this->expectException(\DomainException::class);
        new DisclosedPayload(['medications' => [['name' => 'Lisinopril 10mg']]], $disclosure);
    }

    public function testAllowlistRejectsBlankDataClassesAndFields(): void
    {
        $this->expectException(\DomainException::class);
        new FieldAllowlist(['' => ['name']]);
    }
}

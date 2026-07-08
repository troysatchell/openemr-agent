<?php

/**
 * FROZEN acceptance tests — T7: patient identity resolution / dedupe (D7/D8, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: patient_data has no natural-key uniqueness (D8) — the
 * same human can exist under multiple pids — and uuid is nullable/backfilled
 * (D7), so pid is the trusted surrogate and uuid is best-effort. Duplicate
 * candidates are grouped by normalized demographics (family name + given name
 * + DOB + sex, case/whitespace-insensitive). Conservative rule: any unknown
 * component (D1 empty-string, invalid date) disqualifies a row from grouping
 * — never merge people on incomplete evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\DataTrust;

use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\DataTrust\PatientIdentityResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PatientIdentityResolverTest extends TestCase
{
    private static function patient(
        int $pid,
        ?string $uuid = null,
        ?string $firstName = 'Ellis',
        ?string $lastName = 'Tran',
        ?string $dob = '1980-01-15',
        ?string $sex = 'Male',
    ): PatientDemographics {
        return new PatientDemographics($pid, $uuid, $firstName, $lastName, $dob, $sex);
    }

    /**
     * @return array<string, array{int}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidPidProvider(): array
    {
        return [
            'zero pid' => [0],
            'negative pid' => [-3],
        ];
    }

    #[DataProvider('invalidPidProvider')]
    public function testPidMustBePositive(int $pid): void
    {
        $this->expectException(\DomainException::class);
        self::patient($pid);
    }

    public function testNullUuidRowsAreStillResolvableByPid(): void
    {
        $patient = self::patient(7, null);
        $this->assertSame(7, $patient->pid);
        $this->assertNull($patient->uuid);
    }

    public function testExactDuplicatesAreGrouped(): void
    {
        $resolver = new PatientIdentityResolver();
        $a = self::patient(1);
        $b = self::patient(2);

        $groups = $resolver->duplicateGroups([$a, $b]);

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]);
    }

    public function testCaseAndWhitespaceVariantsAreGrouped(): void
    {
        $resolver = new PatientIdentityResolver();
        $groups = $resolver->duplicateGroups([
            self::patient(1, null, 'Ellis', 'Tran', '1980-01-15', 'Male'),
            self::patient(2, null, '  ELLIS ', 'tran', '1980-01-15', 'male'),
        ]);

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]);
    }

    public function testThreeWayDuplicateFormsOneGroup(): void
    {
        $resolver = new PatientIdentityResolver();
        $groups = $resolver->duplicateGroups([
            self::patient(1),
            self::patient(2),
            self::patient(3),
        ]);

        $this->assertCount(1, $groups);
        $this->assertCount(3, $groups[0]);
    }

    /**
     * @return array<string, array{PatientDemographics, PatientDemographics}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unknownComponentProvider(): array
    {
        return [
            'missing dob on one row' => [
                self::patient(1, null, 'Ellis', 'Tran', '1980-01-15', 'Male'),
                self::patient(2, null, 'Ellis', 'Tran', null, 'Male'),
            ],
            'empty-string dob (D1) on one row' => [
                self::patient(1, null, 'Ellis', 'Tran', '1980-01-15', 'Male'),
                self::patient(2, null, 'Ellis', 'Tran', '', 'Male'),
            ],
            'mysql zero dob (D0) on one row' => [
                self::patient(1, null, 'Ellis', 'Tran', '1980-01-15', 'Male'),
                self::patient(2, null, 'Ellis', 'Tran', '0000-00-00', 'Male'),
            ],
            'unknown sex on one row' => [
                self::patient(1, null, 'Ellis', 'Tran', '1980-01-15', 'Male'),
                self::patient(2, null, 'Ellis', 'Tran', '1980-01-15', ''),
            ],
            'missing family name on one row' => [
                self::patient(1, null, 'Ellis', 'Tran', '1980-01-15', 'Male'),
                self::patient(2, null, 'Ellis', null, '1980-01-15', 'Male'),
            ],
        ];
    }

    #[DataProvider('unknownComponentProvider')]
    public function testUnknownComponentsAreNeverMerged(
        PatientDemographics $complete,
        PatientDemographics $incomplete,
    ): void {
        $resolver = new PatientIdentityResolver();
        $this->assertSame(
            [],
            $resolver->duplicateGroups([$complete, $incomplete]),
            'A row with any unknown demographic component must never be merged (conservative dedupe).'
        );
    }

    public function testSameNameDifferentDobAreDistinctPeople(): void
    {
        $resolver = new PatientIdentityResolver();
        $groups = $resolver->duplicateGroups([
            self::patient(1, null, 'Ellis', 'Tran', '1980-01-15', 'Male'),
            self::patient(2, null, 'Ellis', 'Tran', '1979-06-02', 'Male'),
        ]);

        $this->assertSame([], $groups);
    }

    public function testDistinctPatientsProduceNoGroups(): void
    {
        $resolver = new PatientIdentityResolver();
        $groups = $resolver->duplicateGroups([
            self::patient(1, null, 'Ellis', 'Tran', '1980-01-15', 'Male'),
            self::patient(2, null, 'Dana', 'Reyes', '1975-11-30', 'Female'),
        ]);

        $this->assertSame([], $groups);
    }
}

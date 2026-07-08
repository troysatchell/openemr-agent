<?php

/**
 * FROZEN acceptance tests — T2: external-AI disclosure record (C1/C5, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: every PHI crossing to the LLM is a logged,
 * minimum-necessary disclosure — who, which patient, what data classes, for
 * what purpose, when. A Disclosure that cannot answer all five questions
 * cannot exist.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Audit;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DisclosureTest extends TestCase
{
    private static function when(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-08 21:15:00');
    }

    public function testValidDisclosureExposesAllFiveAnswers(): void
    {
        $disclosure = new Disclosure(
            'ellis.tran',
            42,
            ['medications', 'lab_results'],
            'between-patient-snapshot',
            self::when(),
        );

        $this->assertSame('ellis.tran', $disclosure->userId);
        $this->assertSame(42, $disclosure->patientPid);
        $this->assertSame(['medications', 'lab_results'], $disclosure->dataClasses);
        $this->assertSame('between-patient-snapshot', $disclosure->purpose);
        $this->assertSame('2026-07-08 21:15:00', $disclosure->occurredAt->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{string, int, list<string>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidDisclosureProvider(): array
    {
        return [
            'empty user' => ['', 42, ['medications'], 'snapshot'],
            'whitespace user' => ['   ', 42, ['medications'], 'snapshot'],
            'zero pid' => ['ellis.tran', 0, ['medications'], 'snapshot'],
            'negative pid' => ['ellis.tran', -7, ['medications'], 'snapshot'],
            'no data classes' => ['ellis.tran', 42, [], 'snapshot'],
            'blank data class' => ['ellis.tran', 42, ['medications', ''], 'snapshot'],
            'empty purpose' => ['ellis.tran', 42, ['medications'], ''],
        ];
    }

    /**
     * @param list<string> $dataClasses
     */
    #[DataProvider('invalidDisclosureProvider')]
    public function testIncompleteDisclosuresCannotExist(
        string $userId,
        int $patientPid,
        array $dataClasses,
        string $purpose,
    ): void {
        $this->expectException(\DomainException::class);
        new Disclosure($userId, $patientPid, $dataClasses, $purpose, self::when());
    }
}

<?php

/**
 * FROZEN acceptance tests — T21: today's schedule for the logged-in
 * physician, shaped for the panel's patient dropdown (UC3 "pre-charting the
 * day"; AUDIT D0/D1/D6/D7; ARCHITECTURE.md §4 session-bound delegation).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the endpoint is pure — the appointments reader is an
 * injected callable (the glue backs it with AppointmentService::search, the
 * guarded service surface; never raw SQL) invoked with the physician's user
 * id and the clock's current day. Data-trust rules are structural, not
 * incidental: '' is unknown, so a nameless row reads "(name not recorded)"
 * and is never dropped (D1); every time value is parsed defensively — an
 * invalid time becomes null, sorts last, and never crashes (D0/D6); the
 * patient uuid is nullable and a row without one is carried but not
 * selectable (D7: uuid is backfilled/nullable — the dropdown shows the
 * appointment honestly and refuses to pretend it can open the chart). Rows
 * are sorted by time ascending, reader order preserved on ties. The whole
 * shape is json_encodable as-is.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Panel;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Panel\TodayScheduleEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class TodayScheduleEndpointTest extends TestCase
{
    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-07-09 08:00:00', new \DateTimeZone('UTC'));
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{0?: int, 1?: string} $readerArgs records what the reader was invoked with
     */
    private function endpoint(array $rows, array &$readerArgs = []): TodayScheduleEndpoint
    {
        return new TodayScheduleEndpoint(
            function (int $providerId, string $day) use (&$readerArgs, $rows): array {
                $readerArgs = [$providerId, $day];

                return $rows;
            },
            $this->clock(),
        );
    }

    private function physician(): PhysicianContext
    {
        return new PhysicianContext('dr.tran', 7);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'pid' => 42,
            'puuid' => 'uuid-42',
            'fname' => 'Alma',
            'lname' => 'Reyes',
            'pc_startTime' => '09:15:00',
            'pc_apptstatus' => '-',
        ], $overrides);
    }

    public function testReaderReceivesThePhysicianUserIdAndTheClockDay(): void
    {
        $readerArgs = [];
        $result = $this->endpoint([], $readerArgs)->handle($this->physician());

        $this->assertSame([7, '2026-07-09'], $readerArgs);
        $this->assertSame('2026-07-09', $result['day']);
    }

    public function testEmptyScheduleYieldsAnEmptyListNotAnError(): void
    {
        $readerArgs = [];
        $result = $this->endpoint([], $readerArgs)->handle($this->physician());

        $this->assertSame(['day' => '2026-07-09', 'appointments' => []], $result);
    }

    public function testShapesAFullyPopulatedRow(): void
    {
        $readerArgs = [];
        $result = $this->endpoint([$this->row()], $readerArgs)->handle($this->physician());

        $this->assertSame(
            [
                [
                    'pid' => 42,
                    'patient_uuid' => 'uuid-42',
                    'patient_name' => 'Reyes, Alma',
                    'time' => '09:15',
                    'status' => '-',
                    'selectable' => true,
                ],
            ],
            $result['appointments'],
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function nameProvider(): array
    {
        return [
            'both blank reads honestly, row kept (D1)' => [['fname' => '', 'lname' => ''], '(name not recorded)'],
            'both missing' => [['fname' => null, 'lname' => null], '(name not recorded)'],
            'keys absent entirely' => [['fname' => null, 'lname' => null, '__unset__' => true], '(name not recorded)'],
            'whitespace is unknown (D1)' => [['fname' => '  ', 'lname' => '  '], '(name not recorded)'],
            'only last name' => [['fname' => ''], 'Reyes'],
            'only first name' => [['lname' => null], 'Alma'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('nameProvider')]
    public function testNameFollowsDataTrustRules(array $overrides, string $expectedName): void
    {
        if (isset($overrides['__unset__'])) {
            $row = $this->row();
            unset($row['fname'], $row['lname'], $overrides['__unset__']);
        } else {
            $row = $this->row($overrides);
        }

        $readerArgs = [];
        $result = $this->endpoint([$row], $readerArgs)->handle($this->physician());

        $this->assertCount(1, $result['appointments'], 'A nameless row is never dropped (D1)');
        $this->assertSame($expectedName, $result['appointments'][0]['patient_name']);
    }

    /**
     * @return array<string, array{mixed, ?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function timeProvider(): array
    {
        return [
            'HH:MM:SS' => ['09:15:00', '09:15'],
            'HH:MM' => ['14:05', '14:05'],
            'midnight' => ['00:00:00', '00:00'],
            'empty string is unknown (D1)' => ['', null],
            'null' => [null, null],
            'zero-date garbage (D6)' => ['0000-00-00 00:00:00', null],
            'free text (D0)' => ['soon', null],
            'impossible hour' => ['25:10:00', null],
            'impossible minute' => ['09:61:00', null],
            'non-string' => [915, null],
        ];
    }

    #[DataProvider('timeProvider')]
    public function testTimesAreParsedDefensively(mixed $rawTime, ?string $expected): void
    {
        $readerArgs = [];
        $result = $this->endpoint([$this->row(['pc_startTime' => $rawTime])], $readerArgs)
            ->handle($this->physician());

        $this->assertCount(1, $result['appointments'], 'An untimed row is never dropped (D0/D6)');
        $this->assertSame($expected, $result['appointments'][0]['time']);
    }

    public function testRowsSortByTimeWithInvalidTimesLast(): void
    {
        $readerArgs = [];
        $rows = [
            $this->row(['pid' => 1, 'pc_startTime' => '10:30:00']),
            $this->row(['pid' => 2, 'pc_startTime' => 'soon']),
            $this->row(['pid' => 3, 'pc_startTime' => '08:00:00']),
        ];

        $result = $this->endpoint($rows, $readerArgs)->handle($this->physician());

        $this->assertSame(
            [[3, '08:00'], [1, '10:30'], [2, null]],
            array_map(
                static fn (array $a): array => [$a['pid'], $a['time']],
                $result['appointments'],
            ),
        );
    }

    public function testSortPreservesReaderOrderOnEqualTimes(): void
    {
        $readerArgs = [];
        $rows = [
            $this->row(['pid' => 5, 'pc_startTime' => '09:00:00']),
            $this->row(['pid' => 6, 'pc_startTime' => '09:00:00']),
        ];

        $result = $this->endpoint($rows, $readerArgs)->handle($this->physician());

        $this->assertSame(
            [5, 6],
            array_map(static fn (array $a): int => $a['pid'], $result['appointments']),
        );
    }

    /**
     * @return array<string, array{mixed}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function missingUuidProvider(): array
    {
        return [
            'null uuid (D7: nullable/backfilled)' => [null],
            'empty uuid is unknown (D1)' => [''],
            'whitespace uuid' => ['   '],
            'non-string uuid' => [42],
        ];
    }

    #[DataProvider('missingUuidProvider')]
    public function testRowWithoutAPatientUuidIsCarriedButNotSelectable(mixed $rawUuid): void
    {
        $readerArgs = [];
        $result = $this->endpoint([$this->row(['puuid' => $rawUuid])], $readerArgs)
            ->handle($this->physician());

        $this->assertCount(1, $result['appointments'], 'A uuid-less row is never dropped (D7)');
        $appointment = $result['appointments'][0];
        $this->assertNull($appointment['patient_uuid']);
        $this->assertFalse($appointment['selectable']);
        $this->assertSame('Reyes, Alma', $appointment['patient_name'], 'The rest of the row stays honest');
    }

    /**
     * @return array<string, array{mixed, ?int, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function pidProvider(): array
    {
        return [
            'int pid' => [42, 42, true],
            'digit-string pid is parsed' => ['43', 43, true],
            'zero pid is not a patient' => [0, null, false],
            'negative pid' => [-1, null, false],
            'empty pid is unknown (D1)' => ['', null, false],
            'free-text pid' => ['abc', null, false],
            'null pid' => [null, null, false],
        ];
    }

    #[DataProvider('pidProvider')]
    public function testPidIsParsedDefensivelyAndGatesSelectability(mixed $rawPid, ?int $expectedPid, bool $selectable): void
    {
        $readerArgs = [];
        $result = $this->endpoint([$this->row(['pid' => $rawPid])], $readerArgs)
            ->handle($this->physician());

        $this->assertCount(1, $result['appointments'], 'A pid-less row is never dropped (D1)');
        $this->assertSame($expectedPid, $result['appointments'][0]['pid']);
        $this->assertSame($selectable, $result['appointments'][0]['selectable']);
    }

    public function testBlankStatusIsUnknownNotAValue(): void
    {
        $readerArgs = [];
        $rows = [
            $this->row(['pid' => 1, 'pc_startTime' => '08:00:00', 'pc_apptstatus' => '']),
            $this->row(['pid' => 2, 'pc_startTime' => '09:00:00', 'pc_apptstatus' => '@']),
            $this->row(['pid' => 3, 'pc_startTime' => '10:00:00', 'pc_apptstatus' => null]),
        ];

        $result = $this->endpoint($rows, $readerArgs)->handle($this->physician());

        $this->assertSame(
            [null, '@', null],
            array_map(static fn (array $a): ?string => $a['status'], $result['appointments']),
        );
    }

    public function testOutputIsJsonEncodableAsIs(): void
    {
        $readerArgs = [];
        $rows = [
            $this->row(),
            $this->row(['pid' => null, 'puuid' => null, 'fname' => '', 'lname' => '', 'pc_startTime' => 'soon', 'pc_apptstatus' => '']),
        ];

        $result = $this->endpoint($rows, $readerArgs)->handle($this->physician());

        $this->assertNotFalse(json_encode($result));
    }
}

<?php

/**
 * Today's schedule for the panel's patient dropdown (T21; UC3 "pre-charting
 * the day"; AUDIT D0/D1/D6/D7; ARCHITECTURE.md §4 session-bound delegation).
 *
 * Pure view-model shaper: the appointments reader is an injected callable —
 * the glue backs it with AppointmentService::search (the guarded service
 * surface; never raw SQL) — invoked with the physician's user id and the
 * clock's current day. Data-trust rules are structural, not incidental: ''
 * is unknown, so a nameless row reads "(name not recorded)" and is never
 * dropped (D1); every time value is parsed defensively — an invalid time
 * becomes null, sorts last, and never crashes (D0/D6); the patient uuid is
 * nullable (D7: backfilled/nullable) and a row without one is carried but
 * marked not selectable, never dropped. Rows are sorted by time ascending,
 * reader order preserved on ties.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use Psr\Clock\ClockInterface;

final readonly class TodayScheduleEndpoint
{
    /**
     * @param \Closure(int, string): list<array<string, mixed>> $appointmentsReader Contract:
     *        (int $providerId, string $day): list<array<string, mixed>> —
     *        rows use AppointmentService::search key names: 'pid',
     *        'puuid', 'fname', 'lname', 'pc_startTime', 'pc_apptstatus'.
     *        $day is 'Y-m-d'.
     */
    public function __construct(
        private \Closure $appointmentsReader,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     day: string,
     *     appointments: list<array{
     *         pid: ?int,
     *         patient_uuid: ?string,
     *         patient_name: string,
     *         time: ?string,
     *         status: ?string,
     *         selectable: bool,
     *     }>,
     * }
     */
    public function handle(PhysicianContext $physician): array
    {
        $day = $this->clock->now()->format('Y-m-d');

        $reader = $this->appointmentsReader;
        $rows = $reader($physician->userId, $day);

        $appointments = array_map($this->shapeRow(...), $rows);

        usort($appointments, static function (array $a, array $b): int {
            if ($a['time'] === $b['time']) {
                // Stable sort (PHP 8 usort) preserves reader order on ties.
                return 0;
            }
            if ($a['time'] === null) {
                return 1;
            }
            if ($b['time'] === null) {
                return -1;
            }

            return $a['time'] <=> $b['time'];
        });

        return ['day' => $day, 'appointments' => $appointments];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *     pid: ?int,
     *     patient_uuid: ?string,
     *     patient_name: string,
     *     time: ?string,
     *     status: ?string,
     *     selectable: bool,
     * }
     */
    private function shapeRow(array $row): array
    {
        $pid = $this->parsePositiveId($row['pid'] ?? null);
        $patientUuid = $this->parseNonBlankString($row['puuid'] ?? null);

        return [
            'pid' => $pid,
            'patient_uuid' => $patientUuid,
            'patient_name' => $this->shapeName($row['fname'] ?? null, $row['lname'] ?? null),
            'time' => $this->parseTime($row['pc_startTime'] ?? null),
            'status' => $this->parseNonBlankString($row['pc_apptstatus'] ?? null),
            'selectable' => $pid !== null && $patientUuid !== null,
        ];
    }

    /**
     * pid is the trusted surrogate key when present (D7), but this reader
     * source is never assumed clean: '', non-numeric text, zero, and
     * negative values are all "unknown," never a patient (D1). A row
     * without a parseable pid is still carried, never dropped.
     */
    private function parsePositiveId(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && ctype_digit($raw)) {
            $value = (int) $raw;

            return $value > 0 ? $value : null;
        }

        return null;
    }

    private function parseNonBlankString(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        return trim($raw) === '' ? null : $raw;
    }

    /**
     * '' is unknown, not a value the row lacks entirely (D1) — a nameless
     * row still renders honestly and is never dropped.
     */
    private function shapeName(mixed $rawFirst, mixed $rawLast): string
    {
        $first = $this->parseNonBlankString($rawFirst);
        $last = $this->parseNonBlankString($rawLast);

        if ($first !== null && $last !== null) {
            return $last . ', ' . $first;
        }
        if ($last !== null) {
            return $last;
        }
        if ($first !== null) {
            return $first;
        }

        return '(name not recorded)';
    }

    /**
     * Defensive time parsing (D0/D6): accepts 'HH:MM:SS' or 'HH:MM' with a
     * valid 24-hour range, normalized to 'HH:MM'. Anything else — null,
     * '', zero-date garbage, free text, an impossible hour/minute, or a
     * non-string value — becomes null rather than crashing.
     */
    private function parseTime(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $matches = [];
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $raw, $matches) !== 1) {
            return null;
        }

        return $matches[1] . ':' . $matches[2];
    }
}

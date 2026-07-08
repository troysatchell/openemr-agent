<?php

/**
 * FROZEN acceptance tests — T8: activity/deleted currency filter (D10, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: this schema soft-deletes — discontinued meds and
 * resolved problems read as current unless activity/deleted/enddate are
 * applied. The filter returns a three-state CurrencyStatus (unit enum:
 * Current / NotCurrent / Unknown): an unevaluable row is surfaced as Unknown,
 * never silently treated as current OR silently dropped — the synthesis layer
 * decides how to present it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\DataTrust;

use OpenEMR\Modules\Copilot\DataTrust\ActivityFilter;
use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ActivityFilterTest extends TestCase
{
    private const TODAY = '2026-07-08';

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    /**
     * Cases: [activity flag (mixed variants, D4), enddate (raw, D0/D6), deleted, expected status]
     *
     * @return array<string, array{mixed, ?string, mixed, CurrencyStatus}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function currencyProvider(): array
    {
        return [
            // active, no end date => current
            'active tinyint, no enddate' => [1, null, false, CurrencyStatus::Current],
            'active yes-variant, empty enddate' => ['YES', '', false, CurrencyStatus::Current],
            'active string one, whitespace enddate' => ['1', '  ', false, CurrencyStatus::Current],
            // explicit inactivity => not current
            'inactive tinyint' => [0, null, false, CurrencyStatus::NotCurrent],
            'inactive no-variant' => ['No', null, false, CurrencyStatus::NotCurrent],
            // end dates (date-only comparison, end date inclusive)
            'ended yesterday' => [1, '2026-07-07', false, CurrencyStatus::NotCurrent],
            'ends today is still current' => [1, self::TODAY, false, CurrencyStatus::Current],
            'ends tomorrow' => [1, '2026-07-09', false, CurrencyStatus::Current],
            'ended long ago with datetime' => ['yes', '2019-02-11 08:00:00', false, CurrencyStatus::NotCurrent],
            // deleted flag wins over everything (D10)
            'deleted although active' => [1, null, true, CurrencyStatus::NotCurrent],
            'deleted yes-variant although active' => ['YES', '2099-01-01', 'yes', CurrencyStatus::NotCurrent],
            // unevaluable rows surface as Unknown — never silently current
            'unknown activity flag' => [null, null, false, CurrencyStatus::Unknown],
            'empty activity flag' => ['', null, false, CurrencyStatus::Unknown],
            'unrecognized activity flag' => ['maybe', null, false, CurrencyStatus::Unknown],
            'active but zero-date enddate (D0)' => [1, '0000-00-00', false, CurrencyStatus::Unknown],
            'active but free-text enddate (D6)' => [1, 'ongoing', false, CurrencyStatus::Unknown],
            'unrecognized deleted flag' => [1, null, 'perhaps', CurrencyStatus::Unknown],
        ];
    }

    #[DataProvider('currencyProvider')]
    public function testStatus(mixed $activity, ?string $endDate, mixed $deleted, CurrencyStatus $expected): void
    {
        $this->assertSame(
            $expected,
            ActivityFilter::status($activity, $endDate, self::today(), $deleted),
        );
    }

    public function testDeletedParameterDefaultsToNotDeleted(): void
    {
        $this->assertSame(
            CurrencyStatus::Current,
            ActivityFilter::status(1, null, self::today()),
            'Callers reading tables without a deleted column must get plain activity semantics.'
        );
    }

    public function testCurrencyStatusIsAClosedThreeStateEnum(): void
    {
        $this->assertSame(
            ['Current', 'NotCurrent', 'Unknown'],
            array_map(static fn (CurrencyStatus $case): string => $case->name, CurrencyStatus::cases()),
        );
    }
}

<?php

/**
 * FROZEN acceptance tests — T6: defensive clinical date parsing (D0/D6, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: with sql_mode='' (D0) date columns can hold
 * '0000-00-00', and some dates live in varchar/TEXT columns as free text (D6).
 * The parser accepts exactly 'Y-m-d' and 'Y-m-d H:i:s' with strict round-trip
 * validation (no PHP date rollover: 2024-02-30 is invalid, not March 1st) and
 * returns null for every other shape. Null is "unknown", never a default date.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\DataTrust;

use OpenEMR\Modules\Copilot\DataTrust\ClinicalDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClinicalDateTest extends TestCase
{
    /**
     * @return array<string, array{?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unparseableProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => ['   '],
            'mysql zero date' => ['0000-00-00'],
            'mysql zero datetime' => ['0000-00-00 00:00:00'],
            'zero date with real time' => ['0000-00-00 10:30:00'],
            'rollover day' => ['2024-02-30'],
            'rollover month' => ['2024-13-01'],
            'free text' => ['next week'],
            'human format' => ['March 5th, 2024'],
            'slash format' => ['15/03/2024'],
            'iso with T separator not audited' => ['2024-03-15T10:30:00'],
            'partial date' => ['2024-03'],
            'trailing junk' => ['2024-03-15 approx'],
        ];
    }

    #[DataProvider('unparseableProvider')]
    public function testUnparseableValuesReturnNull(?string $value): void
    {
        $this->assertNull(ClinicalDate::tryParse($value));
    }

    /**
     * @return array<string, array{string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function parseableProvider(): array
    {
        return [
            'plain date' => ['2024-03-15', '2024-03-15 00:00:00'],
            'datetime' => ['2024-03-15 10:30:00', '2024-03-15 10:30:00'],
            'leap day on leap year' => ['2024-02-29', '2024-02-29 00:00:00'],
            'padded input is tolerated' => ['  2024-03-15  ', '2024-03-15 00:00:00'],
        ];
    }

    #[DataProvider('parseableProvider')]
    public function testParseableValuesRoundTrip(string $value, string $expected): void
    {
        $parsed = ClinicalDate::tryParse($value);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
        $this->assertSame($expected, $parsed->format('Y-m-d H:i:s'));
    }

    public function testNonLeapYearFebruary29IsInvalid(): void
    {
        $this->assertNull(ClinicalDate::tryParse('2023-02-29'));
    }
}

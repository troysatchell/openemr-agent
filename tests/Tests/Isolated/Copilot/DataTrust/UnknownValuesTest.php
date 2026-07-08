<?php

/**
 * FROZEN acceptance tests — T6: empty-string-as-unknown normalizer (D1, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: 318 columns in this schema are NOT NULL DEFAULT '' —
 * an empty string means "missing", never "known empty". The normalizer maps
 * every unknown representation to null so downstream code has exactly one
 * missing-value shape to reason about.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\DataTrust;

use OpenEMR\Modules\Copilot\DataTrust\UnknownValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnknownValuesTest extends TestCase
{
    /**
     * @return array<string, array{?string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unknownnessProvider(): array
    {
        return [
            'null' => [null, true],
            'empty string' => ['', true],
            'single space' => [' ', true],
            'multiple spaces' => ['   ', true],
            'tabs and newlines' => ["\t\n ", true],
            'zero string is a value' => ['0', false],
            'word' => ['penicillin', false],
            'value with surrounding whitespace' => ['  penicillin  ', false],
            'literal false-looking string' => ['false', false],
        ];
    }

    #[DataProvider('unknownnessProvider')]
    public function testIsUnknown(?string $value, bool $expected): void
    {
        $this->assertSame($expected, UnknownValues::isUnknown($value));
    }

    /**
     * @return array<string, array{?string, ?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function normalizationProvider(): array
    {
        return [
            'null stays null' => [null, null],
            'empty string becomes null' => ['', null],
            'whitespace-only becomes null' => ['   ', null],
            'value is trimmed' => ['  lisinopril 10mg  ', 'lisinopril 10mg'],
            'plain value passes through' => ['NKDA', 'NKDA'],
            'zero string survives' => ['0', '0'],
        ];
    }

    #[DataProvider('normalizationProvider')]
    public function testNormalize(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, UnknownValues::normalize($value));
    }
}

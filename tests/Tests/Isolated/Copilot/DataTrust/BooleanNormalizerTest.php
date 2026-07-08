<?php

/**
 * FROZEN acceptance tests — T6: boolean-variant normalizer (D4, AUDIT.md).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: this schema stores booleans at least four incompatible
 * ways — tinyint(1) 0/1, varchar 'YES'/'NO', varchar 'yes', enum('Yes','No').
 * The normalizer accepts exactly the audited variants (any letter case,
 * surrounding whitespace tolerated) and returns null — unknown — for
 * everything else. It never guesses: an unrecognized value is not false.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\DataTrust;

use OpenEMR\Modules\Copilot\DataTrust\BooleanNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BooleanNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{mixed, ?bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function booleanVariantProvider(): array
    {
        return [
            // audited true variants (D4)
            'native true' => [true, true],
            'int one' => [1, true],
            'string one' => ['1', true],
            'yes lowercase' => ['yes', true],
            'yes titlecase' => ['Yes', true],
            'yes uppercase' => ['YES', true],
            'yes padded' => ['  yes ', true],
            // audited false variants
            'native false' => [false, false],
            'int zero' => [0, false],
            'string zero' => ['0', false],
            'no lowercase' => ['no', false],
            'no titlecase' => ['No', false],
            'no uppercase' => ['NO', false],
            'no padded' => [' no  ', false],
            // unknown — missing values (D1)
            'null' => [null, null],
            'empty string' => ['', null],
            'whitespace only' => ['   ', null],
            // unknown — unrecognized values are never guessed
            'unrecognized word' => ['maybe', null],
            'int two' => [2, null],
            'string two' => ['2', null],
            'true as word is not an audited variant' => ['true', null],
            'false as word is not an audited variant' => ['false', null],
            'y abbreviation is not an audited variant' => ['y', null],
            'float' => [1.0, null],
            'array' => [[], null],
        ];
    }

    #[DataProvider('booleanVariantProvider')]
    public function testNormalize(mixed $value, ?bool $expected): void
    {
        $this->assertSame($expected, BooleanNormalizer::normalize($value));
    }
}

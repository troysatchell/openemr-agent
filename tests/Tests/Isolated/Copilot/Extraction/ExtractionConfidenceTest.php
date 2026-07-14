<?php

/**
 * FROZEN acceptance tests — TRO-13: per-field extraction confidence (W2_ARCHITECTURE §3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: extraction confidence is a bounded domain primitive in
 * [0.0, 1.0]. Out-of-range values are a \DomainException, never clamped — a
 * confidence the model could not produce is a bug in the caller, not a value to
 * silently coerce.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Extraction;

use OpenEMR\Modules\Copilot\Extraction\ExtractionConfidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExtractionConfidenceTest extends TestCase
{
    /**
     * @return array<string, array{float}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function validProvider(): array
    {
        return [
            'lower bound' => [0.0],
            'upper bound' => [1.0],
            'midpoint' => [0.5],
            'high' => [0.97],
        ];
    }

    #[DataProvider('validProvider')]
    public function testAcceptsValuesInUnitInterval(float $value): void
    {
        $confidence = new ExtractionConfidence($value);
        $this->assertSame($value, $confidence->value);
    }

    /**
     * @return array<string, array{float}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function outOfRangeProvider(): array
    {
        return [
            'below zero' => [-0.01],
            'negative' => [-1.0],
            'above one' => [1.01],
            'far above one' => [42.0],
        ];
    }

    #[DataProvider('outOfRangeProvider')]
    public function testRejectsValuesOutsideUnitInterval(float $value): void
    {
        $this->expectException(\DomainException::class);
        new ExtractionConfidence($value);
    }
}

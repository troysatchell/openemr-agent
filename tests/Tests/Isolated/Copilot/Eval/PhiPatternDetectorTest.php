<?php

/**
 * FROZEN acceptance tests — TRO-41: PHI-free-logs detector (W2_ARCHITECTURE §7, §9; PS-8).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: dumb data, dumb detector. Trace/log text is scanned
 * for identifier-shaped and value-with-clinical-unit patterns regardless of
 * provenance, with ONE narrow allowlist keyed on operational shape
 * (durations, token counts, cost figures, HTTP statuses). The allowlist is
 * shape-narrow, never line-wide: a clinical value sharing a line with a
 * duration still fails. Findings carry the line number and rule name ONLY —
 * never the matched text, because a detector that echoes what it found would
 * launder PHI into its own report. Fails closed: no smart provenance
 * discrimination to get wrong.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Eval;

use OpenEMR\Modules\Copilot\Eval\PhiPatternDetector;
use OpenEMR\Modules\Copilot\Eval\PhiViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhiPatternDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function violatingProvider(): array
    {
        return [
            'potassium with unit' => ['K 6.8 mmol/L'],
            'hemoglobin with unit' => ['Hgb 6.9 g/dL'],
            'glucose mg/dL' => ['glucose came back 42 mg/dL today'],
            'sodium mEq/L' => ['Na 118 mEq/L'],
            'ssn shape' => ['patient ssn 123-45-6789 on file'],
            'clinical value beside operational noise' => ['duration_ms: 84, potassium 6.8 mmol/L'],
        ];
    }

    #[DataProvider('violatingProvider')]
    public function testClinicalValuesAndIdentifiersAreViolations(string $line): void
    {
        $this->assertNotEmpty(PhiPatternDetector::scan($line));
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function operationalProvider(): array
    {
        return [
            'latency' => ['p95: 847ms'],
            'token count' => ['tokens: 1204'],
            'cost' => ['cost_usd: 0.0113'],
            'http status' => ['status: 503'],
            'plain trace event' => ['{"step":"retrieval","outcome":"ok","durationMs":12.4}'],
            'chunk id reference' => ['cited field_or_chunk_id htn.bp-target'],
            'empty' => [''],
        ];
    }

    #[DataProvider('operationalProvider')]
    public function testOperationalShapesPass(string $line): void
    {
        $this->assertSame([], PhiPatternDetector::scan($line));
    }

    public function testFindingsCarryLineNumberAndRuleOnly(): void
    {
        $text = "tokens: 1204\nK 6.8 mmol/L\nstatus: 503";

        $violations = PhiPatternDetector::scan($text);

        $this->assertCount(1, $violations);
        $violation = $violations[0];
        $this->assertInstanceOf(PhiViolation::class, $violation);
        $this->assertSame(2, $violation->lineNumber);
        $this->assertNotSame('', trim($violation->rule));
    }

    public function testViolationShapeCannotCarryMatchedText(): void
    {
        // Structural guarantee: the finding object has exactly two public
        // properties — lineNumber and rule. A detector that echoes what it
        // matched would launder PHI into CI logs.
        $properties = (new \ReflectionClass(PhiViolation::class))
            ->getProperties(\ReflectionProperty::IS_PUBLIC);
        $names = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), $properties);
        sort($names);

        $this->assertSame(['lineNumber', 'rule'], $names);
    }

    public function testMultipleViolationsAcrossLinesAreAllReported(): void
    {
        $text = "K 6.8 mmol/L\np95: 847ms\nssn 123-45-6789";

        $violations = PhiPatternDetector::scan($text);

        $this->assertCount(2, $violations);
        $this->assertSame(1, $violations[0]->lineNumber);
        $this->assertSame(3, $violations[1]->lineNumber);
    }
}

<?php

/**
 * FROZEN acceptance tests — T17: readiness check for /health and /ready
 * (Early Submission observability requirement; AUDIT S5 for the route
 * surface).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: /ready must never be unconditionally healthy. The
 * ReadinessCheck runs named probes (DB, LLM endpoint, trace sink) and is
 * ready only when EVERY probe passes; a throwing probe is a failed probe,
 * not a crashed endpoint; a check with no probes is refused — vacuous
 * readiness is a lie. The report names each probe's result so an operator
 * can see WHAT is down, not just that something is.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Observability;

use OpenEMR\Modules\Copilot\Observability\ReadinessCheck;
use OpenEMR\Modules\Copilot\Observability\ReadinessReport;
use PHPUnit\Framework\TestCase;

class ReadinessCheckTest extends TestCase
{
    public function testReadyOnlyWhenEveryProbePasses(): void
    {
        $check = new ReadinessCheck([
            'db' => static fn (): bool => true,
            'llm' => static fn (): bool => true,
            'trace_sink' => static fn (): bool => true,
        ]);

        $report = $check->run();

        $this->assertInstanceOf(ReadinessReport::class, $report);
        $this->assertTrue($report->ready);
        $this->assertSame(['db' => true, 'llm' => true, 'trace_sink' => true], $report->checks);
    }

    public function testOneFailingProbeMakesTheWholeCheckNotReady(): void
    {
        $check = new ReadinessCheck([
            'db' => static fn (): bool => true,
            'llm' => static fn (): bool => false,
            'trace_sink' => static fn (): bool => true,
        ]);

        $report = $check->run();

        $this->assertFalse($report->ready, '/ready must not report healthy when a dependency is down.');
        $this->assertFalse($report->checks['llm'], 'The report names WHAT is down.');
        $this->assertTrue($report->checks['db']);
    }

    public function testAThrowingProbeIsAFailedProbeNotACrash(): void
    {
        $check = new ReadinessCheck([
            'db' => static fn (): bool => throw new \RuntimeException('connection refused — internals'),
            'trace_sink' => static fn (): bool => true,
        ]);

        $report = $check->run();

        $this->assertFalse($report->ready, 'An unreachable dependency is exactly what readiness exists to surface.');
        $this->assertFalse($report->checks['db']);
        $this->assertTrue($report->checks['trace_sink'], 'One probe failing must not stop the others from reporting.');
    }

    public function testACheckWithNoProbesIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        new ReadinessCheck([]);
    }

    public function testABlankProbeNameIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        new ReadinessCheck([' ' => static fn (): bool => true]);
    }
}

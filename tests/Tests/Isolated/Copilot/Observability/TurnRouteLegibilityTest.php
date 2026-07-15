<?php

/**
 * FROZEN acceptance tests — TRO-45 remainder: the per-turn route is a
 * rendered artifact, not an argument.
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract: DashboardReport gains routesByCorrelation — for every turn
 * (correlation id), the ORDERED list of supervisor routing decisions (the
 * suffixes of its 'handoff.*' steps, in trace order) — so "is it really a
 * multi-agent graph?" is answered by the artifact: a document-bearing
 * turn's route visibly differs from a plain follow-up's. The CLI dashboard
 * renders the per-turn routes. Silent traces stay honestly empty. The
 * fixture JSONL is produced by the real JsonlTraceRecorder, pinning
 * recorder and dashboard as one wire-compatible pair.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Observability;

use OpenEMR\Modules\Copilot\Observability\JsonlTraceRecorder;
use OpenEMR\Modules\Copilot\Observability\StepOutcome;
use OpenEMR\Modules\Copilot\Observability\StepRecord;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Observability\TraceDashboard;
use PHPUnit\Framework\TestCase;

class TurnRouteLegibilityTest extends TestCase
{
    private const DASHBOARD_BIN = __DIR__
        . '/../../../../../interface/modules/custom_modules/oe-module-copilot/bin/trace-dashboard.php';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testEveryTurnRendersItsOrderedRoute(): void
    {
        $path = sys_get_temp_dir() . '/copilot-routes-' . uniqid('', true) . '.jsonl';
        $this->tempFiles[] = $path;
        $recorder = new JsonlTraceRecorder($path);
        $now = new \DateTimeImmutable('2026-07-15T10:00:00+00:00');

        $documentTurn = new TraceContext('corr-doc', 'question', $now);
        $recorder->record($documentTurn, new StepRecord('handoff.intake-extractor', $now, 5.0, StepOutcome::Ok));
        $recorder->record($documentTurn, new StepRecord('llm', $now, 400.0, StepOutcome::Ok));
        $recorder->record($documentTurn, new StepRecord('handoff.compose-answer', $now, 3.0, StepOutcome::Ok));

        $plainTurn = new TraceContext('corr-plain', 'question', $now);
        $recorder->record($plainTurn, new StepRecord('handoff.compose-answer', $now, 2.0, StepOutcome::Ok));

        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $report = (new TraceDashboard())->summarize($raw);

        $this->assertSame(
            [
                'corr-doc' => ['intake-extractor', 'compose-answer'],
                'corr-plain' => ['compose-answer'],
            ],
            $report->routesByCorrelation,
            'a document-bearing turn\'s route visibly differs from a plain follow-up\'s — the artifact answers the question',
        );
    }

    public function testSilentTraceHasNoRoutesNeverInventedOnes(): void
    {
        $report = (new TraceDashboard())->summarize('');

        $this->assertSame([], $report->routesByCorrelation);
    }

    public function testTheCliDashboardRendersPerTurnRoutes(): void
    {
        $bin = file_get_contents(self::DASHBOARD_BIN);
        $this->assertIsString($bin);

        $this->assertStringContainsString('routesByCorrelation', $bin, 'the CLI renders the per-turn route block');
    }
}

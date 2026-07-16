<?php

/**
 * CLI alert checker — evaluates the three defined alerts
 * (docs/OBSERVABILITY.md §"Alert definitions") against the real trace,
 * over a sliding window, with a machine-usable exit code. This is the
 * wired counterpart to the alert SHAPES the observability doc commits to:
 * the same tested TraceDashboard aggregator the dashboard CLI uses, the
 * same JSONL trace every turn writes, evaluated on a schedule (cron) or
 * ad hoc.
 *
 * Usage (in the openemr container):
 *   php .../bin/alert-check.php [/path/to/copilot-trace.jsonl] [--window=900]
 *
 * Defaults: the Bootstrap default trace path; a 15-minute window (900s),
 * matching the alert definitions. --window=0 evaluates the whole file.
 *
 * Exit codes: 0 = no alert firing (including "no traffic in window" —
 * absence of traffic is /ready's job, not this checker's); 2 = at least
 * one alert FIRING; 1 = the trace file could not be read at all.
 *
 * Cron wiring example (every 5 minutes, in-container):
 *   *\/5 * * * * php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-copilot/bin/alert-check.php || <notify>
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\Copilot\Observability\TraceDashboard;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../../../../../vendor/autoload.php';

// Same self-contained namespace registration as trace-dashboard.php: a
// production build drops the root composer autoload-dev mapping, so the CLI
// registers the module namespace itself.
spl_autoload_register(static function (string $class): void {
    $prefix = 'OpenEMR\\Modules\\Copilot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$path = sys_get_temp_dir() . '/copilot-trace.jsonl';
$windowSeconds = 900;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--window=')) {
        $value = substr($arg, strlen('--window='));
        if ($value === '' || !ctype_digit($value)) {
            fwrite(STDERR, "Invalid --window; expected a non-negative integer of seconds (0 = whole file)\n");
            exit(1);
        }
        $windowSeconds = (int) $value;
        continue;
    }
    $path = $arg;
}

if (!is_readable($path)) {
    fwrite(STDERR, sprintf("Trace file not readable: %s\n", $path));
    exit(1);
}
$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, sprintf("Trace file could not be read: %s\n", $path));
    exit(1);
}

// Window filter: keep lines whose started_at falls inside the window.
// Lines without a parseable started_at are kept — TraceDashboard counts
// them as malformed rather than this filter silently discarding evidence.
if ($windowSeconds > 0) {
    $cutoff = time() - $windowSeconds;
    $kept = [];
    foreach (explode("\n", $raw) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        $startedAt = is_array($decoded) && is_string($decoded['started_at'] ?? null)
            ? strtotime($decoded['started_at'])
            : false;
        if ($startedAt === false || $startedAt >= $cutoff) {
            $kept[] = $line;
        }
    }
    $raw = implode("\n", $kept);
}

$report = (new TraceDashboard())->summarize($raw);

$llmSteps = $report->stepCounts['llm'] ?? 0;
$llmFailures = $report->stepFailureCounts['llm'] ?? 0;
$llmFailureRate = $llmSteps > 0 ? $llmFailures / $llmSteps : null;

/** @var list<array{name: string, firing: bool, detail: string}> $alerts */
$alerts = [
    [
        'name' => 'turn latency p95 > 15s',
        'firing' => $report->turnLatencyP95Ms !== null && $report->turnLatencyP95Ms > 15000.0,
        'detail' => $report->turnLatencyP95Ms === null
            ? 'no completed turns in window'
            : sprintf('p95 %.0f ms over %d turn(s), threshold 15000 ms', $report->turnLatencyP95Ms, $report->turnCount),
    ],
    [
        'name' => 'turn error rate > 5%',
        'firing' => $report->errorRate !== null && $report->errorRate > 0.05,
        'detail' => $report->errorRate === null
            ? 'no turns in window'
            : sprintf('%.1f%% (%d of %d turns), threshold 5%%', $report->errorRate * 100, $report->errorTurnCount, $report->turnCount),
    ],
    [
        'name' => 'llm failure rate > 10%',
        'firing' => $llmFailureRate !== null && $llmFailureRate > 0.10,
        'detail' => $llmFailureRate === null
            ? 'no llm steps in window'
            : sprintf('%.1f%% (%d of %d llm steps), threshold 10%%', $llmFailureRate * 100, $llmFailures, $llmSteps),
    ],
];

echo "Clinical Co-Pilot — alert check\n";
echo sprintf("  trace file: %s\n", $path);
echo sprintf("  window:     %s\n", $windowSeconds === 0 ? 'whole file' : sprintf('last %d s', $windowSeconds));
$anyFiring = false;
foreach ($alerts as $alert) {
    $anyFiring = $anyFiring || $alert['firing'];
    echo sprintf("  [%s] %s — %s\n", $alert['firing'] ? 'FIRING' : '  ok  ', $alert['name'], $alert['detail']);
}
if ($report->malformedLineCount > 0) {
    echo sprintf("  note: %d malformed trace line(s) counted, never silently dropped\n", $report->malformedLineCount);
}

exit($anyFiring ? 2 : 0);

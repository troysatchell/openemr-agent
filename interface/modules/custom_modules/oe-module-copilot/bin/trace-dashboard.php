<?php

/**
 * CLI entry for the trace dashboard (T19) — thin shell around the tested
 * TraceDashboard aggregator; all logic lives (and is tested) there.
 *
 * Usage (in the openemr container):
 *   php interface/modules/custom_modules/oe-module-copilot/bin/trace-dashboard.php [/path/to/copilot-trace.jsonl]
 * Defaults to the Bootstrap default trace path.
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

// The module's PSR-4 mapping lives in the root composer.json's autoload-dev,
// which a production build (composer install --no-dev) drops — so on the
// deployed stack Composer's autoloader alone cannot find the module classes.
// The web entry points register the namespace at runtime via
// ModulesClassLoader (see openemr.bootstrap.php); this CLI does the same,
// self-contained, so the dashboard runs identically in dev and on prod.
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

$path = $argv[1] ?? (sys_get_temp_dir() . '/copilot-trace.jsonl');
if (!is_readable($path)) {
    fwrite(STDERR, sprintf("Trace file not readable: %s\n", $path));
    exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, sprintf("Trace file could not be read: %s\n", $path));
    exit(1);
}

$report = (new TraceDashboard())->summarize($raw);

$fmt = static fn (?float $v): string => $v === null ? 'n/a (unmeasurable)' : sprintf('%.1f', $v);

echo "Clinical Co-Pilot — trace dashboard\n";
echo sprintf("  trace file:            %s\n", $path);
echo sprintf("  turns:                 %d\n", $report->turnCount);
echo sprintf("  error turns:           %d (rate: %s)\n", $report->errorTurnCount, $report->errorRate === null ? 'n/a' : sprintf('%.1f%%', $report->errorRate * 100));
echo sprintf("  degraded turns (llm):  %d\n", $report->degradedTurnCount);
echo sprintf("  turn latency p50 ms:   %s\n", $fmt($report->turnLatencyP50Ms));
echo sprintf("  turn latency p95 ms:   %s\n", $fmt($report->turnLatencyP95Ms));
echo "  tool calls (failures):\n";
foreach ($report->stepCounts as $step => $count) {
    echo sprintf("    %-14s %d (%d failed)\n", $step, $count, $report->stepFailureCounts[$step] ?? 0);
}
echo sprintf("  claims grounded:       %d\n", $report->groundedClaimCount);
echo sprintf("  claims rejected:       %d\n", $report->rejectedClaimCount);
echo sprintf("  tokens in/out:         %d / %d\n", $report->inputTokensTotal, $report->outputTokensTotal);
echo sprintf("  cost (USD):            %.4f\n", $report->costUsdTotal);
echo sprintf("  malformed trace lines: %d\n", $report->malformedLineCount);
echo "  not applicable (honest absences):\n";
foreach ($report->notApplicable as $metric => $reason) {
    echo sprintf("    %-14s %s\n", $metric, $reason);
}

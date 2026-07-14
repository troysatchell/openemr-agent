<?php

/**
 * PHI-free-logs detector: scans trace/log text for identifier-shaped and
 * value-with-clinical-unit patterns, regardless of provenance (TRO-41;
 * W2_ARCHITECTURE.md §7, §9; PS-8).
 *
 * `no_phi_in_logs` is verified, not asserted — and the logs are dumb so the
 * detector can be. Trace events are supposed to carry references only
 * (chunk ids, not snippet text; field paths, not values — W2_ARCHITECTURE.md
 * §7/§8, PS-8), from PHI and non-PHI sources alike. Because of that
 * contract, this detector needs no provenance discrimination: it does not
 * ask "did this number come from the chart or from a metric?" — it only
 * asks "does this text have the *shape* of a clinical value or an
 * identifier?" A smart detector that tried to tell PHI from telemetry by
 * context would itself be a rule to get wrong; this one fails closed by
 * having no such rule to get wrong.
 *
 * The rule table below is deliberately a flat, named list — the "dumb
 * table" PS-8 calls for, not a scored or context-aware classifier:
 *
 * - `value-with-clinical-unit`: a number immediately followed by a clinical
 *   unit (mmol/L, mEq/L, mg/dL, g/dL, or a count-style unit such as
 *   `x10^9`/`×10`) is treated as a lab value no matter what else is on the
 *   line. This is what keeps the rule narrow rather than line-wide: it
 *   fires on the *matched span*, so `duration_ms: 84, potassium 6.8 mmol/L`
 *   still violates on the `6.8 mmol/L` span even though `84` alone sits
 *   right next to it unflagged. Plain numbers, durations (`847ms`),
 *   token/cost/status fields, and JSON-shaped operational events never
 *   satisfy this pattern in the first place, because none of them are
 *   followed by a clinical unit token — that is the entire "operational
 *   allowlist": a shape the clinical-unit pattern simply never matches, not
 *   a separate suppression step layered on top that could itself go wrong.
 * - `identifier-shaped`: an SSN-shaped token (`123-45-6789`) is flagged
 *   regardless of whether it is actually a real SSN.
 *
 * One violation is emitted per (line, rule) — a rule that matches a line
 * three times still contributes exactly one `PhiViolation` for that line,
 * because the finding exists to say "this line needs eyes on this rule,"
 * not to enumerate every match. Violations are returned ordered by line
 * number, then by rule-table order within a line.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final class PhiPatternDetector
{
    /**
     * Named rule table, in evaluation/reporting order. Each pattern is
     * matched independently per line; see the class docblock for what each
     * rule catches and why the operational allowlist stays narrow.
     *
     * @var array<string, string>
     */
    private const RULES = [
        'value-with-clinical-unit' => '/\d+(\.\d+)?\s*(mmol\/L|mEq\/L|mg\/dL|g\/dL|×\s?10|x\s?10\^?9?)/i',
        'identifier-shaped' => '/\b\d{3}-\d{2}-\d{4}\b/',
    ];

    private function __construct()
    {
    }

    /**
     * Scans `$text` line by line (1-indexed) against the rule table and
     * returns every finding, ordered by line then by rule-table order.
     *
     * @return list<PhiViolation>
     */
    public static function scan(string $text): array
    {
        $violations = [];

        foreach (explode("\n", $text) as $index => $line) {
            $lineNumber = $index + 1;

            foreach (self::RULES as $rule => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $violations[] = new PhiViolation($lineNumber, $rule);
                }
            }
        }

        return $violations;
    }
}

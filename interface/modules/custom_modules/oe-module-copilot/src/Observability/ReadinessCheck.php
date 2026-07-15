<?php

/**
 * Named-probe readiness check for /api/copilot/ready (T17; Early Submission
 * observability requirement; AUDIT S5 for the route surface; tri-state
 * statuses TRO-47, W2_ARCHITECTURE.md §8).
 *
 * /ready must never be unconditionally healthy: readiness fails only when a
 * probe FAILS. A probe may report three outcomes: `true` ('ok'), the literal
 * string `'degraded'`, or `false` ('failed') — degraded names itself in the
 * report but does not fail readiness on its own (a warning with a name, not
 * an outage; PS-12's "worse results beat no results, but never silently"
 * posture applied to dependency health). A probe that throws is treated as a
 * failed probe, not a crashed endpoint — one unreachable dependency must not
 * take the whole check down. A check constructed with no probes is refused:
 * vacuous readiness is a lie.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final readonly class ReadinessCheck
{
    private const STATUS_OK = 'ok';
    private const STATUS_DEGRADED = 'degraded';
    private const STATUS_FAILED = 'failed';

    /** @var array<string, \Closure(): (bool|string)> */
    private array $probes;

    /**
     * @param array<string, \Closure(): (bool|string)> $probes named probes;
     *        each returns `true` (healthy), `false` (failed), or the literal
     *        string `'degraded'`
     */
    public function __construct(array $probes)
    {
        if ($probes === []) {
            throw new \DomainException('ReadinessCheck requires at least one probe — vacuous readiness is a lie');
        }

        foreach (array_keys($probes) as $name) {
            if (trim((string) $name) === '') {
                throw new \DomainException('ReadinessCheck probe names must be non-blank');
            }
        }

        $this->probes = $probes;
    }

    public function run(): ReadinessReport
    {
        $statuses = [];
        foreach ($this->probes as $name => $probe) {
            try {
                $statuses[$name] = $this->normalizeStatus($probe());
            } catch (\Throwable $e) {
                // A dependency failure is exactly what readiness exists to
                // surface: the probe is marked unhealthy, never a crashed
                // /ready endpoint. A programming \Error / \ErrorException is a
                // bug, not a dependency outage, so it is re-raised to the global
                // handler rather than masked as "unhealthy".
                if (!($e instanceof \Error) && !($e instanceof \ErrorException)) {
                    $statuses[$name] = self::STATUS_FAILED;
                    continue;
                }
                throw $e;
            }
        }

        $checks = [];
        foreach ($statuses as $name => $status) {
            $checks[$name] = $status !== self::STATUS_FAILED;
        }

        $ready = !in_array(self::STATUS_FAILED, $statuses, true);

        return new ReadinessReport($ready, $checks, $statuses);
    }

    /**
     * A probe returning anything other than `true`/`false`/`'degraded'` is
     * itself a wiring bug — never guessed into 'ok', always the conservative
     * 'failed' reading (D1's "treat unknown as unknown" applied to probe
     * results, not just chart data).
     */
    private function normalizeStatus(mixed $result): string
    {
        if (is_bool($result)) {
            return $result ? self::STATUS_OK : self::STATUS_FAILED;
        }

        // Anything that is not exactly true or 'degraded' — including a
        // probe returning null/int by mistake — reads as failed, never as
        // a crashed /ready endpoint (fail closed, loudly visible).
        return $result === self::STATUS_DEGRADED ? self::STATUS_DEGRADED : self::STATUS_FAILED;
    }
}

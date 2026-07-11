<?php

/**
 * Named-probe readiness check for /api/copilot/ready (T17; Early Submission
 * observability requirement; AUDIT S5 for the route surface).
 *
 * /ready must never be unconditionally healthy: it is ready only when every
 * probe passes. A probe that throws is treated as a failed probe, not a
 * crashed endpoint — one unreachable dependency must not take the whole
 * check down. A check constructed with no probes is refused: vacuous
 * readiness is a lie.
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
    /** @var array<string, \Closure(): bool> */
    private array $probes;

    /**
     * @param array<string, \Closure(): bool> $probes named probes; each returns true when healthy
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
        $checks = [];
        foreach ($this->probes as $name => $probe) {
            try {
                $checks[$name] = $probe();
            } catch (\Throwable $e) {
                // A dependency failure is exactly what readiness exists to
                // surface: the probe is marked unhealthy, never a crashed
                // /ready endpoint. A programming \Error / \ErrorException is a
                // bug, not a dependency outage, so it is re-raised to the global
                // handler rather than masked as "unhealthy".
                if (!($e instanceof \Error) && !($e instanceof \ErrorException)) {
                    $checks[$name] = false;
                    continue;
                }
                throw $e;
            }
        }

        $ready = !in_array(false, $checks, true);

        return new ReadinessReport($ready, $checks);
    }
}

<?php

/**
 * Result of running a ReadinessCheck (T17; Early Submission observability
 * requirement; AUDIT S5; tri-state statuses TRO-47, W2_ARCHITECTURE.md §8:
 * "`/ready` grows document-storage, vector-index, and reranker probes and
 * returns per-dependency degraded status rather than binary up/down.").
 *
 * Names each probe's outcome so an operator can see WHAT is down, not just
 * that something is.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final readonly class ReadinessReport
{
    /**
     * @param array<string, bool> $checks probe name => passed (backward-compatible
     *        boolean view; a 'degraded' status counts as passing here — it is
     *        a warning with a name, not an outage)
     * @param array<string, string> $statuses probe name => 'ok' | 'degraded' | 'failed'
     */
    public function __construct(
        public bool $ready,
        public array $checks,
        public array $statuses = [],
    ) {
    }
}

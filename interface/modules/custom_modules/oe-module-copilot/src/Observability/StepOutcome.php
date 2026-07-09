<?php

/**
 * Outcome of one traced orchestrator step (T17; ARCHITECTURE.md §6
 * observability; AUDIT S4/C4/C5; founder decision 5, 2026-07-09).
 *
 * Backed by string so it serializes directly into the PHI-free trace log's
 * JSONL schema without a separate wire mapper.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

enum StepOutcome: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Degraded = 'degraded';
}

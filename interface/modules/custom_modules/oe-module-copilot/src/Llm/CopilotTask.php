<?php

/**
 * Co-pilot task identity at the LLM boundary (T3).
 *
 * Each task carries its own minimum-necessary field allowlist
 * (ARCHITECTURE §3.4, Decision 5; AUDIT C5). String-backed only because the
 * value is persisted into the external-AI disclosure audit record as the
 * disclosure purpose (AUDIT C1) — the backing values are stable audit
 * identifiers and must never be renumbered or reworded.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

enum CopilotTask: string
{
    case Snapshot = 'between-patient-snapshot';
    case FollowUpQa = 'follow-up-qa';
    case PreChart = 'pre-chart';
}

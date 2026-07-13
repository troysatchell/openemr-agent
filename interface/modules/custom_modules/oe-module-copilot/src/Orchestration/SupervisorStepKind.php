<?php

/**
 * The three node kinds in the supervisor's turn graph (W2_ARCHITECTURE.md §6;
 * PS-10).
 *
 * Pure enum (no backing type): these values are runtime routing state, never
 * persisted or serialized — the trace records the kind's name, not this
 * enum, keeping the PHI-free trace schema independent of this type's shape.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

enum SupervisorStepKind
{
    case IntakeExtractor;
    case EvidenceRetriever;
    case ComposeAnswer;
}

<?php

/**
 * Critical-subset finding categories (T10; R13, UC4; ARCHITECTURE.md §6).
 *
 * The four must-not-miss categories — panic labs, drug-drug interactions,
 * drug-allergy conflicts, and open follow-ups — are guaranteed by
 * deterministic detectors in code, never left to model salience. Pure enum
 * (no backing type): these values are runtime state, not persisted or
 * serialized in v1.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Detectors;

enum CriticalFindingType
{
    case PanicLab;
    case DrugDrugInteraction;
    case DrugAllergyConflict;
    case OpenFollowUp;
}

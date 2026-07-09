<?php

/**
 * The reference basis for a "what changed" delta (T13; UC1; AUDIT D1).
 *
 * A change delta is only meaningful relative to a known last-visit date. When
 * that date is unavailable the delta is UNKNOWN — never conflated with "no
 * changes." Treating an absent reference as "empty" would launder a data gap
 * into a false-negative reassurance (AUDIT D1: '' / missing is unknown, not
 * zero).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Snapshot;

enum ChangesBasis
{
    case SinceLastVisit;
    case UnknownNoLastVisit;
}

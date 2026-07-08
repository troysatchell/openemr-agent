<?php

/**
 * Three-state currency of a soft-deleted clinical row (AUDIT D10).
 *
 * This schema soft-deletes: discontinued meds and resolved problems read as
 * current unless activity/deleted/enddate are applied. Unknown is a
 * first-class state — an unevaluable row is surfaced to the synthesis layer,
 * never silently treated as current or silently dropped.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\DataTrust;

enum CurrencyStatus
{
    case Current;
    case NotCurrent;
    case Unknown;
}

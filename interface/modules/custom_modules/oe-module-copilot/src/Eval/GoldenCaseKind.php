<?php

/**
 * The three golden-set execution surfaces (TRO-35; eval/goldenset/README.md
 * "Case schema"; W2_ARCHITECTURE.md §7).
 *
 * String-backed so a case file's `"kind"` wire value maps directly via
 * `tryFrom()` at the loader boundary — an unrecognized kind fails loud
 * (`\DomainException`) rather than silently loading as a partial case.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

enum GoldenCaseKind: string
{
    case Extraction = 'extraction';
    case Retrieval = 'retrieval';
    case Turn = 'turn';
}

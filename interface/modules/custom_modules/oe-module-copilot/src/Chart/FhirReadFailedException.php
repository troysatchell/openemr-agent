<?php

/**
 * Raised when a delegated FHIR read cannot be completed (T5).
 *
 * Chart reads never degrade silently: a failed source read propagates as
 * this exception instead of collapsing into an empty result set, because a
 * silently-empty chart is an omission hazard (missed meds, allergies, labs).
 * Wrapper messages stay generic; the originating exception rides along via
 * getPrevious() and must never reach user-facing output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Chart;

class FhirReadFailedException extends \RuntimeException
{
}

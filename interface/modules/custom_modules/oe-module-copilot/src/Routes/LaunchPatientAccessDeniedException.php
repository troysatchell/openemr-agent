<?php

/**
 * Thrown by LaunchPatientBinding::enforce() whenever a clinical route's
 * request cannot be reconciled with the access token's launch-context
 * patient (TRO-52 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.1).
 *
 * Extends \RuntimeException (not \DomainException) so the composition root
 * can distinguish "authorization refused" (this exception, mapped to a 403)
 * from the module's existing 400-shaped \DomainException input-validation
 * failures without inspecting message text.
 *
 * Messages here are deliberately generic and MUST NEVER include a patient
 * uuid — the token's own launch-context patient, or the mismatched body
 * value, are both identifiers this exception is forbidden from leaking
 * (R11: no identifier leak in error output).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Routes;

final class LaunchPatientAccessDeniedException extends \RuntimeException
{
}

<?php

/**
 * Denial signal for the session panel's default-deny gate (T21; AUDIT S5).
 *
 * Thrown by SessionGate for every denial path — missing/blank CSRF token, a
 * failed CSRF check, a failed ACL check, or an unparseable principal (S4).
 * Messages are always generic: the CSRF token and any collaborator
 * internals never appear in the exception message, so a caller that logs or
 * displays this exception cannot leak either.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel;

class SessionAccessDeniedException extends \RuntimeException
{
}

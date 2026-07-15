<?php

/**
 * Thrown by AuthorizeRedirect::build() whenever the SMART EHR-launch `iss`
 * cannot be reconciled with this deployment's own FHIR base
 * (TRO-53 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.2).
 *
 * The launch page NEVER redirects to an authorize endpoint on the strength
 * of an issuer it does not recognise — trailing-slash differences are
 * tolerated, everything else (different host, scheme, path, or a
 * prefix/suffix lookalike) is refused before any redirect is built.
 *
 * Extends \RuntimeException (not \DomainException) so callers can
 * distinguish "issuer refused" (mapped to a 400, never a redirect) from
 * ordinary input-validation failures without inspecting message text.
 *
 * Messages here are deliberately generic — no issuer value, host, or other
 * request detail is ever included (R11: no identifier leak in error output).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel\SmartLaunch;

final class IssuerMismatchException extends \RuntimeException
{
}

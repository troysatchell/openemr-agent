<?php

/**
 * Pure guard binding every clinical route's request to the access token's
 * launch-context patient (TRO-52 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.1).
 *
 * The token's patient — HttpRestRequest::getPatientUUIDString(), resolved by
 * the composition root and passed in here as a plain constructor argument —
 * is the sole source of authority for "which patient". A request body may
 * omit its own patient_uuid (it is then injected from the token) or restate
 * it (it is then required to match, case-insensitively, after trimming);
 * cross-patient access is structurally impossible because the effective
 * value returned by enforce() is always the token's own canonical uuid,
 * never the caller-supplied one.
 *
 * An unbound token (null / '' / whitespace-only — D1: empty string is
 * unknown, so it does not count as "bound") refuses enforce() unconditionally
 * and before any chart read: clinical routes require a patient-bound token
 * by design (§4.1 decision D1).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Routes;

final readonly class LaunchPatientBinding
{
    /**
     * The token's launch-context patient, canonicalized (trimmed), or null
     * when the token carries no patient (unbound).
     */
    private ?string $tokenPatientUuid;

    public function __construct(?string $tokenPatientUuid)
    {
        $trimmed = $tokenPatientUuid === null ? '' : trim($tokenPatientUuid);
        $this->tokenPatientUuid = $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $input the parsed request body
     *
     * @return array<string, mixed> $input with 'patient_uuid' set to the
     *                               token's own canonical uuid; every other
     *                               key untouched
     *
     * @throws LaunchPatientAccessDeniedException when the token is unbound,
     *         when the body's patient_uuid is not a string, or when it
     *         mismatches the token's launch-context patient
     */
    public function enforce(array $input): array
    {
        if ($this->tokenPatientUuid === null) {
            throw new LaunchPatientAccessDeniedException(
                'This route requires a patient-bound access token.'
            );
        }

        if (!array_key_exists('patient_uuid', $input)) {
            $input['patient_uuid'] = $this->tokenPatientUuid;
            return $input;
        }

        $bodyPatientUuid = $input['patient_uuid'];
        if (!is_string($bodyPatientUuid)) {
            throw new LaunchPatientAccessDeniedException(
                'Request patient identifier must be a string.'
            );
        }

        $trimmedBodyPatientUuid = trim($bodyPatientUuid);
        if ($trimmedBodyPatientUuid === '') {
            $input['patient_uuid'] = $this->tokenPatientUuid;
            return $input;
        }

        if (strcasecmp($trimmedBodyPatientUuid, $this->tokenPatientUuid) !== 0) {
            throw new LaunchPatientAccessDeniedException(
                'Request patient does not match the access token\'s launch-context patient.'
            );
        }

        $input['patient_uuid'] = $this->tokenPatientUuid;
        return $input;
    }
}

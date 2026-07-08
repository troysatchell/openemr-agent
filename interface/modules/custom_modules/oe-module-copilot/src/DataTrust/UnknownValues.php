<?php

/**
 * Empty-string-as-unknown normalizer (AUDIT D1).
 *
 * 318 columns in this schema are NOT NULL DEFAULT '' — an empty string means
 * "missing", never "known empty". This normalizer maps every unknown
 * representation (null, '', whitespace-only) to null so downstream co-pilot
 * code has exactly one missing-value shape to reason about.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\DataTrust;

final class UnknownValues
{
    private function __construct()
    {
    }

    public static function isUnknown(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

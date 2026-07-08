<?php

/**
 * Boolean-variant normalizer (AUDIT D4).
 *
 * This schema stores booleans at least four incompatible ways — tinyint(1)
 * 0/1, varchar 'YES'/'NO', varchar 'yes', enum('Yes','No'). This normalizer
 * accepts exactly the audited variants (any letter case, surrounding
 * whitespace tolerated) and returns null — unknown — for everything else.
 * It never guesses: an unrecognized value is not false (D1: '' is unknown).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\DataTrust;

final class BooleanNormalizer
{
    private function __construct()
    {
    }

    public static function normalize(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'yes' => true,
                '0', 'no' => false,
                default => null,
            };
        }

        return null;
    }
}

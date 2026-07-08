<?php

/**
 * Defensive clinical date parsing (AUDIT D0/D6).
 *
 * With sql_mode='' (D0) date columns can hold '0000-00-00', and some dates
 * live in varchar/TEXT columns as free text (D6). This parser accepts exactly
 * 'Y-m-d' and 'Y-m-d H:i:s' with strict round-trip validation (no PHP date
 * rollover: 2024-02-30 is invalid, not March 1st) and returns null for every
 * other shape. Null is "unknown", never a default date.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\DataTrust;

final class ClinicalDate
{
    /**
     * Audited storage formats (D6): parse format => canonical round-trip
     * format. The '!' prefix zeroes unspecified fields so a bare date yields
     * midnight rather than "now".
     *
     * @var array<string, string>
     */
    private const FORMATS = [
        '!Y-m-d' => 'Y-m-d',
        '!Y-m-d H:i:s' => 'Y-m-d H:i:s',
    ];

    private function __construct()
    {
    }

    public static function tryParse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        foreach (self::FORMATS as $parseFormat => $canonicalFormat) {
            $parsed = \DateTimeImmutable::createFromFormat($parseFormat, $trimmed);
            if ($parsed !== false && $parsed->format($canonicalFormat) === $trimmed) {
                return $parsed;
            }
        }

        return null;
    }
}

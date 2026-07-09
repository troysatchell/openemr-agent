<?php

/**
 * The ONE canonical wire representation of chart-currency state at the LLM
 * boundary (AUDIT D1/D10; ARCHITECTURE.md §3).
 *
 * Every data class routes through this mapper — never a per-class encoding.
 * CurrencyStatus::Unknown crosses as exactly one token ('unknown'), which is
 * also the class-level marker for a chart never assessed for a data class;
 * a class assessed with zero entries recorded (known-absent, e.g. NKDA)
 * crosses as the distinct 'none-recorded'. Neither token is blank or
 * spellable as false: minimum-necessary COMPRESSES what crosses, but
 * honest-uncertainty PRESERVES the known-absent vs never-assessed
 * distinction — trimming must never destroy it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;

final class CurrencyWire
{
    /** Canonical token for Unknown currency AND for a never-assessed data class. */
    public const UNKNOWN = 'unknown';

    /** Class-level token: assessed, zero entries recorded (known-absent). */
    public const KNOWN_ABSENT = 'none-recorded';

    public const CURRENT = 'current';

    public const NOT_CURRENT = 'not-current';

    private function __construct()
    {
    }

    public static function status(CurrencyStatus $status): string
    {
        return match ($status) {
            CurrencyStatus::Current => self::CURRENT,
            CurrencyStatus::NotCurrent => self::NOT_CURRENT,
            CurrencyStatus::Unknown => self::UNKNOWN,
        };
    }
}

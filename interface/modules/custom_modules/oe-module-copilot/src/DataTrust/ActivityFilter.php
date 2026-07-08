<?php

/**
 * Activity/deleted currency filter (AUDIT D10).
 *
 * Decides whether a soft-deleted clinical row (lists-table med, problem,
 * allergy) is current, in precedence order: deleted flag, then activity flag,
 * then end date (date-only, end date inclusive). Boolean flags accept exactly
 * the audited variants (D4); dates parse strictly with no rollover (D0/D6).
 * Anything unevaluable returns CurrencyStatus::Unknown — surfaced, never
 * silently current.
 *
 * Boolean/date helpers are intentionally private duplicates of the
 * BooleanNormalizer/ClinicalDate normalizers (built concurrently); the
 * synthesis ticket consolidates them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\DataTrust;

final class ActivityFilter
{
    private const DATE_FORMATS = [
        '!Y-m-d' => 'Y-m-d',
        '!Y-m-d H:i:s' => 'Y-m-d H:i:s',
    ];

    private function __construct()
    {
    }

    public static function status(
        mixed $activity,
        ?string $endDate,
        \DateTimeImmutable $today,
        mixed $deleted = false,
    ): CurrencyStatus {
        $deletedFlag = self::normalizeBoolean($deleted);
        if ($deletedFlag === true) {
            return CurrencyStatus::NotCurrent;
        }
        if ($deletedFlag === null && !self::isMissing($deleted)) {
            // A deleted flag that is present but unrecognizable cannot be
            // evaluated — surface it rather than guessing (D10).
            return CurrencyStatus::Unknown;
        }

        $activityFlag = self::normalizeBoolean($activity);
        if ($activityFlag === false) {
            return CurrencyStatus::NotCurrent;
        }
        if ($activityFlag === null) {
            return CurrencyStatus::Unknown;
        }

        if ($endDate === null || trim($endDate) === '') {
            return CurrencyStatus::Current;
        }

        $end = self::tryParseDate($endDate);
        if ($end === null) {
            return CurrencyStatus::Unknown;
        }

        // Date-only comparison, end date inclusive: a med ending today is
        // still current between patients today.
        return $end->format('Y-m-d') >= $today->format('Y-m-d')
            ? CurrencyStatus::Current
            : CurrencyStatus::NotCurrent;
    }

    /**
     * True when the value is the schema's "missing" shape (D1): null or a
     * blank string. Missing is distinct from present-but-unrecognizable.
     */
    private static function isMissing(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function normalizeBoolean(mixed $value): ?bool
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

    private static function tryParseDate(string $value): ?\DateTimeImmutable
    {
        $trimmed = trim($value);
        foreach (self::DATE_FORMATS as $parseFormat => $canonicalFormat) {
            $parsed = \DateTimeImmutable::createFromFormat($parseFormat, $trimmed);
            if ($parsed !== false && $parsed->format($canonicalFormat) === $trimmed) {
                return $parsed;
            }
        }

        return null;
    }
}

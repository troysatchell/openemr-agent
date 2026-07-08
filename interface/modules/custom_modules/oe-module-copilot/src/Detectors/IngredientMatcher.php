<?php

/**
 * Word-boundary ingredient matching inside free-text medication names (T10).
 *
 * Medication names are untrusted free text ("Warfarin 5mg Tablet"); an
 * ingredient matches only as a whole word — "warfarin" must never match
 * inside "Nowarfarinol". Case-insensitive via lowercase folding on both
 * sides; the ingredient is preg-quoted so names containing regex
 * metacharacters cannot break or repurpose the pattern.
 *
 * @internal shared helper for the drug-drug and drug-allergy detectors only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Detectors;

final class IngredientMatcher
{
    private function __construct()
    {
    }

    public static function matches(string $medicationName, string $ingredient): bool
    {
        $needle = strtolower(trim($ingredient));
        if ($needle === '') {
            return false;
        }

        return preg_match(
            '/\b' . preg_quote($needle, '/') . '\b/',
            strtolower($medicationName),
        ) === 1;
    }
}

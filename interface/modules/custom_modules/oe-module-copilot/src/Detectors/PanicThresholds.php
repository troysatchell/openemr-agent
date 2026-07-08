<?php

/**
 * Typed panic-lab threshold table (T10; R13, UC4; ARCHITECTURE.md §6).
 *
 * Maps a lowercase analyte name to low/high panic bounds in a declared
 * unit. Every entry must define at least one bound and a non-blank unit —
 * a threshold that cannot be evaluated is a configuration error, not a
 * runtime shrug. Threshold CONTENTS are clinical content and ship as DRAFT
 * until human sign-off (see draftV1()).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Detectors;

final readonly class PanicThresholds
{
    /** @var array<string, array{low: float|null, high: float|null, unit: string}> */
    private array $thresholds;

    /**
     * @param array<string, array{low: float|null, high: float|null, unit: string}> $thresholds
     *   keyed by lowercase analyte name; at least one bound and a non-blank
     *   unit are required per entry
     */
    public function __construct(array $thresholds)
    {
        $normalized = [];
        foreach ($thresholds as $analyte => $bounds) {
            $key = strtolower(trim($analyte));
            if ($key === '') {
                throw new \DomainException('Panic threshold analyte name must be non-blank');
            }
            if ($bounds['low'] === null && $bounds['high'] === null) {
                throw new \DomainException(
                    sprintf('Panic threshold for "%s" must define at least one bound', $key)
                );
            }
            if (trim($bounds['unit']) === '') {
                throw new \DomainException(
                    sprintf('Panic threshold for "%s" must declare a non-blank unit', $key)
                );
            }
            $normalized[$key] = $bounds;
        }
        $this->thresholds = $normalized;
    }

    /**
     * @return array{low: float|null, high: float|null, unit: string}|null
     *   null when the analyte is not tracked by this table
     */
    public function thresholdFor(string $analyte): ?array
    {
        return $this->thresholds[strtolower(trim($analyte))] ?? null;
    }

    /**
     * DRAFT — founder-adjudicated clinical content pending human sign-off.
     *
     * Bounds are deliberately conservative extremes (panic, not abnormal —
     * R7 alert fatigue). Do not extend or tune this table without human
     * clinical review; a red golden-chart gate over these values is a
     * stop-and-escalate, never a fixture edit.
     */
    public static function draftV1(): self
    {
        return new self([
            'potassium' => ['low' => 2.5, 'high' => 6.0, 'unit' => 'mmol/L'],
            'sodium' => ['low' => 120.0, 'high' => 160.0, 'unit' => 'mmol/L'],
            'glucose' => ['low' => 40.0, 'high' => 500.0, 'unit' => 'mg/dL'],
            'hemoglobin' => ['low' => 6.5, 'high' => null, 'unit' => 'g/dL'],
            'platelets' => ['low' => 20.0, 'high' => null, 'unit' => '10*3/uL'],
        ]);
    }
}

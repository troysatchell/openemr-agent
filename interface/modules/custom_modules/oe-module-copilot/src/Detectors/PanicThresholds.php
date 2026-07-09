<?php

/**
 * Typed panic-lab threshold table (T10; R13, UC4; ARCHITECTURE.md §6).
 *
 * Maps a lowercase analyte name to low/high panic bounds in a declared
 * unit. Every entry must define at least one bound and a non-blank unit —
 * a threshold that cannot be evaluated is a configuration error, not a
 * runtime shrug. Whether a bound is inclusive (value AT the bound is panic)
 * is a per-analyte, cited property of the data — ARUP prints "≤ 20" for
 * platelets but "< 3.0" for potassium — carried as explicit lowInclusive/
 * highInclusive flags that default to false (strictly-outside). Threshold
 * CONTENTS are clinical content and ship as DRAFT until human sign-off
 * (see draftV1()).
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
    /** @var array<string, array{low: float|null, high: float|null, unit: string, lowInclusive: bool, highInclusive: bool}> */
    private array $thresholds;

    /**
     * @param array<string, array{low: float|null, high: float|null, unit: string, lowInclusive?: bool, highInclusive?: bool}> $thresholds
     *   keyed by lowercase analyte name; at least one bound and a non-blank
     *   unit are required per entry. Omitted inclusivity flags default to
     *   false (a value exactly at the bound is not panic).
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
            $normalized[$key] = [
                'low' => $bounds['low'],
                'high' => $bounds['high'],
                'unit' => $bounds['unit'],
                'lowInclusive' => $bounds['lowInclusive'] ?? false,
                'highInclusive' => $bounds['highInclusive'] ?? false,
            ];
        }
        $this->thresholds = $normalized;
    }

    /**
     * @return array{low: float|null, high: float|null, unit: string, lowInclusive: bool, highInclusive: bool}|null
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
     * R7 alert fatigue). Values transcribed from the ARUP Laboratories
     * Critical Values List, CORP-APPEND-0104A, Rev. 46, April 2026, p.1
     * (adult bands; PHASE0.md §3a.1): K <3.0, Na <120/>160, glucose <55/>450,
     * Hgb <7.0, platelets ≤20/≥1000 ×10³/µL — the only inclusive bounds ARUP
     * prints, hence the platelet flags. The potassium HIGH bound stays 6.0
     * (ARUP prints >6.1) by explicit human scope: over-flagging 6.0–6.1 is
     * noted in PHASE0.md §3a.5 as a minor over-flag, not a miss. Do not
     * extend or tune this table without human clinical review; a red
     * golden-chart gate over these values is a stop-and-escalate, never a
     * fixture edit.
     */
    public static function draftV1(): self
    {
        return new self([
            'potassium' => ['low' => 3.0, 'high' => 6.0, 'unit' => 'mmol/L'],
            'sodium' => ['low' => 120.0, 'high' => 160.0, 'unit' => 'mmol/L'],
            'glucose' => ['low' => 55.0, 'high' => 450.0, 'unit' => 'mg/dL'],
            'hemoglobin' => ['low' => 7.0, 'high' => null, 'unit' => 'g/dL'],
            'platelets' => [
                'low' => 20.0,
                'high' => 1000.0,
                'unit' => '10*3/uL',
                'lowInclusive' => true,
                'highInclusive' => true,
            ],
        ]);
    }
}

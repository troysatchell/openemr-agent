<?php

/**
 * A normalized page-relative bounding box for click-to-source overlays
 * (TRO-44, R-W3).
 *
 * Coordinates are fractions of the source page's width/height — x and y in
 * [0,1] (the box's top-left corner may sit anywhere on the page, including
 * its edges), width and height in (0,1] (a zero-area box is not a region).
 * This is a UI affordance only: it locates a rectangle for the panel to draw
 * over the rendered page, it is never verification ground (a bbox is never
 * consulted to decide whether a claim is grounded — the citation's quote and
 * field path do that).
 *
 * `fromWire()` is the untrusted-boundary constructor used by
 * VlmExtractionParser: per R-W3 ("a sloppy box degrades UX, never
 * correctness"), it never throws — a malformed wire value degrades to null
 * so the field it decorates stays valid and simply renders without an
 * overlay. It pre-validates rather than construct-and-catch, so the only
 * exception this class can raise is the constructor's own DomainException
 * for genuinely out-of-contract direct construction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Extraction;

final readonly class BoundingBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        if (!self::componentsAreValid($x, $y, $width, $height)) {
            throw new \DomainException(
                'BoundingBox components must be normalized page coordinates: x,y in [0,1], width,height in (0,1]',
            );
        }
    }

    public function toCsv(): string
    {
        return sprintf('%.4f,%.4f,%.4f,%.4f', $this->x, $this->y, $this->width, $this->height);
    }

    /**
     * Parses an untrusted wire value into a BoundingBox, or null when it
     * does not conform. Never throws — a malformed box degrades to "no box"
     * rather than poisoning the field it decorates (R-W3).
     */
    public static function fromWire(mixed $wire): ?self
    {
        if (!is_array($wire) || !array_is_list($wire) || count($wire) !== 4) {
            return null;
        }

        $components = [];
        foreach ($wire as $component) {
            if (!is_int($component) && !is_float($component)) {
                return null;
            }
            $components[] = (float) $component;
        }

        [$x, $y, $width, $height] = $components;
        if (!self::componentsAreValid($x, $y, $width, $height)) {
            return null;
        }

        return new self($x, $y, $width, $height);
    }

    private static function componentsAreValid(float $x, float $y, float $width, float $height): bool
    {
        if ($x < 0.0 || $x > 1.0) {
            return false;
        }
        if ($y < 0.0 || $y > 1.0) {
            return false;
        }
        if ($width <= 0.0 || $width > 1.0) {
            return false;
        }
        if ($height <= 0.0 || $height > 1.0) {
            return false;
        }

        return true;
    }
}

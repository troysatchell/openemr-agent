<?php

/**
 * Typed drug-drug interaction pair table (T10; R13, UC4; ARCHITECTURE.md §6).
 *
 * A list of two-element lowercase ingredient pairs. Members are normalized
 * (trimmed, lowercased) at construction; blank members are rejected — a
 * pair that cannot match anything is a configuration error. Pair CONTENTS
 * are clinical content and ship as DRAFT until human sign-off (see
 * draftV1()).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Detectors;

final readonly class InteractionPairs
{
    /** @var list<array{string, string}> */
    public array $pairs;

    /**
     * @param list<array{string, string}> $pairs two-element lowercase ingredient pairs
     */
    public function __construct(array $pairs)
    {
        $normalized = [];
        foreach ($pairs as $pair) {
            $first = strtolower(trim($pair[0]));
            $second = strtolower(trim($pair[1]));
            if ($first === '' || $second === '') {
                throw new \DomainException('Interaction pair members must be non-blank ingredient names');
            }
            $normalized[] = [$first, $second];
        }
        $this->pairs = $normalized;
    }

    /**
     * DRAFT — founder-adjudicated clinical content pending human sign-off.
     *
     * Only unambiguous classics; do not extend or tune this table without
     * human clinical review.
     */
    public static function draftV1(): self
    {
        return new self([
            ['warfarin', 'aspirin'],
            ['warfarin', 'ibuprofen'],
            ['methotrexate', 'trimethoprim'],
        ]);
    }
}

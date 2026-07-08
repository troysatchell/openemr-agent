<?php

/**
 * Typed allergy cross-reactivity class map (T10; R13, UC4;
 * ARCHITECTURE.md §6).
 *
 * Maps a class name (e.g. "penicillins") to a non-empty list of lowercase
 * member ingredients. An allergy substance expands to itself plus the
 * members of any class it names or belongs to — how a documented
 * "penicillin" allergy reaches an amoxicillin prescription. Class CONTENTS
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

final readonly class AllergyClassMap
{
    /** @var array<string, list<string>> */
    private array $classes;

    /**
     * @param array<string, list<string>> $classes class name => non-empty
     *   list of lowercase member ingredients
     */
    public function __construct(array $classes)
    {
        $normalized = [];
        foreach ($classes as $className => $members) {
            $key = strtolower(trim($className));
            if ($key === '') {
                throw new \DomainException('Allergy class name must be non-blank');
            }
            if ($members === []) {
                throw new \DomainException(
                    sprintf('Allergy class "%s" must list at least one member ingredient', $key)
                );
            }
            $normalizedMembers = [];
            foreach ($members as $member) {
                $trimmed = strtolower(trim($member));
                if ($trimmed === '') {
                    throw new \DomainException(
                        sprintf('Allergy class "%s" contains a blank member ingredient', $key)
                    );
                }
                $normalizedMembers[] = $trimmed;
            }
            $normalized[$key] = $normalizedMembers;
        }
        $this->classes = $normalized;
    }

    /**
     * Expand an allergy substance to every ingredient it implies: the
     * substance itself, plus the members of any class whose name equals the
     * substance or whose member list contains it (cross-reactivity).
     *
     * @return list<string> lowercase ingredient names, deduplicated
     */
    public function expand(string $substance): array
    {
        $needle = strtolower(trim($substance));
        $expanded = [$needle];
        foreach ($this->classes as $className => $members) {
            if ($className === $needle || in_array($needle, $members, true)) {
                $expanded = [...$expanded, ...$members];
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * DRAFT — founder-adjudicated clinical content pending human sign-off.
     *
     * Only unambiguous classics; do not extend or tune this map without
     * human clinical review.
     */
    public static function draftV1(): self
    {
        return new self([
            'penicillins' => ['penicillin', 'amoxicillin', 'ampicillin'],
            'sulfonamides' => ['sulfamethoxazole', 'sulfasalazine'],
        ]);
    }
}

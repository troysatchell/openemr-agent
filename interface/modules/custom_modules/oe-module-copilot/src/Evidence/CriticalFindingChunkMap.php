<?php

/**
 * Deterministic finding-type → chunk map for critical-value evidence
 * (W2_ARCHITECTURE.md §6 "Critical-value evidence is mapped, not searched";
 * PS-10; corpus README §3 chunk inventory, §5 CI invariants, §6 governance
 * record).
 *
 * When the supervisor's one conditional edge fires for a critical finding
 * (UC6 feeding UC4), the supporting evidence chunk is looked up here, never
 * retrieved by similarity ranking. Fuzziness is the failure this map exists
 * to prevent: `af.bleeding-risk` or `critical.hemoglobin` could score close
 * to the right answer in a hybrid retriever at exactly the moment fuzziness
 * is least affordable — a mis-cited protocol for a panic value. So panic-lab
 * analyte matching is EXACT ONLY (case-insensitive, trimmed) — no substring,
 * no partial, no abbreviation match, ever. An analyte the table does not
 * recognize is not "close enough" to a known one; it falls back to the
 * practice's general critical-response protocol chunk, which is always a
 * safe, cited answer.
 *
 * Only `PanicLab` findings carry a per-analyte chunk today. The other three
 * critical-subset categories (drug-drug interaction, drug-allergy conflict,
 * open follow-up) all resolve to the same general-response chunk. Building
 * per-type protocol chunks for those categories is a future corpus-widening
 * decision — a clinical-governance event (corpus README §6), not a
 * mechanical edit — so it is deliberately left undone here rather than
 * guessed at.
 *
 * The map is total over {@see CriticalFindingType} (every case resolves to
 * a chunk id) and every declared chunk id is asserted, in this repo's test
 * suite, to exist in the real corpus manifest — the conditional edge can
 * never fire into a missing target.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Evidence;

use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;

final class CriticalFindingChunkMap
{
    /**
     * Panic-lab analyte → its per-analyte critical-value chunk id. Keys are
     * lowercase; lookups normalize the hint the same way (trim + strtolower)
     * before an EXACT equality check — never a substring or fuzzy match.
     *
     * @var array<string, string>
     */
    private const ANALYTE_CHUNKS = [
        'potassium' => 'critical.potassium',
        'glucose' => 'critical.glucose',
        'sodium' => 'critical.sodium',
        'hemoglobin' => 'critical.hemoglobin',
        'platelets' => 'critical.platelets',
    ];

    /**
     * The practice's general critical-response protocol chunk: the fallback
     * for an unrecognized/missing panic-lab analyte hint, and the only
     * target for the three non-lab critical-subset categories.
     */
    private const GENERAL_RESPONSE_CHUNK_ID = 'critical.response-general';

    private function __construct()
    {
    }

    /**
     * Resolves a critical finding to its supporting evidence chunk id.
     *
     * `PanicLab` findings with an analyte hint that exactly matches a known
     * analyte (case-insensitive, trimmed) resolve to that analyte's chunk;
     * every other case — unknown analyte, missing hint, or a non-lab
     * finding type — resolves to the general critical-response chunk.
     */
    public static function chunkIdFor(CriticalFindingType $type, ?string $analyteHint = null): string
    {
        return match ($type) {
            CriticalFindingType::PanicLab => self::ANALYTE_CHUNKS[self::normalizeAnalyte($analyteHint)]
                ?? self::GENERAL_RESPONSE_CHUNK_ID,
            CriticalFindingType::DrugDrugInteraction,
            CriticalFindingType::DrugAllergyConflict,
            CriticalFindingType::OpenFollowUp => self::GENERAL_RESPONSE_CHUNK_ID,
        };
    }

    /**
     * The full declared finding-key → chunk-id table, for CI iteration
     * (every declared entry must resolve in the real corpus manifest, and
     * every {@see CriticalFindingType} case must appear among the keys).
     *
     * @return array<string, string>
     */
    public static function entries(): array
    {
        $panicLabKey = self::keyFor(CriticalFindingType::PanicLab);

        $entries = [];
        foreach (self::ANALYTE_CHUNKS as $analyte => $chunkId) {
            $entries["{$panicLabKey}.{$analyte}"] = $chunkId;
        }
        $entries["{$panicLabKey}.default"] = self::GENERAL_RESPONSE_CHUNK_ID;

        $entries[self::keyFor(CriticalFindingType::DrugDrugInteraction)] = self::GENERAL_RESPONSE_CHUNK_ID;
        $entries[self::keyFor(CriticalFindingType::DrugAllergyConflict)] = self::GENERAL_RESPONSE_CHUNK_ID;
        $entries[self::keyFor(CriticalFindingType::OpenFollowUp)] = self::GENERAL_RESPONSE_CHUNK_ID;

        return $entries;
    }

    /**
     * Stable kebab-case key per {@see CriticalFindingType} case, used to
     * namespace {@see entries()} keys and to prove (in tests) that every
     * enum case is represented in the declared table.
     */
    public static function keyFor(CriticalFindingType $type): string
    {
        return match ($type) {
            CriticalFindingType::PanicLab => 'panic-lab',
            CriticalFindingType::DrugDrugInteraction => 'drug-drug-interaction',
            CriticalFindingType::DrugAllergyConflict => 'drug-allergy-conflict',
            CriticalFindingType::OpenFollowUp => 'open-follow-up',
        };
    }

    private static function normalizeAnalyte(?string $analyteHint): string
    {
        if ($analyteHint === null) {
            return '';
        }

        return strtolower(trim($analyteHint));
    }
}

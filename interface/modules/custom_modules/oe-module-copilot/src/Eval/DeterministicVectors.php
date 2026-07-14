<?php

/**
 * Deterministic, hash-seeded embedding vectors for the eval gate's vendor
 * fixture policy (TRO-35; eval/goldenset/README.md "Vendor fixture policy";
 * W2_ARCHITECTURE.md §7).
 *
 * The gate's retrieval fixtures assert *plumbing* — candidate-union SQL,
 * top-k discipline, rerank consumption, degradation flags — never embedding
 * *quality*, which real semantic embeddings are out of the gate's reach to
 * verify anyway (no vendor call in the gate, §7). So chunk embeddings are
 * unit vectors seeded deterministically from the chunk text's hash, at the
 * production {@see \OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema::EMBEDDING_DIMENSIONS}
 * dimension — reproducible byte-for-byte across machines and runs without
 * any RNG state, since every component is an independent hash of
 * `(text, componentIndex)`. A query embedding is the normalized centroid of
 * the chunk vectors it is meant to land near (its case's
 * `fixture_aim_chunk_ids`); with no aim chunks, the query text stands in for
 * its own "chunk text" so the vector is still deterministic and still
 * (by construction of independent hash components in a high-dimensional
 * space) not meaningfully close to any specific corpus vector.
 *
 * Pure functions only: no I/O, no clock, no global RNG state.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final class DeterministicVectors
{
    private function __construct()
    {
    }

    /**
     * A deterministic unit vector of `$dimensions` components, seeded from
     * `$text`. Component `$i` is derived from `sha256($text . '|' . $i)`,
     * mapped from its first 4 bytes (big-endian uint32) onto [-1, 1]; the
     * full vector is then L2-normalized.
     *
     * @return list<float>
     */
    public static function vectorForText(string $text, int $dimensions): array
    {
        if ($dimensions < 1) {
            throw new \DomainException('DeterministicVectors dimensions must be >= 1');
        }

        $components = [];
        for ($i = 0; $i < $dimensions; $i++) {
            $digest = hash('sha256', $text . '|' . $i, true);
            $unpacked = unpack('N', substr($digest, 0, 4));
            $uint32 = is_array($unpacked) ? ($unpacked[1] ?? 0) : 0;
            if (!is_int($uint32)) {
                $uint32 = 0;
            }
            $components[] = (($uint32 / 4294967295.0) * 2.0) - 1.0;
        }

        return self::normalize($components);
    }

    /**
     * The normalized mean of `$vectors` — every vector must share the same
     * dimensionality, and the list must be non-empty.
     *
     * @param list<list<float>> $vectors
     *
     * @return list<float>
     */
    public static function centroid(array $vectors): array
    {
        if ($vectors === []) {
            throw new \DomainException('DeterministicVectors centroid requires at least one vector');
        }

        $dimensions = count($vectors[0]);
        $sum = array_fill(0, $dimensions, 0.0);

        foreach ($vectors as $vector) {
            if (count($vector) !== $dimensions) {
                throw new \DomainException('DeterministicVectors centroid requires vectors of equal dimensionality');
            }
            foreach ($vector as $index => $component) {
                $sum[$index] += $component;
            }
        }

        $count = count($vectors);
        /** @var list<float> $mean */
        $mean = [];
        foreach ($sum as $total) {
            $mean[] = $total / $count;
        }

        return self::normalize($mean);
    }

    /**
     * @param list<float> $vector
     *
     * @return list<float>
     */
    private static function normalize(array $vector): array
    {
        $sumOfSquares = 0.0;
        foreach ($vector as $component) {
            $sumOfSquares += $component * $component;
        }
        $norm = sqrt($sumOfSquares);
        if ($norm <= 0.0) {
            // Astronomically unlikely for a hash-derived vector of any real
            // dimension; guarded rather than asserted away.
            return $vector;
        }

        return array_map(static fn (float $component): float => $component / $norm, $vector);
    }

    /**
     * Renders a vector as the `VEC_FromText()`-compatible literal text this
     * module's Rag classes use elsewhere (locale-safe: PHP's float-to-string
     * cast always uses '.' regardless of LC_NUMERIC).
     *
     * @param list<float> $vector
     */
    public static function toVecText(array $vector): string
    {
        return '[' . implode(',', array_map(static fn (float $component): string => (string) $component, $vector)) . ']';
    }
}

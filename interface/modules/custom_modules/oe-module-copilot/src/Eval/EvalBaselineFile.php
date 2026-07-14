<?php

/**
 * Parses the committed eval-gate baseline into the comparator's inputs
 * (TRO-36 residual, delivered with TRO-35; eval/goldenset/README.md
 * "Baseline + regeneration"; W2_ARCHITECTURE.md §7; PS-11).
 *
 * The baseline is a GENERATED, REVIEWED artifact — produced only by
 * `bin/regenerate-eval-goldenset.php`, never in CI, never hand-edited. This
 * class is the read side only: it parses the committed `baseline.json` into
 * an {@see EvalRunResult} (the six per-category `{passed, total}` scores)
 * plus the per-category pass floors {@see BaselineComparator} enforces,
 * refusing malformed data loud rather than silently comparing against a
 * partial baseline.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final readonly class EvalBaselineFile
{
    /**
     * @param array<string, float> $floors
     */
    private function __construct(
        private EvalRunResult $result,
        private array $floors,
    ) {
    }

    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \DomainException(sprintf('Eval baseline file "%s" does not exist or is not readable', $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \DomainException(sprintf('Eval baseline file "%s" could not be read', $path));
        }

        // Malformed JSON on an EXISTING file is acceptable to surface as
        // \JsonException — only an absent/unreadable file must be a
        // \DomainException (the frozen contract).
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \DomainException(sprintf('Eval baseline file "%s" must decode to a JSON object', $path));
        }

        $categoriesRaw = $decoded['categories'] ?? null;
        if (!is_array($categoriesRaw) || $categoriesRaw === []) {
            throw new \DomainException(sprintf('Eval baseline file "%s" is missing a non-empty "categories" object', $path));
        }

        $categoryNames = [];
        $scores = [];
        foreach ($categoriesRaw as $category => $entry) {
            if (!is_string($category)) {
                throw new \DomainException(sprintf('Eval baseline file "%s" has a non-string category key', $path));
            }
            if (!is_array($entry)) {
                throw new \DomainException(sprintf('Eval baseline file "%s" category "%s" must be an object', $path, $category));
            }

            $passed = $entry['passed'] ?? null;
            $total = $entry['total'] ?? null;
            if (!is_int($passed) || !is_int($total)) {
                throw new \DomainException(sprintf('Eval baseline file "%s" category "%s" must carry integer "passed" and "total"', $path, $category));
            }

            $categoryNames[$category] = true;
            $scores[] = new CategoryScore($category, $passed, $total);
        }

        $floorsRaw = $decoded['floors'] ?? null;
        if (!is_array($floorsRaw)) {
            throw new \DomainException(sprintf('Eval baseline file "%s" is missing a "floors" object', $path));
        }

        $floors = [];
        foreach ($floorsRaw as $category => $floor) {
            if (!is_string($category)) {
                throw new \DomainException(sprintf('Eval baseline file "%s" has a non-string floor category key', $path));
            }
            if (!array_key_exists($category, $categoryNames)) {
                throw new \DomainException(sprintf('Eval baseline file "%s" floor names category "%s", absent from "categories"', $path, $category));
            }
            if (!is_int($floor) && !is_float($floor)) {
                throw new \DomainException(sprintf('Eval baseline file "%s" floor for category "%s" must be numeric', $path, $category));
            }
            $floors[$category] = (float) $floor;
        }

        return new self(new EvalRunResult($scores), $floors);
    }

    public function result(): EvalRunResult
    {
        return $this->result;
    }

    /**
     * @return array<string, float>
     */
    public function floors(): array
    {
        return $this->floors;
    }
}

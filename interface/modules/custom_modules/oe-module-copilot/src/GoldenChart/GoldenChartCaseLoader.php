<?php

/**
 * Strict loader for golden-chart fixture files (T11; ARCHITECTURE.md §6).
 *
 * Adjudicated labels enter the harness only as JSON fixtures with an explicit
 * `adjudicated` flag. Files are read in filename order; each is parsed strictly
 * with JSON_THROW_ON_ERROR and explicit shape validation. Malformed input is a
 * \RuntimeException naming the offending file — never a silently skipped case —
 * and the loader NEVER invents, defaults, or repairs a field. Keys the schema
 * does not read (including underscore-prefixed keys such as _comment) are ignored.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\GoldenChart;

final readonly class GoldenChartCaseLoader
{
    /**
     * @return list<GoldenChartCase>
     */
    public function loadFromDirectory(string $dir): array
    {
        $matches = glob(rtrim($dir, '/') . '/*.json');
        $files = $matches === false ? [] : $matches;
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $cases[] = $this->loadCase($file);
        }

        return $cases;
    }

    private function loadCase(string $file): GoldenChartCase
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Golden-chart fixture "%s" could not be read.', basename($file)));
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" is not valid JSON.', basename($file)),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" must decode to a JSON object.', basename($file)),
            );
        }

        $id = $this->extractString($decoded, 'id', $file);
        $adjudicated = $this->extractBool($decoded, 'adjudicated', $file);
        $labelsData = $this->extractObject($decoded, 'labels', $file);
        $mustNotMiss = $this->extractStringList($labelsData, 'must_not_miss', $file);
        $keyFacts = $this->extractStringList($labelsData, 'key_facts', $file);

        try {
            return new GoldenChartCase($id, $adjudicated, new GoldenChartLabels($mustNotMiss, $keyFacts));
        } catch (\DomainException $e) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" contains an invalid id or label.', basename($file)),
                0,
                $e,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function extractString(array $data, string $key, string $file): string
    {
        if (!array_key_exists($key, $data)) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" is missing required key "%s".', basename($file), $key),
            );
        }
        $value = $data[$key];
        if (!is_string($value)) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" key "%s" must be a string.', basename($file), $key),
            );
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function extractBool(array $data, string $key, string $file): bool
    {
        if (!array_key_exists($key, $data)) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" is missing required key "%s".', basename($file), $key),
            );
        }
        $value = $data[$key];
        if (!is_bool($value)) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" key "%s" must be a boolean.', basename($file), $key),
            );
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function extractObject(array $data, string $key, string $file): array
    {
        if (!array_key_exists($key, $data) || !is_array($data[$key])) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" is missing a valid "%s" object.', basename($file), $key),
            );
        }

        return $data[$key];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private function extractStringList(array $data, string $key, string $file): array
    {
        if (!array_key_exists($key, $data)) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" is missing required key "labels.%s".', basename($file), $key),
            );
        }
        $value = $data[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException(
                sprintf('Golden-chart fixture "%s" key "labels.%s" must be a JSON array.', basename($file), $key),
            );
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \RuntimeException(
                    sprintf(
                        'Golden-chart fixture "%s" key "labels.%s" must contain only strings.',
                        basename($file),
                        $key,
                    ),
                );
            }
            $result[] = $item;
        }

        return $result;
    }
}

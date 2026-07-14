<?php

/**
 * Strict loader for the golden-set case files (TRO-35;
 * eval/goldenset/README.md "Case schema"; W2_ARCHITECTURE.md §7).
 *
 * Parse-don't-validate boundary: every case file is untrusted committed
 * data until this loader has checked it against the README's frozen
 * contract. A malformed file never loads as a half-case — every violation
 * fails the WHOLE load loud, the same posture VlmExtractionParser takes on
 * extraction wire data (Decision W2 applied to eval fixtures). Files are
 * read in filename order (`glob()` + `sort()`), and because `id` is
 * required to equal the filename stem, that is also deterministic id order.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final class GoldenSetCaseLoader
{
    /** Closed reporting-group set (eval/goldenset/README.md "Case schema"). */
    private const CATEGORIES = [
        'extraction',
        'retrieval',
        'citation',
        'refusal',
        'missing_data',
        'composition',
        'injection',
    ];

    /** Closed comparator-category set every case's rubrics draw from. */
    private const RUBRICS = [
        'schema_valid',
        'citation_present',
        'factually_consistent',
        'safe_refusal',
        'no_phi_in_logs',
    ];

    /**
     * @return list<GoldenSetCase>
     */
    public function loadFromDirectory(string $dir): array
    {
        $matches = glob(rtrim($dir, '/') . '/*.json');
        $files = $matches === false ? [] : $matches;
        sort($files);

        if ($files === []) {
            throw new \DomainException(sprintf('Golden-set case directory "%s" contains no case files — an empty gate is never a gate', $dir));
        }

        $cases = [];
        foreach ($files as $file) {
            $cases[] = $this->loadCase($file);
        }

        return $cases;
    }

    private function loadCase(string $file): GoldenSetCase
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \DomainException(sprintf('Golden-set case "%s" could not be read', basename($file)));
        }

        // JSON_THROW_ON_ERROR: malformed JSON propagates as \JsonException,
        // uncaught here by design (the frozen loader test pins this).
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \DomainException(sprintf('Golden-set case "%s" must decode to a JSON object', basename($file)));
        }

        /** @var array<string, mixed> $data */
        $data = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \DomainException(sprintf('Golden-set case "%s" has a non-string top-level key', basename($file)));
            }
            $data[$key] = $value;
        }

        $id = $this->extractNonBlankString($data, 'id', $file);
        if ($id !== basename($file, '.json')) {
            throw new \DomainException(sprintf('Golden-set case "%s" declares id "%s", which must equal its filename stem', basename($file), $id));
        }

        $kindRaw = $data['kind'] ?? null;
        if (!is_string($kindRaw)) {
            throw new \DomainException(sprintf('Golden-set case "%s" is missing a string "kind"', $id));
        }
        $kind = GoldenCaseKind::tryFrom($kindRaw);
        if ($kind === null) {
            throw new \DomainException(sprintf('Golden-set case "%s" declares unknown kind "%s"', $id, $kindRaw));
        }

        $category = $this->extractNonBlankString($data, 'category', $file);
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \DomainException(sprintf('Golden-set case "%s" declares unknown category "%s"', $id, $category));
        }

        $adjudicated = $data['adjudicated'] ?? null;
        if ($adjudicated !== true) {
            throw new \DomainException(sprintf('Golden-set case "%s" must carry adjudicated=true — un-adjudicated data is refused outright', $id));
        }

        $rubrics = $this->extractRubrics($data, $id);

        $guardsAgainst = $this->extractNonBlankString($data, '_guards_against', $file);
        $provenance = $this->extractNonBlankString($data, '_provenance', $file);

        $inputs = $data['inputs'] ?? null;
        if (!is_array($inputs)) {
            throw new \DomainException(sprintf('Golden-set case "%s" must carry an "inputs" object', $id));
        }

        $expected = $data['expected'] ?? null;
        if (!is_array($expected)) {
            throw new \DomainException(sprintf('Golden-set case "%s" must carry an "expected" object', $id));
        }

        return new GoldenSetCase(
            $id,
            $kind,
            $category,
            $rubrics,
            $guardsAgainst,
            $provenance,
            $this->stringKeyed($inputs),
            $this->stringKeyed($expected),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractNonBlankString(array $data, string $key, string $file): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException(sprintf('Golden-set case "%s" requires a non-blank "%s"', basename($file, '.json'), $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function extractRubrics(array $data, string $id): array
    {
        $rubricsRaw = $data['rubrics'] ?? null;
        if (!is_array($rubricsRaw) || !array_is_list($rubricsRaw) || $rubricsRaw === []) {
            throw new \DomainException(sprintf('Golden-set case "%s" requires a non-empty "rubrics" list', $id));
        }

        $rubrics = [];
        foreach ($rubricsRaw as $rubric) {
            if (!is_string($rubric) || !in_array($rubric, self::RUBRICS, true)) {
                throw new \DomainException(sprintf('Golden-set case "%s" declares an unknown rubric', $id));
            }
            $rubrics[] = $rubric;
        }

        if (!in_array('no_phi_in_logs', $rubrics, true)) {
            throw new \DomainException(sprintf('Golden-set case "%s" must declare the "no_phi_in_logs" rubric — every case is PHI-scanned', $id));
        }

        return $rubrics;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}

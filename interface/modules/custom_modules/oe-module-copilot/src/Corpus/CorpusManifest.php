<?php

/**
 * Parsed corpus manifest — the §3 index of the corpus README (corpus README
 * §3; W2_ARCHITECTURE §5, §11).
 *
 * The README's "### Documents" and "### Chunk inventory" markdown tables are
 * the machine-readable source of truth for what documents and chunk IDs
 * exist: the indexer and eval enumerate valid IDs from here, never by
 * parsing prose or scanning the corpus directory. Parsing is structural
 * (split rows on `|`, skip the header and separator rows) so the manifest
 * stays in lockstep with the README without hardcoding counts or IDs — the
 * "33 chunks" and "7 documents" the tests assert are read off the real
 * file, not duplicated as a constant here.
 *
 * `fromDirectory()` fails loud (never returns a partial manifest) if the
 * directory or its README.md is missing — a renamed or missing manifest
 * must never be silently skipped (corpus README §5).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Corpus;

final readonly class CorpusManifest
{
    /**
     * @param list<CorpusDocument> $documents
     * @param list<ChunkInventoryEntry> $chunkInventory
     */
    private function __construct(
        private array $documents,
        private array $chunkInventory,
    ) {
    }

    public static function fromDirectory(string $dir): self
    {
        $normalizedDir = rtrim($dir, '/');
        if (!is_dir($normalizedDir)) {
            throw new \RuntimeException(sprintf('Corpus directory "%s" does not exist.', $normalizedDir));
        }

        $readmePath = $normalizedDir . '/README.md';
        if (!is_file($readmePath)) {
            throw new \RuntimeException(sprintf('Corpus manifest "%s" does not exist.', $readmePath));
        }

        $raw = file_get_contents($readmePath);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Corpus manifest "%s" could not be read.', $readmePath));
        }

        $lines = explode("\n", $raw);

        $documents = [];
        foreach (self::extractTableRows($lines, '### Documents', $readmePath) as $row) {
            $documents[] = new CorpusDocument(
                sourceId: $row[0] ?? '',
                file: $row[1] ?? '',
                sourceType: $row[2] ?? '',
                license: $row[3] ?? '',
            );
        }

        $chunkInventory = [];
        foreach (self::extractTableRows($lines, '### Chunk inventory', $readmePath) as $row) {
            $chunkInventory[] = new ChunkInventoryEntry(
                chunkId: $row[0] ?? '',
                sourceId: $row[1] ?? '',
                section: $row[2] ?? '',
            );
        }

        return new self($documents, $chunkInventory);
    }

    /**
     * @return list<CorpusDocument>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * @return list<ChunkInventoryEntry>
     */
    public function chunkInventory(): array
    {
        return $this->chunkInventory;
    }

    /**
     * Structurally parses one markdown pipe table following a "### {heading}"
     * line: collects contiguous `|`-prefixed lines, drops the header and
     * separator rows, and returns the remaining rows as trimmed,
     * backtick-stripped cell lists. Never counts or matches on content —
     * purely positional, so it stays correct as the README's prose evolves.
     *
     * @param list<string> $lines
     * @return list<list<string>>
     */
    private static function extractTableRows(array $lines, string $headingPrefix, string $readmePath): array
    {
        $inSection = false;
        $tableLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (!$inSection) {
                if (str_starts_with($trimmed, $headingPrefix)) {
                    $inSection = true;
                }
                continue;
            }

            if ($trimmed === '') {
                if ($tableLines !== []) {
                    break;
                }
                continue;
            }

            if (!str_starts_with($trimmed, '|')) {
                break;
            }

            $tableLines[] = $trimmed;
        }

        if (!$inSection) {
            throw new \RuntimeException(
                sprintf('Corpus manifest "%s" is missing a "%s" section.', $readmePath, $headingPrefix),
            );
        }

        if (count($tableLines) < 2) {
            throw new \RuntimeException(
                sprintf('Corpus manifest "%s" section "%s" has no table rows.', $readmePath, $headingPrefix),
            );
        }

        // Row 0 is the header, row 1 the `|---|---|` separator — neither is data.
        $dataLines = array_slice($tableLines, 2);

        $rows = [];
        foreach ($dataLines as $dataLine) {
            $rows[] = self::splitTableRow($dataLine);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function splitTableRow(string $dataLine): array
    {
        // explode() never returns an empty list, so the leading cell is safe to inspect.
        $cells = explode('|', $dataLine);

        if (trim($cells[0]) === '') {
            array_shift($cells);
        }
        if ($cells !== [] && trim($cells[count($cells) - 1]) === '') {
            array_pop($cells);
        }

        return array_map(
            static fn (string $cell): string => trim(str_replace('`', '', $cell)),
            $cells,
        );
    }
}

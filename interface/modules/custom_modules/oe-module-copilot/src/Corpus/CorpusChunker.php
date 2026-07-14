<?php

/**
 * Splits a corpus document into chunks on `<!-- chunk: ... -->` markers
 * (corpus README §2; W2_ARCHITECTURE §5).
 *
 * The retriever splits on chunk markers, not on headings or file
 * boundaries, so a heading can be reworded without changing a chunk's
 * stable identity. A chunk's body runs from the end of its marker to the
 * start of the next marker (or EOF); its heading is the first markdown
 * heading line within that span. The chunker parses exactly the file it is
 * given — callers (the manifest-driven indexer, `CorpusIntegrityTest`) are
 * responsible for reading only the files the §3 manifest lists, so a stray
 * example marker elsewhere (this README's own §2 illustration included) can
 * never become a phantom chunk.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Corpus;

final class CorpusChunker
{
    private const MARKER_PATTERN = '/<!--\s*chunk:\s*([^|]+?)\s*\|\s*source:\s*([^|]+?)\s*\|\s*derived_from:\s*(.*?)\s*-->/s';

    private function __construct()
    {
    }

    /**
     * @return list<CorpusChunk>
     */
    public static function chunkFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Corpus file "%s" is not a readable file.', $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Corpus file "%s" could not be read.', $path));
        }

        // With PREG_SPLIT_DELIM_CAPTURE, the three marker capture groups
        // (id, source, derived_from) ride along in the split result: parts[0]
        // is any preamble before the first marker (discarded), then every
        // marker contributes a (id, source, derived_from, span-to-next-marker)
        // quadruple, in order.
        $parts = preg_split(self::MARKER_PATTERN, $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException(sprintf('Corpus file "%s" could not be scanned for chunk markers.', $path));
        }

        $chunks = [];
        $count = count($parts);
        for ($i = 1; $i + 3 < $count; $i += 4) {
            $chunkId = trim($parts[$i]);
            $sourceId = trim($parts[$i + 1]);
            $derivedFrom = trim($parts[$i + 2]);
            $span = trim($parts[$i + 3]);

            [$heading, $body] = self::splitHeadingAndBody($span);

            $chunks[] = new CorpusChunk($chunkId, $sourceId, $derivedFrom, $heading, $body);
        }

        return $chunks;
    }

    /**
     * Pulls the first markdown heading line out of a chunk's span (its
     * `page_or_section` citation value) from the prose that follows it (its
     * `quote_or_value` citation value).
     *
     * @return array{0: string, 1: string}
     */
    private static function splitHeadingAndBody(string $span): array
    {
        $lines = explode("\n", $span);
        $heading = '';
        $headingFound = false;
        $bodyLines = [];

        foreach ($lines as $line) {
            if (!$headingFound && preg_match('/^#{1,6}\s*(.+?)\s*$/', trim($line), $matches) === 1) {
                $heading = $matches[1];
                $headingFound = true;
                continue;
            }
            $bodyLines[] = $line;
        }

        return [$heading, trim(implode("\n", $bodyLines))];
    }
}

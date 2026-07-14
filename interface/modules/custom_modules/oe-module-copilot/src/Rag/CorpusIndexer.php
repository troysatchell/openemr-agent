<?php

/**
 * The committed corpus indexer — rebuilds the whole RAG index from the
 * repo alone (W2_ARCHITECTURE.md §5 "Hybrid RAG + rerank", §11 "reproducible
 * from the repo alone"; PS-12 degradation pair).
 *
 * `rebuild()` is the one path that ever writes
 * `mod_copilot_corpus_chunks`/`mod_copilot_chunk_embeddings`: it ensures the
 * module-owned schema exists, parses the manifest-driven corpus into stable
 * chunks (`CorpusManifest` + `CorpusChunker` — Wave A), and **replaces** both
 * tables' contents in full every run — never an incremental merge, so a
 * rebuild is always a faithful snapshot of the committed corpus, and a
 * renamed/removed chunk can never linger as a stale row. The embedding leg is
 * replaced first (it has a foreign-key-shaped dependency on chunk ids existing)
 * so no embedding row can ever outlive the chunk row it points at.
 *
 * The two legs degrade independently by design (PS-12, build-time half): if
 * the Cohere embed vendor boundary is unreachable, the keyword (FULLTEXT) leg
 * still indexes in full and only the dense (VECTOR) leg is skipped — a
 * stale-index alarm carried in the returned `IndexReport`, never a
 * user-facing exception. `CorpusIndexer` catches ONLY
 * `EmbeddingUnavailableException` for this — any other throwable (a schema
 * problem, a corrupt manifest, a database fault) propagates, because those
 * are not the one degradation mode this class is built to absorb.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Corpus\CorpusChunk;
use OpenEMR\Modules\Copilot\Corpus\CorpusChunker;
use OpenEMR\Modules\Copilot\Corpus\CorpusManifest;

final class CorpusIndexer
{
    public function __construct(private readonly CohereEmbedClient $embedder)
    {
    }

    /**
     * Rebuilds the corpus index from the committed corpus directory.
     *
     * @param string $corpusDir path to the corpus directory (the one
     *        containing README.md and the source document files it lists).
     */
    public function rebuild(string $corpusDir): IndexReport
    {
        CorpusIndexSchema::ensureInstalled();

        $chunks = $this->chunkManifest($corpusDir);

        // Replace semantics: embeddings first (they point at chunk ids),
        // then chunks — never an accumulate-on-rebuild path (§11).
        QueryUtils::sqlStatementThrowException('DELETE FROM ' . CorpusIndexSchema::EMBEDDING_TABLE, []);
        QueryUtils::sqlStatementThrowException('DELETE FROM ' . CorpusIndexSchema::CHUNK_TABLE, []);

        foreach ($chunks as $chunk) {
            $this->insertChunk($chunk);
        }

        try {
            $vectors = $this->embedder->embed(array_map(self::chunkText(...), $chunks));
        } catch (EmbeddingUnavailableException) {
            // PS-12, build time: the keyword leg already indexed above; the
            // dense leg is skipped whole — this report IS the operator alarm.
            return new IndexReport(count($chunks), 0, true);
        }

        foreach ($chunks as $i => $chunk) {
            $this->insertEmbedding($chunk->chunkId, $vectors[$i]);
        }

        return new IndexReport(count($chunks), count($vectors), false);
    }

    /**
     * @return list<CorpusChunk>
     */
    private function chunkManifest(string $corpusDir): array
    {
        $manifest = CorpusManifest::fromDirectory($corpusDir);
        $normalizedDir = rtrim($corpusDir, '/');

        $chunks = [];
        foreach ($manifest->documents() as $document) {
            $path = $normalizedDir . '/' . $document->file;
            foreach (CorpusChunker::chunkFile($path) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    private static function chunkText(CorpusChunk $chunk): string
    {
        return $chunk->heading . "\n" . $chunk->body;
    }

    private function insertChunk(CorpusChunk $chunk): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . CorpusIndexSchema::CHUNK_TABLE
            . ' (chunk_id, source_id, heading, body, derived_from, indexed_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$chunk->chunkId, $chunk->sourceId, $chunk->heading, $chunk->body, $chunk->derivedFrom],
        );
    }

    /**
     * @param list<float> $vector
     */
    private function insertEmbedding(string $chunkId, array $vector): void
    {
        // PHP's string-cast of a float always uses '.' as the decimal
        // separator regardless of locale (it is not affected by
        // setlocale(LC_NUMERIC, ...)), so a plain implode is locale-safe.
        $vectorText = '[' . implode(',', array_map(static fn (float $f): string => (string) $f, $vector)) . ']';

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . CorpusIndexSchema::EMBEDDING_TABLE
            . ' (chunk_id, embedding, embedding_model) VALUES (?, VEC_FromText(?), ?)',
            [$chunkId, $vectorText, $this->embedder->modelId],
        );
    }
}

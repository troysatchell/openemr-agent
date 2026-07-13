<?php

/**
 * Module-owned schema for the RAG corpus index (W2_ARCHITECTURE.md §5
 * "Hybrid RAG + rerank", §10 "Data model"; PS-12 keyword-only degradation).
 *
 * The committed SQL file (`sql/corpus_index.sql`, relative to the module
 * root) is the single source of truth for these two tables — this class
 * executes it verbatim, module install/enable hooks execute it, and tests
 * execute it. Nobody hand-edits `mod_copilot_corpus_chunks` or
 * `mod_copilot_chunk_embeddings` directly; a schema change means editing the
 * SQL file, never issuing ad hoc DDL from application code.
 *
 * Two module-owned tables, no core schema edits (CLAUDE.md danger-zone
 * rule): a chunk table carrying the corpus text with a FULLTEXT index (the
 * keyword leg) and an embeddings table with a NOT NULL native `VECTOR`
 * column plus a `VECTOR INDEX` (the dense leg), keyed to chunk ids. The legs
 * are split deliberately — chunks can exist without embeddings (embedder
 * unreachable at build time leaves a keyword-only index, PS-12), and the
 * vector column's NOT NULL requirement therefore never blocks chunk
 * ingestion.
 *
 * `ensureInstalled()` is idempotent because every statement in the SQL file
 * is `CREATE TABLE IF NOT EXISTS`.
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

final class CorpusIndexSchema
{
    /** Keyword leg: corpus chunk text + FULLTEXT index. */
    public const CHUNK_TABLE = 'mod_copilot_corpus_chunks';

    /** Dense leg: native VECTOR column + VECTOR index, keyed to chunk_id. */
    public const EMBEDDING_TABLE = 'mod_copilot_chunk_embeddings';

    /** Cohere embed v3 dimension — a schema property, fixed at column-definition time. */
    public const EMBEDDING_DIMENSIONS = 1024;

    private function __construct()
    {
        // Static-only: the SQL file is the single source of truth, not this class's state.
    }

    /**
     * Idempotently creates both module-owned tables by executing the
     * committed install SQL. Safe to call on every request path that needs
     * the tables present (index build, ingestion, tests) — every statement
     * in the file is `CREATE TABLE IF NOT EXISTS`.
     *
     * @throws \RuntimeException if the committed SQL file is missing or unreadable — fail loud, never silently skip schema setup.
     */
    public static function ensureInstalled(): void
    {
        $path = self::sqlFilePath();
        $sql = is_readable($path) ? file_get_contents($path) : false;
        if ($sql === false) {
            throw new \RuntimeException('Cannot read module install SQL at ' . $path);
        }

        foreach (self::statements($sql) as $statement) {
            QueryUtils::sqlStatementThrowException($statement, []);
        }
    }

    private static function sqlFilePath(): string
    {
        return __DIR__ . '/../../sql/corpus_index.sql';
    }

    /**
     * Splits the committed SQL file into individual statements on the ';'
     * boundary. A simple split is sufficient because the file is
     * module-owned and deliberately kept free of embedded semicolons (see
     * the file's own header comment) — this is not a general-purpose SQL
     * statement splitter.
     *
     * @return list<string>
     */
    private static function statements(string $sql): array
    {
        $statements = [];
        foreach (explode(';', $sql) as $candidate) {
            $statement = trim($candidate);
            if ($statement === '') {
                continue;
            }
            $statements[] = $statement;
        }

        return $statements;
    }
}

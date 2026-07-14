-- Clinical Co-Pilot RAG corpus index — module-owned tables.
--
-- W2_ARCHITECTURE.md §5 (Hybrid RAG + rerank) and §10 (data model): no core
-- schema edits, ever (CLAUDE.md danger-zone rule) — this file is the module's
-- own install SQL and the single canonical DDL for these two tables.
-- CorpusIndexSchema::ensureInstalled() executes this file verbatim, module
-- install hooks execute this same file, and tests execute this same file —
-- nobody hand-edits the tables directly.
--
-- Two legs, split deliberately (PS-12, keyword-only degradation): the chunk
-- table below is the keyword leg (FULLTEXT) and can be populated and
-- searched with zero embedding rows present. The embeddings table is the
-- dense leg (native VECTOR), keyed to chunk_id by a separate row — the
-- embedding column's NOT NULL requirement therefore never blocks chunk
-- ingestion when the embedder is unreachable at build time.
--
-- 1024 = the Cohere embed v3 dimension. The dimension is a schema property
-- (fixed at column-definition time, not a runtime parameter), so it lives
-- here in the DDL and is mirrored by CorpusIndexSchema::EMBEDDING_DIMENSIONS.
--
-- Statement-splitting constraint: CorpusIndexSchema reads this file and
-- splits it into statements on the semicolon character. That is safe only
-- because this file is module-owned and deliberately kept free of any
-- string literal or comment containing that character — keep it that way
-- when editing (this comment block itself must not contain one either).

CREATE TABLE IF NOT EXISTS mod_copilot_corpus_chunks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chunk_id VARCHAR(191) NOT NULL,
    source_id VARCHAR(191) NOT NULL,
    heading VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    derived_from VARCHAR(255) NOT NULL,
    indexed_at DATETIME NULL,
    UNIQUE KEY uq_mod_copilot_corpus_chunks_chunk_id (chunk_id),
    FULLTEXT KEY ft_mod_copilot_corpus_chunks_heading_body (heading, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mod_copilot_chunk_embeddings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chunk_id VARCHAR(191) NOT NULL,
    embedding VECTOR(1024) NOT NULL,
    embedding_model VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mod_copilot_chunk_embeddings_chunk_id (chunk_id),
    VECTOR INDEX (embedding)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

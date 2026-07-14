-- Clinical Co-Pilot extraction lineage — module-owned link table.
--
-- W2_ARCHITECTURE.md §2 step 5 (the persistence spine) and §10 (data model),
-- PS-4 (SP-1: the derived-observation write is the native procedure chain,
-- never a FHIR write — C_Document.class.php:1399 is the core precedent for
-- stamping procedure_result.document_id). No core schema edits, ever
-- (CLAUDE.md danger-zone rule) — this file is the module's own install SQL
-- and the single canonical DDL for this table. ExtractionLineageSchema::
-- ensureInstalled() executes this file verbatim, module install hooks
-- execute this same file, and tests execute this same file — nobody
-- hand-edits the table directly.
--
-- The native procedure_order/procedure_report/procedure_result chain
-- already carries the visible provenance stamps (result_status
-- preliminary, document_id, report.source). This table carries the
-- extraction-specific detail those columns have no room for — extractor
-- version, the schema field path the value was read from, the source page,
-- and the per-field confidence — keyed one-to-one by procedure_result_id so
-- a derived result's lineage is always resolvable without widening a core
-- table.
--
-- Statement-splitting constraint: ExtractionLineageSchema reads this file
-- and splits it into statements on the semicolon character. That is safe
-- only because this file is module-owned and deliberately kept free of any
-- string literal or comment containing that character — keep it that way
-- when editing (this comment block itself must not contain one either).

CREATE TABLE IF NOT EXISTS mod_copilot_extraction_lineage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    procedure_result_id BIGINT NOT NULL,
    document_id BIGINT NOT NULL,
    extractor_version VARCHAR(50) NOT NULL,
    field_path VARCHAR(191) NOT NULL,
    page VARCHAR(20) NULL,
    confidence DOUBLE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mod_copilot_extraction_lineage_result_id (procedure_result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

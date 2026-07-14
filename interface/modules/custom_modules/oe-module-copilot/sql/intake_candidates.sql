-- Clinical Co-Pilot intake reconciliation candidates — module-owned table.
--
-- W2_ARCHITECTURE.md §2 step 5 (the persistence spine) and §10 (data model),
-- PS-4. List-shaped intake facts (current medications, allergies, family
-- history, demographics, chief concern) are module-owned reconciliation
-- candidates surfaced to the physician as cited evidence — they are NEVER
-- written into the native med/allergy lists, because that would be med
-- reconciliation, a clinical act beyond the two-write amendment. No core
-- schema edits, ever (CLAUDE.md danger-zone rule) — this file is the
-- module's own install SQL and the single canonical DDL for this table.
-- IntakeCandidatesSchema::ensureInstalled() executes this file verbatim,
-- module install hooks execute this same file, and tests execute this same
-- file — nobody hand-edits the table directly.
--
-- Each row is one extracted field-group entry, carrying the citation shape
-- needed to render it as a cited candidate (field path, page, confidence)
-- plus the extractor version for lineage. Re-persisting a document does not
-- overwrite prior rows: they are stamped superseded_at and retained (§10) —
-- a row is active while superseded_at is NULL.
--
-- Statement-splitting constraint: IntakeCandidatesSchema reads this file and
-- splits it into statements on the semicolon character. That is safe only
-- because this file is module-owned and deliberately kept free of any
-- string literal or comment containing that character — keep it that way
-- when editing (this comment block itself must not contain one either).

CREATE TABLE IF NOT EXISTS mod_copilot_intake_candidates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    patient_pid BIGINT NOT NULL,
    document_id BIGINT NOT NULL,
    field_group VARCHAR(50) NOT NULL,
    value_text TEXT NOT NULL,
    field_path VARCHAR(191) NOT NULL,
    page VARCHAR(20) NULL,
    confidence DOUBLE NULL,
    extractor_version VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    superseded_at DATETIME NULL,
    KEY idx_mod_copilot_intake_candidates_patient (patient_pid),
    KEY idx_mod_copilot_intake_candidates_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

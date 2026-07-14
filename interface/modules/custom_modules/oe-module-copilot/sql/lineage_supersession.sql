-- Clinical Co-Pilot supersession annotations on the extraction lineage table.
--
-- W2_ARCHITECTURE.md §2 step 5 ("Dedup is one-directional by invariant") and
-- PS-5 (docs/W2_PRD_SEEDS.md): suppression is a MODULE-LAYER annotation on
-- the module-owned lineage table only — never a mutation of any core row,
-- derived or real. A derived observation is superseded by a matching real
-- one, and the columns below record that fact on the lineage row that
-- already exists for the derived result. A real observation never has a
-- lineage row at all (only DerivedObservationWriter inserts one), so this
-- ALTER can never reach one.
--
-- superseded_by_result_id: the procedure_result_id of the real observation
-- that superseded this derived one (NULL until reconciled).
-- superseded_at: when reconciliation annotated this row (NULL until
-- reconciled) -- an ambiguous match leaves this NULL forever, both real
-- candidates are kept, nothing merges.
-- ambiguous_flag: set when more than one real candidate matched -- the
-- derived record is flagged for physician review instead of being
-- suppressed, and is excluded from further reconciliation passes.
--
-- MariaDB supports ADD COLUMN IF NOT EXISTS per-column, so this single
-- ALTER TABLE statement is idempotent and safe to run against an
-- already-migrated table. Statement-splitting constraint (see
-- ExtractionLineageSchema/SupersessionSchema): this file is read, split on
-- the semicolon character, and executed statement by statement, so it must
-- stay free of any embedded semicolon in a string literal or comment --
-- including this one.

ALTER TABLE mod_copilot_extraction_lineage
    ADD COLUMN IF NOT EXISTS superseded_by_result_id BIGINT NULL,
    ADD COLUMN IF NOT EXISTS superseded_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS ambiguous_flag TINYINT(1) NOT NULL DEFAULT 0;

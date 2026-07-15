<?php

/**
 * Module-owned schema for the extraction lineage link table
 * (W2_ARCHITECTURE.md §2 step 5, §10 "Data model"; PS-4).
 *
 * The committed SQL file (`sql/extraction_lineage.sql`, relative to the
 * module root) is the single source of truth for this table — this class
 * executes it verbatim, module install/enable hooks execute it, and tests
 * execute it. Nobody hand-edits `mod_copilot_extraction_lineage` directly; a
 * schema change means editing the SQL file, never issuing ad hoc DDL from
 * application code.
 *
 * One module-owned table, no core schema edits (CLAUDE.md danger-zone
 * rule): a link table keyed one-to-one by `procedure_result_id`, carrying
 * the extraction-specific detail (extractor version, source field path,
 * page, confidence) that the native procedure chain's own columns have no
 * room for. The chain itself already carries the visible provenance stamps
 * (`result_status` preliminary, `document_id`, `procedure_report.source`);
 * this table is a detail lookup, never the provenance of record.
 *
 * `ensureInstalled()` is idempotent because the SQL file is
 * `CREATE TABLE IF NOT EXISTS`.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Persistence;

use OpenEMR\Common\Database\QueryUtils;

final class ExtractionLineageSchema
{
    /** Link table: extraction lineage detail, keyed one-to-one by procedure_result_id. */
    public const LINEAGE_TABLE = 'mod_copilot_extraction_lineage';

    private function __construct()
    {
        // Static-only: the SQL file is the single source of truth, not this class's state.
    }

    /**
     * Idempotently creates the module-owned table by executing the
     * committed install SQL. Safe to call on every request path that needs
     * the table present (persistence, tests) — the statement in the file is
     * `CREATE TABLE IF NOT EXISTS`.
     *
     * Also upgrades an already-installed table in place: a deployed site
     * never reinstalls the module, so a column added to the committed SQL
     * after go-live (e.g. TRO-44's `bbox`) would otherwise never reach an
     * existing installation. Each upgrade is its own `SHOW COLUMNS` check +
     * conditional `ALTER TABLE ADD COLUMN` — additive only, never a DROP or
     * MODIFY, so this never touches or reinterprets data already stored.
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

        self::ensureBboxColumn();
    }

    /**
     * In-place upgrade for a pre-bbox installation (TRO-44): adds the
     * nullable `bbox` column when it is not already present.
     */
    private static function ensureBboxColumn(): void
    {
        $rows = QueryUtils::fetchRecords(
            'SHOW COLUMNS FROM ' . self::LINEAGE_TABLE . " LIKE 'bbox'",
            [],
        );

        if ($rows !== []) {
            return;
        }

        QueryUtils::sqlStatementThrowException(
            'ALTER TABLE ' . self::LINEAGE_TABLE . ' ADD COLUMN bbox VARCHAR(64) NULL',
            [],
        );
    }

    private static function sqlFilePath(): string
    {
        return __DIR__ . '/../../sql/extraction_lineage.sql';
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

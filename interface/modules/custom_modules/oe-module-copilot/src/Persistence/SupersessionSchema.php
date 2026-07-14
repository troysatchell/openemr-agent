<?php

/**
 * Module-owned schema migration: supersession annotation columns on the
 * extraction lineage table (W2_ARCHITECTURE.md §2 step 5 "Dedup is
 * one-directional by invariant"; PS-5, docs/W2_PRD_SEEDS.md).
 *
 * The committed SQL file (`sql/lineage_supersession.sql`, relative to the
 * module root) is the single source of truth for these columns — this
 * class executes it verbatim, module install/enable hooks execute it, and
 * tests execute it. Nobody hand-edits `mod_copilot_extraction_lineage`
 * directly; a schema change means editing the SQL file, never issuing ad
 * hoc DDL from application code.
 *
 * Suppression is a module-layer ANNOTATION on the lineage table, never a
 * mutation of any core row (derived or real). PS-5's "impossible by
 * construction" invariant starts here: these columns only ever exist on
 * `mod_copilot_extraction_lineage`, a table a derived result has exactly
 * one row in. A real observation (interface feed, manual entry) never has
 * a lineage row at all — only `DerivedObservationWriter` inserts one — so
 * it can never carry these columns and can never be suppressed by them.
 *
 * `ensureInstalled()` first ensures the base lineage table exists
 * (`ExtractionLineageSchema::ensureInstalled()`), then applies this file's
 * `ADD COLUMN IF NOT EXISTS` migration — both steps are idempotent, so
 * repeated calls are safe on every request path that needs these columns
 * present (reconciliation, tests).
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

final class SupersessionSchema
{
    private function __construct()
    {
        // Static-only: the SQL file is the single source of truth, not this class's state.
    }

    /**
     * Idempotently ensures the base lineage table exists, then applies the
     * supersession-column migration by executing the committed install SQL.
     * Safe to call on every request path that needs these columns present
     * (reconciliation, tests) — the migration statement is
     * `ADD COLUMN IF NOT EXISTS`.
     *
     * @throws \RuntimeException if the committed SQL file is missing or unreadable — fail loud, never silently skip schema setup.
     */
    public static function ensureInstalled(): void
    {
        ExtractionLineageSchema::ensureInstalled();

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
        return __DIR__ . '/../../sql/lineage_supersession.sql';
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

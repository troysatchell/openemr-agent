<?php

/**
 * Module-owned schema for the intake reconciliation candidates table
 * (W2_ARCHITECTURE.md §2 step 5, §10 "Data model"; PS-4).
 *
 * The committed SQL file (`sql/intake_candidates.sql`, relative to the
 * module root) is the single source of truth for this table — this class
 * executes it verbatim, module install/enable hooks execute it, and tests
 * execute it. Nobody hand-edits `mod_copilot_intake_candidates` directly; a
 * schema change means editing the SQL file, never issuing ad hoc DDL from
 * application code.
 *
 * One module-owned table, no core schema edits (CLAUDE.md danger-zone
 * rule): list-shaped intake facts (current medications, allergies, family
 * history, demographics, chief concern) live here as cited reconciliation
 * candidates — never in the native med/allergy lists (that would be med
 * reconciliation, a clinical act beyond the two-write amendment).
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

final class IntakeCandidatesSchema
{
    /** Module-owned table: intake reconciliation candidates, superseded-not-deleted. */
    public const CANDIDATES_TABLE = 'mod_copilot_intake_candidates';

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
        return __DIR__ . '/../../sql/intake_candidates.sql';
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

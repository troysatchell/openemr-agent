<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Common\Environment;

final class ErrorDisplayPolicy
{
    /**
     * S7 (AUDIT.md): PHP error display is forced off outside an explicit dev
     * environment, so production never leaks paths/SQL/stack context to the
     * client regardless of the DB-driven user_php_debug global. Matches
     * Kernel::isDev()'s exact-'dev' semantics (empty/unset => force off).
     */
    public static function shouldForceOff(string $environment): bool
    {
        return $environment !== 'dev';
    }
}

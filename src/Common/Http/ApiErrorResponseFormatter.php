<?php

/**
 * Generic client-facing body for unhandled API errors (AUDIT S1).
 *
 * The API dispatcher's last-resort catch block must never echo
 * $e->getMessage() to unauthenticated callers — exception messages carry SQL
 * fragments, file paths, and credential hints. The full detail belongs in the
 * server log; the client gets exactly one generic sentence, for every
 * throwable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Common\Http;

final class ApiErrorResponseFormatter
{
    private function __construct()
    {
    }

    /**
     * @return array{error: string}
     */
    public static function format(\Throwable $e): array
    {
        return ['error' => 'An error occurred while processing the request.'];
    }
}

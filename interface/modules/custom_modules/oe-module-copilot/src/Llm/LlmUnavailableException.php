<?php

/**
 * Thrown when model inference failed or was unreachable (T12; UC2; R11;
 * ARCHITECTURE.md §2 ORCH, §3.5).
 *
 * The orchestrator degrades honestly on this exception: the deterministic
 * critical subset survives (it never depended on the model), the answer is
 * withheld, and a generic reason reaches the physician — never this
 * exception's message, which may carry internal details (AUDIT: never
 * expose exception internals in user-facing output).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

final class LlmUnavailableException extends \RuntimeException
{
}

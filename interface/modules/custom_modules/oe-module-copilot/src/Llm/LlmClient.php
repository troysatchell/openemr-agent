<?php

/**
 * Contract for one grounded model completion (T12; UC2;
 * ARCHITECTURE.md §2 ORCH, §3.5).
 *
 * The LLM sits outside the trust boundary: implementations never receive
 * credentials or database access, only the minimum-necessary
 * DisclosedPayload already carried on the request. A failed or unreachable
 * model raises LlmUnavailableException so the orchestrator can degrade
 * honestly rather than fail silently (R11).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Llm;

interface LlmClient
{
    /**
     * @throws LlmUnavailableException model inference failed or was unreachable
     */
    public function complete(LlmTurnRequest $request): LlmTurnResponse;
}

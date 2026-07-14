<?php

/**
 * Thrown when the Cohere rerank endpoint failed, was unreachable, or
 * returned an unparseable/malformed response (W2_ARCHITECTURE.md §5 "Hybrid
 * RAG + rerank").
 *
 * Reranking sits outside the trust boundary the same way the answer LLM
 * does: this exception lets HybridRetriever's caller degrade honestly
 * (§5 "Degradation") rather than silently substituting an unranked or
 * partial candidate set. TRO-28 wires the actual fallback (hybrid-score
 * order without rerank, flagged in the trace); this class exists here only
 * as the typed failure signal the fallback will catch. Vendor response
 * bodies and transport exception messages are never echoed into a thrown
 * message (AUDIT: never expose internals in user-facing output) — the
 * original throwable, if any, rides on getPrevious() only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final class RerankUnavailableException extends \RuntimeException
{
}
